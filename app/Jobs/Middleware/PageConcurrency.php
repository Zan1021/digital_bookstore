<?php

namespace App\Jobs\Middleware;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Caps how many page jobs run at once (task 10.4, Req 3.2): at most `per_book` pages of one
 * book and `global` pages overall. Each job takes one book slot and one global slot (cache
 * locks, so it works on the database cache locally and Redis in production); when none is
 * free it goes back on the queue for a few seconds. Slots expire on their own if a worker dies.
 */
class PageConcurrency
{
    public function __construct(private int $bookId, private int $lockSeconds = 900) {}

    public function handle(object $job, \Closure $next): void
    {
        $book = $this->takeSlot("scene-pages:book:{$this->bookId}", (int) config('scene.concurrency.per_book', 4));
        if ($book === null) {
            $job->release((int) config('scene.concurrency.release_seconds', 5));

            return;
        }
        $global = $this->takeSlot('scene-pages:global', (int) config('scene.concurrency.global', 8));
        if ($global === null) {
            $book->release();
            $job->release((int) config('scene.concurrency.release_seconds', 5));

            return;
        }

        try {
            $next($job);
        } finally {
            $global->release();
            $book->release();
        }
    }

    private function takeSlot(string $prefix, int $limit): ?Lock
    {
        for ($slot = 0; $slot < max(1, $limit); $slot++) {
            $lock = Cache::lock("{$prefix}:{$slot}", $this->lockSeconds);
            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }
}
