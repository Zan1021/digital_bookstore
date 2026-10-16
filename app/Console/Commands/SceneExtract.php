<?php

namespace App\Console\Commands;

use App\Jobs\IntakeBookJob;
use App\Models\Book;
use App\Scene\PageBatch;
use Illuminate\Console\Command;

/**
 * Task 11.1: run text extraction for books that already exist (new uploads are extracted after
 * intake). Books without source pages get them from the intake check first.
 */
class SceneExtract extends Command
{
    protected $signature = 'scene:extract {book* : Book ids} {--force : Extract every page even if nothing changed}';

    protected $description = 'Queue Scene Engine text extraction (one job per page) for the given books';

    public function handle(PageBatch $batches): int
    {
        foreach (Book::whereIn('id', $this->argument('book'))->get() as $book) {
            if ($book->sourcePages()->doesntExist()) {
                IntakeBookJob::savePagesFromCheck($book);
            }
            if ($this->option('force')) {
                foreach ($book->sourcePages()->get() as $page) {
                    app(\App\Scene\PageStages::class)->invalidate($page, 'extract');
                }
            }
            $batch = $batches->dispatch($book, 'extract');
            $this->line("Book {$book->id} \u{201C}{$book->title}\u{201D}: {$batch->totalJobs} pages queued (batch {$batch->id}).");
        }

        return self::SUCCESS;
    }
}
