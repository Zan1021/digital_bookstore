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

        // XL1 (R10.4): if this book has a BOOK-LEVEL approved artwork set (cleaned
        // backgrounds / region repairs promoted from an earlier language), seed those
        // language-independent artwork overrides into THIS edition's layout_overrides so
        // the cleaned artwork is reused without re-approval. Per-id text (per-language) is
        // never seeded — only container/mask/cleaned_bg/content_class.
        $sharedArtwork = ($book->metadata['shared_artwork']['overrides'] ?? []);
        if (!empty($sharedArtwork)) {
            $ov = $translation->layout_overrides ?? [];
            foreach ($sharedArtwork as $id => $bits) {
                $ov[$id] = array_merge($bits, $ov[$id] ?? []); // existing edition edits win
            }
            $translation->forceFill(['layout_overrides' => $ov])->save();
        }

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

        // STAGED RENDER (spec R9.1, Phase 6.5): render to a UNIQUE staging path and keep
        // the existing public edition untouched until the new result is complete and
        // publishable. The engine + all post-processing (cover flatten, illustration,
        // QA) operate on the staging file; only on success do we PROMOTE staging -> public.
        // A failed render therefore never overwrites a good published edition.
        $outputFilename = "books/translated/{$book->id}_{$translation->language_code}.pdf";
        $stagingFilename = "books/staging/{$book->id}_{$translation->language_code}_"
            . substr(bin2hex(random_bytes(6)), 0, 10) . ".pdf";
        $publicPath = Storage::disk('public')->path($outputFilename);
        $outputPath = Storage::disk('public')->path($stagingFilename); // engine writes HERE
        foreach ([dirname($publicPath), dirname($outputPath)] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
        // The illustration post-pass reads $translation->rendered_pdf_path to locate the
        // file to mutate — point it at STAGING for the duration of post-processing.
        $translation->forceFill(['rendered_pdf_path' => $stagingFilename])->save();

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

        // TYPOGRAPHY POLICY (world-class-render-engine spec Req 4.3/4.4): when the book
        // carries an explicit per-role/edition/unit font policy, write it to a temp JSON
        // and hand it to the engine so BOTH render paths resolve fonts through it. Absent
        // policy = unchanged behaviour (engine falls back to source > house font).
        $policyPath = null;
        $typographyPolicy = $book->getTypographyPolicy();
        if (!empty($typographyPolicy)) {
            $policyPath = storage_path("app/temp/typopolicy_{$book->id}_{$translation->language_code}.json");
            file_put_contents($policyPath, json_encode($typographyPolicy, JSON_UNESCAPED_UNICODE));
            $cmd[] = '--typography-policy';
            $cmd[] = $policyPath;
        }

        $process = new Process($cmd);

        $process->setTimeout(300);
        $process->run();

        // Capture the report from stderr
        $report = json_decode($process->getErrorOutput(), true);

        if (!$process->isSuccessful()) {
            @unlink($translationsPath);
            if ($policyPath) {
                @unlink($policyPath);
            }
            throw new \RuntimeException(
                "PDF translation failed: " . $process->getErrorOutput()
            );
        }

        // COVER FLATTEN (front-page fix, config-gated). Some covers draw the subtitle
        // drop-shadow as a Form XObject through a LUMINOSITY soft mask; our per-span
        // redaction re-serialises the stream and that backdrop then renders as a dark/
        // washed box behind the translated subtitle in PDF.js (invisible to a PyMuPDF
        // pixmap). Flattening page 0 to an opaque raster composites the mask to its
        // intended (invisible) state and leaves no form/mask for any renderer to
        // mis-composite — renderer-proof and book-agnostic (works even for text on an
        // illustration, since the output is pure pixels). Verified via the pdfjs-dist
        // harness (scripts/render_pdfjs.mjs). Off by default; enable per environment.
        if (config('bookstore.cover_retypeset.enabled')) {
            try {
                $ppi = (int) config('bookstore.cover_retypeset.ppi', 600);
                $flatten = new Process([
                    'python',
                    base_path('scripts/cover_retypeset.py'),
                    'flatten-cover',
                    '--input', $outputPath,
                    '--output', $outputPath,
                    '--page', '0',
                    '--ppi', (string) $ppi,
                ]);
                $flatten->setTimeout(180);
                $flatten->run();
                if ($flatten->isSuccessful()) {
                    Log::info("Cover flattened (front-page soft-mask fix) for book #{$book->id}"
                        . " ({$translation->language_code})", [
                            'report' => json_decode($flatten->getErrorOutput(), true),
                            'ppi' => $ppi,
                        ]);
                } else {
                    // Non-fatal: keep the un-flattened cover rather than fail the whole render.
                    Log::warning("Cover flatten failed (non-fatal); keeping un-flattened cover", [
                        'book' => $book->id,
                        'language' => $translation->language_code,
                        'stderr' => $flatten->getErrorOutput(),
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning("Cover flatten threw (non-fatal)", [
                    'book' => $book->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // ILLUSTRATION-TEXT VISION (config-gated, non-fatal). Some books bake text INTO a
        // raster illustration, which the contract renderer cannot redact — the English then
        // survives on the translated page. When enabled, IllustrationTextService locates the
        // baked-in text with GPT-4o vision, deterministically inpaints it out, overlays the
        // translated vector text, and verifies with the VisualQa compare gate. Book-agnostic
        // and fail-closed (uncertain backgrounds route to review). Off by default; when off,
        // the candidate pre-filter is not even run. Spec: .kiro/specs/illustration-text-vision.
        $illustrationReview = [];
        if (config('bookstore.illustration_text.enabled')) {
            try {
                $illus = app(IllustrationTextService::class)->process($book, $translation->fresh());
                if (!empty($illus['modified_pages']) || !empty($illus['review_pages'])) {
                    Log::info("Illustration-text pass for book #{$book->id} ({$translation->language_code})", $illus);
                }
                $illustrationReview = $illus['review_pages'] ?? [];
            } catch (\Throwable $e) {
                // Non-fatal: a failure here must not sink the whole render.
                Log::warning("Illustration-text pass threw (non-fatal)", [
                    'book' => $book->id, 'error' => $e->getMessage(),
                ]);
            }
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

        // STRUCTURED QA (spec R8.3/R8.4, Phase 6.1/6.2): build a machine-readable QaReport
        // that each pass contributes to MONOTONICALLY. The legacy $publishable flag is kept
        // (strengthened, not replaced); the QaReport is the authoritative, auditable merge
        // and is cross-checked at the end — any pass failing it forces review (fail closed).
        $qaReport = new \App\Services\Qa\QaReport();
        // Seed the three required checks from the engine's own structural gate.
        if ($report['publishable'] ?? false) {
            $qaReport->pass('structure')->pass('fit')->pass('target_mapping');
        } else {
            $qaReport->fail('structure', 'ENGINE_GATE_FAILED', 'render',
                ['detail' => $report['render_status'] ?? 'not_publishable']);
            // fit/target_mapping remain not_run unless the engine said otherwise
            $qaReport->pass('fit')->pass('target_mapping');
        }
        if (!empty($report['overflow_warnings'])) {
            $qaReport->fail('fit', 'TEXT_OVERFLOW', 'fit',
                ['count' => count($report['overflow_warnings'])]);
        }

        // FIX C: even if the engine's own gate passed, unresolved spans (rendered
        // blank to avoid an English leak) mean the edition is incomplete and must be
        // reviewed before it can be published. Fail closed.
        if ($hadUnresolvedSpans) {
            $publishable = false;
            $renderStatus = 'NEEDS_LAYOUT_REVIEW';
            $unresolvedIds = $this->getUnresolvedSpanIds();
            $qaReport->fail('target_mapping', 'UNRESOLVED_TRANSLATION_SPANS', 'contract',
                ['count' => count($unresolvedIds)]);
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

        // Illustration-text pages the module could not confidently fix (uncertain
        // background, overflow, or failed AI verify) must be reviewed — fail closed.
        if (!empty($illustrationReview)) {
            $publishable = false;
            $renderStatus = 'NEEDS_LAYOUT_REVIEW';
            $qaReport->fail('artwork', 'ILLUSTRATION_TEXT_REVIEW', 'illustration',
                ['pages' => array_values($illustrationReview)])
                ->requireArtworkApproval();
            Log::warning("Illustration-text pages flagged for review — routing to review", [
                'book' => $book->id,
                'language' => $translation->language_code,
                'pages' => $illustrationReview,
            ]);
            if (is_array($report)) {
                $report['publishable'] = false;
                $report['render_status'] = 'NEEDS_LAYOUT_REVIEW';
                $report['flags']['ILLUSTRATION_TEXT_REVIEW'] = array_values($illustrationReview);
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
                $qaReport->pass('visual');
                if (!($qa['ok'] ?? true) && !empty($qa['flagged_pages'])) {
                    $publishable = false;
                    $renderStatus = 'NEEDS_LAYOUT_REVIEW';
                    $qaReport->fail('visual', 'VISUAL_QA_FLAGGED', 'visual',
                        ['flagged_pages' => array_values($qa['flagged_pages'])]);
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

        // INDEPENDENT PDF.js VISUAL CHECK (spec R8.6, Phase 6.4). PyMuPDF flattens soft
        // masks and can report a cover "clean" while the browser (PDF.js) shows a washed/
        // blank box. Render the cover (and any flagged pages) through the SAME engine the
        // reader uses and flag a blank/washed result. Config-gated; if node/deps are
        // unavailable the check is recorded not_run (never a false pass).
        if (config('bookstore.pdfjs_check.enabled', false)) {
            try {
                $checkPages = array_values(array_unique(array_merge(
                    [0], // the cover is always checked
                    array_map(fn ($p) => (int) $p - 1, $illustrationReview) // mask pages (0-based)
                )));
                $pdfjs = $this->pdfJsVisualCheck($outputPath, $checkPages, $book, $translation);
                if (is_array($report)) {
                    $report['pdfjs_check'] = $pdfjs;
                }
                if ($pdfjs['ran'] ?? false) {
                    if (!empty($pdfjs['blank_pages'])) {
                        $publishable = false;
                        $renderStatus = 'NEEDS_LAYOUT_REVIEW';
                        $qaReport->fail('visual_pdfjs', 'PDFJS_BLANK_PAGE', 'visual_pdfjs',
                            ['pages' => $pdfjs['blank_pages']]);
                    } else {
                        $qaReport->pass('visual_pdfjs');
                    }
                }
                // not ran => leave the check not_run on the QaReport (fail-closed aware)
            } catch (\Throwable $e) {
                Log::warning('PDF.js visual check errored (non-blocking)', ['error' => $e->getMessage()]);
            }
        }

        // INDEPENDENT EDUCATIONAL CHECK (unified-rendering-and-testing Req 6, D2). When the
        // edition carries a structured exercise contract, its educational validity is gated
        // HERE as its own layer — a failure routes to review regardless of a clean layout,
        // and a layout pass can never clear it. Editions with no exercises are not_applicable
        // (a no-op), so this is safe for every current book.
        try {
            $edu = app(\App\Services\Qa\BookTestingService::class)->educationalCheck($book, $translation);
            if (is_array($report)) {
                $report['educational'] = $edu;
            }
            if (($edu['applicable'] ?? false) === true) {
                if (($edu['status'] ?? null) === 'passed') {
                    $qaReport->pass('educational');
                } else {
                    $publishable = false;
                    $renderStatus = 'NEEDS_LAYOUT_REVIEW';
                    $qaReport->fail('educational', 'EDUCATIONAL_INVALID', 'educational',
                        ['issues' => $edu['issues'] ?? []]);
                    Log::warning('Educational check failed — routing edition to review', [
                        'book' => $book->id, 'language' => $translation->language_code,
                    ]);
                }
            }
            // not_applicable => leave 'educational' off the required gate (noneducational book)
        } catch (\Throwable $e) {
            Log::warning('Educational check errored (non-blocking)', ['error' => $e->getMessage()]);
        }

        // FINAL QA CROSS-CHECK (spec R8.4, Phase 6.2): compute publish eligibility from the
        // merged QaReport LAST. The machine-readable result is embedded in the persisted
        // report.
        if (is_array($report)) {
            $report['qa'] = $qaReport->toArray();
        }
        if (!$qaReport->isPublishable() && $publishable) {
            $publishable = false;
            $renderStatus = 'NEEDS_LAYOUT_REVIEW';
            if (is_array($report)) {
                $report['publishable'] = false;
                $report['render_status'] = 'NEEDS_LAYOUT_REVIEW';
            }
            Log::warning('QaReport cross-check forced review (structured gate)', [
                'book' => $book->id, 'language' => $translation->language_code,
                'checks' => $qaReport->checks(),
            ]);
        }

        // PROMOTE staging -> public (spec R9.5, Phase 6.5). The render + all post-passes
        // succeeded and wrote to the staging file. Copy it over the public edition
        // coherently, THEN persist the final actual path before anything resolves it. On a
        // copy failure we keep the prior public edition and route to review (fail closed).
        $finalRel = $outputFilename;
        try {
            if (is_file($outputPath)) {
                if (!@copy($outputPath, $publicPath)) {
                    throw new \RuntimeException("staging->public copy failed");
                }
                @unlink($outputPath); // remove staging artifact
            } else {
                throw new \RuntimeException("staging file missing after render: {$stagingFilename}");
            }
        } catch (\Throwable $e) {
            Log::error('Staged promotion failed — keeping prior public edition', [
                'book' => $book->id, 'language' => $translation->language_code,
                'error' => $e->getMessage(),
            ]);
            $renderStatus = 'NEEDS_LAYOUT_REVIEW';
            if (is_array($report)) {
                $report['publishable'] = false;
                $report['render_status'] = 'NEEDS_LAYOUT_REVIEW';
                $report['flags']['STAGING_PROMOTION_FAILED'] = $e->getMessage();
            }
            // leave the staging file for diagnostics; final path stays the public edition
        }

        // Compute the render fingerprint (spec R9.2, Phase 6.6) from the inputs that
        // determine this edition's output. Approvals are tied to it; a change invalidates
        // them. The TARGET-TEXT sub-hash separately gates narration (R9.3).
        $fingerprint = \App\Services\Qa\RenderFingerprint::compute([
            'source_version' => $book->updated_at?->timestamp,
            'manifest_version' => $book->manifest_path,
            'engine_version' => (string) config('bookstore.engine_version', 'v8'),
            'item_translations' => $translation->item_translations ?? [],
            'typography_policy' => $typographyPolicy ?? [],
        ]);
        $narrationFp = \App\Services\Qa\RenderFingerprint::hashTextComponent(
            $translation->item_translations ?? []
        );
        // Invalidate stored approvals if the fingerprint changed (R9.3): a new render of
        // different content must not inherit the prior edition's layout/artwork approvals.
        $priorFingerprint = $translation->getAttribute('render_fingerprint');
        if ($priorFingerprint !== null && $priorFingerprint !== $fingerprint) {
            $translation->forceFill(['page_approvals' => []]);
            Log::info('Render fingerprint changed — invalidated prior approvals', [
                'book' => $book->id, 'language' => $translation->language_code,
            ]);
        }

        // Hash the EXACT output file on disk (unified-rendering-and-testing Req 2, A1.4).
        // CandidateReadiness binds every check + approval to this hash so a stale PDF is
        // detected even when the input fingerprint matches. Null when the file is absent
        // (e.g. staging promotion failed) — readiness then fails closed (INVALID_GATE_INPUT).
        $outputSha256 = null;
        try {
            $finalAbs = Storage::disk('public')->path($finalRel);
            if (is_file($finalAbs)) {
                $outputSha256 = hash_file('sha256', $finalAbs);
            }
        } catch (\Throwable $e) {
            Log::warning('Could not hash rendered output', [
                'book' => $book->id, 'language' => $translation->language_code, 'error' => $e->getMessage(),
            ]);
        }

        // Persist qa_report via the ARRAY CAST (spec R8.5, Phase 6.3) — NOT json_encode().
        // Writing json_encode() into an `array`-cast column double-encodes it, which made
        // every is_array($qa) check silently fail and lost all diagnostics. Pass the array;
        // Laravel encodes once. Translation::decodeQaReport() still reads legacy rows.
        // The final actual path is persisted here, before any caller resolves it (R9.5).
        $translation->forceFill([
            'render_status' => $renderStatus,
            'qa_report' => is_array($report) ? $report : null,
            'render_fingerprint' => $fingerprint,
            'narration_fingerprint' => $narrationFp,
            'output_sha256' => $outputSha256,
            'rendered_pdf_path' => $finalRel,
        ])->save();

        // Cleanup temp file
        @unlink($translationsPath);
        if ($policyPath) {
            @unlink($policyPath);
        }

        return $finalRel;
    }

    /**
     * Independent PDF.js visual check (spec R8.6, Phase 6.4). Renders each requested page
     * through scripts/render_pdfjs.mjs (the SAME engine the reader uses) and flags any page
     * that comes out blank/washed — the soft-mask defect PyMuPDF cannot see. Returns:
     *   ['ran'=>bool, 'blank_pages'=>int[] (0-based), 'pages'=>[pageIndex=>result]].
     * ran=false when node/the harness is unavailable (recorded, never a false pass).
     *
     * @param int[] $pageIndexes 0-based page indexes to check
     */
    private function pdfJsVisualCheck(string $pdfPath, array $pageIndexes, Book $book, Translation $translation): array
    {
        $out = ['ran' => false, 'blank_pages' => [], 'pages' => []];
        $script = base_path('scripts/render_pdfjs.mjs');
        if (!is_file($script)) {
            return $out;
        }
        $dir = storage_path('app/temp');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        foreach ($pageIndexes as $pi) {
            $png = "{$dir}/pdfjs_{$book->id}_{$translation->language_code}_p{$pi}_" . uniqid() . '.png';
            try {
                $proc = new Process(['node', $script, $pdfPath, (string) $pi, $png, '2.0']);
                $proc->setTimeout(120);
                $proc->run();
                if (!$proc->isSuccessful()) {
                    // harness/node missing or render failed — do not mark as a pass or a
                    // false blank; record and move on (overall ran stays as-is).
                    Log::info('PDF.js check could not run for a page (non-fatal)', [
                        'page' => $pi, 'stderr' => substr($proc->getErrorOutput(), 0, 500),
                    ]);
                    @unlink($png);
                    continue;
                }
                $res = json_decode(trim($proc->getOutput()), true);
                if (is_array($res)) {
                    $out['ran'] = true;
                    $out['pages'][$pi] = [
                        'blank' => (bool) ($res['blank'] ?? false),
                        'near_white_frac' => $res['near_white_frac'] ?? null,
                        'variance' => $res['variance'] ?? null,
                    ];
                    if ($res['blank'] ?? false) {
                        $out['blank_pages'][] = $pi;
                    }
                }
            } catch (\Throwable $e) {
                Log::info('PDF.js check threw for a page (non-fatal)', [
                    'page' => $pi, 'error' => $e->getMessage(),
                ]);
            } finally {
                @unlink($png);
            }
        }
        return $out;
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
        // Array-cast persistence (R8.5, Phase 6.3) — not json_encode (avoids double-encode).
        $translation->forceFill([
            'render_status' => $renderStatus,
            'qa_report' => is_array($report) ? $report : null,
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
