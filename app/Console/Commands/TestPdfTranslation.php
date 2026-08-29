<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\PdfTranslationService;
use Illuminate\Console\Command;

class TestPdfTranslation extends Command
{
    protected $signature = 'pdf:test-translation 
                            {book? : Book ID (default: first book)}
                            {--language=af : Target language code}
                            {--check : Only check dependencies, do not translate}
                            {--extract : Only extract metadata from the PDF}';

    protected $description = 'Test the PyMuPDF-based PDF translation pipeline';

    public function handle(PdfTranslationService $service): int
    {
        // Check dependencies first
        $this->info('Checking dependencies...');
        $deps = $service->checkDependencies();

        if ($this->option('check')) {
            if ($deps['ready']) {
                $this->info('✓ All dependencies satisfied');
                $this->line("  Python: {$deps['python_version']}");
                $this->line("  Fonts available: {$deps['fonts_available']}");

                $fonts = $service->getAvailableFonts();
                foreach ($fonts as $font) {
                    $size = round($font['size'] / 1024);
                    $this->line("    - {$font['name']} ({$size}KB)");
                }
            } else {
                $this->error('Dependencies not satisfied:');
                foreach ($deps['issues'] as $issue) {
                    $this->line("  ✗ {$issue}");
                }
                return Command::FAILURE;
            }
            return Command::SUCCESS;
        }

        if (!$deps['ready']) {
            $this->error('Dependencies not ready:');
            foreach ($deps['issues'] as $issue) {
                $this->line("  ✗ {$issue}");
            }
            return Command::FAILURE;
        }

        $this->info("✓ Dependencies OK (Python {$deps['python_version']}, {$deps['fonts_available']} fonts)");

        // Get the book
        $bookId = $this->argument('book');
        $book = $bookId ? Book::findOrFail($bookId) : Book::first();

        if (!$book) {
            $this->error('No books found in database');
            return Command::FAILURE;
        }

        $this->info("Book: {$book->title} (ID: {$book->id}, {$book->page_count} pages)");

        // Extract mode
        if ($this->option('extract')) {
            $this->info('Extracting text metadata...');
            $metadata = $service->extractMetadata($book);

            $this->info("Extracted metadata for {$metadata['page_count']} pages:");
            foreach ($metadata['pages'] as $page) {
                $spanCount = 0;
                $fonts = [];
                foreach ($page['text_blocks'] as $block) {
                    foreach ($block['lines'] as $line) {
                        foreach ($line['spans'] as $span) {
                            $spanCount++;
                            $fonts[$span['font']] = $span['size'];
                        }
                    }
                }
                $fontStr = implode(', ', array_map(
                    fn($f, $s) => "{$f}@{$s}pt",
                    array_keys($fonts),
                    array_values($fonts)
                ));
                $this->line("  Page {$page['page_number']}: {$spanCount} spans [{$fontStr}]");
            }

            return Command::SUCCESS;
        }

        // Translation mode
        $langCode = $this->option('language');
        $this->info("Target language: {$langCode}");

        // Check if translation exists
        $translation = $book->translations()->where('language_code', $langCode)->first();

        if (!$translation) {
            $this->error("No translation found for language '{$langCode}'. Run translation first.");
            $this->line('  Available translations:');
            foreach ($book->translations as $t) {
                $this->line("    - {$t->language_code} ({$t->language_name}) — {$t->status}");
            }
            return Command::FAILURE;
        }

        $pageCount = $translation->translatedPages()->count();
        $this->info("Found translation: {$translation->language_name} ({$pageCount} translated pages)");

        // Run the translation
        $this->info('Running PyMuPDF text replacement...');
        $startTime = microtime(true);

        try {
            $outputPath = $service->createTranslatedPdf($book, $translation);
            $elapsed = round(microtime(true) - $startTime, 2);

            $this->newLine();
            $this->info("✓ Translation complete in {$elapsed}s");
            $this->line("  Output: storage/app/public/{$outputPath}");

            $fullPath = storage_path("app/public/{$outputPath}");
            if (file_exists($fullPath)) {
                $size = round(filesize($fullPath) / 1024 / 1024, 2);
                $this->line("  File size: {$size}MB");
            }
        } catch (\Throwable $e) {
            $this->error("Translation failed: " . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
