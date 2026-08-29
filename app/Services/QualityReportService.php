<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Translation;
use App\Models\TranslatedPage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generates visual QA reports for translated books.
 *
 * Features:
 * - Side-by-side comparison (original vs translated page renders)
 * - Per-page quality flags (green/yellow/red)
 * - Issue detection (overflow, missing text, formatting problems)
 * - Review queue for flagged pages
 */
class QualityReportService
{
    /**
     * Generate a full QA report for a translation.
     */
    public function generateReport(Translation $translation): array
    {
        $book = $translation->book;
        $pages = TranslatedPage::where('translation_id', $translation->id)
            ->orderBy('page_number')
            ->get();

        $report = [
            'translation_id' => $translation->id,
            'book_title' => $book->title,
            'language' => $translation->language_name,
            'generated_at' => now()->toIso8601String(),
            'summary' => [
                'total_pages' => $pages->count(),
                'green' => $pages->where('quality_flag', 'green')->count(),
                'yellow' => $pages->where('quality_flag', 'yellow')->count(),
                'red' => $pages->where('quality_flag', 'red')->count(),
                'unreviewed' => $pages->where('review_status', 'unreviewed')->count(),
                'approved' => $pages->where('review_status', 'approved')->count(),
            ],
            'pages' => [],
            'issues' => [],
        ];

        foreach ($pages as $page) {
            $pageReport = [
                'page_number' => $page->page_number,
                'quality_flag' => $page->quality_flag,
                'confidence_score' => $page->confidence_score,
                'review_status' => $page->review_status,
                'quality_notes' => $page->quality_notes,
                'text_length_original' => strlen($page->bookPage?->extracted_text ?? ''),
                'text_length_translated' => strlen($page->translated_text),
                'issues' => [],
            ];

            // Detect potential issues
            $issues = $this->detectIssues($page);
            $pageReport['issues'] = $issues;

            if (!empty($issues)) {
                foreach ($issues as $issue) {
                    $report['issues'][] = array_merge($issue, ['page_number' => $page->page_number]);
                }
            }

            $report['pages'][] = $pageReport;
        }

        return $report;
    }

    /**
     * Detect potential quality issues on a translated page.
     */
    private function detectIssues(TranslatedPage $page): array
    {
        $issues = [];
        $original = $page->bookPage?->extracted_text ?? '';
        $translated = $page->translated_text;

        // Issue: Translation significantly longer than original (potential overflow)
        if (strlen($translated) > strlen($original) * 1.8) {
            $issues[] = [
                'type' => 'length_warning',
                'severity' => 'yellow',
                'message' => 'Translation is significantly longer than original — check for overflow',
                'ratio' => round(strlen($translated) / max(1, strlen($original)), 2),
            ];
        }

        // Issue: Translation much shorter (potential missing content)
        if (strlen($translated) < strlen($original) * 0.4 && strlen($original) > 20) {
            $issues[] = [
                'type' => 'content_missing',
                'severity' => 'red',
                'message' => 'Translation is much shorter than original — content may be missing',
                'ratio' => round(strlen($translated) / max(1, strlen($original)), 2),
            ];
        }

        // Issue: Low confidence score
        if ($page->confidence_score < 6.0) {
            $issues[] = [
                'type' => 'low_confidence',
                'severity' => 'red',
                'message' => "Low quality score: {$page->confidence_score}/10",
            ];
        }

        // Issue: Contains untranslated English words (simple heuristic)
        $englishPatterns = ['the ', ' is ', ' are ', ' was ', ' were ', ' and ', ' but '];
        $englishCount = 0;
        foreach ($englishPatterns as $pattern) {
            if (stripos($translated, $pattern) !== false) {
                $englishCount++;
            }
        }
        if ($englishCount >= 3) {
            $issues[] = [
                'type' => 'untranslated_content',
                'severity' => 'yellow',
                'message' => 'Possible untranslated English content detected',
            ];
        }

        // Issue: Educational page needs specialist review
        if (str_contains($page->quality_notes ?? '', 'educational') ||
            str_contains($page->quality_notes ?? '', 'phonics')) {
            $issues[] = [
                'type' => 'educational_review',
                'severity' => 'yellow',
                'message' => 'Educational content — requires specialist review',
            ];
        }

        return $issues;
    }

    /**
     * Get pages that need review, sorted by priority.
     */
    public function getReviewQueue(Translation $translation): array
    {
        $pages = TranslatedPage::where('translation_id', $translation->id)
            ->where(function ($query) {
                $query->where('quality_flag', '!=', 'green')
                    ->orWhere('review_status', 'unreviewed');
            })
            ->orderByRaw("CASE quality_flag WHEN 'red' THEN 1 WHEN 'yellow' THEN 2 ELSE 3 END")
            ->orderBy('confidence_score')
            ->get();

        return $pages->map(function ($page) {
            return [
                'page_number' => $page->page_number,
                'quality_flag' => $page->quality_flag,
                'confidence_score' => $page->confidence_score,
                'review_status' => $page->review_status,
                'quality_notes' => $page->quality_notes,
                'issues' => $this->detectIssues($page),
            ];
        })->toArray();
    }

    /**
     * Generate side-by-side comparison images for a specific page.
     * Returns paths to the original and translated page PNGs.
     */
    public function generateComparison(Translation $translation, int $pageNumber): array
    {
        $book = $translation->book;
        $originalPdf = Storage::disk('public')->path($book->pdf_path);
        $translatedPdf = Storage::disk('public')->path($translation->rendered_pdf_path ?? '');

        $outputDir = storage_path("app/temp/qa_comparison/{$translation->id}");
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $result = [
            'page_number' => $pageNumber,
            'original' => null,
            'translated' => null,
        ];

        // Render original page
        if (file_exists($originalPdf)) {
            $originalPng = "{$outputDir}/original_p{$pageNumber}.png";
            $this->renderPageToPng($originalPdf, $pageNumber, $originalPng);
            $result['original'] = $originalPng;
        }

        // Render translated page
        if (file_exists($translatedPdf)) {
            $translatedPng = "{$outputDir}/translated_p{$pageNumber}.png";
            $this->renderPageToPng($translatedPdf, $pageNumber, $translatedPng);
            $result['translated'] = $translatedPng;
        }

        return $result;
    }

    /**
     * Render a single PDF page to PNG using PyMuPDF.
     */
    private function renderPageToPng(string $pdfPath, int $pageNumber, string $outputPath): void
    {
        $escapedPdf = escapeshellarg($pdfPath);
        $escapedOutput = escapeshellarg($outputPath);
        $pageIdx = $pageNumber - 1;

        $script = <<<PYTHON
import pymupdf
doc = pymupdf.open({$escapedPdf})
if {$pageIdx} < len(doc):
    page = doc[{$pageIdx}]
    pix = page.get_pixmap(dpi=150)
    pix.save({$escapedOutput})
doc.close()
PYTHON;

        $tempScript = tempnam(sys_get_temp_dir(), 'qa_render_') . '.py';
        file_put_contents($tempScript, $script);
        exec("python {$tempScript} 2>&1", $output, $returnCode);
        unlink($tempScript);

        if ($returnCode !== 0) {
            Log::warning("QA render failed for page {$pageNumber}", ['output' => implode("\n", $output)]);
        }
    }
}
