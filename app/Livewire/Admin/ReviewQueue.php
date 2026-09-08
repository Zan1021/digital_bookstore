<?php

namespace App\Livewire\Admin;

use App\Models\Book;
use App\Models\Translation;
use App\Models\TranslatedPage;
use Illuminate\Support\Facades\Storage;
use Livewire\Component;

class ReviewQueue extends Component
{
    public Book $book;
    public ?Translation $translation = null;
    public string $language = 'af';
    public array $pages = [];
    public ?int $currentPage = null;
    public string $filter = 'all'; // all, flagged, approved, rejected

    public function mount(Book $book, ?string $language = null)
    {
        $this->book = $book;
        $this->language = $language ?? 'af';
        $this->loadTranslation();
    }

    private function loadTranslation()
    {
        $this->translation = Translation::where('book_id', $this->book->id)
            ->where('language_code', $this->language)
            ->first();

        if (!$this->translation) {
            $this->pages = [];
            return;
        }

        $query = TranslatedPage::where('translation_id', $this->translation->id)
            ->orderBy('page_number');

        if ($this->filter === 'flagged') {
            $query->whereIn('quality_flag', ['yellow', 'red']);
        } elseif ($this->filter === 'approved') {
            $query->where('review_status', 'approved');
        } elseif ($this->filter === 'rejected') {
            $query->where('review_status', 'rejected');
        }

        // Pages the render gate flagged with structure deviations (Req 4.2) — surfaced
        // so the reviewer's attention is drawn to likely-broken pages.
        $qa = $this->translation->qa_report ?? [];
        $deviationPages = [];
        foreach (($qa['structureDeviations'] ?? $qa['review_pages'] ?? []) as $key => $val) {
            // Accept either a list of page numbers or a map keyed by page number.
            $deviationPages[] = is_int($val) ? $val : (int) (is_numeric($key) ? $key : $val);
        }
        $deviationPages = array_filter($deviationPages);

        $this->pages = $query->get()->map(function ($tp) use ($deviationPages) {
            return [
                'id' => $tp->id,
                'page_number' => $tp->page_number,
                'translated_text' => $tp->translated_text,
                'confidence_score' => $tp->confidence_score,
                'quality_flag' => $tp->quality_flag ?? 'gray',
                'quality_notes' => $tp->quality_notes,
                'review_status' => $tp->review_status ?? 'unreviewed',
                'has_deviation' => in_array($tp->page_number, $deviationPages, true),
                'source_image' => $this->getPageImage('source', $tp->page_number),
                'translated_image' => $this->getPageImage('translated', $tp->page_number),
            ];
        })->toArray();

        if (empty($this->currentPage) && !empty($this->pages)) {
            $this->currentPage = $this->pages[0]['page_number'];
        }
    }

    private function getPageImage(string $type, int $pageNum): ?string
    {
        // Renders are stored as: books/comparison/{bookId}_{lang}/{type}/{type}_001.png
        $path = sprintf(
            'books/comparison/%d_%s/%s/%s_%03d.png',
            $this->book->id,
            $this->language,
            $type,
            $type,
            $pageNum
        );

        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        // Return a root-relative URL so images resolve regardless of the
        // host/port the app is served on (APP_URL may not match `artisan serve`).
        return Storage::disk('public')->url($path);
    }

    public function selectPage(int $pageNum)
    {
        $this->currentPage = $pageNum;
    }

    public function setFilter(string $filter)
    {
        $this->filter = $filter;
        $this->loadTranslation();
    }

    public function approvePage(int $pageId)
    {
        $tp = TranslatedPage::find($pageId);
        if ($tp) {
            $tp->update([
                'review_status' => 'approved',
                'quality_flag' => 'green',
            ]);
            // Mirror into the edition's page_approvals so markApproved() can gate on it
            // (spec Req 4.4 / 5.2).
            $this->translation?->setPageApproval($tp->page_number, true);
            $this->loadTranslation();
        }
    }

