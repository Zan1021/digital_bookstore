<?php

namespace App\Scene;

use App\Jobs\RunPageStage;
use App\Models\Book;
use App\Models\SourcePage;
use App\Models\Translation;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

/**
 * Fans a stage out over a book's pages as one Laravel job batch (task 10.4, Req 3.2): one
 * RunPageStage per page on the stage's queue, failures kept per page (one bad page does not
 * stop the others), progress readable from the batch.
 */
class PageBatch
{
    /** @param iterable<SourcePage>|null $pages  default: every page of the book */
    public function dispatch(Book $book, string $stage, ?Translation $edition = null, ?iterable $pages = null): Batch
    {
        RunPageStage::handler($stage); // refuse before queueing anything for a stage that cannot run
        $pages ??= $book->sourcePages()->get();

        $jobs = [];
        foreach ($pages as $page) {
            $jobs[] = new RunPageStage($stage, $page->id, $edition?->id);
        }

        return Bus::batch($jobs)
            ->name(trim("{$stage} · book {$book->id}".($edition ? " · {$edition->language_code}" : '')))
            ->onQueue((string) config("scene.stages.{$stage}.queue", 'default'))
            ->allowFailures()
            ->dispatch();
    }

    /** Progress of a batch for status screens: pages done, failed, total, finished. */
    public static function progress(string $batchId): ?array
    {
        $batch = Bus::findBatch($batchId);

        return $batch ? [
            'total' => $batch->totalJobs,
            'done' => $batch->processedJobs(),
            'failed' => $batch->failedJobs,
            'percent' => $batch->progress(),
            'finished' => $batch->finished(),
        ] : null;
    }
}
