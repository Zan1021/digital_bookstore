<?php

namespace Tests\Feature;

use App\Jobs\Middleware\PageConcurrency;
use App\Jobs\RunPageStage;
use App\Models\Book;
use App\Models\JobRun;
use App\Models\SourcePage;
use App\Models\Translation;
use App\Scene\Contracts\PageStageHandler;
use App\Scene\PageBatch;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** A stand-in stage: records what it did; can be told to fail on one page. */
class RecordingStage implements PageStageHandler
{
    public static array $done = [];

    public static ?int $failOn = null;

    public function inputs(SourcePage $page, ?Translation $edition): array
    {
        return [$page->number, $page->width_pt, $edition?->language_code];
    }

    public function handle(SourcePage $page, ?Translation $edition): void
    {
        if ($page->number === self::$failOn) {
            throw new \RuntimeException("page {$page->number} broke");
        }
        self::$done[] = ($edition ? $edition->language_code.':' : '').$page->number;
    }
}

/** Task 10.4 (Req 3.2): a stage fans out over a book's pages as a capped job batch. */
class PageBatchTest extends TestCase
{
    use RefreshDatabase;

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();
        RecordingStage::$done = [];
        RecordingStage::$failOn = null;
        config(['scene.stages.extract.handler' => RecordingStage::class, 'scene.stages.layout.handler' => RecordingStage::class]);
        $this->book = $this->bookWithPages(3);
    }

    private function bookWithPages(int $count): Book
    {
        $book = Book::create(['title' => 'Kolulu', 'page_count' => $count, 'pdf_path' => 'k.pdf', 'original_language' => 'en']);
        foreach (range(1, $count) as $n) {
            SourcePage::create(['book_id' => $book->id, 'number' => $n, 'width_pt' => 509.72, 'height_pt' => 722.32]);
        }

        return $book;
    }

    public function test_a_stage_becomes_one_job_per_page_on_its_queue(): void
    {
        Bus::fake();

        app(PageBatch::class)->dispatch($this->book, 'extract');

        Bus::assertBatched(fn (PendingBatch $batch) => $batch->jobs->count() === 3
            && $batch->name === "extract · book {$this->book->id}"
            && $batch->queue() === 'intake'
            && $batch->allowsFailures()
            && $batch->jobs->every(fn ($job) => $job instanceof RunPageStage && $job->stage === 'extract' && $job->editionId === null));
    }

    public function test_the_batch_runs_every_page_and_skips_unchanged_ones_next_time(): void
    {
        $batch = app(PageBatch::class)->dispatch($this->book, 'extract'); // sync queue in tests

        $this->assertSame(['1', '2', '3'], RecordingStage::$done);
        $this->assertSame(['total' => 3, 'done' => 3, 'failed' => 0, 'percent' => 100, 'finished' => true], PageBatch::progress($batch->id));
        $this->assertSame(3, JobRun::where('stage', 'scene.extract')->where('book_id', $this->book->id)->count(), 'each page timed (Req 3.3)');

        RecordingStage::$done = [];
        $this->book->sourcePages()->where('number', 2)->update(['width_pt' => 510]);
        app(PageBatch::class)->dispatch($this->book, 'extract');
        $this->assertSame(['2'], RecordingStage::$done, 'only the changed page runs again (task 9.4)');
    }

    public function test_language_stages_carry_the_edition(): void
    {
        $af = Translation::create(['book_id' => $this->book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans', 'status' => 'draft']);

        app(PageBatch::class)->dispatch($this->book, 'layout', $af, $this->book->sourcePages()->where('number', '<=', 2)->get());

        $this->assertSame(['af:1', 'af:2'], RecordingStage::$done);
    }

    public function test_one_failing_page_does_not_stop_the_others(): void
    {
        RecordingStage::$failOn = 2;
        config(['queue.default' => 'database']); // a real queue: each page job fails on its own

        $batch = app(PageBatch::class)->dispatch($this->book, 'extract');
        $this->artisan('queue:work', ['--queue' => 'intake', '--stop-when-empty' => true])->assertSuccessful();

        sort(RecordingStage::$done);
        $this->assertSame(['1', '3'], RecordingStage::$done);
        $progress = PageBatch::progress($batch->id);
        $this->assertSame(1, $progress['failed']);
        $this->assertSame('failed', JobRun::where('stage', 'scene.extract')->where('meta->page', 2)->value('status'));
    }

    public function test_a_stage_without_a_handler_is_refused_before_queueing(): void
    {
        Bus::fake();

        try {
            app(PageBatch::class)->dispatch($this->book, 'plates');
            $this->fail('a stage that cannot run was queued');
        } catch (\LogicException $e) {
            $this->assertStringContainsString("'plates' has no handler", $e->getMessage());
        }
        Bus::assertNothingBatched();
    }

    /** A page job with release() captured, run through the middleware directly. */
    private function throughLimits(int $bookId): array
    {
        $job = new class
        {
            public ?int $releasedAfter = null;

            public function release(int $delay = 0): void
            {
                $this->releasedAfter = $delay;
            }
        };
        $ran = false;
        (new PageConcurrency($bookId))->handle($job, function () use (&$ran) {
            $ran = true;
        });

        return [$ran, $job->releasedAfter];
    }

    public function test_the_per_book_limit_makes_extra_pages_wait(): void
    {
        config(['scene.concurrency.per_book' => 2, 'scene.concurrency.release_seconds' => 7]);
        $busy = [Cache::lock("scene-pages:book:{$this->book->id}:0", 60), Cache::lock("scene-pages:book:{$this->book->id}:1", 60)];
        foreach ($busy as $lock) {
            $lock->get();
        }

        $this->assertSame([false, 7], $this->throughLimits($this->book->id), 'both slots of this book are taken: wait');
        $other = $this->bookWithPages(1);
        $this->assertSame([true, null], $this->throughLimits($other->id), 'another book still runs');

        $busy[0]->release();
        $this->assertSame([true, null], $this->throughLimits($this->book->id), 'a freed slot is used');
        $this->assertTrue(Cache::lock("scene-pages:book:{$this->book->id}:0", 60)->get(), 'and given back after the page ran');
    }

    public function test_the_global_limit_caps_pages_across_all_books(): void
    {
        config(['scene.concurrency.global' => 1]);
        $held = Cache::lock('scene-pages:global:0', 60);
        $held->get();

        $this->assertSame([false, 5], $this->throughLimits($this->book->id));
        $this->assertTrue(Cache::lock("scene-pages:book:{$this->book->id}:0", 60)->get(), 'the book slot is not kept while waiting');
    }

    public function test_page_jobs_wait_for_slots_instead_of_failing(): void
    {
        $job = new RunPageStage('extract', $this->book->sourcePages()->first()->id);

        $this->assertSame(0, $job->tries, 'releases for a busy slot are not counted against the job');
        $this->assertSame(2, $job->maxExceptions);
        $this->assertTrue($job->retryUntil() > now()->addMinutes(29));
        $this->assertInstanceOf(PageConcurrency::class, $job->middleware()[0]);
    }
}
