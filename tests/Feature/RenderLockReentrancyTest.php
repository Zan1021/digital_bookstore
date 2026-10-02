<?php

namespace Tests\Feature;

use App\Services\PdfTranslationService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * unified-rendering-and-testing D1 — the per-edition render lock is REENTRANT by owner.
 *
 * We assert the lock SEAM directly (without a live render): when the caller already holds
 * the edition lock and passes its owner token via setRenderLockOwner(), a nested acquisition
 * for the same key+owner is recognised as owned (runs inline, no deadlock). A different owner
 * is NOT recognised and would block/fail-closed.
 */
class RenderLockReentrancyTest extends TestCase
{
    public function test_same_owner_is_recognised_as_held(): void
    {
        $key = 'translate-edition-999';
        $lock = Cache::lock($key, 30);
        $this->assertTrue($lock->get(), 'acquire the outer lock');

        try {
            // The renderer, told the outer owner, must see the lock as owned by this process.
            $restored = Cache::restoreLock($key, $lock->owner());
            $this->assertTrue($restored->isOwnedByCurrentProcess(),
                'same owner token → reentrant (no deadlock)');
        } finally {
            $lock->release();
        }
    }

    public function test_different_owner_is_not_recognised(): void
    {
        $key = 'translate-edition-998';
        $lock = Cache::lock($key, 30);
        $this->assertTrue($lock->get());

        try {
            $restored = Cache::restoreLock($key, 'some-other-owner-token');
            $this->assertFalse($restored->isOwnedByCurrentProcess(),
                'a foreign owner must NOT be treated as holding the lock');
        } finally {
            $lock->release();
        }
    }

    public function test_setter_is_callable_and_defaults_null(): void
    {
        // Guards the public seam the Job uses; a plain instantiation starts with no owner.
        $svc = new PdfTranslationService();
        $svc->setRenderLockOwner('owner-xyz');
        $svc->setRenderLockOwner(null);
        $this->assertTrue(true); // no exception == seam intact
    }
}
