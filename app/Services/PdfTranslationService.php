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
     * Create a translated PDF using PyMuPDF V6 engine.
     *
     * V6 approach:
     * 1. Pass full translated text per page to Python
     * 2. Python classifies page types (cover, story, vocabulary, back_cover)
     * 3. Python erases text zones and re-renders with insert_htmlbox + CSS
     */
    public function createTranslatedPdf(Book $book, Translation $translation): string
    {
        $originalPath = Storage::disk('public')->path($book->pdf_path);
        $translatedPages = $translation->translatedPages()->orderBy('page_number')->get();

        if ($translatedPages->isEmpty()) {
            throw new \RuntimeException("No translated pages found for translation #{$translation->id}");
        }

        // Build translations JSON (V6: just full text per page)
        $this->setCurrentBook($book);
        $translationsData = $this->buildTranslationsJson([], $translatedPages);

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

        // Run the appropriate rendering engine based on book setting
        $scriptPath = $this->getScriptPath($book);
        
        $process = new Process([
            'python',
            $scriptPath,
            'replace',
            '--input', $originalPath,
            '--output', $outputPath,
            '--translations', $translationsPath,
            '--fonts-dir', $this->fontsDir,
        ]);

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
            Log::info("PDF translation V6 report for book #{$book->id} ({$translation->language_code})", $report);

            if (!empty($report['errors'])) {
                Log::warning("V6 rendering errors", $report['errors']);
            }
            if (!empty($report['overflow_warnings'])) {
                Log::warning("V6 text overflow warnings", $report['overflow_warnings']);
            }
        }

        // FAIL-CLOSED (overflow-fix brief §13/§14): persist the render gate's QA
        // verdict with the edition. If the engine reports the render is not
        // publishable (any page failed the hard-constraint gate), the layout state
        // becomes NEEDS_LAYOUT_REVIEW so it CANNOT be silently approved/published.
        $publishable = $report['publishable'] ?? true;
        $renderStatus = $report['render_status'] ?? ($publishable ? 'READY_FOR_REVIEW' : 'NEEDS_LAYOUT_REVIEW');
        $translation->forceFill([
            'render_status' => $renderStatus,
            'qa_report' => $report ? json_encode($report, JSON_UNESCAPED_UNICODE) : null,
        ])->save();

        // Cleanup temp file
        @unlink($translationsPath);

        return $outputFilename;
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
