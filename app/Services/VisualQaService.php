<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Translation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use Symfony\Component\Process\Process;

/**
 * VISUAL QA GATE (gpt-4o vision).
 *
 * The structural render gate (render_gate.py) verifies element geometry but
 * deliberately relaxes size/alignment checks on content cells, and the pixel
 * comparison (render_comparison.py) masks OUT text — so NEITHER actually looks at
 * whether the translated TEXT is rendered well. This service closes that gap: it
 * renders the SOURCE page and the TRANSLATED page to images and asks a vision model
 * to compare them and flag layout defects (inconsistent sizes, misalignment, missing
 * padding, overlap, clipping, garbled or missing text). Pages the model flags are
 * returned so the caller can route the edition to NEEDS_LAYOUT_REVIEW.
 *
 * Book-agnostic: the model compares each translated page to its own source; no
 * per-book rules. Intended to run on a SAMPLE of pages (or all) after a render.
 */
class VisualQaService
{
    private const MODEL = 'gpt-4o';
    private const DPI = 120;
    private const MAX_API_ATTEMPTS = 2;

    // Per-page statuses consumed by the whole-book coverage gate (visual_coverage.py).
    // Only PASSED clears a page; everything else is unresolved and blocks approval.
    public const STATUS_PASSED = 'passed';
    public const STATUS_REVIEW = 'review';
    public const STATUS_FAILED = 'failed';
    public const STATUS_NOT_RUN = 'not_run';

    /**
     * Run the visual QA gate over the given 1-based page numbers (or all pages when
     * null). Returns:
     *   [
     *     'ok' => bool,                 // true when every expected page PASSED (fail-closed)
     *     'flagged_pages' => int[],     // 1-based page numbers with defects
     *     'records' => [ ['page_number'=>n, 'status'=>'passed|review|failed|not_run',
     *                     'candidate_fingerprint'=>..., 'issues'=>[...], 'severity'=>...], ],
     *     'pages' => [ n => record ],   // same records keyed by page (back-compat)
     *   ]
     *
     * Statuses map to the coverage gate (visual_coverage.py): only 'passed' clears a page.
     * A missing PDF, a page that will not render, an API failure, or malformed JSON each
     * produce an explicit NON-passing record — never a silent skip or a fail-open pass
     * (unified-rendering-and-testing Req 4.3).
     *
     * @param int[]|null $pageNumbers
     */
    public function review(Book $book, Translation $translation, ?array $pageNumbers = null): array
    {
        $fingerprint = (string) ($translation->render_fingerprint ?? '');
        $sourcePath = Storage::disk('public')->path($book->pdf_path);
        $translatedRel = $translation->rendered_pdf_path
            ?? "books/translated/{$book->id}_{$translation->language_code}.pdf";
        $translatedPath = Storage::disk('public')->path($translatedRel);

        $pages = $pageNumbers ?: ($this->allPageNumbers($sourcePath) ?: $this->allPageNumbers($translatedPath));

        // Missing input is NOT a pass. Every expected page is recorded not_run so the
        // coverage gate reports the whole book unchecked (fail closed).
        if (!is_file($sourcePath) || !is_file($translatedPath)) {
            Log::warning('VisualQa: source or translated PDF missing', [
                'source' => $sourcePath, 'translated' => $translatedPath,
            ]);
            return $this->allNotRun($pages, $fingerprint, 'MISSING_PDF');
        }

        $records = [];
        foreach ($pages as $pageNum) {
            $records[$pageNum] = $this->reviewPage($sourcePath, $translatedPath, $pageNum, $fingerprint);
        }

        $flagged = [];
        foreach ($records as $pageNum => $rec) {
            if (($rec['status'] ?? null) !== self::STATUS_PASSED) {
                $flagged[] = $pageNum;
            }
        }

        return [
            'ok' => $flagged === [],
            'flagged_pages' => $flagged,
            'records' => array_values($records),
            'pages' => $records,
        ];
    }

    /** Build an all-pages not_run result (missing input) — never a pass. */
    private function allNotRun(array $pages, string $fingerprint, string $reason): array
    {
        $records = [];
        foreach ($pages as $pageNum) {
            $records[$pageNum] = [
                'page_number' => $pageNum,
                'status' => self::STATUS_NOT_RUN,
                'candidate_fingerprint' => $fingerprint,
                'severity' => 'major',
                'issues' => [$reason],
            ];
        }
        return [
            'ok' => false,
            'flagged_pages' => array_values($pages),
            'records' => array_values($records),
            'pages' => $records,
        ];
    }

    /**
     * Review ONE page, returning an explicit status record bound to the candidate
     * fingerprint. A render failure or an API/JSON failure yields a non-passing status —
     * NOT a silent skip (Req 4.3). Bounded retries for the transient API path.
     */
    private function reviewPage(string $sourcePath, string $translatedPath, int $pageNum, string $fingerprint): array
    {
        $base = [
            'page_number' => $pageNum,
            'candidate_fingerprint' => $fingerprint,
            'severity' => 'major',
        ];

        [$srcImg, $transImg] = $this->renderPagePair($sourcePath, $translatedPath, $pageNum);
        if ($srcImg === null || $transImg === null) {
            if ($srcImg) { @unlink($srcImg); }
            if ($transImg) { @unlink($transImg); }
            // Could not render — the page is UNVERIFIED, not clean.
            return $base + ['status' => self::STATUS_NOT_RUN, 'issues' => ['PAGE_RENDER_FAILED']];
        }

        $verdict = null;
        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_API_ATTEMPTS && $verdict === null; $attempt++) {
            try {
                $verdict = $this->askModel($srcImg, $transImg, $pageNum);
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning("VisualQa: model call failed for page {$pageNum} (attempt {$attempt})", ['error' => $lastError]);
            }
        }
        @unlink($srcImg); @unlink($transImg);

