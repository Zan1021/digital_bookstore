<?php

namespace App\Jobs;

use App\Assets\AssetStorage;
use App\Intake\IntakePreflight;
use App\Intake\TitleChooser;
use App\Models\Book;
use App\Models\ProcessingJob;
use App\Models\SourcePage;
use App\Scene\PageBatch;
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

    public function handle(PdfService $pdfs, IntakePreflight $preflight): void
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
            // Task 10.2: the security check runs before anything else reads the file (Req 2.3).
            $check = $preflight->run(AssetStorage::disk()->path($book->pdf_path));
            if (! $check['ok']) {
                $this->refuse($book, $row, $check);

                return;
            }
            $notes = $this->savePages($book, $check);
            // Task 10.3: a clean draft title before anything (e.g. the metadata suggestions) uses it.
            $this->chooseTitle($book, $check, $row->details['file'] ?? null);
            $row->update(['progress' => 30, 'details' => array_merge($row->details ?? [], ['notes' => $notes])]);

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

            // Scene Engine stage 2 (task 11.1): read the text blocks of every page, in parallel.
            // Its own batch records page failures; a problem there does not undo a finished intake.
            try {
                app(PageBatch::class)->dispatch($book, 'extract');
            } catch (\Throwable $e) {
                Log::warning("Text extraction could not start for book #{$book->id}: ".$e->getMessage());
            }
        } catch (\Throwable $e) {
            Log::error("IntakeBookJob failed for book #{$book->id}: ".$e->getMessage());
            $book->update(['status' => 'failed']);
            $row->update(['status' => 'failed', 'error_message' => self::plainError($e), 'completed_at' => now()]);
        }
    }

    /** Source pages for a book that was imported before intake made them (task 11.1, existing books). */
    public static function savePagesFromCheck(Book $book): void
    {
        $check = app(IntakePreflight::class)->run(AssetStorage::disk()->path($book->pdf_path));
        if (! $check['ok']) {
            throw new \RuntimeException("Book {$book->id} failed the intake check: ".implode(' ', array_column($check['rejections'], 'message')));
        }
        (new self($book->id))->savePages($book, $check);
    }

    /** Refused by the security check: keep the record and the reason, delete the file (Req 2.3). */
    private function refuse(Book $book, ProcessingJob $row, array $check): void
    {
        AssetStorage::disk()->delete($book->pdf_path);
        $book->update(['status' => 'rejected']);
        $reasons = array_column($check['rejections'], 'message');
        $row->update(['status' => 'rejected', 'progress' => 100, 'completed_at' => now(),
            'error_message' => mb_strimwidth(implode(' ', $reasons), 0, 480, '…'),
            'details' => array_merge($row->details ?? [], ['rejections' => $check['rejections']])]);
        Log::warning("Intake refused book #{$book->id}", ['rejections' => $check['rejections']]);
    }

    /**
     * Req 2.4–2.5: one source page per PDF page with its size, trim box and live-text flag;
     * returns plain notes for the intake list (scanned pages, trims to check, warnings).
     *
     * @return list<string>
     */
    private function savePages(Book $book, array $check): array
    {
        foreach ($check['pages'] as $p) {
            SourcePage::updateOrCreate(['book_id' => $book->id, 'number' => $p['number']], [
                'width_pt' => $p['width_pt'], 'height_pt' => $p['height_pt'],
                'trim_box' => $p['trim_box'], 'has_live_text' => (bool) $p['has_live_text'],
            ]);
        }
        SourcePage::where('book_id', $book->id)->where('number', '>', count($check['pages']))->delete();

        $pages = collect($check['pages']);
        $scanned = $pages->where('kind', 'scanned')->pluck('number')->all();
        $empty = $pages->where('kind', 'no_text')->pluck('number')->all();
        $review = $pages->where('trim_review', true)->pluck('number')->all();
        $book->update(['metadata' => array_merge($book->metadata ?? [], ['intake' => [
            'checked_at' => now()->toIso8601String(),
            'warnings' => $check['warnings'],
            'crop' => $check['crop'],
            'scanned_pages' => $scanned,
            'no_text_pages' => $empty,
            'trim_review_pages' => $review,
        ]])]);

        $list = fn (array $n) => implode(', ', $n);
        $notes = array_column($check['warnings'], 'message');
        if ($scanned) {
            $notes[] = count($scanned).' scanned '.(count($scanned) === 1 ? 'page' : 'pages').' ('.$list($scanned).'): the text will be read from the image.';
        }
        if ($empty) {
            $notes[] = count($empty).' '.(count($empty) === 1 ? 'page has' : 'pages have').' no text ('.$list($empty).').';
        }
        if ($review) {
            $notes[] = 'Check where '.(count($review) === 1 ? 'page '.$review[0].' is' : 'pages '.$list($review).' are').' trimmed: the crop marks are faint.';
        }

        return $notes;
    }

    /**
     * Req 2.7: replace the file-name title with one from the PDF title, cover and file name —
     * only while the title is still the automatic one, so a title someone typed is never lost.
     */
    private function chooseTitle(Book $book, array $check, ?string $fileName): void
    {
        if ($fileName === null || $book->title !== PdfService::fileNameTitle($fileName)) {
            return;
        }
        $choice = app(TitleChooser::class)->choose($check['meta']['title'] ?? null, $check['cover_lines'] ?? [], $fileName);
        $book->update([
            'title' => $choice['title'],
            'metadata' => array_merge($book->metadata ?? [], ['intake' => array_merge($book->metadata['intake'] ?? [], [
                'title' => ['chosen' => $choice['title'], 'source' => $choice['source'], 'file_name' => $fileName],
            ])]),
        ]);
    }

    /** A short reason a publisher can act on, not a stack trace. */
    private static function plainError(\Throwable $e): string
    {
        $message = trim(strtok($e->getMessage(), "\n") ?: get_class($e));

        return mb_strimwidth($message, 0, 240, '…');
    }
}
