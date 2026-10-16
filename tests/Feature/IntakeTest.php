<?php

namespace Tests\Feature;

use App\Jobs\IntakeBookJob;
use App\Livewire\Admin\Intake;
use App\Models\Book;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\PdfService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\ActsAsSuperAdmin;
use Tests\TestCase;

/** Task 10.1 (Req 2.1, 2.2, 2.8): one intake screen; processing in the background. */
class IntakeTest extends TestCase
{
    use ActsAsSuperAdmin, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('assets.disk'));
    }

    private function pdf(string $name = 'Play with me.pdf', int $kb = 20): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    public function test_a_finished_upload_is_stored_and_queued_before_processing(): void
    {
        Queue::fake();

        $result = Livewire::test(Intake::class)->set('upload', $this->pdf())->call('finishUpload', 'Play with me.pdf')->effects['returns'][0];

        $this->assertTrue($result['ok']);
        $book = Book::findOrFail($result['book_id']);
        $this->assertSame(['queued', 0], [$book->status, $book->page_count], 'the record exists before any processing (Req 2.2)');
        $this->assertTrue(Storage::disk(config('assets.disk'))->exists($book->pdf_path), 'original stored privately');
        $row = ProcessingJob::where('book_id', $book->id)->where('type', 'intake')->sole();
        $this->assertSame(['queued', 'Play with me.pdf'], [$row->status, $row->details['file']]);
        Queue::assertPushedOn('intake', IntakeBookJob::class, fn ($job) => $job->bookId === $book->id);
    }

    public function test_many_files_each_get_their_own_book(): void
    {
        Queue::fake();
        $page = Livewire::test(Intake::class);
        foreach (['Book one.pdf', 'Book two.pdf', 'Book three.pdf'] as $name) {
            $page->set('upload', $this->pdf($name))->call('finishUpload', $name);
        }

        $this->assertSame(3, Book::count());
        Queue::assertPushed(IntakeBookJob::class, 3);
    }

    public function test_wrong_files_are_refused_with_a_reason(): void
    {
        Queue::fake();
        config(['assets.max_upload_kb' => 50]);

        $notPdf = Livewire::test(Intake::class)->set('upload', UploadedFile::fake()->create('cover.jpg', 10, 'image/jpeg'))
            ->call('finishUpload', 'cover.jpg')->effects['returns'][0];
        $tooBig = Livewire::test(Intake::class)->set('upload', $this->pdf('Huge.pdf', 60))
            ->call('finishUpload', 'Huge.pdf')->effects['returns'][0];

        $this->assertFalse($notPdf['ok']);
        $this->assertStringStartsWith('Only PDF files', $notPdf['error']);
        $this->assertSame(['ok' => false, 'error' => 'The file is larger than the upload limit.'], $tooBig);
        $this->assertSame(0, Book::count());
        Queue::assertNothingPushed();
    }

    private function fakePdfService(?\Throwable $fail = null): void
    {
        $this->app->instance(PdfService::class, new class($fail) extends PdfService
        {
            public function __construct(private ?\Throwable $fail) {}

            public function processBook(Book $book): Book
            {
                if ($this->fail) {
                    throw $this->fail;
                }
                $book->update(['page_count' => 16, 'status' => 'ready']);

                return $book;
            }

            public function detectCropMarks(string $pdfPath): ?array
            {
                return ['detected' => true, 'crop_avg' => 4.2, 'crop_box' => [9, 9, 500, 713]];
            }
        });
    }

    private function storedBook(): Book
    {
        $book = app(PdfService::class)->storeIntake($this->pdf());
        IntakeBookJob::row($book->id)->update(['details' => ['file' => 'Play with me.pdf']]);

        return $book;
    }

    public function test_the_job_prepares_the_book_and_reports_progress(): void
    {
        $this->fakePdfService();
        $book = $this->storedBook();

        dispatch_sync(new IntakeBookJob($book->id));

        $book->refresh();
        $this->assertSame(['draft', 16, true, 5, 3, 14], [$book->status, $book->page_count, $book->crop_enabled, $book->crop_percent,
            $book->narration_start_page, $book->narration_end_page]);
        $row = IntakeBookJob::row($book->id);
        $this->assertSame(['completed', 100], [$row->status, $row->progress]);
        $this->assertNotNull($row->completed_at);
        $this->assertSame('Play with me.pdf', $row->details['file'], 'the original file name is kept for the list');
    }

    public function test_a_failure_is_shown_plainly_and_can_be_retried(): void
    {
        $this->fakePdfService(new \RuntimeException("Unable to parse the PDF\n#0 stack trace line\n#1 more"));
        $book = $this->storedBook();

        dispatch_sync(new IntakeBookJob($book->id)); // must not throw: the screen shows the failure

        $row = IntakeBookJob::row($book->id);
        $this->assertSame(['failed', 'Unable to parse the PDF'], [$row->status, $row->error_message]);
        $this->assertSame('failed', $book->refresh()->status);

        Queue::fake();
        Livewire::test(Intake::class)->assertSee('Unable to parse the PDF')->assertSee('Retry')->call('retry', $book->id);
        $this->assertSame(['queued', null], [$row->fresh()->status, $row->fresh()->error_message]);
        Queue::assertPushed(IntakeBookJob::class, fn ($job) => $job->bookId === $book->id);
    }

    public function test_retry_ignores_books_that_did_not_fail_and_remove_keeps_the_book(): void
    {
        Queue::fake();
        $book = $this->storedBook();
        IntakeBookJob::row($book->id)->update(['status' => 'processing']);

        $page = Livewire::test(Intake::class)->call('retry', $book->id)->call('dismiss', $book->id);
        Queue::assertNothingPushed();
        $this->assertSame(1, ProcessingJob::count(), 'a running intake cannot be removed from the list');

        IntakeBookJob::row($book->id)->update(['status' => 'completed']);
        $page->call('dismiss', $book->id);
        $this->assertSame(0, ProcessingJob::count());
        $this->assertTrue(Book::whereKey($book->id)->exists(), 'removing from the list keeps the book');
    }

    public function test_the_list_survives_leaving_the_page_and_refreshes_only_while_busy(): void
    {
        Queue::fake();
        Livewire::test(Intake::class)->set('upload', $this->pdf())->call('finishUpload', 'Play with me.pdf');

        // A new visit (a fresh component) still shows the upload and keeps polling.
        $html = Livewire::test(Intake::class)->assertSee('Play with me')->assertSee('Waiting')->html();
        $this->assertStringContainsString('wire:poll', $html);

        ProcessingJob::query()->update(['status' => 'completed', 'progress' => 100]);
        $html = Livewire::test(Intake::class)->assertSee('Ready')->assertSee('Open book')->html();
        $this->assertStringNotContainsString('wire:poll', $html, 'nothing running, nothing to refresh');
    }

    public function test_old_upload_links_land_on_the_intake_screen(): void
    {
        $this->get(route('admin.intake'))->assertOk()->assertSee('Add books')->assertSee('Drop PDF files here');
        $this->get('/admin/upload')->assertRedirect(route('admin.intake'));
        $this->get('/admin/onboard')->assertRedirect(route('admin.intake'));
    }

    public function test_only_staff_who_manage_books_can_add_books(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $reviewer = User::factory()->create();
        $reviewer->assignRole('Language Reviewer');

        $this->actingAs($reviewer)->get(route('admin.intake'))->assertForbidden();
        $this->actingAs($reviewer)->get(route('admin.dashboard'))->assertDontSee('Add Books');
    }
}