    public function rejectPage(int $pageId)
    {
        $tp = TranslatedPage::find($pageId);
        if ($tp) {
            $tp->update([
                'review_status' => 'rejected',
                'quality_flag' => 'red',
            ]);
            $this->translation?->setPageApproval($tp->page_number, false);
            $this->loadTranslation();
        }
    }

    public function flagForReview(int $pageId)
    {
        $tp = TranslatedPage::find($pageId);
        if ($tp) {
            $tp->update([
                'review_status' => 'needs_review',
                'quality_flag' => 'yellow',
            ]);
            $this->loadTranslation();
        }
    }

    /**
     * Edit a page's translated text, then re-render the edition through the V8
     * contract path so the change is reflected in the rendered PDF + comparison
     * images (spec Req 4.3 / Task 9). Editing invalidates the page's approval and any
     * existing narration for the edition (spec Req 6.1 / Task 11). Full-edition
     * re-render via the proven contract path avoids reintroducing the English-leak
     * fallback that a bespoke partial render risked.
     */
    public function updateTranslation(int $pageId, string $newText)
    {
        $tp = TranslatedPage::find($pageId);
        if (! $tp || ! $this->translation) {
            return;
        }

        $tp->update([
            'translated_text' => $newText,
            'review_status' => 'edited',
            'quality_notes' => ($tp->quality_notes ?? '') . ' | Manually edited by reviewer.',
        ]);

        // The edit un-approves this page (content changed since any prior approval).
        $this->translation->setPageApproval($tp->page_number, false);

        // Invalidate the edition's narration if it was already generated (Req 6.1).
        $this->translation->editionNarrations()
            ->where('status', 'completed')
            ->update(['is_outdated' => true]);

        // Re-render the edition so the rendered PDF + comparison images reflect the
        // edit; this also re-runs the hard-constraint gate + Fix C.
        try {
            app(\App\Services\PdfTranslationService::class)
                ->createTranslatedPdf($this->book, $this->translation->fresh());
            $this->translation = $this->translation->fresh();
        } catch (\Throwable $e) {
            session()->flash('error', 'Re-render after edit failed: ' . $e->getMessage());
        }

        $this->loadTranslation();
    }

    /**
     * Promote the edition to APPROVED once every page is approved and layout QA is
     * clear (spec Req 5.2). Narration for the edition unlocks only after this.
     */
    public function approveEdition()
    {
        if (! $this->translation) {
            return;
        }
        if ($this->translation->markApproved()) {
            session()->flash('success',
                "{$this->translation->language_name} edition approved. Narration is now available.");
        } else {
            [$approved, $total] = $this->translation->pageApprovalProgress();
            session()->flash('error',
                "Cannot approve yet: {$approved}/{$total} pages approved"
                . ($this->translation->canBePublished() ? '' : ' and layout QA is not clear') . '.');
        }
        $this->loadTranslation();
    }

    public function render()
    {
        $currentPageData = null;
        if ($this->currentPage) {
            $currentPageData = collect($this->pages)->firstWhere('page_number', $this->currentPage);
        }

        [$editionApproved, $editionTotal] = $this->translation
            ? $this->translation->pageApprovalProgress()
            : [0, 0];

        return view('livewire.admin.review-queue', [
            'currentPageData' => $currentPageData,
            'editionApprovedCount' => $editionApproved,
            'editionTotalPages' => $editionTotal,
            'editionCanApprove' => $this->translation?->allPagesApproved() && $this->translation?->canBePublished(),
            'editionRenderStatus' => $this->translation?->render_status,
            'stats' => [
                'total' => count($this->pages),
                'approved' => collect($this->pages)->where('review_status', 'approved')->count(),
                'rejected' => collect($this->pages)->where('review_status', 'rejected')->count(),
                'flagged' => collect($this->pages)->whereIn('quality_flag', ['yellow', 'red'])->count(),
                'unreviewed' => collect($this->pages)->where('review_status', 'unreviewed')->count(),
            ],
        ])->layout('layouts.admin');
    }
}
