<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\TranslationService;
use App\Services\PdfTranslationService;
use Illuminate\Console\Command;

class TranscreateBook extends Command
{
    protected $signature = 'book:transcreate 
                            {book? : Book ID (default: first book)}
                            {--language=af : Target language code}
                            {--pdf : Also generate the translated PDF after translation}
                            {--report : Show quality report for existing translation}';

    protected $description = 'Translate a book using the 3-step transcreation pipeline (creative translation + back-translation + quality scoring)';

    public function handle(TranslationService $translationService, PdfTranslationService $pdfService): int
    {
        $bookId = $this->argument('book');
        $book = $bookId ? Book::findOrFail($bookId) : Book::first();

        if (!$book) {
            $this->error('No books found.');
            return Command::FAILURE;
        }

        $langCode = $this->option('language');
        $langName = TranslationService::SUPPORTED_LANGUAGES[$langCode] ?? $langCode;

        $this->info("Book: {$book->title} (ID: {$book->id})");
        $this->info("Target: {$langName} ({$langCode})");

        // Report mode
        if ($this->option('report')) {
            return $this->showReport($book, $langCode);
        }

        // Translation
        $this->info('');
        $this->info('Starting 3-step transcreation pipeline...');
        $this->info('  Step 1: Creative translation (transcreation)');
        $this->info('  Step 2: Back-translation (verification)');
        $this->info('  Step 3: Quality scoring + auto-refinement');
        $this->info('');

        $startTime = microtime(true);

        $translation = $translationService->translate($book, $langCode);

        $elapsed = round(microtime(true) - $startTime, 1);
        $this->info("Transcreation complete in {$elapsed}s");
        $this->newLine();

        // Show quality summary
        $this->showReport($book, $langCode);

        // Generate PDF if requested
        if ($this->option('pdf')) {
            $this->newLine();
            $this->info('Generating translated PDF...');
            try {
                $path = $pdfService->createTranslatedPdf($book, $translation);
                $this->info("PDF saved: storage/app/public/{$path}");
            } catch (\Throwable $e) {
                $this->error("PDF generation failed: " . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }

    private function showReport(Book $book, string $langCode): int
    {
        $translation = $book->translations()->where('language_code', $langCode)->first();

        if (!$translation) {
            $this->error("No translation found for '{$langCode}'");
            return Command::FAILURE;
        }

        $pages = $translation->translatedPages()->orderBy('page_number')->get();

        $this->info('=== QUALITY REPORT ===');
        $this->newLine();

        $green = 0;
        $yellow = 0;
        $red = 0;
        $totalScore = 0;
        $scored = 0;

        $headers = ['Page', 'Score', 'Flag', 'Status', 'Notes'];
        $rows = [];

        foreach ($pages as $page) {
            $score = $page->confidence_score;
            $flag = $page->quality_flag;
            $status = $page->review_status;
            $notes = $page->quality_notes ? substr($page->quality_notes, 0, 50) : '-';

            if ($flag === 'green') $green++;
            elseif ($flag === 'yellow') $yellow++;
            elseif ($flag === 'red') $red++;

            if ($score) {
                $totalScore += $score;
                $scored++;
            }

            $flagIcon = match ($flag) {
                'green' => '[OK]',
                'yellow' => '[!!]',
                'red' => '[XX]',
                default => '[--]',
            };

            $rows[] = [
                $page->page_number,
                $score ? number_format($score, 1) : '-',
                $flagIcon,
                $status,
                $notes,
            ];
        }

        $this->table($headers, $rows);
        $this->newLine();

        $avgScore = $scored > 0 ? round($totalScore / $scored, 1) : 0;
        $this->info("Average confidence: {$avgScore}/10");
        $this->info("Pages: {$green} green, {$yellow} yellow, {$red} red");
        $this->info("Reviewer workload: " . ($yellow + $red) . " pages need attention (out of " . count($pages) . ")");

        return Command::SUCCESS;
    }
}
