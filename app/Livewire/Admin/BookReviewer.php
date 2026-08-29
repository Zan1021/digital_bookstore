<?php

namespace App\Livewire\Admin;

use App\Models\Book;
use App\Models\Translation;
use App\Models\TranslatedPage;
use App\Services\PdfTranslationService;
use Livewire\Component;

class BookReviewer extends Component
{
    public ?Book $book = null;
    public ?Translation $translation = null;
    public array $pages = [];
    public int $currentPage = 1;
    public string $editingText = '';
    public ?int $editingPageId = null;

    // Cover page text block controls
    public array $coverBlocks = [];
    public array $coverBlockTranslate = []; // block_index => true/false

    // Filters
    public string $filterStatus = 'all'; // all, unreviewed, approved, needs_edit

    // Interactive layout-debug overlay (§15)
    public bool $showOverlay = false;
    public array $overlayData = [];
    public array $overlayToggles = [
        'sourceBounds' => true,
        'safeInnerBounds' => true,
        'renderedGlyphBounds' => false,
        'readingOrder' => false,
        'invalidRegions' => true,
    ];
    public ?string $selectedRegionId = null;

    public function mount(Book $book, string $language = 'af')
    {
        $this->book = $book;
        $this->translation = $this->book->translations()
            ->where('language_code', $language)
            ->first();

        if ($this->translation) {
            $this->loadPages();
            $this->loadCoverBlocks();
        }
    }

    /**
     * Load text blocks detected on the cover page (page 1).
     * Publisher can toggle which blocks should be translated.
     */
    public function loadCoverBlocks()
    {
        try {
            $pdfService = app(PdfTranslationService::class);
            $metadata = $pdfService->extractMetadata($this->book);

            if (empty($metadata['pages'])) return;

            $page1 = $metadata['pages'][0] ?? null;
            if (!$page1) return;

            $blocks = [];
            $blockIdx = 0;
            foreach ($page1['text_blocks'] as $block) {
                foreach ($block['lines'] as $line) {
                    foreach ($line['spans'] as $span) {
                        $text = trim($span['text'] ?? '');
                        if (empty($text)) continue;

                        $blocks[] = [
                            'index' => $blockIdx,
                            'text' => $text,
                            'font' => $span['font'] ?? 'Unknown',
                            'size' => round($span['size'] ?? 0, 1),
                            'is_bold' => $span['is_bold'] ?? false,
                        ];
                        $blockIdx++;
                    }
                }
            }

            $this->coverBlocks = $blocks;

            // Load saved preferences from book metadata, or auto-detect defaults
            $savedConfig = $this->book->metadata['cover_translate_blocks'] ?? null;

            if ($savedConfig) {
                $this->coverBlockTranslate = $savedConfig;
            } else {
                // Auto-detect: translate by default, skip publisher/symbols
                foreach ($blocks as $b) {
                    $text = $b['text'];
                    $shouldSkip = (
                        preg_match('/^®$/', $text) ||
                        preg_match('/^©/', $text) ||
                        preg_match('/studios?$/i', $text) ||
                        preg_match('/^mthombothi/i', $text) ||
                        mb_strlen($text) <= 2
                    );
                    $this->coverBlockTranslate[$b['index']] = !$shouldSkip;
                }
            }
        } catch (\Throwable $e) {
            // If extraction fails, just skip — non-fatal
        }
    }

    public function saveCoverConfig()
    {
        $metadata = $this->book->metadata ?? [];
        $metadata['cover_translate_blocks'] = $this->coverBlockTranslate;
        $this->book->update(['metadata' => $metadata]);
        session()->flash('success', 'Cover page configuration saved.');
    }

    public function renderPdf()
    {
        if (!$this->translation) return;

        set_time_limit(300);

        try {
            $service = app(PdfTranslationService::class);
            $outputPath = $service->createTranslatedPdf($this->book, $this->translation);

            // Fail-closed (§13): render gate verdict already persisted by the service.
            $this->translation->refresh();
            $this->translation->update([
                'rendered_pdf_path' => $outputPath,
                'status' => $this->translation->isPublishable() ? 'rendered' : 'needs_review',
            ]);

            $this->translation->refresh();
            if ($this->translation->isPublishable()) {
                session()->flash('success', "PDF rendered and passed layout QA. Download it above.");
            } else {
                $pages = collect($this->translation->qa_report['review_pages'] ?? [])->implode(', ');
                session()->flash('error',
                    "PDF rendered but FAILED layout QA on page(s): {$pages}. Marked NEEDS_LAYOUT_REVIEW — not publishable."
                );
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'PDF rendering failed: ' . $e->getMessage());
        }
    }

    public function loadPages()
    {
        $query = $this->translation->translatedPages()->orderBy('page_number');

        if ($this->filterStatus !== 'all') {
            $query->where('review_status', $this->filterStatus);
        }

        $this->pages = $query->get()->map(function ($tp) {
            $originalPage = $this->book->pages()->where('page_number', $tp->page_number)->first();
            return [
                'id' => $tp->id,
                'page_number' => $tp->page_number,
                'original_text' => $originalPage?->extracted_text ?? '',
                'translated_text' => $tp->translated_text,
                'back_translation' => $tp->back_translation,
                'confidence_score' => $tp->confidence_score,
                'quality_flag' => $tp->quality_flag,
                'quality_notes' => $tp->quality_notes,
                'review_status' => $tp->review_status,
                'reviewer_notes' => $tp->reviewer_notes,
            ];
        })->toArray();
    }

    public function approvePage(int $pageId)
    {
        $tp = TranslatedPage::find($pageId);
        if ($tp) {
            $tp->update(['review_status' => 'approved']);
            $this->loadPages();
        }
    }

