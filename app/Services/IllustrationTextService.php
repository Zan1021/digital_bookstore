<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Translation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use Symfony\Component\Process\Process;

/**
 * ILLUSTRATION-TEXT VISION MODULE (orchestrator).
 *
 * Spec: .kiro/specs/illustration-text-vision/{requirements,design}.md
 * Brief: Brief/kiro-pdf-translation-cover-fix.md §4 (text over illustrations), §5.
 *
 * Handles text BAKED INTO raster illustrations (pixels, not PDF text objects) that the
 * V8 contract renderer cannot redact. Book-agnostic:
 *   1. DETECT   — GPT-4o vision locates baked-in text on the rendered page and returns
 *                 normalized bboxes + background_type + proposed sampling regions. AI is
 *                 used for EYES ONLY (§5).
 *   2. FILTER   — regions that correspond to native PDF text are dropped (owned elsewhere).
 *   3. MEASURE  — actual pixel colours are sampled deterministically from proposed regions
 *                 (never trust model-reported RGB, §5); flat-background uniformity is checked.
 *   4. ERASE+OVERLAY — the deterministic Python half (scripts/illustration_text.py) inpaints
 *                 the text and overlays translated vector text. A generative background route
 *                 is opt-in and review-gated (§4 baked-in).
 *   5. VERIFY   — VisualQaService compares the result to source; a flagged page routes the
 *                 edition to NEEDS_LAYOUT_REVIEW.
 *
 * Fail-closed: uncertain background / low confidence / overflow => page is NOT modified and
 * is routed to review. Any single-page failure is non-fatal to the book render.
 */
class IllustrationTextService
{
    private string $fontsDir;

    public function __construct(private ?VisualQaService $visualQa = null)
    {
        $this->fontsDir = storage_path('app/fonts');
        $this->visualQa = $visualQa ?: app(VisualQaService::class);
    }

