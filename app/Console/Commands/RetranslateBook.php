<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\TranslationService;
use Illuminate\Console\Command;

class RetranslateBook extends Command
{
    protected $signature = 'book:retranslate {book_id} {language=af}';
    protected $description = 'Re-translate a book using the improved V7 prompts';

    public function handle(): int
    {
        $bookId = $this->argument('book_id');
        $language = $this->argument('language');

        $book = Book::find($bookId);
        if (!$book) {
            $this->error("Book #{$bookId} not found.");
            return 1;
        }

        $this->info("Re-translating: {$book->title} → {$language}");
        $this->info("Using improved prompts with context, glossary, and translation memory.");

        try {
            $service = new TranslationService();
            // Use the manifest-driven path so per-id translations are persisted
            // (item_translations / fix B). Falls back to legacy translate() automatically
            // if the book has no manifest.
            $translation = $service->translateWithManifest($book, $language);

            $this->info("✓ Translation complete! ID: {$translation->id}, Status: {$translation->status}");
            $this->info("Now re-rendering PDF...");

            // Trigger PDF render (createTranslatedPdf takes the book + translation)
            $pdfService = app(\App\Services\PdfTranslationService::class);
            $outputPath = $pdfService->createTranslatedPdf($book, $translation);

            // Persist the render result so admin surfaces (review queue, book manager,
            // reader route) resolve the FRESH pdf. Without this, rendered_pdf_path/status
            // stay stale and the review queue shows an old render. Fail-closed (§13):
            // only mark rendered when layout QA passed.
            $translation->refresh();
            $translation->update([
                'rendered_pdf_path' => $outputPath,
                'status' => $translation->isPublishable() ? 'rendered' : 'needs_review',
            ]);

            $this->info("✓ PDF rendered successfully.");
            $this->info("Render status: {$translation->render_status} · edition status: {$translation->status}");
            $this->info("URL: http://127.0.0.1:8000/read/{$bookId}?lang={$language}");

            return 0;
        } catch (\Exception $e) {
            $this->error("Translation failed: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }
}
