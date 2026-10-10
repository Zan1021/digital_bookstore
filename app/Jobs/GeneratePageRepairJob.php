<?php

namespace App\Jobs;

use App\Models\Book;
use App\Models\ProcessingJob;
use App\Models\Translation;
use App\Services\IllustrationTextService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Queued per-page GENERATIVE repair candidate (on-demand-generative-repair spec G6 / C-C).
 *
 * The "Fix with AI" button dispatches this so the ~1-minute generative call runs in the
 * background instead of blocking the Livewire request. The result (candidate/current image
 * paths, ok/reason) is written to a ProcessingJob row of type 'page_repair', keyed by
 * (book, edition, page), which the ReviewQueue polls to transition running → choose|failed.
 *
 * Cost safety: locked per (edition,page) so a double-click cannot fire two paid calls; the
 * generative work itself is produced ONLY by IllustrationTextService::repairPageGenerative
 * (the single sanctioned producer — see the G3 guard).
 */
class GeneratePageRepairJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;        // a paid call — do NOT auto-retry and double-spend
    public int $timeout = 180;    // generative image edit can be slow

    public function __construct(public int $bookId, public int $translationId, public int $page)
    {
    }

    /** Stable key for the ProcessingJob row the UI polls. */
    public static function jobKey(int $translationId, int $page): string
    {
        return "page_repair:{$translationId}:{$page}";
    }

    public function handle(IllustrationTextService $illus): void
    {
        $book = Book::find($this->bookId);
        $edition = Translation::find($this->translationId);
        if (! $book || ! $edition) {
            return;
        }

        $jobKey = self::jobKey($this->translationId, $this->page);

        // One paid run per (edition,page) at a time.
        $lock = Cache::lock("gen-repair-{$this->translationId}-{$this->page}", $this->timeout);
        if (! $lock->get()) {
            Log::info("GeneratePageRepairJob: {$jobKey} already running — skipping duplicate");
            return;
        }

        $row = ProcessingJob::updateOrCreate(
            ['book_id' => $this->bookId, 'type' => 'page_repair'],
            ['status' => 'processing', 'progress' => 10, 'started_at' => now(),
             'error_message' => null, 'completed_at' => null,
             'details' => ['key' => $jobKey, 'page' => $this->page,
                           'language' => $edition->language_code]],
        );

        try {
            $res = $illus->repairPageGenerative($book, $edition, $this->page);
            $row->update([
                'status' => ! empty($res['ok']) ? 'completed' : 'failed',
                'progress' => 100,
                'completed_at' => now(),
                'error_message' => $res['reason'] ?? null,
                'details' => [
                    'key' => $jobKey,
                    'page' => $this->page,
                    'language' => $edition->language_code,
                    'ok' => (bool) ($res['ok'] ?? false),
                    'candidate_image' => $res['candidate_image'] ?? null,
                    'current_image' => $res['current_image'] ?? null,
                    'reason' => $res['reason'] ?? null,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error("GeneratePageRepairJob failed for {$jobKey}: " . $e->getMessage());
            $row->update(['status' => 'failed', 'error_message' => $e->getMessage(),
                          'completed_at' => now()]);
        } finally {
            optional($lock)->release();
        }
    }
}
