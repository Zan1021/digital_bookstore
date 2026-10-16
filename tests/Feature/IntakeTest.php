<?php

namespace Tests\Feature;

use App\Intake\IntakePreflight;
use App\Jobs\IntakeBookJob;
use App\Livewire\Admin\Intake;
use App\Models\Book;
use App\Models\ProcessingJob;
use App\Models\SourcePage;
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
            // Different contents: identical files would (rightly) be caught as duplicates.
            $page->set('upload', UploadedFile::fake()->createWithContent($name, "%PDF-1.7 {$name}"))->call('finishUpload', $name);
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

    /** Stand-in for scripts/intake_preflight.py (its own Python tests cover the real checks). */
    private function fakePreflight(array $result): void
    {
        $this->app->instance(IntakePreflight::class, new class($result) extends IntakePreflight
        {
            public function __construct(private array $result) {}

            public function run(string $absolutePath): array
            {
                return $this->result + ['ok' => true, 'rejections' => [], 'warnings' => [], 'page_count' => 0, 'pages' => [], 'crop' => null];
            }
        });
    }

    private static function page(int $n, string $kind = 'live_text', bool $review = false): array
    {
        return ['number' => $n, 'width_pt' => 538.06, 'height_pt' => 750.66, 'trim_box' => [35.2, 35.2, 502.9, 715.5],
            'trim_source' => 'trim_box_metadata', 'trim_review' => $review, 'has_live_text' => $kind === 'live_text', 'kind' => $kind, 'text_chars' => 40];
    }

    private function fakePdfService(?\Throwable $fail = null): void
    {
        $this->fakePreflight(['pages' => [self::page(1), self::page(2)], 'page_count' => 2]);
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

    public function test_the_same_file_again_is_a_warning_with_a_choice(): void
    {
        Queue::fake();
        $file = fn () => UploadedFile::fake()->createWithContent('Play with me.pdf', '%PDF-1.7 same bytes');
        $first = Livewire::test(Intake::class)->set('upload', $file())->call('finishUpload', 'Play with me.pdf')->effects['returns'][0];
        $book = Book::findOrFail($first['book_id']);
        $this->assertSame(hash('sha256', '%PDF-1.7 same bytes'), $book->source_checksum, 'checksum recorded (Req 2.2)');

        $again = Livewire::test(Intake::class)->set('upload', $file())->call('finishUpload', 'Copy of Play with me.pdf')->effects['returns'][0];
        $this->assertFalse($again['ok']);
        $this->assertTrue($again['duplicate']);
        $this->assertSame([$book->id, route('admin.book', $book)], [$again['existing_id'], $again['existing_url']]);
        $this->assertStringContainsString('already in your books', $again['error']);
        $this->assertSame(1, Book::count(), 'no second copy without the publisher saying so');

        $anyway = Livewire::test(Intake::class)->set('upload', $file())->call('finishUpload', 'Copy of Play with me.pdf', true)->effects['returns'][0];
        $this->assertTrue($anyway['ok']);
        $this->assertSame(2, Book::count());
    }

    public function test_a_refused_upload_is_not_a_duplicate(): void
    {
        Queue::fake();
        $file = fn () => UploadedFile::fake()->createWithContent('Bad.pdf', '%PDF-1.7 refused bytes');
        $first = Livewire::test(Intake::class)->set('upload', $file())->call('finishUpload', 'Bad.pdf')->effects['returns'][0];
        Book::whereKey($first['book_id'])->update(['status' => 'rejected']);

        $this->assertTrue(Livewire::test(Intake::class)->set('upload', $file())->call('finishUpload', 'Bad.pdf')->effects['returns'][0]['ok']);
    }

    public function test_an_unsafe_file_is_refused_with_its_reason_and_not_kept(): void
    {
        $this->fakePreflight(['ok' => false, 'rejections' => [
            ['code' => 'javascript', 'message' => 'The PDF contains JavaScript, which is not allowed.'],
            ['code' => 'attachments', 'message' => 'The PDF contains 1 attached file(s). Remove the attachments and upload again.'],
        ]]);
        $spy = new class extends PdfService
        {
            public bool $processed = false;

            public function processBook(Book $book): Book
            {
                $this->processed = true;

                return $book;
            }
        };
        $this->app->instance(PdfService::class, $spy);
        $book = app(PdfService::class)->storeIntake($this->pdf());
        IntakeBookJob::row($book->id);

        dispatch_sync(new IntakeBookJob($book->id));

        $this->assertFalse($spy->processed, 'nothing else reads a refused file');
        $this->assertFalse(Storage::disk(config('assets.disk'))->exists($book->pdf_path), 'the unsafe file is deleted');
        $this->assertSame('rejected', $book->refresh()->status);
        $row = IntakeBookJob::row($book->id);
        $this->assertSame('rejected', $row->status);
        $this->assertStringContainsString('JavaScript', $row->error_message);
        $this->assertSame(['javascript', 'attachments'], array_column($row->details['rejections'], 'code'));

        Livewire::test(Intake::class)
            ->assertSee('Refused')->assertSee('which is not allowed')->assertSee('The file was not kept')->assertDontSee('Retry')
            ->call('retry', $book->id)
            ->call('dismiss', $book->id);
        $this->assertFalse(Book::whereKey($book->id)->exists(), 'removing a refused upload removes its empty record');
    }

    public function test_page_facts_are_saved_and_explained(): void
    {
        $this->fakePdfService();
        $this->fakePreflight(['page_count' => 4, 'warnings' => [['code' => 'restricted', 'message' => 'The PDF has usage restrictions; it can still be processed.']],
            'crop' => ['has_crop_marks' => true, 'consensus_trim_box' => [35.2, 35.2, 502.9, 715.5], 'confidence' => 0.9],
            'pages' => [self::page(1, 'live_text', true), self::page(2, 'scanned'), self::page(3, 'no_text'), self::page(4, 'scanned')]]);
        $book = $this->storedBook();
        SourcePage::create(['book_id' => $book->id, 'number' => 9, 'width_pt' => 1, 'height_pt' => 1]); // from an older, longer file

        dispatch_sync(new IntakeBookJob($book->id));

        $pages = $book->sourcePages()->get();
        $this->assertSame([1, 2, 3, 4], $pages->pluck('number')->all(), 'one source page per PDF page; leftovers removed');
        $this->assertSame([true, false, false, false], $pages->pluck('has_live_text')->all());
        $this->assertSame([35.2, 35.2, 502.9, 715.5], $pages[0]->trim_box);
        $this->assertSame(538.06, $pages[0]->width_pt);
        $intake = $book->refresh()->metadata['intake'];
        $this->assertSame([[2, 4], [3], [1]], [$intake['scanned_pages'], $intake['no_text_pages'], $intake['trim_review_pages']]);

        Livewire::test(Intake::class)->assertSee('Ready')
            ->assertSee('2 scanned pages (2, 4): the text will be read from the image.')
            ->assertSee('1 page has no text (3).')
            ->assertSee('Check where page 1 is trimmed: the crop marks are faint.')
            ->assertSee('usage restrictions');
    }

    public function test_intake_sets_a_clean_title_before_the_metadata_suggestions_run(): void
    {
        $this->fakePdfService();
        $this->fakePreflight(['pages' => [self::page(1)], 'meta' => ['title' => ''],
            'cover_lines' => [['text' => "Kolulu\u{FFFD}s Home", 'size' => 49.0, 'y' => 400], ['text' => 'MTHOMBOTHI STUDIOS', 'size' => 16.8, 'y' => 700]]]);
        $book = app(PdfService::class)->storeIntake(UploadedFile::fake()->createWithContent("Kolulu Engl Series 3 - 1 - Kolulu's Home.pdf", '%PDF-1.7 a'));
        IntakeBookJob::row($book->id)->update(['details' => ['file' => "Kolulu Engl Series 3 - 1 - Kolulu's Home.pdf"]]);
        $this->assertSame("Kolulu Engl Series 3 - 1 - Kolulu's Home", $book->title, 'file-name title at upload');

        dispatch_sync(new IntakeBookJob($book->id));

        $book->refresh();
        $this->assertSame("Kolulu's Home", $book->title);
        $this->assertSame(['chosen' => "Kolulu's Home", 'source' => 'cover+file_name', 'file_name' => "Kolulu Engl Series 3 - 1 - Kolulu's Home.pdf"],
            $book->metadata['intake']['title']);
    }

    public function test_a_title_someone_typed_is_never_replaced(): void
    {
        $this->fakePdfService();
        $this->fakePreflight(['pages' => [self::page(1)], 'cover_lines' => [['text' => 'Play with me', 'size' => 54.0, 'y' => 400]]]);
        $book = app(PdfService::class)->storeIntake(UploadedFile::fake()->createWithContent('Kolulu S2 BK2 Engl - Play with me.pdf', '%PDF-1.7 b'));
        IntakeBookJob::row($book->id)->update(['details' => ['file' => 'Kolulu S2 BK2 Engl - Play with me.pdf']]);
        $book->update(['title' => 'Kolulu: Play With Me!']);

        dispatch_sync(new IntakeBookJob($book->id));

        $this->assertSame('Kolulu: Play With Me!', $book->refresh()->title);
    }

    public function test_clean_titles_for_existing_books_are_only_proposed_until_applied(): void
    {
        $this->fakePreflight(['cover_lines' => [['text' => 'Play with me', 'size' => 54.0, 'y' => 400]]]);
        Storage::disk(config('assets.disk'))->put('books/pdfs/p.pdf', '%PDF-1.7 p');
        $book = Book::create(['title' => 'Kolulu S2 BK2 Engl - Play with me', 'pdf_path' => 'books/pdfs/p.pdf', 'page_count' => 16]);

        $this->artisan('books:clean-titles')->expectsOutputToContain('Run again with --apply')->assertSuccessful();
        $this->assertSame('Kolulu S2 BK2 Engl - Play with me', $book->refresh()->title);

        $this->artisan('books:clean-titles', ['--apply' => true])->expectsOutputToContain('Titles changed: 1.')->assertSuccessful();
        $this->assertSame('Play with me', $book->refresh()->title);
    }

    public function test_the_checksum_backfill_fills_only_what_is_missing(): void
    {
        $disk = Storage::disk(config('assets.disk'));
        $disk->put('books/pdfs/old.pdf', '%PDF-1.4 old book');
        $old = Book::create(['title' => 'Old', 'pdf_path' => 'books/pdfs/old.pdf', 'page_count' => 1]);
        $gone = Book::create(['title' => 'Gone', 'pdf_path' => 'books/pdfs/gone.pdf', 'page_count' => 1]);

        $this->artisan('books:backfill-checksums')->expectsOutputToContain('Checksums recorded: 1; originals not found: 1.')->assertSuccessful();

        $this->assertSame(hash('sha256', '%PDF-1.4 old book'), $old->refresh()->source_checksum);
        $this->assertNull($gone->refresh()->source_checksum);
    }
}