    /**
     * ITEM 3 (spec Req 5.6) — INVENTORY artwork text regions from the SOURCE and emit them
     * as contract-ready items UP FRONT, so artwork units are translated in the same pass as
     * everything else (not discovered after translation). NO repair happens here.
     *
     * Returns a list of items: ['id','source_text','semantic_role','content_class',
     * 'page_number','bbox_px','ppi']. The caller (translation path) translates the
     * source_text and stores the result in item_translations[id]; attachTargetsById() then
     * consumes item_translations[id] at repair time — closing the loop.
     *
     * Book-agnostic, source-only (I5). Config-gated OFF by default (same flag as process()).
     */
    public function inventoryArtworkRegions(Book $book, Translation $translation): array
    {
        if (!config('bookstore.illustration_text.enabled')) {
            return [];
        }
        $ppi = (int) config('bookstore.illustration_text.ppi', 300);
        $model = (string) config('bookstore.illustration_text.model', 'gpt-4o');

        // Inventory from the SOURCE PDF (I5), not the translated edition.
        $sourceRel = $book->pdf_path ?? $book->source_pdf_path ?? null;
        $pdfPath = $sourceRel ? Storage::disk('public')->path($sourceRel) : null;
        if (!$pdfPath || !is_file($pdfPath)) {
            Log::info('IllustrationText: inventory skipped, no source pdf', ['book' => $book->id]);
            return [];
        }

        $candidates = $this->candidatePages($pdfPath);
        if (empty($candidates)) {
            return [];
        }
        $ownedRegionsByPage = $this->contractOwnedRegions($book);

        $items = [];
        foreach ($candidates as $cand) {
            $pageIndex = (int) $cand['page'];
            $pageNumber = $pageIndex + 1;
            $ownedBoxes = $ownedRegionsByPage[$pageNumber] ?? [];

            // Prefer native text-layer geometry; fall back to vision only for baked pixels.
            $regions = $this->nativeTextRegions($pdfPath, $pageIndex, $ppi, $translation);
            $usedNative = !empty($regions);
            if (!$usedNative) {
                try {
                    $regions = $this->detect($pdfPath, $pageIndex, $ppi, $model);
                } catch (\Throwable $e) {
                    Log::warning('IllustrationText: inventory detect failed (non-fatal)', [
                        'page' => $pageIndex, 'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }
            if (empty($regions)) {
                continue;
            }

            // Region-level ownership: skip regions the contract renderer already owns.
            $regions = $this->filterContractOwnedRegions($regions, $ownedBoxes, $ppi);
            if (empty($regions)) {
                continue;
            }
            // Stable IDs + source-based content class.
            $regions = $this->tagRegions($regions, $pageNumber, $usedNative, $pdfPath, $pageIndex, $ppi);

            foreach ($regions as $r) {
                $src = trim((string) ($r['source_text'] ?? ''));
                if ($src === '') {
                    continue;
                }
                $items[] = [
                    'id' => $r['id'],
                    'source_text' => $src,
                    'semantic_role' => $r['semantic_role'] ?? 'artwork_label',
                    'content_class' => $r['content_class'] ?? 'raster_text',
                    'page_number' => $pageNumber,
                    'bbox_px' => $r['bbox_px'] ?? null,
                    'ppi' => $ppi,
                ];
            }
        }
        return $items;
    }

    /**
     * Process every candidate page of an edition. Returns:
     *   ['modified_pages'=>int[], 'review_pages'=>int[], 'skipped'=>[], 'ok'=>bool]
     */
    public function process(Book $book, Translation $translation): array
    {
        $model = (string) config('bookstore.illustration_text.model', 'gpt-4o');
        $ppi = (int) config('bookstore.illustration_text.ppi', 300);
        $generative = (bool) config('bookstore.illustration_text.generative', false);

        $translatedRel = $translation->rendered_pdf_path
            ?? "books/translated/{$book->id}_{$translation->language_code}.pdf";
        $pdfPath = Storage::disk('public')->path($translatedRel);

        $result = ['modified_pages' => [], 'review_pages' => [], 'skipped' => [], 'ok' => true,
                   'coverage' => [], 'issues' => []];

        if (!is_file($pdfPath)) {
            Log::warning('IllustrationText: translated PDF missing', ['path' => $pdfPath]);
            return $result + ['skipped_reason' => 'missing_pdf'];
        }

        // Cheap pre-filter: only pages with dominant imagery + little native text. The
        // heuristic only TRIAGES which pages to inspect first — it never DECIDES that a
        // page has no artwork text (spec Req 5.5). Pages it skips are recorded as
        // 'no-candidate' coverage, not silently dropped.
        $candidates = $this->candidatePages($pdfPath);
        $candidatePageNumbers = array_map(fn ($c) => ((int) $c['page']) + 1, $candidates);

        // REGION-LEVEL OWNERSHIP (spec Req 5.2/5.3): the V8 contract renderer owns the
        // native text REGIONS it has a manifest for — not whole pages. A page with a
        // native paragraph AND a baked-in sign must still have the sign processed. We
        // skip only a candidate REGION whose box overlaps a contract-owned region box,
        // never the whole page. (Replaces the old whole-page contractOwnedPages skip.)
        $ownedRegionsByPage = $this->contractOwnedRegions($book);

        if (empty($candidates)) {
            return $result; // nothing to do — no vision spend
        }

        foreach ($candidates as $cand) {
            $pageIndex = (int) $cand['page']; // 0-based
            $pageNumber = $pageIndex + 1;     // 1-based (manifest/page_number space)
            $ownedBoxes = $ownedRegionsByPage[$pageNumber] ?? [];

            // PREFER GROUND TRUTH: if the page has a real text layer over the artwork, use
            // the PDF's own line boxes for exact placement (the correct, book-agnostic path
            // for text-over-illustration, brief §5 — AI is unreliable for geometry). Fall
            // back to GPT-4o vision ONLY for genuinely baked-in pixel text (no text layer).
            $regions = $this->nativeTextRegions($pdfPath, $pageIndex, $ppi, $translation);
            $usedNative = !empty($regions);

            if (!$usedNative) {
                try {
                    $regions = $this->detect($pdfPath, $pageIndex, $ppi, $model);
                } catch (\Throwable $e) {
                    Log::warning("IllustrationText: detect failed (non-fatal)", [
                        'page' => $pageIndex, 'error' => $e->getMessage(),
                    ]);
                    $result['coverage'][$pageNumber] = 'unresolved'; // inspection failed
                    continue; // detection failure must not block the render
                }
                if (empty($regions)) {
                    // Distinguish "inspected, nothing baked-in" from "not inspected"
                    // (spec Req 5.4): this page WAS scanned and had no artwork text.
                    $result['coverage'][$pageNumber] = 'scanned-clean';
                    continue;
                }
            }

            // REGION-LEVEL OWNERSHIP: drop only the regions already owned by the contract
            // renderer (bbox overlap), keeping genuinely-baked-in regions on the same page.
            $regions = $this->filterContractOwnedRegions($regions, $ownedBoxes, $ppi);
            if (empty($regions)) {
                $result['skipped'][] = ['page' => $pageNumber, 'reason' => 'all regions contract-owned'];
                $result['coverage'][$pageNumber] = 'scanned-clean';
                continue;
            }

            // STABLE REGION IDS + CONTENT CLASS (spec Req 5.1/5.6): tag each region with a
            // source-derived stable id and its content class so targets attach by ID and
            // the manifest record is unambiguous.
            $regions = $this->tagRegions($regions, $pageNumber, $usedNative, $pdfPath, $pageIndex, $ppi);

            if (!$usedNative) {
                // Only the vision path needs colour measurement + uniformity gating; the
                // native path already has exact boxes, colours and font sizes.
                [$regions, $needsReview] = $this->measureAndGate($pdfPath, $pageIndex, $ppi, $regions);
                if ($needsReview) {
                    $result['review_pages'][] = $pageNumber;
                    $result['coverage'][$pageNumber] = 'deferred'; // uncertain -> review
                    $result['ok'] = false;
                    continue; // do NOT modify a page we are not confident about
                }
            }

            // PER-ID TARGETS (spec Req 5.6 / Phase 4.5): resolve each region's target by its
            // stable id from the edition's per-element translations / overrides. A region
            // with no target is NOT erased — it is recorded as an issue and blocks the page.
            [$regions, $regionIssues] = $this->attachTargetsById($translation, $regions);
            if (!empty($regionIssues)) {
                foreach ($regionIssues as $iss) {
                    $result['issues'][] = $iss + ['page' => $pageNumber];
                }
                $result['review_pages'][] = $pageNumber;
                $result['coverage'][$pageNumber] = 'deferred';
                $result['ok'] = false;
                continue; // missing target => do not modify; block (fail closed)
            }

            $applied = $this->repair(
                $book, $translation, $pdfPath, $pageIndex, $ppi, $regions, $generative
            );
            if ($applied['modified'] ?? false) {
                $result['modified_pages'][] = $pageNumber;
                $result['coverage'][$pageNumber] = 'resolved';
            }
            if (!empty($applied['overflow'])) {
                $result['review_pages'][] = $pageNumber;
                $result['coverage'][$pageNumber] = 'deferred';
                $result['ok'] = false;
            }
        }

        // EVERY page gets a recorded coverage result (spec Req 5.4/5.5). The candidate
        // heuristic only TRIAGES which pages are inspected first — it never DECIDES a page
        // has no artwork text. A page the heuristic did not flag is recorded 'no-candidate'
        // (triaged out, not inspected), which is explicitly DISTINCT from 'scanned-clean'
        // (inspected, nothing baked-in). No page is silently dropped.
        $totalPages = $this->pdfPageCount($pdfPath);
        for ($pn = 1; $pn <= $totalPages; $pn++) {
            $result['coverage'][$pn] = $result['coverage'][$pn] ?? 'no-candidate';
        }
        ksort($result['coverage']);

        // Verify modified pages with the existing AI compare gate.
        if ((bool) config('bookstore.illustration_text.verify', true) && $result['modified_pages']) {
            try {
                $verdict = $this->visualQa->review($book, $translation, $result['modified_pages']);
                if (!($verdict['ok'] ?? true)) {
                    $result['ok'] = false;
                    $result['review_pages'] = array_values(array_unique(
                        array_merge($result['review_pages'], $verdict['flagged_pages'] ?? [])
                    ));
                }
            } catch (\Throwable $e) {
                Log::warning('IllustrationText: verify failed (non-fatal)', ['error' => $e->getMessage()]);
            }
        }

        return $result;
    }

    /**
     * Contract-owned REGIONS per page (spec Req 5.2/5.3): map page_number => list of
     * [x0,y0,x1,y1] PDF-point boxes the V8 contract renderer already owns (any manifest
     * region that is translatable and has items). Region-level, NOT whole-page, so a
     * baked-in sign on a page that also has a native paragraph is still processed.
     * Returns [] if no manifest.
     */
    private function contractOwnedRegions(Book $book): array
    {
        try {
            $disk = Storage::disk('public');
            if (!$book->manifest_path || !$disk->exists($book->manifest_path)) {
                return [];
            }
            $manifest = json_decode($disk->get($book->manifest_path), true) ?: [];
            $byPage = [];
            foreach (($manifest['pages'] ?? []) as $pg) {
                $pageNum = $pg['page_number'] ?? null;
                if ($pageNum === null) {
                    continue;
                }
                foreach (($pg['regions'] ?? []) as $region) {
                    $policy = $region['translation_policy'] ?? 'preserve';
                    $hasItems = !empty($region['items']);
                    $bbox = $region['bbox'] ?? $region['bbox_pt'] ?? null;
                    if ($policy !== 'preserve' && $hasItems && is_array($bbox) && count($bbox) === 4) {
                        $byPage[(int) $pageNum][] = array_map('floatval', $bbox);
                    }
                }
            }
            return $byPage;
        } catch (\Throwable $e) {
            Log::warning('IllustrationText: contractOwnedRegions failed; treating none as owned', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Drop candidate regions whose box overlaps a contract-owned region box (so the
     * contract renderer keeps ownership of native text while genuinely baked-in regions
     * on the same page survive). Owned boxes are PDF points; region boxes are pixels at
     * $ppi — convert owned boxes to pixels for comparison. Overlap = IoU-ish center/area
     * intersection over the smaller box > 0.5.
     */
    private function filterContractOwnedRegions(array $regions, array $ownedBoxesPt, int $ppi): array
    {
        if (empty($ownedBoxesPt)) {
            return $regions;
        }
        $scale = $ppi / 72.0;
        $ownedPx = array_map(fn ($b) => [$b[0] * $scale, $b[1] * $scale, $b[2] * $scale, $b[3] * $scale], $ownedBoxesPt);

        $overlapFrac = function (array $a, array $b): float {
            $ix0 = max($a[0], $b[0]); $iy0 = max($a[1], $b[1]);
            $ix1 = min($a[2], $b[2]); $iy1 = min($a[3], $b[3]);
            if ($ix1 <= $ix0 || $iy1 <= $iy0) {
                return 0.0;
            }
            $inter = ($ix1 - $ix0) * ($iy1 - $iy0);
            $areaA = max(1.0, ($a[2] - $a[0]) * ($a[3] - $a[1]));
            $areaB = max(1.0, ($b[2] - $b[0]) * ($b[3] - $b[1]));
            return $inter / min($areaA, $areaB);
        };

        $kept = [];
        foreach ($regions as $r) {
            $box = $r['bbox_px'] ?? null;
            if (!is_array($box) || count($box) !== 4) {
                $kept[] = $r;
                continue;
            }
            $owned = false;
            foreach ($ownedPx as $ob) {
                if ($overlapFrac($box, $ob) > 0.5) {
                    $owned = true;
                    break;
                }
            }
            if (!$owned) {
                $kept[] = $r;
            }
        }
        return $kept;
    }

    /**
     * Tag each region with a stable source-derived id + content class (spec Req 5.1/5.6).
     * id = p{page:02d}_art{index:02d}. content class: 'native' when it came from the PDF
     * text layer (selectable text over artwork), else 'raster_text' (baked-in pixels).
     * Book-agnostic — derived from how the region was detected, not any book constant.
     */
    private function tagRegions(array $regions, int $pageNumber, bool $usedNative,
                                ?string $pdfPath = null, ?int $pageIndex = null, int $ppi = 300): array
    {
        // SOURCE-BASED CONTENT CLASS (spec R5.1): classify each region as native /
        // outlined_vector / raster_text from the SOURCE PDF geometry (I5), not the
        // detection route alone. The old binary native/raster_text (derived only from
        // whether we used the text layer) could not see outlined/vector lettering and
        // risked the hidden-OCR trap. We overlay the richer class when available.
        $classified = [];
        if ($pdfPath !== null && $pageIndex !== null && !empty($regions)) {
            $classified = $this->classifyContentClass($pdfPath, $pageIndex, $ppi, $regions);
        }

        $out = [];
        foreach (array_values($regions) as $i => $r) {
            $r['id'] = $r['id'] ?? sprintf('p%02d_art%02d', $pageNumber, $i + 1);
            // content_class from the source classifier when present; else fall back to the
            // detection-derived binary (native if we used the PDF text layer, else raster).
            $cc = $classified[$i]['content_class'] ?? ($usedNative ? 'native' : 'raster_text');
            $r['content_class'] = $cc;
            if (isset($classified[$i]['classified_by'])) {
                $r['classified_by'] = $classified[$i]['classified_by'];
            }
            // source_kind kept for backward compatibility with existing consumers; derive it
            // from the richer class (native stays native; outlined_vector + raster_text are
            // both non-native pixel/vector art).
            $r['source_kind'] = $cc === 'native' ? 'native' : 'raster_text';
            $r['semantic_role'] = $r['semantic_role'] ?? 'artwork_label';
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Source-based 3-class classifier (scripts/illustration_classify.py). Returns the
     * regions array enriched with content_class/classified_by, index-aligned with input.
     * Non-fatal: returns [] on any failure so tagRegions falls back to the binary class.
     */
    private function classifyContentClass(string $pdfPath, int $pageIndex, int $ppi, array $regions): array
    {
        try {
            $tmp = storage_path('app/temp/illus_classify_' . uniqid() . '.json');
            if (!is_dir(dirname($tmp))) {
                mkdir(dirname($tmp), 0755, true);
            }
            file_put_contents($tmp, json_encode(['regions' => $regions], JSON_UNESCAPED_UNICODE));
            $proc = new Process(['python', base_path('scripts/illustration_classify.py'),
                '--input', $pdfPath, '--page', (string) $pageIndex, '--ppi', (string) $ppi,
                '--regions', $tmp]);
            $proc->setTimeout(60);
            $proc->run();
            @unlink($tmp);
            if (!$proc->isSuccessful()) {
                Log::warning('IllustrationText: classify failed (non-fatal)', [
                    'page' => $pageIndex, 'stderr' => $proc->getErrorOutput(),
                ]);
                return [];
            }
            $data = json_decode(trim($proc->getOutput()), true) ?: [];
            return $data['regions'] ?? [];
        } catch (\Throwable $e) {
            Log::warning('IllustrationText: classify exception (non-fatal)', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Resolve each region's target text BY STABLE ID (spec Req 5.6 / Phase 4.5),
     * replacing the old whole-page `__single__` blob and any line-index fallback.
     * Priority per region: a per-id layout override > the machine per-element
     * translation (item_translations[id]) > an already-attached target_text (native
     * path carries the page's translated line). A region with NO usable target is NOT
     * given one — it is returned as an ISSUE so the caller blocks the page (fail closed,
     * never erase without a replacement, never leak the source).
     *
     * @return array{0: array, 1: array} [regions-with-targets, issues]
     */
    private function attachTargetsById(Translation $translation, array $regions): array
    {
        $overrides = $translation->layout_overrides ?? [];
        $itemTr = $translation->item_translations ?? [];
        $issues = [];
        $out = [];

        foreach ($regions as $r) {
            $id = $r['id'] ?? null;
            // A region marked preserve keeps its source pixels; never erase it.
            if (($r['translation_policy'] ?? 'translate') === 'preserve') {
                continue;
            }
            $target = null;
            if ($id && isset($overrides[$id]['translation']) && trim((string) $overrides[$id]['translation']) !== '') {
                $target = (string) $overrides[$id]['translation'];
            } elseif ($id && isset($itemTr[$id]) && trim((string) $itemTr[$id]) !== '') {
                $target = (string) $itemTr[$id];
            } elseif (!empty($r['target_text']) && trim((string) $r['target_text']) !== '') {
                // Native path already resolved a per-line target from the edition.
                $target = (string) $r['target_text'];
            }

            if ($target === null) {
                $issues[] = ['code' => 'MISSING_TARGET', 'region_id' => $id,
                             'source_text' => $r['source_text'] ?? ''];
                continue; // do NOT erase this region
            }
            $r['target_text'] = $target;
            $out[] = $r;
        }
        return [$out, $issues];
    }

    /**
     * GROUND-TRUTH regions from the PDF text layer. For text-over-illustration where the
     * text is real (selectable) PDF text, this returns exact per-line boxes, font sizes and
     * colours — no AI geometry guessing. Returns [] when the page has no usable text layer
     * (genuinely baked-in pixel text), in which case the caller falls back to vision detect.
     */
    private function nativeTextRegions(string $pdfPath, int $pageIndex, int $ppi, Translation $translation): array
    {
        $py = <<<PY
import pymupdf, json
d = pymupdf.open(r"{$pdfPath}"); pg = d[$pageIndex]
lines = []
for b in pg.get_text("dict")["blocks"]:
    for l in b.get("lines", []):
        txt = "".join(s["text"] for s in l["spans"]).strip()
        if not txt:
            continue
        x0, y0, x1, y1 = l["bbox"]
        sp = l["spans"][0]
        lines.append({"text": txt, "bbox_pt": [x0, y0, x1, y1],
                      "size": sp["size"], "color": sp.get("color", 0)})
print(json.dumps({"lines": lines}))
PY;
        $proc = new Process(['python', '-c', $py]);
        $proc->setTimeout(60);
        $proc->run();
        if (!$proc->isSuccessful()) {
            return [];
        }
        $data = json_decode(trim($proc->getOutput()), true) ?: [];
        $lines = $data['lines'] ?? [];
        if (empty($lines)) {
            return [];
        }

        // Target text: prefer the edition's translated line(s) for this page; fall back to
        // the source (so at worst we re-render the same text cleanly, never English-leak).
        $targets = $this->pageTargetLines($translation, $pageIndex, count($lines));
        $scale = $ppi / 72.0;
        $regions = [];
        foreach ($lines as $i => $ln) {
            [$x0, $y0, $x1, $y1] = $ln['bbox_pt'];
            $c = (int) ($ln['color'] ?? 0);
            $regions[] = [
                'source_text' => $ln['text'],
                'target_text' => $targets[$i] ?? $ln['text'],
                'bbox_px' => [$x0 * $scale, $y0 * $scale, $x1 * $scale, $y1 * $scale],
                'background_type' => 'illustration', // reconstruct/inpaint behind the line
                'align' => 'center',
                'text_color_rgb' => [($c >> 16) & 255, ($c >> 8) & 255, $c & 255],
                'source_font_pt' => (float) ($ln['size'] ?? 0),
            ];
        }
        return $regions;
    }

    /** Best-effort per-line translated text for a page from the edition's translated pages. */
    private function pageTargetLines(Translation $translation, int $pageIndex, int $count): array
    {
        $page = $translation->translatedPages()
            ->where('page_number', $pageIndex + 1)->first();
        if (!$page || !$page->translated_text) {
            return [];
        }
        $lines = preg_split('/\r?\n/', trim($page->translated_text));
        return array_values(array_filter(array_map('trim', $lines), fn ($s) => $s !== ''));
    }

    /** Cheap Python pre-filter for candidate pages. */
    private function candidatePages(string $pdfPath): array
    {
        $proc = new Process(['python', base_path('scripts/illustration_text.py'),
            'candidates', '--input', $pdfPath]);
        $proc->setTimeout(120);
        $proc->run();
        if (!$proc->isSuccessful()) {
            return [];
        }
        return json_decode(trim($proc->getOutput()), true) ?: [];
    }

    /** Total page count of the PDF (for the full-coverage ledger, spec Req 5.4). */
    private function pdfPageCount(string $pdfPath): int
    {
        $proc = new Process(['python', '-c',
            'import pymupdf,sys; print(pymupdf.open(sys.argv[1]).page_count)', $pdfPath]);
        $proc->setTimeout(30);
        $proc->run();
        if (!$proc->isSuccessful()) {
            return 0;
        }
        return (int) trim($proc->getOutput());
    }

    /**
     * GPT-4o vision detect. Renders the page to an image (PyMuPDF) then asks the model to
     * locate baked-in text. Returns regions with PIXEL-space bboxes at the given ppi.
     */
    private function detect(string $pdfPath, int $pageIndex, int $ppi, string $model): array
    {
        $img = $this->renderPage($pdfPath, $pageIndex, $ppi);
        if ($img === null) {
            return [];
        }
        [$imgPath, $wPx, $hPx] = $img;

        $system = <<<SYS
You locate text that is BAKED INTO the artwork of a children's book page image (text that
is part of the picture's pixels, e.g. a title/subtitle painted onto an illustration or a
solid-colour panel). You are given ONE image. Use a top-left origin; coordinates are
NORMALIZED to [0,1] where (0,0) is top-left and (1,1) is bottom-right of the image.

Return STRICT JSON:
{"regions":[{"source_text":str,"bbox_normalized":[x0,y0,x1,y1],
  "background_type":"flat"|"gradient"|"illustration"|"uncertain",
  "background_sample_regions":[[x0,y0,x1,y1],...],
  "is_baked_in":true|false}]}

Rules: include a region ONLY if the text looks painted into the image (not a crisp
selectable overlay). bbox must tightly cover the glyphs AND their drop-shadow. Propose
2-4 small background_sample_regions of CLEAN background near the text (no letters, no
shadow, no artwork edges). Set background_type to "uncertain" if the background behind the
text is busy artwork you could not cleanly sample. If there is no baked-in text, return
{"regions":[]}.
SYS;

        $data = 'data:image/png;base64,' . base64_encode(file_get_contents($imgPath));
        @unlink($imgPath);

        $response = OpenAI::chat()->create([
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' =>
                        "Image is {$wPx}x{$hPx} pixels. Locate baked-in text. Return the JSON."],
                    ['type' => 'image_url', 'image_url' => ['url' => $data]],
                ]],
            ],
            'temperature' => 0.0,
            'response_format' => ['type' => 'json_object'],
        ]);

        $parsed = json_decode($response->choices[0]->message->content ?? '{}', true) ?: [];
        $out = [];
        foreach (($parsed['regions'] ?? []) as $r) {
            if (!($r['is_baked_in'] ?? true)) {
                continue;
            }
            $bbox = $this->validateNormalized($r['bbox_normalized'] ?? null);
            if ($bbox === null) {
                continue; // R1.2 coordinate validation
            }
            $out[] = [
                'source_text' => (string) ($r['source_text'] ?? ''),
                'bbox_px' => [
                    $bbox[0] * $wPx, $bbox[1] * $hPx, $bbox[2] * $wPx, $bbox[3] * $hPx,
                ],
                'background_type' => in_array($r['background_type'] ?? '', ['flat', 'gradient', 'illustration', 'uncertain'], true)
                    ? $r['background_type'] : 'uncertain',
                'sample_regions_px' => array_values(array_filter(array_map(
                    function ($s) use ($wPx, $hPx) {
                        $b = $this->validateNormalized($s);
                        return $b ? [$b[0] * $wPx, $b[1] * $hPx, $b[2] * $wPx, $b[3] * $hPx] : null;
                    }, $r['background_sample_regions'] ?? []
                ))),
            ];
        }
        return $out;
    }

    /**
     * Measure real background colour from proposed sample regions and gate on uniformity.
     * Returns [regions(with color_rgb + text_color_rgb + align), needsReview].
     */
    private function measureAndGate(string $pdfPath, int $pageIndex, int $ppi, array $regions): array
    {
        $payload = json_encode(['regions' => $regions]);
        $tmp = storage_path('app/temp/illus_measure_' . uniqid() . '.json');
        file_put_contents($tmp, $payload);

        $proc = new Process(['python', base_path('scripts/illustration_measure.py'),
            '--input', $pdfPath, '--page', (string) $pageIndex, '--ppi', (string) $ppi,
            '--regions', $tmp]);
        $proc->setTimeout(120);
        $proc->run();
        @unlink($tmp);

        if (!$proc->isSuccessful()) {
            // Cannot measure => be safe, route to review.
            return [$regions, true];
        }
        $measured = json_decode(trim($proc->getOutput()), true) ?: [];
        $needsReview = (bool) ($measured['needs_review'] ?? true);
        return [$measured['regions'] ?? $regions, $needsReview];
    }

    /** Run the deterministic Python erase+overlay. Returns its JSON report. */
    private function repair(Book $book, Translation $translation, string $pdfPath,
                            int $pageIndex, int $ppi, array $regions, bool $generative): array
    {
        // Targets are already resolved per-region by attachTargetsById() before this call
        // (spec Req 5.6) — no whole-page blob fallback here.
        $regionsPath = storage_path('app/temp/illus_regions_' . uniqid() . '.json');
        file_put_contents($regionsPath, json_encode(['ppi' => $ppi, 'regions' => $regions], JSON_UNESCAPED_UNICODE));

        $cmd = ['python', base_path('scripts/illustration_text.py'), 'repair',
            '--input', $pdfPath, '--page', (string) $pageIndex,
            '--regions', $regionsPath, '--fonts-dir', $this->fontsDir,
            '--output', $pdfPath, '--ppi', (string) $ppi];

        if ($generative) {
            $bg = $this->generativeBackground($pdfPath, $pageIndex, $ppi, $regions);
            if ($bg !== null) {
                $cmd[] = '--generative-bg';
                $cmd[] = $bg;
            }
        }

        $proc = new Process($cmd);
        $proc->setTimeout(240);
        $proc->run();
        @unlink($regionsPath);

        if (!$proc->isSuccessful()) {
            Log::warning('IllustrationText: repair failed (non-fatal)', [
                'page' => $pageIndex, 'stderr' => $proc->getErrorOutput(),
            ]);
            return ['modified' => false];
        }
        return json_decode($proc->getErrorOutput(), true) ?: ['modified' => false];
    }

    /**
     * OPT-IN generative background reconstruction (§4). Reconstructs BACKGROUND ONLY behind
     * the located text via the images edit endpoint, then hands the result to Python which
     * composites ONLY the masked regions over the original raster (so any off-region drift
     * the model introduces is discarded — deterministic mask, §4). Translated vector text is
     * added by Python on top afterwards. Returns a PNG path (full-page, model-reconstructed)
     * or null on any failure (Python then falls back to deterministic inpaint).
     *
     * Safety: background-only prompt, explicit alpha mask limits regeneration to the text
     * regions, and the whole route is review-gated by the caller. Never auto-approved.
     */
    private function generativeBackground(string $pdfPath, int $pageIndex, int $ppi, array $regions): ?string
    {
        try {
            $model = (string) config('bookstore.illustration_text.image_model', 'gpt-image-1');
            $dir = storage_path('app/temp');
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            // 1. Render the page and build a mask (transparent where text should be
            //    regenerated, opaque elsewhere). Done in Python — it owns the pixels and
            //    the exact region geometry. Produces a SQUARE, edit-endpoint-friendly pair.
            $prep = storage_path('app/temp/illus_genprep_' . uniqid() . '.json');
            file_put_contents($prep, json_encode(['ppi' => $ppi, 'regions' => $regions], JSON_UNESCAPED_UNICODE));
            $base = "{$dir}/illus_genbase_" . uniqid() . '.png';
            $mask = "{$dir}/illus_genmask_" . uniqid() . '.png';

            $prepProc = new Process(['python', base_path('scripts/illustration_genmask.py'),
                '--input', $pdfPath, '--page', (string) $pageIndex, '--ppi', (string) $ppi,
                '--regions', $prep, '--out-image', $base, '--out-mask', $mask]);
            $prepProc->setTimeout(120);
            $prepProc->run();
            @unlink($prep);
            if (!$prepProc->isSuccessful() || !is_file($base) || !is_file($mask)) {
                Log::warning('IllustrationText: genmask prep failed; using deterministic inpaint', [
                    'stderr' => $prepProc->getErrorOutput(),
                ]);
                @unlink($base); @unlink($mask);
                return null;
            }

            // 2. Ask the image model to reconstruct BACKGROUND ONLY in the transparent
            //    (masked) regions. No new text — the translated text is added later as
            //    crisp PDF vector text by the Python repair step.
            $prompt = 'Reconstruct only the background of a childrens book illustration in the '
                . 'transparent regions so it seamlessly matches the surrounding artwork, colours '
                . 'and texture. Do not add any text, letters, words, characters or new objects. '
                . 'Leave all non-transparent areas exactly as they are.';

            // One retry: the images edit endpoint occasionally times out on the first hit.
            // Since the deterministic erase already ran, a null return here is harmless.
            $response = null;
            $editParams = [
                'model' => $model,
                'image' => fopen($base, 'r'),
                'mask' => fopen($mask, 'r'),
                'prompt' => $prompt,
                'n' => 1,
                'size' => $this->squareSizeFor($base),
            ];
            for ($attempt = 1; $attempt <= 2 && $response === null; $attempt++) {
                try {
                    // reopen streams for a retry (a consumed handle can't be re-sent)
                    $editParams['image'] = fopen($base, 'r');
                    $editParams['mask'] = fopen($mask, 'r');
                    $response = OpenAI::images()->edit($editParams);
                } catch (\Throwable $ex) {
                    Log::warning("IllustrationText: images.edit attempt {$attempt} failed", [
                        'error' => $ex->getMessage(),
                    ]);
                    if ($attempt >= 2) {
                        @unlink($base); @unlink($mask);
                        return null; // give up; deterministic erase stands
                    }
                }
            }

            $item = $response->data[0] ?? null;
            $b64 = $item->b64_json ?? null;
            $url = $item->url ?? null;
            // The SDK types these as non-nullable strings; treat empty string as absent.
            $b64 = ($b64 !== null && $b64 !== '') ? $b64 : null;
            $url = ($url !== null && $url !== '') ? $url : null;

            $out = "{$dir}/illus_genout_" . uniqid() . '.png';
            if ($b64) {
                file_put_contents($out, base64_decode($b64));
            } elseif ($url) {
                $bytes = @file_get_contents($url);
                if ($bytes === false) {
                    @unlink($base); @unlink($mask);
                    return null;
                }
                file_put_contents($out, $bytes);
            } else {
                @unlink($base); @unlink($mask);
                return null;
            }

            @unlink($base); @unlink($mask);

            // QUALITY CHECK: reject a generative result that diverges from the real
            // background (model returned garbage / ignored the mask). Since the erase is
            // already deterministic, rejecting here just keeps the clean deterministic fill.
            $regionsTmp = storage_path('app/temp/illus_genval_' . uniqid() . '.json');
            file_put_contents($regionsTmp, json_encode(['regions' => $regions]));
            $val = new Process(['python', base_path('scripts/illustration_genvalidate.py'),
                '--gen', $out, '--orig-pdf', $pdfPath, '--page', (string) $pageIndex,
                '--ppi', (string) $ppi, '--regions', $regionsTmp]);
            $val->setTimeout(120);
            $val->run();
            @unlink($regionsTmp);
            $verdict = json_decode(trim($val->getOutput()), true) ?: ['ok' => false];
            if (!($verdict['ok'] ?? false)) {
                Log::info('IllustrationText: generative result rejected by quality check; '
                    . 'keeping deterministic erase', ['verdict' => $verdict]);
                @unlink($out);
                return null;
            }

            // Python `repair` will composite ONLY the masked regions over the original,
            // discarding any off-region drift.
            return $out;
        } catch (\Throwable $e) {
            Log::warning('IllustrationText: generative bg failed (non-fatal)', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /** The images edit endpoint expects a square size; pick the nearest supported one. */
    private function squareSizeFor(string $pngPath): string
    {
        $info = @getimagesize($pngPath);
        $side = $info ? max($info[0], $info[1]) : 1024;
        // gpt-image-1 / dall-e-2 supported square sizes.
        foreach ([256, 512, 1024] as $s) {
            if ($side <= $s) {
                return "{$s}x{$s}";
            }
        }
        return '1024x1024';
    }

    private function renderPage(string $pdfPath, int $pageIndex, int $ppi): ?array
    {
        $dir = storage_path('app/temp');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $out = "{$dir}/illus_page_{$pageIndex}_" . uniqid() . '.png';
        $py = sprintf(
            'import pymupdf,sys; d=pymupdf.open(r"%s"); p=d[%d]; ' .
            'z=%d/72.0; m=pymupdf.Matrix(z,z); pix=p.get_pixmap(matrix=m,alpha=False); ' .
            'pix.save(r"%s"); print(pix.width, pix.height)',
            $pdfPath, $pageIndex, $ppi, $out
        );
        $proc = new Process(['python', '-c', $py]);
        $proc->setTimeout(120);
        $proc->run();
        if (!$proc->isSuccessful() || !is_file($out)) {
            return null;
        }
        [$w, $h] = array_map('intval', explode(' ', trim($proc->getOutput())));
        return [$out, $w, $h];
    }

    /** Validate a normalized [x0,y0,x1,y1] box: bounds [0,1] + positive area (R1.2). */
    private function validateNormalized($b): ?array
    {
        if (!is_array($b) || count($b) !== 4) {
            return null;
        }
        [$x0, $y0, $x1, $y1] = array_map('floatval', $b);
        foreach ([$x0, $y0, $x1, $y1] as $v) {
            if ($v < 0.0 || $v > 1.0) {
                return null;
            }
        }
        if ($x1 <= $x0 || $y1 <= $y0) {
            return null;
        }
        return [$x0, $y0, $x1, $y1];
    }
}
