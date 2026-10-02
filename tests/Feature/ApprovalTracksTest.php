<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Translation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 7.4 (R10.4/R10.5) — separate approval tracks, each tied to the render fingerprint
 * so a content change auto-invalidates stale sign-offs.
 */
class ApprovalTracksTest extends TestCase
{
    use RefreshDatabase;

    private function edition(?string $fp = 'fp-v1'): Translation
    {
        $book = Book::create([
            'title' => 'Track Book', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/x.pdf',
        ]);
        return Translation::create([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'AF',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
            'render_fingerprint' => $fp,
        ]);
    }

    public function test_each_track_is_independent(): void
    {
        $t = $this->edition();
        $t->approveTrack('language');
        $this->assertTrue($t->isTrackApproved('language'));
        $this->assertFalse($t->isTrackApproved('layout'));
        $this->assertFalse($t->isTrackApproved('artwork'));

        $t->approveTrack('layout');
        $this->assertTrue($t->isTrackApproved('layout'));
    }

    public function test_unknown_track_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->edition()->approveTrack('nonsense');
    }

    public function test_fingerprint_change_invalidates_track(): void
    {
        $t = $this->edition('fp-v1');
        $t->approveTrack('language');
        $this->assertTrue($t->isTrackApproved('language'));

        // content changes -> new fingerprint. The stored approval no longer matches.
        $t->forceFill(['render_fingerprint' => 'fp-v2'])->save();
        $this->assertFalse($t->fresh()->isTrackApproved('language'),
            'a fingerprint change must invalidate the stale approval (R10.5)');
    }

    public function test_sweep_clears_only_stale_tracks(): void
    {
        $t = $this->edition('fp-v1');
        $t->approveTrack('language');
        $t->approveTrack('layout');
        // bump fingerprint, re-approve ONLY layout against the new one
        $t->forceFill(['render_fingerprint' => 'fp-v2'])->save();
        $t->approveTrack('layout');

        $t->invalidateStaleApprovalTracks();
        $fresh = $t->fresh();
        $this->assertFalse($fresh->isTrackApproved('language'), 'language was stale -> cleared');
        $this->assertTrue($fresh->isTrackApproved('layout'), 'layout re-approved on current fp -> kept');
    }

    public function test_explicit_invalidate(): void
    {
        $t = $this->edition();
        $t->approveTrack('artwork');
        $t->invalidateTrack('artwork');
        $this->assertFalse($t->fresh()->isTrackApproved('artwork'));
    }
}