        if ($verdict === null) {
            // API exhausted — unresolved, never a pass.
            return $base + ['status' => self::STATUS_NOT_RUN, 'issues' => ['VISUAL_API_FAILED: ' . (string) $lastError]];
        }

        // Map the model verdict to a status. A clean verdict passes; any defect is a
        // review/fail. Uncertainty (malformed) was already normalised in askModel.
        $status = ($verdict['ok'] ?? false)
            ? self::STATUS_PASSED
            : (($verdict['severity'] ?? 'minor') === 'major' ? self::STATUS_FAILED : self::STATUS_REVIEW);

        return $base + [
            'status' => $status,
            'severity' => $verdict['severity'] ?? 'minor',
            'issues' => $verdict['issues'] ?? [],
        ];
    }

    /**
     * Ask gpt-4o vision to compare the source and translated page images.
     * Returns ['ok'=>bool, 'severity'=>'minor'|'major', 'issues'=>string[]].
     */
    private function askModel(string $sourceImgPath, string $translatedImgPath, int $pageNum): array
    {
        $system = <<<SYS
You are a layout QA reviewer for a translated children's book. You are shown two
images of the SAME page: IMAGE 1 is the ORIGINAL, IMAGE 2 is the TRANSLATED render.
The translated text will differ (different language) — that is expected and NOT a
defect. Judge only the LAYOUT/RENDERING QUALITY of the translated page against the
original's design.

Flag a page ONLY for real rendering defects:
- text clipped or running off the edge / outside its box/column/cell;
- inconsistent font sizes where the original is uniform (e.g. a word list column
  where some words are visibly larger/smaller than their neighbours);
- text overlapping other text or overlapping artwork it should not;
- missing translated text where the original had text;
- garbled/overlapping characters (e.g. two words stamped on top of each other);
- text with no padding jammed against a table grid line/border;
- badly broken alignment vs the original (e.g. centered header now hard left).

Do NOT flag: different words/language, minor spacing differences, colour of text
that matches the source, or faithful reproduction of the original's own spacing.

Return STRICT JSON: {"ok": boolean, "severity": "minor"|"major", "issues": [string, ...]}.
"ok" is false only if there is at least one real defect above. Keep issues concise.
SYS;

        $srcData = 'data:image/png;base64,' . base64_encode(file_get_contents($sourceImgPath));
        $transData = 'data:image/png;base64,' . base64_encode(file_get_contents($translatedImgPath));

        $response = OpenAI::chat()->create([
            'model' => self::MODEL,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => [
                    ['type' => 'text', 'text' => "Page {$pageNum}. IMAGE 1 = original, IMAGE 2 = translated. Compare and return the JSON verdict."],
                    ['type' => 'image_url', 'image_url' => ['url' => $srcData]],
                    ['type' => 'image_url', 'image_url' => ['url' => $transData]],
                ]],
            ],
            'temperature' => 0.0,
            'response_format' => ['type' => 'json_object'],
        ]);

        $content = $response->choices[0]->message->content ?? '{}';
        $data = json_decode($content, true) ?: [];
        return [
            'ok' => (bool) ($data['ok'] ?? true),
            'severity' => $data['severity'] ?? 'minor',
            'issues' => array_values(array_filter((array) ($data['issues'] ?? []))),
        ];
    }

    /**
     * Render page $pageNum (1-based) of both PDFs to temp PNGs. Returns [srcPng, transPng]
     * (either may be null on failure). Uses the shared Python engine (PyMuPDF).
     */
    private function renderPagePair(string $sourcePdf, string $translatedPdf, int $pageNum): array
    {
        $dir = storage_path('app/temp');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $src = "{$dir}/qa_src_{$pageNum}_" . uniqid() . '.png';
        $trans = "{$dir}/qa_trans_{$pageNum}_" . uniqid() . '.png';

        $ok1 = $this->renderOnePage($sourcePdf, $pageNum, $src);
        $ok2 = $this->renderOnePage($translatedPdf, $pageNum, $trans);
        return [$ok1 ? $src : null, $ok2 ? $trans : null];
    }

    private function renderOnePage(string $pdfPath, int $pageNum, string $outPng): bool
    {
        // Inline Python: render a single 1-based page to PNG at QA DPI.
        $py = sprintf(
            'import pymupdf,sys; d=pymupdf.open(r"%s"); i=%d-1; ' .
            'p=d[i] if 0<=i<len(d) else None; ' .
            'sys.exit(1) if p is None else (p.get_pixmap(dpi=%d).save(r"%s"), print("ok"))',
            $pdfPath, $pageNum, self::DPI, $outPng
        );
        try {
            $proc = new Process(['python', '-c', $py]);
            $proc->setTimeout(60);
            $proc->run();
            return $proc->isSuccessful() && is_file($outPng);
        } catch (\Throwable $e) {
            Log::warning('VisualQa: page render failed', ['page' => $pageNum, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /** @return int[] 1-based page numbers of the source PDF. */
    private function allPageNumbers(string $pdfPath): array
    {
        try {
            $proc = new Process(['python', '-c',
                sprintf('import pymupdf; print(len(pymupdf.open(r"%s")))', $pdfPath)]);
            $proc->setTimeout(30);
            $proc->run();
            $n = (int) trim($proc->getOutput());
            return $n > 0 ? range(1, $n) : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
