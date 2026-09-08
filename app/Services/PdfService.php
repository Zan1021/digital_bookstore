<?php

namespace App\Services;

use App\Models\Book;
use App\Models\BookPage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use OpenAI\Laravel\Facades\OpenAI;
use Smalot\PdfParser\Parser;

class PdfService
{
    private Parser $parser;

    public function __construct()
    {
        $this->parser = new Parser();
    }

    /**
     * Process an uploaded PDF: store it, extract text, create book record, auto-narrate.
     */
    public function processUpload(UploadedFile $file): Book
    {
        // Store the PDF
        $filename = time() . '_' . $file->getClientOriginalName();
        $path = $file->storeAs('books/pdfs', $filename, 'public');

        // Parse PDF for text and page count
        $fullPath = Storage::disk('public')->path($path);
        $pdf = $this->parser->parseFile($fullPath);
        $pages = $pdf->getPages();

        // Create the book record
        $title = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $title = $this->cleanTitle($title);

        $book = Book::create([
            'title' => $title,
            'pdf_path' => $path,
            'page_count' => count($pages),
            'status' => 'processing',
            'original_language' => 'en',
        ]);

        // Extract text per page
        $hasText = false;
        foreach ($pages as $index => $page) {
            $text = trim($page->getText());

            BookPage::create([
                'book_id' => $book->id,
                'page_number' => $index + 1,
                'extracted_text' => !empty($text) ? $text : null,
            ]);

            if (!empty($text)) {
                $hasText = true;
            }
        }

        $book->update(['status' => 'ready']);

        // Auto-classify + draft a store description on import (book-classification-discovery
        // spec, Req 4.1/5.1). Queued so upload isn't blocked; failure never blocks the book.
        // V8-safe: reads extracted text only, never the render engine.
        \App\Jobs\AnalyzeBookClassificationJob::dispatch($book->id);

        // Auto-resolve fonts from the PDF (downloads matching Google Fonts)
        $this->resolveFonts($fullPath);

        // Generate V8 page manifest (stable content IDs for translation)
        $this->rebuildManifest($book, $fullPath);

        // NOTE: narration is intentionally NOT triggered here. Upload only creates the
        // ebook; narration is chosen deliberately on the book management page
        // (gated-translation-narration-flow Req 1.3 / Req 2). The original language may
        // be narrated immediately there; translated editions only after approval.

        return $book;
    }

    /**
     * Auto-generate narration in the book's original language using a default voice.
     */
    private function autoNarrate(Book $book): void
    {
        $narrationService = app(NarrationService::class);

        // Get the first available voice
        $voices = $narrationService->getVoices();
        if (empty($voices)) {
            return;
        }

        // Prefer a female storyteller voice, or just use the first one
        $voice = collect($voices)->first(function ($v) {
            $name = strtolower($v['name']);
            return str_contains($name, 'rachel') || str_contains($name, 'sarah')
                || str_contains($name, 'emily') || str_contains($name, 'charlotte');
        }) ?? $voices[0];

        $narrationService->narrate(
            $book,
            $book->original_language,
            $voice['voice_id'],
            $voice['name']
        );
    }

    /**
     * Process multiple PDFs in bulk.
     */
    public function processBulk(array $files): array
    {
        $results = [];

        foreach ($files as $file) {
            try {
                $book = $this->processUpload($file);
                $results[] = ['success' => true, 'book' => $book, 'filename' => $file->getClientOriginalName()];
            } catch (\Throwable $e) {
                $results[] = ['success' => false, 'error' => $e->getMessage(), 'filename' => $file->getClientOriginalName()];
            }
        }

        return $results;
    }

