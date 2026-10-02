<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Translation;
use App\Services\Qa\QaReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * unified-rendering-and-testing spec Req 2 / task A1.6 — the Translation publish gate
 * now delegates to the single CandidateReadiness authority. These tests prove the
 * model-level consequences that pure CandidateReadinessTest cannot: a passed QA report
 * is NOT enough — it must be bound to the edition's current render_fingerprint AND
 * output_sha256, and any staleness blocks publish.
 */
class CandidateReadinessGateTest extends TestCase
{
    use RefreshDatabase;

    private function edition(array $overrides = []): Translation
    {
        $book = Book::create([
            'title' => 'Gate Book', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/x.pdf',
        ]);
        return Translation::create(array_merge([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'AF',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
            'qa_report' => (new QaReport())->pass('structure')->pass('fit')->pass('target_mapping')->toArray(),
            'render_fingerprint' => str_repeat('a', 16),
            'output_sha256' => str_repeat('b', 16),
        ], $overrides))->fresh();
    }

    public function test_bound_passed_candidate_is_publishable(): void
    {
        $this->assertTrue($this->edition()->canBePublished());
    }

    public function test_passed_qa_without_fingerprint_is_not_publishable(): void
    {
        // The legacy shortcut: passed checks but no render identity. Must fail closed.
        $t = $this->edition(['render_fingerprint' => null]);
        $this->assertFalse($t->canBePublished(), 'passed QA alone is not readiness');
    }

    public function test_passed_qa_without_output_hash_is_not_publishable(): void
    {
        $t = $this->edition(['output_sha256' => null]);
        $this->assertFalse($t->canBePublished());
    }

    public function test_failed_required_check_blocks_publish(): void
    {
        $qa = (new QaReport())->pass('structure')->pass('fit')
            ->fail('target_mapping', 'MISSING_TARGET', 'illustration');
        $t = $this->edition(['qa_report' => $qa->toArray()]);
        $this->assertFalse($t->canBePublished());
    }

    public function test_blocking_render_state_blocks_publish(): void
    {
        $t = $this->edition(['render_status' => Translation::STATE_NEEDS_LAYOUT_REVIEW]);
        $this->assertFalse($t->canBePublished());
        $this->assertSame('BLOCKING_RENDER_STATE', $t->readiness()['issues'][0]['code']);
    }

    public function test_required_approval_track_blocks_until_bound_and_approved(): void
    {
        $t = $this->edition();
        // With a required 'layout' approval track that was never approved → not ready.
        $this->assertFalse($t->readiness(['layout'])['ready']);

        // Approve the track (binds current fingerprint + output hash) → ready.
        $t->approveTrack('layout');
        $this->assertTrue($t->fresh()->readiness(['layout'])['ready']);
    }

    public function test_approval_track_goes_stale_when_output_hash_changes(): void
    {
        $t = $this->edition();
        $t->approveTrack('layout');
        $this->assertTrue($t->fresh()->readiness(['layout'])['ready']);

        // A re-render produces a new output file (new hash) — the prior sign-off is stale.
        $t->forceFill(['output_sha256' => str_repeat('c', 16)])->save();
        $readiness = $t->fresh()->readiness(['layout']);
        $this->assertFalse($readiness['ready']);
        $this->assertSame('APPROVAL_PENDING_OR_STALE', $readiness['issues'][0]['code']);
    }

    public function test_approval_track_goes_stale_when_fingerprint_changes(): void
    {
        $t = $this->edition();
        $t->approveTrack('layout');

        $t->forceFill(['render_fingerprint' => str_repeat('z', 16)])->save();
        $this->assertFalse($t->fresh()->readiness(['layout'])['ready']);
    }
}
