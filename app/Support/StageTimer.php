<?php

namespace App\Support;

use App\Models\JobRun;
use Throwable;

/**
 * Records a pipeline stage in jobs_runs (task 5.3, Req 3.3; rule R6 "measure speed").
 *
 *   $pdf = StageTimer::run('translate.render', fn () => $this->render(...), $book->id, ['lang' => 'af']);
 *
 * Writes a `running` row, runs the work, then marks it `succeeded` or `failed` with the
 * duration (monotonic clock) and the error, and returns the work's result. A failure is
 * recorded and re-thrown unchanged, so callers handle errors exactly as before.
 *
 * The work receives its JobRun, e.g. to attach a book id created by the work itself.
 *
 * Stage names are dotted, area first: intake.*, translate.*, ai.*, narration.*, layout.*,
 * export.*. Pass the queue attempt from a job ($this->attempts()) so retries are counted.
 */
class StageTimer
{
    private const MAX_ERROR_LENGTH = 2000;

    /**
     * @template T
     * @param  callable(JobRun): T  $work
     * @param  array<string, mixed>  $meta
     * @return T
     */
    public static function run(string $stage, callable $work, ?int $bookId = null, array $meta = [], int $attempt = 1): mixed
    {
        $run = JobRun::create([
            'book_id' => $bookId,
            'stage' => $stage,
            'status' => JobRun::RUNNING,
            'attempt' => max(1, $attempt),
            'started_at' => now(),
            'meta' => $meta ?: null,
        ]);
        $start = hrtime(true);

        try {
            $result = $work($run);
        } catch (Throwable $e) {
            self::finish($run, $start, JobRun::FAILED, $e);
            throw $e;
        }

        self::finish($run, $start, JobRun::SUCCEEDED);

        return $result;
    }

    private static function finish(JobRun $run, int $start, string $status, ?Throwable $error = null): void
    {
        $run->forceFill([
            'status' => $status,
            'finished_at' => now(),
            'duration_ms' => (int) round((hrtime(true) - $start) / 1_000_000),
            'error' => $error ? mb_substr($error::class.': '.$error->getMessage(), 0, self::MAX_ERROR_LENGTH) : null,
        ])->save();
    }
}
