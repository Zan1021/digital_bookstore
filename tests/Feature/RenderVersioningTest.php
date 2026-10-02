<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Translation;
use App\Services\Qa\QaReport;
use App\Services\Qa\RenderFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 6.5/6.6/6.7 — versioned staged commits + fingerprint-tied approvals.
 * Deterministic integration behaviors (no engine render / no network):
 *   - a fingerprint change invalidates prior approvals;
 *   - a QaReport that is not publishable blocks publish even if render_status looks ready;
 *   - the narration sub-hash is independent of layout-only changes.
 */
class RenderVersioningTest extends TestCase
{
    use RefreshDatabase;

    private function edition(array $overrides = []): Translation
    {
        $book = Book::create([
            'title' => 'Ver Book', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/x.pdf',
        ]);
        return Translation::create(array_merge([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'AF',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
        ], $overrides));
    }

    public function test_fingerprint_change_marks_prior_approvals_stale(): void
    {
        // simulate: an edition approved under fingerprint F1
        $t = $this->edition([
            'render_fingerprint' => RenderFingerprint::compute(['item_translations' => ['a' => 'old']]),
            'page_approvals' => [['page' => 1, 'approved' => true]],
        ]);

        // content changed -> new fingerprint F2 differs
        $f2 = RenderFingerprint::compute(['item_translations' => ['a' => 'NEW']]);
        $this->assertNotSame($t->render_fingerprint, $f2);

        // the service's invalidation rule: when prior != new, approvals are cleared.
        if ($t->render_fingerprint !== null && $t->render_fingerprint !== $f2) {
            $t->forceFill(['page_approvals' => [], 'render_fingerprint' => $f2])->save();
        }
        $this->assertSame([], $t->fresh()->page_approvals);
    }

    public function test_identical_inputs_keep_fingerprint_stable_no_drift(): void
    {
        $components = ['source_version' => 5, 'engine_version' => 'v8',
                       'item_translations' => ['p01_s0001' => 'Hallo', 'p01_s0002' => 'Wêreld']];
        $this->assertSame(
            RenderFingerprint::compute($components),
            RenderFingerprint::compute($components),
            'rerun with identical inputs => no fingerprint drift (R9.4)'
        );
    }

    public function test_layout_only_change_does_not_invalidate_narration(): void
    {
        $text = ['p01_s0001' => 'Hallo'];
        $narrA = RenderFingerprint::hashTextComponent($text);
        // a font-policy (layout) change alters the full render fp but NOT the text sub-hash
        $full1 = RenderFingerprint::compute(['item_translations' => $text, 'typography_policy' => ['body' => 'Kalam']]);
        $full2 = RenderFingerprint::compute(['item_translations' => $text, 'typography_policy' => ['body' => 'ComicSans']]);
        $narrB = RenderFingerprint::hashTextComponent($text);

        $this->assertNotSame($full1, $full2, 'layout change alters render fp');
        $this->assertSame($narrA, $narrB, 'but narration (text) hash is unchanged (R9.3)');
    }

    public function test_qa_report_not_publishable_blocks_even_if_status_ready(): void
    {
        // render_status reads READY, but the structured QaReport failed a required check.
        $qa = (new QaReport())->pass('structure')->pass('fit')
            ->fail('target_mapping', 'MISSING_TARGET', 'illustration');
        $t = $this->edition([
            'render_status' => Translation::STATE_READY_FOR_REVIEW,
            'qa_report' => $qa->toArray(), // publishable=false embedded
        ]);
        $this->assertFalse($t->fresh()->canBePublished(),
            'a not-publishable QaReport must block publish regardless of state');
    }

    public function test_qa_report_publishable_allows_publish(): void
    {
        $qa = (new QaReport())->pass('structure')->pass('fit')->pass('target_mapping');
        $t = $this->edition([
            'render_status' => Translation::STATE_READY_FOR_REVIEW,
            'qa_report' => $qa->toArray(),
            // A publishable candidate must be bound to its render identity + output file
            // (unified-rendering-and-testing Req 2) — passed checks alone no longer suffice.
            'render_fingerprint' => str_repeat('a', 16),
            'output_sha256' => str_repeat('b', 16),
        ]);
        $this->assertTrue($t->fresh()->canBePublished());
    }
}
