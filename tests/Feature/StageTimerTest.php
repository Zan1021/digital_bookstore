<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeBookClassificationJob;
use App\Models\Book;
use App\Models\JobRun;
use App\Support\StageTimer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Task 5.3 (Req 3.3, rule R6): every pipeline stage run writes a jobs_runs row with start,
 * end, duration, attempt, status and error, without changing the stage's own behaviour.
 */
class StageTimerTest extends TestCase
{
    use RefreshDatabase;

    private function book(): Book
    {
        return Book::create(['title' => 'Timed', 'original_language' => 'en', 'page_count' => 1, 'status' => 'ready', 'pdf_path' => 'books/pdfs/t.pdf']);
    }

    public function test_a_successful_stage_is_recorded_with_its_duration_and_result_returned(): void
    {
        $book = $this->book();

        $result = StageTimer::run('translate.text', function () {
            usleep(30_000);

            return 'done';
        }, $book->id, ['language' => 'af'], 2);

        $this->assertSame('done', $result);
        $run = JobRun::sole();
        $this->assertSame('translate.text', $run->stage);
        $this->assertSame(JobRun::SUCCEEDED, $run->status);
        $this->assertSame($book->id, $run->book_id);
        $this->assertSame(2, $run->attempt);
        $this->assertSame(['language' => 'af'], $run->meta);
        $this->assertNotNull($run->finished_at);
        $this->assertGreaterThanOrEqual(25, $run->duration_ms);
        $this->assertNull($run->error);
    }

    public function test_a_failed_stage_is_recorded_and_the_same_exception_rethrown(): void
    {
        $thrown = new RuntimeException('provider timed out');

        try {
            StageTimer::run('translate.render', fn () => throw $thrown);
            $this->fail('the exception must reach the caller');
        } catch (RuntimeException $caught) {
            $this->assertSame($thrown, $caught);
        }

        $run = JobRun::sole();
        $this->assertSame(JobRun::FAILED, $run->status);
        $this->assertSame('RuntimeException: provider timed out', $run->error);
        $this->assertNotNull($run->duration_ms);
        $this->assertNotNull($run->finished_at);
    }

    public function test_the_work_can_link_the_run_to_a_book_it_creates(): void
    {
        StageTimer::run('intake.upload', function (JobRun $run) {
            $book = $this->book();
            $run->forceFill(['book_id' => $book->id])->save();
        });

        $this->assertNotNull(JobRun::sole()->book_id);
    }

    public function test_long_errors_are_truncated(): void
    {
        try {
            StageTimer::run('narration.generate', fn () => throw new RuntimeException(str_repeat('x', 5000)));
        } catch (RuntimeException) {
        }

        $this->assertSame(2000, mb_strlen(JobRun::sole()->error));
    }

    public function test_a_real_pipeline_stage_is_timed(): void
    {
        $book = $this->book();

        AnalyzeBookClassificationJob::dispatchSync($book->id);

        $run = JobRun::where('stage', 'intake.classify')->sole();
        $this->assertSame($book->id, $run->book_id);
        $this->assertSame(JobRun::SUCCEEDED, $run->status);
        $this->assertSame(1, $run->attempt);
        $this->assertNotNull($run->duration_ms);
    }

    public function test_runs_survive_their_book_being_deleted(): void
    {
        $book = $this->book();
        StageTimer::run('intake.cover', fn () => true, $book->id);

        $book->delete();

        $this->assertNull(JobRun::sole()->book_id, 'timing history is kept, unlinked');
    }
}