    /**
     * Use AI to suggest metadata for a book based on extracted text.
     */
    public function suggestMetadata(Book $book): array
    {
        $text = $book->getExtractedText();

        if (empty($text)) {
            return [];
        }

        // Limit text to avoid token overload
        $text = mb_substr($text, 0, 3000);

        $response = OpenAI::chat()->create([
            'model' => 'gpt-4o',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => "You are a children's book cataloguer. Based on the extracted text from a children's book, suggest metadata. Return JSON with these fields: title, author (if mentioned), description (2-3 sentences), short_description (1 sentence), category, age_group (e.g., '3-5', '6-8'), themes (array), tags (array), language.",
                ],
                [
                    'role' => 'user',
                    'content' => "Book text:\n\n{$text}",
                ],
            ],
            'temperature' => 0.3,
            'response_format' => ['type' => 'json_object'],
        ]);

        $content = $response->choices[0]->message->content;

        return json_decode($content, true) ?? [];
    }

    /**
     * Auto-resolve fonts from a PDF by downloading matching fonts from Google Fonts.
     * Runs the Python font_resolver script which handles detection and download.
     */
    private function resolveFonts(string $pdfPath): void
    {
        $scriptPath = base_path('scripts/font_resolver.py');
        $fontsDir = storage_path('app/fonts');

        if (!file_exists($scriptPath)) {
            Log::warning("Font resolver script not found: {$scriptPath}");
            return;
        }

        try {
            $process = new \Symfony\Component\Process\Process([
                'python',
                $scriptPath,
                'resolve-all',
                $pdfPath,
                '--output', $fontsDir,
            ]);

            $process->setTimeout(60);
            $process->run();

            if ($process->isSuccessful()) {
                $results = json_decode($process->getOutput(), true);
                if ($results) {
                    $resolved = count(array_filter($results, fn($r) => !empty($r['path'])));
                    Log::info("Font resolver: {$resolved}/" . count($results) . " fonts resolved from PDF");
                }
            } else {
                Log::warning("Font resolver failed: " . $process->getErrorOutput());
            }
        } catch (\Throwable $e) {
            Log::warning("Font resolver error: " . $e->getMessage());
        }
    }

    /**
     * Clean up a filename into a readable title.
     */
    private function cleanTitle(string $filename): string
    {
        // Remove common patterns: series numbers, underscores, hyphens
        $title = str_replace(['_', '  '], [' ', ' '], $filename);
        $title = trim($title);

        return $title;
    }

    /**
     * Generate V8 page manifest using the Python manifest builder.
     * Stores the manifest as JSON alongside the book's PDF.
     *
     * PUBLIC + reusable: called both on upload and by `book:rebuild-manifest`. Uses the
     * scene-graph builder (builder=scene_graph) which carries merged-header structure.
     * If $pdfPath is null it is resolved from the book's stored pdf_path.
     *
     * @return bool true if the manifest was (re)generated successfully.
     */
    public function rebuildManifest(Book $book, ?string $pdfPath = null): bool
    {
        $scriptPath = base_path('scripts/page_manifest.py');
        $manifestPath = storage_path("app/public/books/manifests/{$book->id}_manifest.json");

        if ($pdfPath === null) {
            if (!$book->pdf_path || !Storage::disk('public')->exists($book->pdf_path)) {
                Log::warning("rebuildManifest: source PDF missing for book {$book->id}");
                return false;
            }
            $pdfPath = Storage::disk('public')->path($book->pdf_path);
        }

        if (!file_exists($scriptPath)) {
            Log::warning("Page manifest script not found: {$scriptPath}");
            return false;
        }

        // Ensure manifests directory exists
        $manifestDir = dirname($manifestPath);
        if (!is_dir($manifestDir)) {
            mkdir($manifestDir, 0755, true);
        }

        try {
            // Scene-graph builder (default, no --legacy): carries merged-header structure.
            $process = new \Symfony\Component\Process\Process([
                'python',
                $scriptPath,
                $pdfPath,
                '--output', $manifestPath,
            ]);

            // Scene graph is heavier than the old flat builder; give it room.
            $process->setTimeout(180);
            $process->run();

            if ($process->isSuccessful()) {
                $book->update(['manifest_path' => "books/manifests/{$book->id}_manifest.json"]);
                Log::info("V8 scene-graph manifest generated for book {$book->id}: {$manifestPath}");
                return true;
            }

            Log::warning("Manifest generation failed for book {$book->id}: " . $process->getErrorOutput());
            return false;
        } catch (\Throwable $e) {
            Log::warning("Manifest generation error for book {$book->id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * @deprecated Use rebuildManifest(). Kept as a thin alias for any internal callers.
     */
    private function generateManifest(Book $book, string $pdfPath): void
    {
        $this->rebuildManifest($book, $pdfPath);
    }

    /**
     * Detect TrimBox/CropBox from PDF and calculate crop percentages.
     * Returns null if no TrimBox is found.
     */
    public function detectCropMarks(string $pdfPath): ?array
    {
        try {
            $fullPath = Storage::disk('public')->path($pdfPath);
            if (!is_file($fullPath)) {
                return null;
            }

            // Use the dedicated crop-mark detection engine (scripts/cropmark_detection.py):
            // TrimBox metadata → vector crop-mark detection → book-wide consensus. This
            // is far more capable than a PHP-only TrimBox read (which misses PDFs whose
            // marks are vector art with no TrimBox, and mis-reads some TrimBox encodings).
            $scriptPath = base_path('scripts/cropmark_detection.py');
            if (!file_exists($scriptPath)) {
                return $this->detectCropMarksFallback($pdfPath); // legacy TrimBox reader
            }

            $process = new \Symfony\Component\Process\Process([
                'python', $scriptPath, 'detect', '--input', $fullPath, '--json',
            ]);
            $process->setTimeout(120);
            $process->run();

            if (!$process->isSuccessful()) {
                Log::warning("cropmark_detection.py failed for {$pdfPath}: " . $process->getErrorOutput());
                return $this->detectCropMarksFallback($pdfPath);
            }

            $result = json_decode($process->getOutput(), true);
            if (!is_array($result) || empty($result['has_crop_marks']) || empty($result['consensus_trim_box'])) {
                // No crop marks — render as-is (this is a valid, confident outcome).
                return null;
            }

            // consensus_trim_box is absolute points [x0,y0,x1,y1]. Convert to per-edge
            // fractions of the MediaBox — the loss-free representation the reader
            // consumes via Book::getCropBoxFractions().
            $trim = $result['consensus_trim_box'];
            $media = $result['media_box'] ?? null;
            if (!$media || count($media) < 4) {
                return $this->detectCropMarksFallback($pdfPath);
            }

            $mediaW = $media[2] - $media[0];
            $mediaH = $media[3] - $media[1];
            if ($mediaW <= 0 || $mediaH <= 0) {
                return $this->detectCropMarksFallback($pdfPath);
            }

            $left   = ($trim[0] - $media[0]) / $mediaW;
            $bottom = ($trim[1] - $media[1]) / $mediaH;
            $right  = ($media[2] - $trim[2]) / $mediaW;
            $top    = ($media[3] - $trim[3]) / $mediaH;

            $cropTop = round($top * 100, 1);
            $cropBottom = round($bottom * 100, 1);
            $cropLeft = round($left * 100, 1);
            $cropRight = round($right * 100, 1);

            return [
                'detected' => true,
                'confidence' => $result['confidence'] ?? null,
                'detection_source' => $result['detection_summary'] ?? null,
                'crop_top' => $cropTop,
                'crop_bottom' => $cropBottom,
                'crop_left' => $cropLeft,
                'crop_right' => $cropRight,
                'crop_avg' => round(($cropTop + $cropBottom + $cropLeft + $cropRight) / 4, 1),
                'crop_box' => [
                    'left' => round($left, 4),
                    'top' => round($top, 4),
                    'right' => round($right, 4),
                    'bottom' => round($bottom, 4),
                ],
                'trim_width_mm' => round(($trim[2] - $trim[0]) * 0.3528),
                'trim_height_mm' => round(($trim[3] - $trim[1]) * 0.3528),
                'media_width_mm' => round($mediaW * 0.3528),
                'media_height_mm' => round($mediaH * 0.3528),
            ];
        } catch (\Throwable $e) {
            Log::warning("detectCropMarks error for {$pdfPath}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Legacy PHP-only TrimBox reader — used only if the Python crop engine is
     * unavailable. Detects crop only when an explicit TrimBox < MediaBox exists.
     */
    private function detectCropMarksFallback(string $pdfPath): ?array
    {
        try {
            $fullPath = Storage::disk('public')->path($pdfPath);
            $pdf = $this->parser->parseFile($fullPath);
            $pages = $pdf->getPages();

            if (empty($pages)) return null;

            $page = $pages[0];
            $details = $page->getDetails();

            $mediaBox = $details['MediaBox'] ?? null;
            $trimBox = $details['TrimBox'] ?? null;

            if (!$mediaBox || !$trimBox) return null;

            // MediaBox = full page, TrimBox = finished trim size
            $mediaW = $mediaBox[2] - $mediaBox[0];
            $mediaH = $mediaBox[3] - $mediaBox[1];
            $trimLeft = $trimBox[0] - $mediaBox[0];
            $trimBottom = $trimBox[1] - $mediaBox[1];
            $trimRight = $mediaBox[2] - $trimBox[2];
            $trimTop = $mediaBox[3] - $trimBox[3];

            // Convert to percentages
            $cropLeft = round(($trimLeft / $mediaW) * 100, 1);
            $cropRight = round(($trimRight / $mediaW) * 100, 1);
            $cropTop = round(($trimTop / $mediaH) * 100, 1);
            $cropBottom = round(($trimBottom / $mediaH) * 100, 1);

            // Convert to mm (1 point = 0.3528mm)
            $trimW = round(($trimBox[2] - $trimBox[0]) * 0.3528);
            $trimH = round(($trimBox[3] - $trimBox[1]) * 0.3528);
            $mediaWmm = round($mediaW * 0.3528);
            $mediaHmm = round($mediaH * 0.3528);

            return [
                'detected' => true,
                'crop_top' => $cropTop,
                'crop_bottom' => $cropBottom,
                'crop_left' => $cropLeft,
                'crop_right' => $cropRight,
                'crop_avg' => round(($cropTop + $cropBottom + $cropLeft + $cropRight) / 4, 1),
                // Per-edge fractions of the MediaBox — the loss-free representation
                // the reader consumes. Never average these into one number.
                'crop_box' => [
                    'left' => round($trimLeft / $mediaW, 4),
                    'top' => round($trimTop / $mediaH, 4),
                    'right' => round($trimRight / $mediaW, 4),
                    'bottom' => round($trimBottom / $mediaH, 4),
                ],
                'trim_width_mm' => $trimW,
                'trim_height_mm' => $trimH,
                'media_width_mm' => $mediaWmm,
                'media_height_mm' => $mediaHmm,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
