<?php

namespace App\Livewire\Admin;

use App\Jobs\IntakeBookJob;
use App\Livewire\Attributes\Can;
use App\Models\ProcessingJob;
use App\Services\PdfService;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Book intake (task 10.1, Req 2.1, 2.2, 2.8): one screen for one or many PDFs, replacing
 * "Upload Books" and "Book Onboarding". The browser sends files one at a time (per-file
 * progress); each finished file is stored privately, gets its book record at once, and is
 * processed by IntakeBookJob in the background, so the publisher can leave and come back.
 * Translation, narration and fonts are chosen later on the book's page (Req 1.3).
 */
class Intake extends Component
{
    use WithFileUploads;

    /** The file currently being sent by the browser (one at a time). */
    public $upload;

    /** How many recent intakes the list shows. */
    public const LIST_SIZE = 50;

    /**
     * Called by the browser after a file finished uploading: validate, store, queue.
     *
     * @return array{ok: bool, book_id?: int, title?: string, error?: string}
     */
    #[Can('books.manage')]
    public function finishUpload(string $clientName, bool $addAnyway = false): array
    {
        $validator = Validator::make(['upload' => $this->upload], [
            'upload' => ['required', 'file', 'mimes:pdf', 'max:'.(int) config('assets.max_upload_kb', 102400)],
        ], [
            // A file the upload layer already refused arrives as "no file".
            'upload.required' => 'Only PDF files up to '.(int) round(config('assets.max_upload_kb', 102400) / 1024).' MB can be uploaded.',
            'upload.mimes' => 'Only PDF files can be uploaded.',
            'upload.max' => 'The file is larger than the upload limit.',
        ]);

        if ($validator->fails()) {
            $this->reset('upload');

            return ['ok' => false, 'error' => $validator->errors()->first('upload')];
        }

        // Req 2.6: the same file again is a warning with a choice, not a silent second copy.
        if (! $addAnyway && ($existing = PdfService::findDuplicate(PdfService::checksum($this->upload)))) {
            $this->reset('upload');

            return ['ok' => false, 'duplicate' => true, 'existing_id' => $existing->id,
                'existing_url' => route('admin.book', $existing), 'existing_title' => $existing->title,
                'error' => "This file is already in your books as \u{201C}{$existing->title}\u{201D}."];
        }

        $book = app(PdfService::class)->storeIntake($this->upload);
        IntakeBookJob::row($book->id)->update(['details' => ['file' => $clientName]]);
        IntakeBookJob::dispatch($book->id);
        $this->reset('upload');

        return ['ok' => true, 'book_id' => $book->id, 'title' => $book->title];
    }

    /** Run a failed intake again. */
    #[Can('books.manage')]
    public function retry(int $bookId): void
    {
        $row = ProcessingJob::where('type', 'intake')->where('book_id', $bookId)->where('status', 'failed')->first();
        if (! $row) {
            return;
        }
        $row->update(['status' => 'queued', 'progress' => 0, 'error_message' => null, 'completed_at' => null]);
        IntakeBookJob::dispatch($bookId);
    }

    /**
     * Remove a finished entry from the list. A ready or failed book stays; a refused upload
     * (its file already deleted by the security check) is removed with its book record.
     */
    #[Can('books.manage')]
    public function dismiss(int $bookId): void
    {
        $row = ProcessingJob::with('book')->where('type', 'intake')->where('book_id', $bookId)
            ->whereIn('status', ['completed', 'failed', 'rejected'])->first();
        if (! $row) {
            return;
        }
        if ($row->status === 'rejected' && $row->book?->status === 'rejected') {
            $row->book->delete();
        }
        $row->delete();
    }

    public function render()
    {
        $rows = ProcessingJob::with('book:id,title,page_count,status')
            ->where('type', 'intake')->latest('id')->limit(self::LIST_SIZE)->get();

        return view('livewire.admin.intake', [
            'rows' => $rows,
            'busy' => $rows->contains(fn ($r) => in_array($r->status, ['queued', 'processing'], true)),
            'maxMb' => (int) round(config('assets.max_upload_kb', 102400) / 1024),
        ])->layout('layouts.admin');
    }
}
