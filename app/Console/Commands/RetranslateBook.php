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
            $translation = $service->translate($book, $language);

            $this->info("✓ Translation complete! ID: {$translation->id}, Status: {$translation->status}");
            $this->info("Now re-rendering PDF...");

            // Trigger PDF render
            $pdfService = app(\App\Services\PdfTranslationService::class);
            $pdfService->renderTranslatedPdf($translation);

            $this->info("✓ PDF rendered successfully.");
            $this->info("URL: http://127.0.0.1:8001/storage/books/translated/{$bookId}_{$language}.pdf");

            return 0;
        } catch (\Exception $e) {
            $this->error("Translation failed: " . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }
}
