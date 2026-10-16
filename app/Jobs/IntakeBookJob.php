<?php

namespace App\Jobs;

use App\Models\Book;
use App\Models\ProcessingJob;
use App\Services\PdfService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Processes one uploaded book in the background (task 10.1, Req 2.2, 2.8, 3.1): text and
 * page count, cover, manifest, fonts, crop marks, defaults. The book record and the stored
 * original already exist (PdfService::storeIntake). Progress, status and errors go to the
 * book's 'intake' ProcessingJob row, which the intake screen shows; a failed book can be
 * retried from there. No paid AI runs here.
 */
class IntakeBookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;       // a failure is shown with a Retry button, not retried blindly
    public int $timeout = 600;

    public function __construct(public int $bookId)
    {
        $this->onQueue('intake');
    }

    public static function row(int $bookId): ProcessingJob
    {
        return ProcessingJob::firstOrCreate(['book_id' => $bookId, 'type' => 'intake'], ['status' => 'queued', 'progress' => 0]);
    }

    public function handle(PdfService $pdfs): void
    {
        $book = Book::find($this->bookId);
        if (! $book) {
            return;
        }
        // The PHP PDF text parser holds whole files in memory; big picture books exceed 128M.
        ini_set('memory_limit', '1024M');

        $row = self::row($book->id);
        $row->update(['status' => 'processing', 'progress' => 10, 'started_at' => now(),
            'error_message' => null, 'completed_at' => null]);

        try {
            $pdfs->processBook($book);
            $row->update(['progress' => 80]);

            // Crop marks and defaults, as the old upload wizard applied them.
            $crop = $pdfs->detectCropMarks($book->pdf_path);
            $book->refresh()->update([
                'crop_enabled' => (bool) ($crop['detected'] ?? false),
                'crop_percent' => ($crop['detected'] ?? false) ? (int) ceil($crop['crop_avg'] ?? 0) : 0,
                'crop_box' => $crop['crop_box'] ?? null,
                'narration_start_page' => 3,
                'narration_end_page' => max(3, $book->page_count - 2),
                'status' => 'draft',
            ]);

            $row->update(['status' => 'completed', 'progress' => 100, 'completed_at' => now(),
                'details' => array_merge($row->details ?? [], ['title' => $book->title, 'pages' => $book->page_count])]);
        } catch (\Throwable $e) {
            Log::error("IntakeBookJob failed for book #{$book->id}: ".$e->getMessage());
            $book->update(['status' => 'failed']);
            $row->update(['status' => 'failed', 'error_message' => self::plainError($e), 'completed_at' => now()]);
        }
    }

    /** A short reason a publisher can act on, not a stack trace. */
    private static function plainError(\Throwable $e): string
    {
        $message = trim(strtok($e->getMessage(), "\n") ?: get_class($e));

        return mb_strimwidth($message, 0, 240, '…');
    }
}
