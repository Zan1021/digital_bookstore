<?php

namespace Tests\Unit;

use App\Services\Qa\QaReport;
use App\Services\Qa\RenderFingerprint;
use PHPUnit\Framework\TestCase;

/**
 * Phase 6.1/6.2/6.6 — machine-readable fail-closed QA + monotonic merge + fingerprint.
 */
class QaReportTest extends TestCase
{
    // ---- 6.1: machine-readable, not-run vs passed vs failed ----------------
    public function test_empty_report_is_not_publishable(): void
    {
        $qa = new QaReport();
        $this->assertFalse($qa->isPublishable(), 'no checks ran => fail closed');
        $this->assertSame('not_publishable', $qa->status());
    }

    public function test_all_required_checks_passed_is_publishable(): void
    {
        $qa = (new QaReport())
            ->pass('structure')->pass('fit')->pass('target_mapping');
        $this->assertTrue($qa->isPublishable());
        $this->assertSame('passed', $qa->checks()['structure']);
    }

    public function test_required_check_not_run_blocks_publish(): void
    {
        $qa = (new QaReport())->pass('structure')->pass('fit'); // target_mapping NOT run
        $this->assertFalse($qa->isPublishable());
        $this->assertArrayNotHasKey('target_mapping', $qa->checks());
    }

    public function test_failed_check_records_issue_and_blocks(): void
    {
        $qa = (new QaReport())
            ->pass('structure')->pass('fit')
            ->fail('target_mapping', 'MISSING_TARGET', 'illustration',
                   ['page' => 7, 'region_id' => 'p07_art01']);
        $this->assertFalse($qa->isPublishable());
        $this->assertSame('failed', $qa->checks()['target_mapping']);
        $this->assertSame('MISSING_TARGET', $qa->issues()[0]['code']);
        $this->assertSame(7, $qa->issues()[0]['page']);
        $this->assertSame('illustration', $qa->issues()[0]['stage']);
    }

    // ---- 6.2: MONOTONIC merge ----------------------------------------------
    public function test_merge_never_clears_an_earlier_failure(): void
    {
        $a = (new QaReport())->fail('structure', 'ELEMENT_OUT_OF_COLUMN', 'layout');
        $b = (new QaReport())->pass('structure')->pass('fit')->pass('target_mapping');
        $a->merge($b);
        // the later PASS must NOT un-fail structure
        $this->assertSame('failed', $a->checks()['structure']);
        $this->assertFalse($a->isPublishable());
    }

    public function test_setcheck_cannot_downgrade_failure_to_pass(): void
    {
        $qa = (new QaReport())->fail('fit', 'OVERFLOW', 'fit');
        $qa->pass('fit'); // attempt to clear
        $this->assertSame('failed', $qa->checks()['fit']);
    }

    public function test_artwork_approval_blocks_until_signed_off(): void
    {
        $qa = (new QaReport())->pass('structure')->pass('fit')->pass('target_mapping')
            ->requireArtworkApproval();
        $this->assertFalse($qa->isPublishable());
        $this->assertTrue($qa->requiresArtworkApproval());
    }

    public function test_roundtrip_to_array_and_back(): void
    {
        $qa = (new QaReport())->pass('structure')->pass('fit')
            ->fail('target_mapping', 'X', 'stageY', ['page' => 2])
            ->withExtra(['diagnostic_manifest' => ['pages' => [1, 2]]]);
        $arr = $qa->toArray();
        $this->assertSame('not_publishable', $arr['status']);
        $this->assertFalse($arr['publishable']);
        $this->assertArrayHasKey('diagnostic_manifest', $arr);

        $back = QaReport::fromArray($arr);
        $this->assertSame('failed', $back->checks()['target_mapping']);
        $this->assertFalse($back->isPublishable());
        $this->assertArrayHasKey('diagnostic_manifest', $back->toArray());
    }

    // ---- 6.6: render fingerprint -------------------------------------------
    public function test_fingerprint_is_deterministic_for_identical_inputs(): void
    {
        $c = ['source_version' => 10, 'engine_version' => 'v8',
              'item_translations' => ['a' => 'x', 'b' => 'y']];
        $this->assertSame(RenderFingerprint::compute($c), RenderFingerprint::compute($c),
            'identical inputs => identical fingerprint (no drift, R9.4)');
    }

    public function test_fingerprint_order_independent_for_maps(): void
    {
        $f1 = RenderFingerprint::compute(['item_translations' => ['a' => 'x', 'b' => 'y']]);
        $f2 = RenderFingerprint::compute(['item_translations' => ['b' => 'y', 'a' => 'x']]);
        $this->assertSame($f1, $f2);
    }

    public function test_fingerprint_changes_when_target_text_changes(): void
    {
        $f1 = RenderFingerprint::compute(['item_translations' => ['a' => 'x']]);
        $f2 = RenderFingerprint::compute(['item_translations' => ['a' => 'CHANGED']]);
        $this->assertNotSame($f1, $f2);
    }

    public function test_narration_subhash_tracks_text_only(): void
    {
        $textA = ['a' => 'x', 'b' => 'y'];
        // same text, different font policy => narration hash unchanged, render fp differs
        $this->assertSame(
            RenderFingerprint::hashTextComponent($textA),
            RenderFingerprint::hashTextComponent($textA)
        );
        $full1 = RenderFingerprint::compute(['item_translations' => $textA, 'typography_policy' => ['body' => 'Kalam']]);
        $full2 = RenderFingerprint::compute(['item_translations' => $textA, 'typography_policy' => ['body' => 'ComicSans']]);
        $this->assertNotSame($full1, $full2, 'font policy change alters render fp');
    }
}
