<?php

namespace App\Services\Qa;

use App\Models\Book;
use App\Models\Translation;
use App\Services\ExerciseService;
use App\Services\VisualQaService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * BookTestingService — whole-book testing COORDINATOR
 * (unified-rendering-and-testing spec Req 4, brief §9).
 *
 * This does NOT render. It runs validators over the EXACT final staged/rendered PDF and
 * produces coverage ledgers that the readiness authority consumes. The first layer wired
 * here is the whole-book VISUAL coverage ledger: the visual QA pass checks pages one at a
 * time and (even after hardening) can only report per-page records; this coordinator proves
 * that EVERY page of the final PDF has a passing record bound to the current candidate.
 *
 * Coverage is derived from the FINAL PDF page count — not the translated-page DB rows — so
 * blank, preserved and image-only pages are included. A missing file, an unchecked page, a
 * stale result or an unresolved verdict is a blocking issue, never a silent pass.
 *
 * Further layers (integrity/geometry/artwork/educational) attach here in Phases C/D; each
 * stays independent (one layer cannot clear another's failure).
 */
class BookTestingService
{
    public function __construct(
        private ?VisualQaService $visualQa = null,
        private ?ExerciseService $exercises = null,
    ) {
        $this->visualQa = $visualQa ?: app(VisualQaService::class);
        $this->exercises = $exercises ?: app(ExerciseService::class);
    }

    /**
     * The INDEPENDENT educational check layer (unified-rendering-and-testing Req 6.4).
     * Returns {applicable, status, issues}. An educational failure blocks approval
     * regardless of layout/visual; a layout pass can never clear it. A book with no
     * exercises records status=not_applicable WITH a reason (never a silent pass).
     *
     * @return array{applicable: bool, status: string, reason?: string, issues: list<array<string,mixed>>}
     */
    public function educationalCheck(Book $book, Translation $translation): array
    {
        $contract = $translation->exercise_contract;

        // Not applicable: no exercise contract on this edition. Record the reason.
        if ($contract === null || ($contract['exercises'] ?? []) === []) {
            return [
                'applicable' => false,
                'status' => 'not_applicable',
                'reason' => 'NO_EXERCISES_ON_EDITION',
                'issues' => [],
            ];
        }

        $policy = [
            'language' => $translation->language_code,
            'audience' => $translation->education_phase,
        ];
        $issues = $this->exercises->educationalIssues($contract, $policy);

        return [
            'applicable' => true,
            'status' => $issues === [] ? 'passed' : 'failed',
            'issues' => $issues,
        ];
    }

    /**
     * Run the whole-book visual coverage layer for an edition.
     *
     * @return array{
     *   ran: bool,
     *   covered: bool,
     *   expected_pages: int[],
     *   issues: list<array<string,mixed>>,
     *   records: list<array<string,mixed>>
     * }
     */
    public function visualCoverage(Book $book, Translation $translation): array
    {
        $fingerprint = (string) ($translation->render_fingerprint ?? '');
        $translatedRel = $translation->rendered_pdf_path
            ?? "books/translated/{$book->id}_{$translation->language_code}.pdf";
        $translatedPath = Storage::disk('public')->path($translatedRel);

        // Expected page set derived from the FINAL PDF — includes blank/preserved/image-only
        // pages. If we cannot count the PDF, the set is empty and the gate fails closed.
        $expected = $this->pdfPageNumbers($translatedPath);

        // Run the (hardened) visual QA over the WHOLE book — no subset sampling for the
        // final coverage decision (Req 4.4). Each page yields an explicit status record.
        $qa = $this->visualQa->review($book, $translation, $expected ?: null);
        $records = $qa['records'] ?? [];

        $issues = $this->coverageIssues($expected, $records, $fingerprint);

        return [
            'ran' => true,
            'covered' => $issues === [],
            'expected_pages' => $expected,
            'issues' => $issues,
            'records' => $records,
        ];
    }

    /**
     * Evaluate coverage via the Python gate (scripts/visual_coverage.py) — the single
     * source of the coverage rules, shared with the Python test suite. Falls back to an
     * equivalent in-PHP evaluation if Python is unavailable (never a false pass).
     *
     * @param int[] $expected
     * @param list<array<string,mixed>> $records
     * @return list<array<string,mixed>>
     */
    private function coverageIssues(array $expected, array $records, string $fingerprint): array
    {
        $payload = json_encode([
            'expected_pages' => array_values($expected),
            'records' => array_values($records),
            'fingerprint' => $fingerprint,
        ], JSON_UNESCAPED_UNICODE);

        $script = base_path('scripts/visual_coverage_cli.py');
        if (is_file($script)) {
            try {
                $proc = new Process(['python', $script]);
                $proc->setInput($payload);
                $proc->setTimeout(30);
                $proc->run();
                if ($proc->isSuccessful()) {
                    $decoded = json_decode(trim($proc->getOutput()), true);
                    if (is_array($decoded) && isset($decoded['issues']) && is_array($decoded['issues'])) {
                        return $decoded['issues'];
                    }
                }
                Log::warning('visual_coverage_cli failed; using PHP fallback', [
                    'stderr' => $proc->getErrorOutput(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('visual_coverage_cli threw; using PHP fallback', ['error' => $e->getMessage()]);
            }
        }

        return $this->coverageIssuesPhp($expected, $records, $fingerprint);
    }

    /**
     * Pure-PHP mirror of visual_coverage.visual_coverage_issues — used when Python is not
     * available. Fail closed on an empty expected set.
     *
     * @param int[] $expected
     * @param list<array<string,mixed>> $records
     * @return list<array<string,mixed>>
     */
    private function coverageIssuesPhp(array $expected, array $records, string $fingerprint): array
    {
        $expectedSet = array_fill_keys($expected, true);
        if ($expectedSet === []) {
            return [['code' => 'EMPTY_EXPECTED_PAGE_SET']];
        }

        $issues = [];
        $seen = [];
        foreach ($records as $record) {
            $page = $record['page_number'] ?? null;
            if (!isset($expectedSet[$page]) || isset($seen[$page])) {
                $issues[] = ['code' => 'UNEXPECTED_OR_DUPLICATE_PAGE', 'page_number' => $page];
                continue;
            }
            $seen[$page] = true;
            if (($record['candidate_fingerprint'] ?? null) !== $fingerprint) {
                $issues[] = ['code' => 'STALE_VISUAL_RESULT', 'page_number' => $page];
            } elseif (($record['status'] ?? null) !== VisualQaService::STATUS_PASSED) {
                $issues[] = ['code' => 'VISUAL_CHECK_UNRESOLVED', 'page_number' => $page,
                             'status' => $record['status'] ?? 'missing'];
            }
        }
        foreach ($expected as $page) {
            if (!isset($seen[$page])) {
                $issues[] = ['code' => 'PAGE_NOT_CHECKED', 'page_number' => $page];
            }
        }
        return $issues;
    }

    /**
     * 1-based page numbers of a PDF via PyMuPDF. Returns [] when the file is missing or the
     * count cannot be read — the coverage gate then fails closed.
     *
     * @return int[]
     */
    protected function pdfPageNumbers(string $pdfPath): array
    {
        if (!is_file($pdfPath)) {
            return [];
        }
        try {
            $proc = new Process(['python', '-c',
                sprintf('import pymupdf; print(len(pymupdf.open(r"%s")))', $pdfPath)]);
            $proc->setTimeout(30);
            $proc->run();
            $n = (int) trim($proc->getOutput());
            return $n > 0 ? range(1, $n) : [];
        } catch (\Throwable $e) {
            Log::warning('BookTesting: could not count PDF pages', ['pdf' => $pdfPath, 'error' => $e->getMessage()]);
            return [];
        }
    }
}
