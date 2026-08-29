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

        $this->pages = $query->get()->map(function ($tp) {
            return [
                'id' => $tp->id,
                'page_number' => $tp->page_number,
                'translated_text' => $tp->translated_text,
                'confidence_score' => $tp->confidence_score,
                'quality_flag' => $tp->quality_flag ?? 'gray',
                'quality_notes' => $tp->quality_notes,
                'review_status' => $tp->review_status ?? 'unreviewed',
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

    public function updateTranslation(int $pageId, string $newText)
    {
        $tp = TranslatedPage::find($pageId);
        if ($tp) {
            $tp->update([
                'translated_text' => $newText,
                'review_status' => 'edited',
                'quality_notes' => ($tp->quality_notes ?? '') . ' | Manually edited by reviewer.',
            ]);
            $this->loadTranslation();
        }
    }

    public function render()
    {
        $currentPageData = null;
        if ($this->currentPage) {
            $currentPageData = collect($this->pages)->firstWhere('page_number', $this->currentPage);
        }

        return view('livewire.admin.review-queue', [
            'currentPageData' => $currentPageData,
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
