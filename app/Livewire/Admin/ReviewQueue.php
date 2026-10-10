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

    // Phase 7.2 / UI1 — interactive overlay state.
    public bool $showOverlay = false;
    public array $overlay = [];            // overlay-data payload for the current page
    public array $overlayToggles = [       // which box layers are visible
        'sourceBounds' => true,
        'layoutContainer' => true,
        'eraseMask' => false,
        'targetGlyphBounds' => false,
        'protectedArtwork' => true,
    ];
    public ?string $selectedRegionId = null;

    // G4 — per-page "Fix with AI" state machine (on-demand generative repair).
    // idle → running → choose → applied | failed. Only ever touches the current page.
    public string $fixState = 'idle';
    public ?string $fixCandidateImage = null; // AI (generative) candidate preview
    public ?string $fixCurrentImage = null;   // current (cheap) preview
    public ?string $fixReason = null;          // failure/why text
    public ?string $fixRecompare = null;       // re-compare verdict after apply

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
        // so the reviewer's attention is drawn to likely-broken pages. Read via the
        // decoder (Phase 6.3) so legacy double-encoded qa_report rows still resolve.
        $qa = $this->translation->decodeQaReport() ?? [];
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
        $this->overlay = [];           // invalidate stale overlay for the previous page
        $this->selectedRegionId = null;
        $this->resetFix();             // G4: clear any pending AI-fix state for the old page
        if ($this->showOverlay) {
            $this->loadOverlay();
        }
    }

    /**
     * Phase 7.2 / UI1 — toggle the interactive overlay and (lazily) load its box data for
     * the current page from the engine's overlay-data command.
     */
    public function toggleOverlay(): void
    {
        $this->showOverlay = ! $this->showOverlay;
        if ($this->showOverlay && empty($this->overlay)) {
            $this->loadOverlay();
        }
    }

    public function toggleOverlayLayer(string $layer): void
    {
        if (array_key_exists($layer, $this->overlayToggles)) {
            $this->overlayToggles[$layer] = ! $this->overlayToggles[$layer];
        }
    }

    public function selectRegion(?string $regionId): void
    {
        $this->selectedRegionId = $regionId;
    }

    /** Load the overlay-data payload (image + per-region boxes) for the current page. */
    public function loadOverlay(): void
    {
        if (! $this->translation || ! $this->currentPage) {
            return;
        }
        try {
            $this->overlay = app(\App\Services\PdfTranslationService::class)
                ->buildOverlayData($this->book, $this->translation, $this->currentPage);
        } catch (\Throwable $e) {
            $this->overlay = [];
            session()->flash('error', 'Could not build overlay: ' . $e->getMessage());
        }
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
     * PER-REGION EDIT (spec R10.3, Phase 7.3). Write a reviewer's correction for a single
     * region to the CANONICAL override store (layout_overrides[regionId]) — NOT a second
     * parallel store — then re-render. The contract resolver + attachTargetsById already
     * consume layout_overrides[id] with top priority, so the edit flows through the single
     * production path. The edit invalidates the page approval, the layout+artwork approval
     * tracks, and narration (R10.5). Supported fields: translation (text), font_role,
     * translation_policy, container (bbox override).
     *
     * @param array $fields subset of ['translation','font_role','translation_policy','container']
     */
    public function updateRegion(string $regionId, array $fields): void
    {
        if (! $this->translation) {
            return;
        }
        $allowed = ['translation', 'font_role', 'translation_policy', 'container'];
        $clean = array_intersect_key($fields, array_flip($allowed));
        if (empty($clean)) {
            return;
        }

        $overrides = $this->translation->layout_overrides ?? [];
        $overrides[$regionId] = array_merge($overrides[$regionId] ?? [], $clean, [
            'edited_by_reviewer' => true,
            'edited_at' => now()->toIso8601String(),
        ]);
        $this->translation->layout_overrides = $overrides;
        $this->translation->save();

        // A per-region edit changes content/layout: drop the layout + artwork approvals and
        // mark narration outdated (R10.5). Language approval survives a pure layout tweak,
        // but a text change also drops it.
        $this->translation->invalidateTrack('layout');
        $this->translation->invalidateTrack('artwork');
        if (array_key_exists('translation', $clean)) {
            $this->translation->invalidateTrack('language');
            $this->translation->editionNarrations()
                ->where('status', 'completed')
                ->update(['is_outdated' => true]);
        }

        // Re-render through the single contract path so the override takes effect and the
        // fingerprint (and thus approval validity) is recomputed.
        try {
            app(\App\Services\PdfTranslationService::class)
                ->createTranslatedPdf($this->book, $this->translation->fresh());
            $this->translation = $this->translation->fresh();
        } catch (\Throwable $e) {
            session()->flash('error', 'Re-render after region edit failed: ' . $e->getMessage());
        }

        $this->loadTranslation();
    }

    /** Approve one of the separate tracks (language | layout | artwork) — R10.4. */
    public function approveTrack(string $track): void
    {
        try {
            $this->translation?->approveTrack($track);
            session()->flash('success', ucfirst($track) . ' approved for this edition.');
        } catch (\Throwable $e) {
            session()->flash('error', $e->getMessage());
        }
        $this->loadTranslation();
    }

    /**
     * UI2 — reviewer dragged a region's layout container to new bounds in the overlay. The
     * incoming box is in IMAGE PIXELS at the overlay dpi; convert to PDF points and persist
     * it as a per-region container override via updateRegion (which re-renders + invalidates).
     */
    public function updateRegionContainer(string $regionId, float $x0, float $y0, float $x1, float $y1): void
    {
        $dpi = (float) ($this->overlay['dpi'] ?? 110);
        $scale = 72.0 / $dpi; // px -> pt
        $container = [
            round($x0 * $scale, 2), round($y0 * $scale, 2),
            round($x1 * $scale, 2), round($y1 * $scale, 2),
        ];
        // guard against a degenerate drag
        if ($container[2] <= $container[0] || $container[3] <= $container[1]) {
            session()->flash('error', 'Ignored a degenerate container drag.');
            return;
        }
        $this->updateRegion($regionId, ['container' => $container]);
    }

    /**
     * XL1 (R10.4) — promote this edition's APPROVED artwork (cleaned backgrounds + region
     * repairs) to a BOOK-LEVEL store so other-language editions of the same book reuse the
     * cleaned artwork without re-approving it. The artwork is language-independent (only the
     * overlaid text differs), so a one-time approval is reusable across languages.
     */
    public function reuseArtworkAcrossLanguages(): void
    {
        if (! $this->translation || ! $this->translation->isTrackApproved('artwork')) {
            session()->flash('error', 'Approve the artwork track for this edition first.');
            return;
        }
        $artworkOverrides = [];
        foreach (($this->translation->layout_overrides ?? []) as $id => $ov) {
            // Keep only artwork-relevant, language-independent bits (mask/container/cleaned bg),
            // NOT the translated text (that is per-language).
            $keep = array_intersect_key($ov, array_flip(['container', 'mask', 'cleaned_bg', 'content_class']));
            if (!empty($keep)) {
                $artworkOverrides[$id] = $keep;
            }
        }
        $meta = $this->book->metadata ?? [];
        $meta['shared_artwork'] = [
            'approved_at' => now()->toIso8601String(),
            'approved_from_language' => $this->translation->language_code,
            'fingerprint' => $this->translation->render_fingerprint,
            'overrides' => $artworkOverrides,
        ];
        $this->book->forceFill(['metadata' => $meta])->save();
        session()->flash('success',
            'Artwork approved once and now reusable across all languages of this book.');
    }

    // =========================================================================
    // G4 — "Fix with AI" per-page generative repair (on-demand-generative-repair spec C-C)
    // =========================================================================

    /** Is the current page flagged (yellow/red or a render deviation)? The button only
     *  appears on flagged pages — a clean page never offers a paid AI fix. */
    public function currentPageIsFlagged(): bool
    {
        $pd = collect($this->pages)->firstWhere('page_number', $this->currentPage);
        if (!$pd) {
            return false;
        }
        return in_array($pd['quality_flag'] ?? 'gray', ['yellow', 'red'], true)
            || !empty($pd['has_deviation']);
    }

    /**
     * Kick off the AI (generative) candidate for the current page — ASYNC (G6). Dispatches
     * GeneratePageRepairJob so the ~1-min generative call does NOT block the request, then
     * sets state to 'running'. The UI polls pollFix() for completion. The ONLY production
     * trigger of a generative call. In tests the job runs sync for determinism.
     */
    public function fixWithAi(): void
    {
        if (!$this->translation || !$this->currentPage) {
            return;
        }
        $this->resetFix();
        $this->fixState = 'running';

        $job = new \App\Jobs\GeneratePageRepairJob($this->book->id, $this->translation->id, $this->currentPage);
        if (app()->environment('testing')) {
            \App\Jobs\GeneratePageRepairJob::dispatchSync($this->book->id, $this->translation->id, $this->currentPage);
            $this->pollFix(); // resolve immediately in tests
        } else {
            dispatch($job);
        }
    }

    /**
     * Poll the background repair job (G6). Called by the UI on a short interval while
     * fixState === 'running'. Transitions running → choose (two images) or → failed.
     */
    public function pollFix(): void
    {
        if ($this->fixState !== 'running' || !$this->translation || !$this->currentPage) {
            return;
        }
        $key = \App\Jobs\GeneratePageRepairJob::jobKey($this->translation->id, $this->currentPage);
        $row = \App\Models\ProcessingJob::where('book_id', $this->book->id)
            ->where('type', 'page_repair')->latest('updated_at')->first();

        if (!$row || ($row->details['key'] ?? null) !== $key) {
            return; // not our job yet
        }
        if ($row->status === 'completed' && ($row->details['ok'] ?? false)) {
            $this->fixCurrentImage = $this->publicUrlForTemp($row->details['current_image'] ?? null);
            $this->fixCandidateImage = $this->publicUrlForTemp($row->details['candidate_image'] ?? null);
            $this->fixState = 'choose';
        } elseif (in_array($row->status, ['completed', 'failed'], true)) {
            $this->fixReason = $row->details['reason'] ?? $row->error_message ?? 'UNKNOWN';
            $this->fixState = 'failed';
        }
        // else still processing — stay 'running', UI polls again.
    }

    /**
     * Apply the publisher's pick for the current page: 'keep_cheap' or 'use_generative'.
     * Delegates to applyPageVersion (G2), then reflects the outcome + re-compare verdict.
     */
    public function applyFix(string $choice): void
    {
        if (!$this->translation || !$this->currentPage) {
            return;
        }
        $res = app(\App\Services\IllustrationTextService::class)
            ->applyPageVersion($this->book, $this->translation, $this->currentPage, $choice);

        if (!empty($res['ok'])) {
            $this->fixRecompare = $res['recompare'] ?? null;
            $this->fixState = 'applied';
            if ($choice === 'use_generative' && ($res['recompare'] ?? null) !== \App\Services\VisualQaService::STATUS_PASSED) {
                session()->flash('error', 'AI version applied but the page still failed the layout re-check — left flagged for manual review.');
            } else {
                session()->flash('success', 'Page updated and re-checked.');
            }
        } else {
            $this->fixReason = $res['reason'] ?? 'APPLY_FAILED';
            $this->fixState = 'failed';
        }
        $this->translation = $this->translation->fresh();
        $this->loadTranslation();
    }

    /** Reset the Fix-with-AI panel back to idle (e.g. on page switch or cancel). */
    public function resetFix(): void
    {
        $this->fixState = 'idle';
        $this->fixCandidateImage = null;
        $this->fixCurrentImage = null;
        $this->fixReason = null;
        $this->fixRecompare = null;
    }

    /** Expose a storage/app/temp artifact to the browser via a one-off public copy. The
     *  candidate/current previews live in temp (not web-served); copy into the public disk
     *  under a predictable, page-scoped name so the <img> can load it. */
    private function publicUrlForTemp(?string $absPath): ?string
    {
        if (!$absPath || !is_file($absPath)) {
            return null;
        }
        $rel = "books/fixpreview/{$this->book->id}_{$this->language}/" . basename($absPath);
        Storage::disk('public')->put($rel, file_get_contents($absPath));
        return Storage::disk('public')->url($rel);
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

    /**
     * Readiness summary for the UI (unified-rendering-and-testing D3). Surfaces the per-check
     * QA statuses, the coverage count, and the single readiness decision so the reviewer sees
     * exactly WHY an edition is ready or blocked — distinct from the human page approvals.
     * Read-only: computes nothing that changes the gate.
     *
     * @return array<string,mixed>
     */
    private function readinessReport(): array
    {
        if (! $this->translation) {
            return ['ready' => false, 'checks' => [], 'issues' => [], 'coverage' => null];
        }
        $qa = $this->translation->decodeQaReport() ?? [];
        $checks = [];
        if (isset($qa['qa']['checks']) && is_array($qa['qa']['checks'])) {
            $checks = $qa['qa']['checks'];
        } elseif (isset($qa['checks']) && is_array($qa['checks'])) {
            $checks = $qa['checks'];
        }

        // Coverage count, if the whole-book coverage gate recorded one (e.g. "16/16").
        $coverage = null;
        if (isset($qa['visual_coverage']) && is_array($qa['visual_coverage'])) {
            $vc = $qa['visual_coverage'];
            $expected = is_array($vc['expected_pages'] ?? null) ? count($vc['expected_pages']) : 0;
            $issueCount = is_array($vc['issues'] ?? null) ? count($vc['issues']) : 0;
            $coverage = [
                'covered' => (bool) ($vc['covered'] ?? false),
                'expected' => $expected,
                'checked' => max(0, $expected - $issueCount),
            ];
        }

        $readiness = $this->translation->readiness();
        return [
            'ready' => $readiness['ready'],
            'issues' => $readiness['issues'],
            'checks' => $checks,
            'coverage' => $coverage,
        ];
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
            'readinessReport' => $this->readinessReport(),
            'fixState' => $this->fixState,
            'fixCandidateImage' => $this->fixCandidateImage,
            'fixCurrentImage' => $this->fixCurrentImage,
            'fixReason' => $this->fixReason,
            'fixRecompare' => $this->fixRecompare,
            'currentPageFlagged' => $this->currentPageIsFlagged(),
            'overlay' => $this->overlay,
            'overlayToggles' => $this->overlayToggles,
            'showOverlay' => $this->showOverlay,
            'selectedRegionId' => $this->selectedRegionId,
            'approvalTracks' => [
                'language' => $this->translation?->isTrackApproved('language') ?? false,
                'layout' => $this->translation?->isTrackApproved('layout') ?? false,
                'artwork' => $this->translation?->isTrackApproved('artwork') ?? false,
            ],
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
