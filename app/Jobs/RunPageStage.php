<?php

namespace App\Jobs;

use App\Jobs\Middleware\PageConcurrency;
use App\Models\SourcePage;
use App\Models\Translation;
use App\Scene\Contracts\PageStageHandler;
use App\Scene\PageStages;
use App\Support\StageTimer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One pipeline stage for one page, as part of a book's batch (task 10.4, Req 3.2, 3.6).
 * Skips the page when its inputs are unchanged (PageStages), runs under the per-book and
 * global page limits (PageConcurrency), and is timed as scene.{stage} (Req 3.3).
 */
class RunPageStage implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Waiting for a slot is not an error; real errors are capped. */
    public int $tries = 0;

    public int $maxExceptions = 2;

    public int $timeout = 600;

    public function __construct(public string $stage, public int $pageId, public ?int $editionId = null) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes((int) config('scene.concurrency.retry_minutes', 30));
    }

    public function middleware(): array
    {
        $bookId = (int) SourcePage::whereKey($this->pageId)->value('book_id');

        return [new PageConcurrency($bookId, $this->timeout + 60)];
    }

    public function handle(PageStages $stages): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }
        $page = SourcePage::find($this->pageId);
        if (! $page) {
            return; // the book was deleted while its batch was queued
        }
        $edition = $this->editionId ? Translation::findOrFail($this->editionId) : null;
        $handler = self::handler($this->stage);

        StageTimer::run("scene.{$this->stage}", function ($run) use ($stages, $page, $edition, $handler) {
            $summary = $stages->run($this->stage, [$page], $edition,
                inputs: fn ($p) => $handler->inputs($p, $edition),
                work: fn ($p) => $handler->handle($p, $edition));
            $run->forceFill(['meta' => ($run->meta ?? []) + ['skipped' => $summary->skipped !== []]])->save();
        }, $page->book_id, ['page' => (int) $page->number, 'translation_id' => $edition?->id], $this->attempts());
    }

    /** The stage's handler from config/scene.php; a stage without one cannot run (fail closed). */
    public static function handler(string $stage): PageStageHandler
    {
        $class = config("scene.stages.{$stage}.handler");
        if (! $class) {
            throw new \LogicException("Pipeline stage '{$stage}' has no handler yet (config/scene.php).");
        }

        return app($class);
    }
}
