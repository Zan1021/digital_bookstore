<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Translation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class PdfTranslationService
{
    /**
     * Path to fonts directory.
     */
    private string $fontsDir;

    /**
     * Current book context (for cover config access during PDF generation).
     */
    private ?Book $currentBook = null;

    /**
     * Stable IDs of contract spans that could NOT be resolved to a translation on a
     * page that DOES have translated text (fix C — English-leak guard). Such spans
     * are rendered BLANK rather than falling back to the English source_text, and
     * their presence forces the edition to NEEDS_LAYOUT_REVIEW so English never ships
     * silently. Populated by resolveItemTranslations(), read by createTranslatedPdf().
     *
     * @var string[]
     */
    private array $unresolvedSpanIds = [];

    public function __construct()
    {
        $this->fontsDir = storage_path('app/fonts');
    }

    /**
     * Get the script path — always V8.
     */
    private function getScriptPath(Book $book = null): string
    {
        return base_path('scripts/pdf_translate_v8.py');
    }

    /**
     * Extract text metadata from a PDF file.
     * Returns structured data with positions, fonts, sizes for every text span.
     */
    public function extractMetadata(Book $book): array
    {
        $pdfPath = Storage::disk('public')->path($book->pdf_path);
        $outputPath = storage_path("app/temp/metadata_{$book->id}.json");

        // Ensure temp directory exists
        $tempDir = dirname($outputPath);
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // Use V8 page manifest for extraction
        $process = new Process([
            'python',
            base_path('scripts/page_manifest.py'),
            $pdfPath,
            '--output', $outputPath,
        ]);

        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "PDF metadata extraction failed: " . $process->getErrorOutput()
            );
        }

        $metadata = json_decode(file_get_contents($outputPath), true);

        // Cleanup temp file
        @unlink($outputPath);

        return $metadata;
    }

    /**
     * Create a translated PDF for an edition (LIVE render).
     *
     * TASK 9 (spec Req 2.4): the CONTRACT path is now the default source of truth.
     * We build+persist the per-element contract, resolve each element's translation
     * (override > page text > source), and drive the engine with the ID-mapped,
     * structure-carrying items payload — NOT the lossy flat translated_text. This
     * resolves the flat/merge mismatch (e.g. p15 phonics fragments) because the
     * engine places each logical element in its true cell instead of re-splitting a
     * flat text blob by line position.
     *
     * The flat translated_text path is retained ONLY as a fallback for when the
     * engine cannot produce a contract for a source (no structural manifest).
     */
    public function createTranslatedPdf(Book $book, Translation $translation): string
    {
        $originalPath = Storage::disk('public')->path($book->pdf_path);
        $translatedPages = $translation->translatedPages()->orderBy('page_number')->get();

        if ($translatedPages->isEmpty()) {
            throw new \RuntimeException("No translated pages found for translation #{$translation->id}");
        }

        $this->setCurrentBook($book);

        // CONTRACT PATH (default): build+persist the per-element contract and render
        // from it. Fall back to the flat path only if no contract items are available.
        $usedContract = false;
        $hadUnresolvedSpans = false;
        try {
            $contractItems = $this->buildEditionContract($book, $translation);
        } catch (\Throwable $e) {
            Log::warning("Edition contract build failed for book #{$book->id} "
                . "({$translation->language_code}); falling back to flat render: " . $e->getMessage());
            $contractItems = [];
        }

        if (!empty($contractItems)) {
            $itemTranslations = $this->resolveItemTranslations($contractItems, $translation);
            // FIX C: any span left blank on a translated page must block publication.
            $hadUnresolvedSpans = !empty($this->getUnresolvedSpanIds());
            $translationsData = $this->buildIdMappedTranslationsJson($contractItems, $itemTranslations);
            $usedContract = !empty($translationsData['items']);
        }

        if (!$usedContract) {
            // Fallback: legacy flat per-page translated_text (lossy line-position map).
            $translationsData = $this->buildTranslationsJson([], $translatedPages);
        }

        // Write translations to temp file
        $translationsPath = storage_path("app/temp/translations_{$book->id}_{$translation->language_code}.json");
        $tempDir = dirname($translationsPath);
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        file_put_contents($translationsPath, json_encode($translationsData, JSON_UNESCAPED_UNICODE));

        // Define output path
        $outputFilename = "books/translated/{$book->id}_{$translation->language_code}.pdf";
        $outputPath = Storage::disk('public')->path($outputFilename);
        $outputDir = dirname($outputPath);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Run the rendering engine (full render — no --only-items, so every page that
        // owns contract items is rendered from the contract).
        // TASK 11: the lossy legacy flat mapper is opt-in only. When we fell back to
        // the flat payload (no contract available), pass --allow-legacy-flat so the
        // edition still renders; on the default CONTRACT path we do NOT, so any page
        // the contract cannot cover fails closed to review instead of mapping lossily.
        $scriptPath = $this->getScriptPath($book);

        $cmd = [
            'python',
            $scriptPath,
            'replace',
            '--input', $originalPath,
            '--output', $outputPath,
            '--translations', $translationsPath,
            '--fonts-dir', $this->fontsDir,
        ];
        if (!$usedContract) {
            $cmd[] = '--allow-legacy-flat';
        }

        $process = new Process($cmd);

        $process->setTimeout(300);
        $process->run();

        // Capture the report from stderr
        $report = json_decode($process->getErrorOutput(), true);

        if (!$process->isSuccessful()) {
            @unlink($translationsPath);
            throw new \RuntimeException(
                "PDF translation failed: " . $process->getErrorOutput()
            );
        }

        // Log the replacement report
        if ($report) {
            $report['render_source'] = $usedContract ? 'contract' : 'flat';
            Log::info("PDF translation report for book #{$book->id} ({$translation->language_code})"
                . " via " . ($usedContract ? 'CONTRACT' : 'FLAT') . " path", $report);

            if (!empty($report['errors'])) {
                Log::warning("Rendering errors", $report['errors']);
            }
            if (!empty($report['overflow_warnings'])) {
                Log::warning("Text overflow warnings", $report['overflow_warnings']);
            }
            if (!$usedContract && !empty($report['flags']['LEGACY_FLAT_MAPPING'])) {
                Log::warning("Edition rendered via LEGACY_FLAT_MAPPING pages",
                    $report['flags']['LEGACY_FLAT_MAPPING']);
            }
        }

        // FAIL-CLOSED (overflow-fix brief §13/§14): persist the render gate's QA
        // verdict with the edition. If the engine reports the render is not
        // publishable (any page failed the hard-constraint gate), the layout state
        // becomes NEEDS_LAYOUT_REVIEW so it CANNOT be silently approved/published.
        $publishable = $report['publishable'] ?? true;
        $renderStatus = $report['render_status'] ?? ($publishable ? 'READY_FOR_REVIEW' : 'NEEDS_LAYOUT_REVIEW');

        // FIX C: even if the engine's own gate passed, unresolved spans (rendered
        // blank to avoid an English leak) mean the edition is incomplete and must be
        // reviewed before it can be published. Fail closed.
        if ($hadUnresolvedSpans) {
            $publishable = false;
            $renderStatus = 'NEEDS_LAYOUT_REVIEW';
            $unresolvedIds = $this->getUnresolvedSpanIds();
            Log::warning("Edition has unresolved translation spans rendered blank "
                . "(fix C, English-leak guard) — routing to review", [
                    'book' => $book->id,
                    'language' => $translation->language_code,
                    'unresolved_span_ids' => $unresolvedIds,
                    'count' => count($unresolvedIds),
                ]);
            if (is_array($report)) {
                $report['publishable'] = false;
                $report['render_status'] = 'NEEDS_LAYOUT_REVIEW';
                $report['unresolved_span_ids'] = $unresolvedIds;
                $report['flags']['UNRESOLVED_TRANSLATION_SPANS'] = $unresolvedIds;
            }
        }

        // VISUAL QA GATE (Task 6, gpt-4o vision) — optional, config-gated. Renders
        // source vs translated page images and asks a vision model to flag layout
        // defects the structural gate can't see (clipping, inconsistent sizes, overlap,
        // garbled/missing text). Flagged pages force NEEDS_LAYOUT_REVIEW. Off by default
        // (costs one vision call per reviewed page). Never blocks on its own failure.
        if (config('bookstore.visual_qa_enabled')) {
            try {
                $scope = config('bookstore.visual_qa_scope', 'structured');
                $qaPages = null; // null = all
                if ($scope === 'structured' && is_array($report) && !empty($report['page_types'])) {
                    $qaPages = [];
                    foreach ($report['page_types'] as $pn => $ptype) {
                        if (in_array($ptype, ['vocabulary', 'cover', 'back_cover'], true)) {
                            $qaPages[] = (int) $pn;
                        }
                    }
                    $qaPages = $qaPages ?: null;
                }
                $qa = (new VisualQaService())->review($book, $translation, $qaPages);
                if (is_array($report)) {
                    $report['visual_qa'] = $qa;
                }
                if (!($qa['ok'] ?? true) && !empty($qa['flagged_pages'])) {
                    $publishable = false;
                    $renderStatus = 'NEEDS_LAYOUT_REVIEW';
                    Log::warning('Visual QA flagged pages — routing edition to review', [
                        'book' => $book->id,
                        'language' => $translation->language_code,
                        'flagged_pages' => $qa['flagged_pages'],
                    ]);
                    if (is_array($report)) {
                        $report['publishable'] = false;
                        $report['render_status'] = 'NEEDS_LAYOUT_REVIEW';
                        $report['review_pages'] = array_values(array_unique(array_merge(
                            $report['review_pages'] ?? [], $qa['flagged_pages']
                        )));
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Visual QA gate errored (non-blocking)', ['error' => $e->getMessage()]);
            }
        }

        $translation->forceFill([
            'render_status' => $renderStatus,
            'qa_report' => $report ? json_encode($report, JSON_UNESCAPED_UNICODE) : null,
        ])->save();

        // Cleanup temp file
        @unlink($translationsPath);

        return $outputFilename;
    }

    /**
     * Generate interactive layout-overlay data for a single page (§15).
     * Renders the page PNG into public storage and returns the overlay JSON
     * (region boxes in image pixel coords + status). Requires the edition to have
     * a persisted qa_report (diagnostic manifest) from the last render.
     */
    public function buildOverlayData(Book $book, Translation $translation, int $pageNumber, int $dpi = 110): array
    {
        $renderedRel = $translation->rendered_pdf_path
            ?? "books/translated/{$book->id}_{$translation->language_code}.pdf";
        $renderedPath = Storage::disk('public')->path($renderedRel);
        if (!is_file($renderedPath)) {
            throw new \RuntimeException("Rendered PDF not found: {$renderedRel}");
        }

        // Persist the diagnostic manifest to a temp file for the engine to read.
        $qa = $translation->qa_report ?? [];
        $manifest = $qa['diagnostic_manifest'] ?? ['pages' => []];
        $manifestPath = storage_path("app/temp/manifest_{$book->id}_{$translation->language_code}.json");
        $tempDir = dirname($manifestPath);
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_UNICODE));

        $imageRel = "books/overlays/{$book->id}_{$translation->language_code}_p{$pageNumber}.png";
        $imagePath = Storage::disk('public')->path($imageRel);
        $imageDir = dirname($imagePath);
        if (!is_dir($imageDir)) {
            mkdir($imageDir, 0755, true);
        }

        $process = new Process([
            'python',
            base_path('scripts/pdf_translate_v8.py'),
            'overlay-data',
            '--rendered', $renderedPath,
            '--page', (string) $pageNumber,
            '--manifest', $manifestPath,
            '--image-out', $imagePath,
            '--dpi', (string) $dpi,
        ]);
        $process->setTimeout(120);
        $process->run();
        @unlink($manifestPath);

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("Overlay data generation failed: " . $process->getErrorOutput());
        }

        $data = json_decode($process->getOutput(), true) ?: [];
        $data['image_url'] = Storage::disk('public')->url($imageRel);
        return $data;
    }

    /**
     * Build the translations JSON for V6 engine.
     * V6 uses full-page translated_text mode — the Python engine handles
     * page classification, text zone detection, and HTML rendering.
     * No more per-span splitting needed!
     */
    private function buildTranslationsJson(array $metadata, $translatedPages): array
    {
        $pages = [];

        foreach ($translatedPages as $translatedPage) {
            $pageNum = $translatedPage->page_number;
            $translatedText = $translatedPage->translated_text;

            if (empty(trim($translatedText))) {
                continue;
            }

            // V6: Simply pass the full translated text per page.
            // The Python engine handles all the rendering logic.
            $pages[] = [
                'page_number' => $pageNum,
                'translated_text' => trim($translatedText),
            ];
        }

        return ['pages' => $pages];
    }

    /**
     * STABLE-ID CONTRACT (overflow-fix brief §2.2 / §7 / §8).
     *
     * Ask the engine to emit the page->region->item translation request with
     * stable IDs for a source PDF. The returned structure is:
     *   { document_id, source_language, target_language, schema_version,
     *     items: [ { id, source_text, semantic_role, page_number, context, constraints } ] }
     *
     * This is the contract the translation layer should store against, so that
     * translations can be returned keyed by stable ID (no string reconstruction).
     */
    public function buildStableIdContract(Book $book, string $targetLanguage): array
    {
        $pdfPath = Storage::disk('public')->path($book->pdf_path);
        $outputPath = storage_path("app/temp/contract_{$book->id}_{$targetLanguage}.json");
        $tempDir = dirname($outputPath);
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $process = new Process([
            'python',
            base_path('scripts/pdf_translate_v8.py'),
            'contract',
            '--input', $pdfPath,
            '--target-language', $targetLanguage,
            '--output', $outputPath,
        ]);
        $process->setTimeout(180);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "Stable-ID contract generation failed: " . $process->getErrorOutput()
            );
        }

        $contract = json_decode(file_get_contents($outputPath), true) ?: [];
        @unlink($outputPath);

        return $contract;
    }

    /**
     * TASK 9 — Build AND PERSIST the per-element contract for an edition, then return
     * its item list. This is the source of truth the LIVE render uses (spec Req 2.4):
     * each item carries its stable id, page_number, reading order, source_text and the
     * structure-aware placement fields (cell_box, align_h/v, peer_group_id,
     * column_span, is_merged, semantic_role).
     *
     * Idempotent: reuses the stored contract on the translation unless $force is set
     * (e.g. after the source PDF changed). Book-agnostic — the shape comes entirely
     * from the engine's `contract` command.
     *
     * @return array the contract item list ([] if the engine produced none)
     */
    public function buildEditionContract(Book $book, Translation $translation, bool $force = false): array
    {
        $stored = $translation->translation_contract['items'] ?? null;
        if (!$force && is_array($stored) && count($stored) > 0) {
            return $stored;
        }

        $full = $this->buildStableIdContract($book, $translation->language_code);
        $items = $full['items'] ?? [];

        // Persist the full contract envelope (not just items) so downstream tooling
        // has the schema_version / language metadata too.
        $translation->forceFill([
            'translation_contract' => array_merge($full, ['items' => $items]),
        ])->save();

        return $items;
    }

    /**
     * TASK 9 — Resolve a book-wide stable-id => translation map for the contract
     * render. Generalises the per-page resolution used by the single-page overlay
     * re-render so a FULL edition render is driven by the same per-element source.
     *
     * Priority per item (highest first):
     *   1. a per-region override edited in the admin overlay (layout_overrides[id]);
     *   2. FIX B: the machine per-element translation for this id (item_translations[id]);
     *   3. this span's segment of the current per-page translated_text (reading order);
     *   4. single-span page: the whole per-page translated_text;
     *   5. FIX C: blank + route-to-review when the page has text but this span has none
     *      (never leak the English source_text);
     *   6. the item's source_text ONLY when the page is genuinely untranslated.
     *
     * FIX C (English-leak guard, 2026-08-30): step 4 no longer falls back to the
     * English source_text when the page HAS translated text but this span ran out of
     * segments. Doing so silently shipped English titles/words on multi-span pages
     * (back cover p16, WOORDE p15). Instead such a span renders BLANK and its id is
     * recorded on $unresolvedSpanIds so the edition is routed to NEEDS_LAYOUT_REVIEW.
     * The source_text fallback is retained ONLY for genuinely untranslated pages
     * (no translated_text at all), where showing the source in place is legitimate.
     *
     * @param array       $contractItems the persisted contract item list
     * @param Translation $translation
     * @return array<string,string> stable id => translated string
     */
    public function resolveItemTranslations(array $contractItems, Translation $translation): array
    {
        // Reset per-resolve so a re-run doesn't carry stale unresolved ids.
        $this->unresolvedSpanIds = [];

        $overrides = $translation->layout_overrides ?? [];
        // FIX B: machine per-element translations keyed by stable id. These carry each
        // span's OWN translation so we place it directly instead of splitting a flat
        // per-page blob (the lossy path that leaked English). Human overrides still win.
        $itemTranslations = $translation->item_translations ?? [];
        $pageText = $translation->translatedPages()
            ->pluck('translated_text', 'page_number')
            ->toArray();

        // Group items by page, preserving reading order. The previous implementation
        // assigned the ENTIRE page's translated_text to EVERY span on the page, so a
        // page with N spans rendered the same blob N times (overlapping / smeared).
        // Instead we split the page's translated text into segments and hand each
        // page span its OWN segment in reading order (1:1). Single-span pages keep the
        // whole-page text (the already-correct case). Mismatched counts fall back to
        // the span's source_text so an element still renders IN PLACE rather than
        // duplicating the blob — the render gate then flags any residual for review.
        $byPage = [];
        foreach ($contractItems as $order => $item) {
            $id = $item['id'] ?? null;
            if ($id === null) {
                continue;
            }
            $page = $item['page_number'] ?? null;
            $byPage[$page][] = ['order' => $item['reading_order'] ?? $order, 'item' => $item];
        }

        $map = [];
        foreach ($byPage as $page => $entries) {
            // Reading order within the page so segment[i] lands on span[i].
            usort($entries, fn ($a, $b) => $a['order'] <=> $b['order']);

            $full = ($page !== null && isset($pageText[$page])) ? (string) $pageText[$page] : null;
            $pageHasText = $full !== null && trim($full) !== '';
            $segments = $this->splitPageTextIntoSegments($full, count($entries));

            $i = 0;
            foreach ($entries as $entry) {
                $item = $entry['item'];
                $id = $item['id'];

                // 1) explicit per-region override always wins.
                if (isset($overrides[$id]['translation'])) {
                    $map[$id] = (string) $overrides[$id]['translation'];
                    $i++;
                    continue;
                }

                // 2) FIX B: machine per-element translation for this exact id. Placing
                // the span's OWN translation avoids the flat-blob re-split entirely, so
                // multi-span pages (back cover, WOORDE) get real text — not blank/leak.
                if (isset($itemTranslations[$id]) && trim((string) $itemTranslations[$id]) !== '') {
                    $map[$id] = (string) $itemTranslations[$id];
                    $i++;
                    continue;
                }

                // 3) this span's OWN segment of the page text (1:1 by reading order).
                if ($segments !== null && array_key_exists($i, $segments)) {
                    $seg = trim($segments[$i]);
                    if ($seg !== '') {
                        $map[$id] = $seg;
                        $i++;
                        continue;
                    }
                }

                // 4) single-span page: give it the whole page text (already-correct case).
                if (count($entries) === 1 && $pageHasText) {
                    $map[$id] = $full;
                    $i++;
                    continue;
                }

                // 5) FIX C: the page HAS translated text but this span has no segment.
                // Do NOT leak English by falling back to source_text — render BLANK and
                // flag the edition for review so English never ships silently.
                if ($pageHasText) {
                    $map[$id] = '';
                    $this->unresolvedSpanIds[] = $id;
                    $i++;
                    continue;
                }

                // 6) genuinely untranslated page (no translated_text at all): render the
                // source in place rather than vanishing. This is legitimate, not a leak.
                $map[$id] = (string) ($item['source_text'] ?? '');
                $i++;
            }
        }

        return $map;
    }

    /**
     * Stable IDs left unresolved by the most recent resolveItemTranslations() call:
     * spans on a translated page that had no matching segment and were rendered blank
     * (fix C). A non-empty list means the edition must NOT be silently published.
     *
     * @return string[]
     */
    public function getUnresolvedSpanIds(): array
    {
        return $this->unresolvedSpanIds;
    }

    /**
     * Split a page's flat translated_text into one segment per content span, in
     * reading order. The translator emits page text with each logical unit on its
     * own line (WOORDE lists, multi-caption pages, back-cover title lists), so a
     * newline split is the natural per-span boundary.
     *
     * Returns a 0-indexed array of exactly $spanCount segments when the line count
     * matches (clean 1:1), or when it can be coalesced/padded to fit; returns null
     * when there is nothing to split (caller then uses its fallbacks). Never returns
     * the whole blob duplicated across slots.
     *
     * @return array<int,string>|null
     */
    private function splitPageTextIntoSegments(?string $text, int $spanCount): ?array
    {
        if ($text === null || $spanCount < 1) {
            return null;
        }
        $normalized = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $normalized)),
            fn ($l) => $l !== ''
        ));

        if (empty($lines)) {
            return null;
        }

        // Exact match: one line per span.
        if (count($lines) === $spanCount) {
            return $lines;
        }

        // Single span: caller handles the whole-page case; nothing to split.
        if ($spanCount === 1) {
            return null;
        }

        // More lines than spans: distribute lines across spans as evenly as possible
        // (contiguous groups, preserving reading order) so no span gets the whole blob
        // and none is left empty.
        if (count($lines) > $spanCount) {
            $segments = array_fill(0, $spanCount, []);
            $per = (int) ceil(count($lines) / $spanCount);
            foreach ($lines as $idx => $line) {
                $slot = min((int) floor($idx / $per), $spanCount - 1);
                $segments[$slot][] = $line;
            }
            return array_map(fn ($group) => implode("\n", $group), $segments);
        }

        // Fewer lines than spans: assign the available lines to the first spans in
        // reading order; remaining spans get '' so the caller falls back to source_text
        // (never a duplicated blob).
        $segments = array_fill(0, $spanCount, '');
        foreach ($lines as $idx => $line) {
            $segments[$idx] = $line;
        }
        return $segments;
    }

    /**
     * Build an ID-mapped translations payload (§2.2) from stored per-item
     * translations. $itemTranslations maps stable unit ID => translated string.
     * The engine consumes these IDs DIRECTLY (no re-splitting of flat text).
     *
     * $contractItems is the item list from buildStableIdContract() — it supplies
     * page_number and reading order for each id so the engine can order them.
     */
    private function buildIdMappedTranslationsJson(array $contractItems, array $itemTranslations): array
    {
        // Structure-aware placement fields (spec Req 2/3) carried through to the
        // engine so the contract render places each element in its true cell.
        $structureKeys = ['source_text', 'semantic_role', 'cell_box', 'align_h',
                          'align_v', 'peer_group_id', 'column_span', 'is_merged'];

        $items = [];
        foreach ($contractItems as $order => $item) {
            $id = $item['id'] ?? null;
            if ($id === null || !array_key_exists($id, $itemTranslations)) {
                continue;
            }
            $entry = [
                'id' => $id,
                'page_number' => $item['page_number'] ?? null,
                'reading_order' => $item['reading_order'] ?? $order,
                'translation' => (string) $itemTranslations[$id],
            ];
            // Forward any structure fields the contract carries (only when present,
            // so non-structured items stay compact — matches the engine's expectation).
            foreach ($structureKeys as $k) {
                if (array_key_exists($k, $item)) {
                    $entry[$k] = $item[$k];
                }
            }
            $items[] = $entry;
        }

        return ['items' => $items];
    }

    /**
     * Re-render ONLY the pages that own the given stable item IDs (§2.2 / §15).
     * Used by the admin overlay when an editor changes a single region/item:
     * we re-run the engine restricted to those items, so unrelated pages are
     * copied through unchanged and only the edited page(s) are re-rendered.
     *
     * @param array $itemTranslations stable unit ID => translated string
     * @param array $contractItems    the stored contract item list
     * @param string[] $itemIds       the stable IDs that were edited
     */
    public function reRenderItems(Book $book, Translation $translation, array $contractItems, array $itemTranslations, array $itemIds): array
    {
        $originalPath = Storage::disk('public')->path($book->pdf_path);
        $translationsData = $this->buildIdMappedTranslationsJson($contractItems, $itemTranslations);

        $translationsPath = storage_path("app/temp/reitems_{$book->id}_{$translation->language_code}.json");
        $tempDir = dirname($translationsPath);
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        file_put_contents($translationsPath, json_encode($translationsData, JSON_UNESCAPED_UNICODE));

        $outputFilename = "books/translated/{$book->id}_{$translation->language_code}.pdf";
        $outputPath = Storage::disk('public')->path($outputFilename);

        $process = new Process([
            'python',
            base_path('scripts/pdf_translate_v8.py'),
            'replace',
            '--input', $originalPath,
            '--output', $outputPath,
            '--translations', $translationsPath,
            '--fonts-dir', $this->fontsDir,
            '--only-items', implode(',', $itemIds),
        ]);
        $process->setTimeout(300);
        $process->run();

        $report = json_decode($process->getErrorOutput(), true);
        @unlink($translationsPath);

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("Per-item re-render failed: " . $process->getErrorOutput());
        }

        $publishable = $report['publishable'] ?? true;
        $renderStatus = $report['render_status'] ?? ($publishable ? 'READY_FOR_REVIEW' : 'NEEDS_LAYOUT_REVIEW');
        $translation->forceFill([
            'render_status' => $renderStatus,
            'qa_report' => $report ? json_encode($report, JSON_UNESCAPED_UNICODE) : null,
        ])->save();

        return [
            'success' => true,
            'path' => $outputFilename,
            'render_status' => $renderStatus,
            'publishable' => $publishable,
            'report' => $report,
        ];
    }

    /**
     * Get the current book being processed (for accessing metadata).
     */
    private function getCurrentBook(): ?Book
    {
        return $this->currentBook;
    }

    /**
     * Set the current book context for cover config access.
     */
    public function setCurrentBook(Book $book): void
    {
        $this->currentBook = $book;
    }

    /**
     * Generate translated PDFs for all translations of a book.
     */
    public function generateAllTranslatedPdfs(Book $book): array
    {
        $results = [];

        foreach ($book->translations as $translation) {
            try {
                $path = $this->createTranslatedPdf($book, $translation);
                // Fail-closed (§13): only mark approved when the layout QA passed.
                // createTranslatedPdf has already set render_status from the gate.
                $translation->refresh();
                $blocked = $translation->render_status === 'NEEDS_LAYOUT_REVIEW';
                $translation->update([
                    'status' => $blocked ? 'needs_review' : 'approved',
                ]);
                $results[$translation->language_code] = [
                    'success' => true,
                    'path' => $path,
                    'render_status' => $translation->render_status,
                    'publishable' => !$blocked,
                ];
            } catch (\Throwable $e) {
                Log::error("PDF translation failed for book #{$book->id} ({$translation->language_code}): " . $e->getMessage());
                $results[$translation->language_code] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * List available fonts in the registry.
     */
    public function getAvailableFonts(): array
    {
        $fonts = [];
        $dir = $this->fontsDir;

        if (!is_dir($dir)) {
            return $fonts;
        }

        foreach (scandir($dir) as $file) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['ttf', 'otf'])) {
                $fonts[] = [
                    'name' => pathinfo($file, PATHINFO_FILENAME),
                    'file' => $file,
                    'path' => $dir . '/' . $file,
                    'size' => filesize($dir . '/' . $file),
                ];
            }
        }

        return $fonts;
    }

    /**
     * Check if the Python script and PyMuPDF are available.
     */
    public function checkDependencies(): array
    {
        $issues = [];

        if (!file_exists(base_path('scripts/pdf_translate_v8.py'))) {
            $issues[] = "V8 engine not found at: scripts/pdf_translate_v8.py";
        }

        // Check Python is available
        $process = new Process(['python', '--version']);
        $process->run();
        if (!$process->isSuccessful()) {
            $issues[] = "Python is not available on PATH";
        }

        // Check PyMuPDF is installed
        $process = new Process(['python', '-c', 'import pymupdf; print(pymupdf.__version__)']);
        $process->run();
        if (!$process->isSuccessful()) {
            $issues[] = "PyMuPDF is not installed (pip install pymupdf)";
        }

        // Check fonts directory
        if (!is_dir($this->fontsDir)) {
            $issues[] = "Fonts directory not found: {$this->fontsDir}";
        }

        return [
            'ready' => empty($issues),
            'issues' => $issues,
            'python_version' => trim($process->getOutput()),
            'fonts_available' => count($this->getAvailableFonts()),
        ];
    }
}