    public function rejectPage(int $pageId)
    {
        $tp = TranslatedPage::find($pageId);
        if ($tp) {
            $tp->update(['review_status' => 'needs_edit']);
            $this->loadPages();
        }
    }

    public function approveAll()
    {
        $this->translation->translatedPages()
            ->where('review_status', 'unreviewed')
            ->update(['review_status' => 'approved']);
        $this->loadPages();
    }

    public function startEdit(int $pageId)
    {
        $tp = TranslatedPage::find($pageId);
        if ($tp) {
            $this->editingPageId = $pageId;
            $this->editingText = $tp->translated_text;
        }
    }

    public function saveEdit()
    {
        if ($this->editingPageId) {
            $tp = TranslatedPage::find($this->editingPageId);
            if ($tp) {
                $tp->update([
                    'translated_text' => $this->editingText,
                    'review_status' => 'approved',
                    'reviewer_notes' => 'Manually edited by admin',
                ]);
            }
            $this->editingPageId = null;
            $this->editingText = '';
            $this->loadPages();
        }
    }

    public function cancelEdit()
    {
        $this->editingPageId = null;
        $this->editingText = '';
    }

    public function updatedFilterStatus()
    {
        $this->loadPages();
    }

    // ===================================================================
    // INTERACTIVE LAYOUT-DEBUG OVERLAY (overflow-fix brief §15)
    // ===================================================================

    /**
     * Load the overlay data (page image + region boxes) for the current page.
     */
    public function loadOverlay(?int $page = null)
    {
        if (!$this->translation) return;
        $page = $page ?? $this->currentPage;
        try {
            $service = app(PdfTranslationService::class);
            $this->overlayData = $service->buildOverlayData($this->book, $this->translation, $page);
            $this->showOverlay = true;
            $this->selectedRegionId = null;
        } catch (\Throwable $e) {
            session()->flash('error', 'Could not build overlay: ' . $e->getMessage());
        }
    }

    public function toggleOverlayLayer(string $layer)
    {
        if (array_key_exists($layer, $this->overlayToggles)) {
            $this->overlayToggles[$layer] = !$this->overlayToggles[$layer];
        }
    }

    public function selectRegion(string $regionId)
    {
        $this->selectedRegionId = $regionId;
    }

    /**
     * Persist a per-region override for THIS edition (edited translation, chosen
     * font, size/tracking/line-height, boundary edit). Stored keyed by region ID
     * with an audit trail — never in code (§15).
     */
    public function saveRegionOverride(string $regionId, array $override)
    {
        if (!$this->translation) return;
        $overrides = $this->translation->layout_overrides ?? [];
        $overrides[$regionId] = array_merge($overrides[$regionId] ?? [], $override, [
            '_updated_at' => now()->toIso8601String(),
            '_updated_by' => auth()->id(),
        ]);
        $this->translation->update(['layout_overrides' => $overrides]);
        session()->flash('success', "Override saved for region {$regionId}.");
    }

    /**
     * Re-render ONLY the current page (§15 single-page re-render). Uses the stored
     * stable-ID contract + per-item translations so unrelated pages are untouched.
     */
    public function reRenderPage(?int $page = null)
    {
        if (!$this->translation) return;
        $page = $page ?? $this->currentPage;
        set_time_limit(300);

        try {
            $service = app(PdfTranslationService::class);
            $contract = $this->translation->translation_contract['items'] ?? [];
            if (empty($contract)) {
                // No stored contract yet — build and persist one.
                $full = $service->buildStableIdContract($this->book, $this->translation->language_code);
                $contract = $full['items'] ?? [];
                $this->translation->update(['translation_contract' => ['items' => $contract]]);
            }

            // Collect the stable IDs that belong to this page + their translations.
            $itemIds = [];
            $itemTranslations = [];
            $overrides = $this->translation->layout_overrides ?? [];
            foreach ($contract as $item) {
                if (($item['page_number'] ?? null) !== $page) continue;
                $id = $item['id'];
                $itemIds[] = $id;
                // Prefer an edited override translation; else the source (round-trip).
                $itemTranslations[$id] = $overrides[$id]['translation']
                    ?? ($item['source_text'] ?? '');
            }

            if (empty($itemIds)) {
                session()->flash('error', "No contract items found for page {$page}.");
                return;
            }

            $result = $service->reRenderItems(
                $this->book, $this->translation, $contract, $itemTranslations, $itemIds
            );
            $this->translation->refresh();
            $this->loadOverlay($page);

            if ($result['publishable']) {
                session()->flash('success', "Page {$page} re-rendered and passed layout QA.");
            } else {
                session()->flash('error', "Page {$page} re-rendered but still NEEDS_LAYOUT_REVIEW.");
            }
        } catch (\Throwable $e) {
            session()->flash('error', 'Single-page re-render failed: ' . $e->getMessage());
        }
    }

    public function closeOverlay()
    {
        $this->showOverlay = false;
        $this->overlayData = [];
    }

    public function getStats(): array
    {
        if (!$this->translation) return [];

        $all = $this->translation->translatedPages;
        return [
            'total' => $all->count(),
            'approved' => $all->where('review_status', 'approved')->count(),
            'unreviewed' => $all->where('review_status', 'unreviewed')->count(),
            'needs_edit' => $all->where('review_status', 'needs_edit')->count(),
            'avg_score' => round($all->avg('confidence_score') ?? 0, 1),
        ];
    }

    public function render()
    {
        return view('livewire.admin.book-reviewer', [
            'stats' => $this->getStats(),
        ])->layout('layouts.admin');
    }
}
