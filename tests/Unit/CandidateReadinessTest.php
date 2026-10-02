<?php

namespace Tests\Unit;

use App\Services\Qa\CandidateReadiness;
use PHPUnit\Framework\TestCase;

/**
 * unified-rendering-and-testing spec Req 2 / task A1.2 — the single readiness
 * authority. Every required check AND approval must be `passed`/`approved` AND bound
 * to the candidate's fingerprint + output hash, or the edition is NOT ready.
 */
class CandidateReadinessTest extends TestCase
{
    private const FP = 'fp-abc123';
    private const SHA = 'sha-deadbeef';

    /** A fully bound, passing check record. */
    private function check(string $status = CandidateReadiness::STATUS_PASSED, ?string $fp = self::FP, ?string $sha = self::SHA): array
    {
        return ['status' => $status, 'candidate_fingerprint' => $fp, 'output_sha256' => $sha];
    }

    private function approval(string $status = CandidateReadiness::STATUS_APPROVED, ?string $fp = self::FP, ?string $sha = self::SHA): array
    {
        return ['status' => $status, 'candidate_fingerprint' => $fp, 'output_sha256' => $sha];
    }

    // ---- happy path --------------------------------------------------------
    public function test_all_checks_and_approvals_bound_and_passed_is_ready(): void
    {
        $result = CandidateReadiness::evaluate(
            ['structure', 'fit'],
            ['structure' => $this->check(), 'fit' => $this->check()],
            ['language', 'layout'],
            ['language' => $this->approval(), 'layout' => $this->approval()],
            self::FP,
            self::SHA
        );
        $this->assertTrue($result['ready']);
        $this->assertSame([], $result['issues']);
    }

    public function test_no_required_approvals_still_ready_when_checks_pass(): void
    {
        $result = CandidateReadiness::evaluate(
            ['structure'],
            ['structure' => $this->check()],
            [],
            [],
            self::FP,
            self::SHA
        );
        $this->assertTrue($result['ready']);
    }

    // ---- INVALID_GATE_INPUT (fail closed) ----------------------------------
    public function test_empty_fingerprint_is_invalid_input(): void
    {
        $result = CandidateReadiness::evaluate(['structure'], ['structure' => $this->check()], [], [], '', self::SHA);
        $this->assertFalse($result['ready']);
        $this->assertSame(CandidateReadiness::CODE_INVALID_INPUT, $result['issues'][0]['code']);
    }

    public function test_empty_output_hash_is_invalid_input(): void
    {
        $result = CandidateReadiness::evaluate(['structure'], ['structure' => $this->check()], [], [], self::FP, '');
        $this->assertFalse($result['ready']);
        $this->assertSame(CandidateReadiness::CODE_INVALID_INPUT, $result['issues'][0]['code']);
    }

    public function test_empty_required_checks_is_invalid_input(): void
    {
        // A vacuous pass must never happen: no required checks = not ready.
        $result = CandidateReadiness::evaluate([], [], [], [], self::FP, self::SHA);
        $this->assertFalse($result['ready']);
        $this->assertSame(CandidateReadiness::CODE_INVALID_INPUT, $result['issues'][0]['code']);
    }

    // ---- stale / unresolved checks -----------------------------------------
    public function test_missing_check_record_is_stale(): void
    {
        $result = CandidateReadiness::evaluate(['structure', 'fit'], ['structure' => $this->check()], [], [], self::FP, self::SHA);
        $this->assertFalse($result['ready']);
        $this->assertSame([['code' => CandidateReadiness::CODE_CHECK_STALE, 'check' => 'fit']], $result['issues']);
    }

    public function test_check_not_passed_is_stale(): void
    {
        $result = CandidateReadiness::evaluate(['structure'], ['structure' => $this->check('failed')], [], [], self::FP, self::SHA);
        $this->assertFalse($result['ready']);
        $this->assertSame(CandidateReadiness::CODE_CHECK_STALE, $result['issues'][0]['code']);
    }

    public function test_check_bound_to_different_fingerprint_is_stale(): void
    {
        $result = CandidateReadiness::evaluate(['structure'], ['structure' => $this->check(fp: 'OLD-fp')], [], [], self::FP, self::SHA);
        $this->assertFalse($result['ready']);
        $this->assertSame(CandidateReadiness::CODE_CHECK_STALE, $result['issues'][0]['code']);
    }

    public function test_check_bound_to_different_output_hash_is_stale(): void
    {
        $result = CandidateReadiness::evaluate(['structure'], ['structure' => $this->check(sha: 'OLD-sha')], [], [], self::FP, self::SHA);
        $this->assertFalse($result['ready']);
        $this->assertSame(CandidateReadiness::CODE_CHECK_STALE, $result['issues'][0]['code']);
    }

    public function test_non_array_check_record_is_stale(): void
    {
        $result = CandidateReadiness::evaluate(['structure'], ['structure' => true], [], [], self::FP, self::SHA);
        $this->assertFalse($result['ready']);
        $this->assertSame(CandidateReadiness::CODE_CHECK_STALE, $result['issues'][0]['code']);
    }

    // ---- stale / pending approvals -----------------------------------------
    public function test_missing_approval_is_stale(): void
    {
        $result = CandidateReadiness::evaluate(['structure'], ['structure' => $this->check()], ['layout'], [], self::FP, self::SHA);
        $this->assertFalse($result['ready']);
        $this->assertSame([['code' => CandidateReadiness::CODE_APPROVAL_STALE, 'approval' => 'layout']], $result['issues']);
    }

    public function test_approval_bound_to_stale_fingerprint_is_pending(): void
    {
        $result = CandidateReadiness::evaluate(
            ['structure'],
            ['structure' => $this->check()],
            ['layout'],
            ['layout' => $this->approval(fp: 'OLD-fp')],
            self::FP,
            self::SHA
        );
        $this->assertFalse($result['ready']);
        $this->assertSame(CandidateReadiness::CODE_APPROVAL_STALE, $result['issues'][0]['code']);
    }

    // ---- dedupe + aggregation ----------------------------------------------
    public function test_duplicate_required_keys_are_deduped(): void
    {
        // 'structure' listed twice but missing — should produce exactly ONE issue.
        $result = CandidateReadiness::evaluate(['structure', 'structure'], [], [], [], self::FP, self::SHA);
        $this->assertFalse($result['ready']);
        $this->assertCount(1, $result['issues']);
    }

    public function test_multiple_failures_all_reported(): void
    {
        $result = CandidateReadiness::evaluate(
            ['structure', 'fit'],
            [], // both missing
            ['layout'],
            [], // missing
            self::FP,
            self::SHA
        );
        $this->assertFalse($result['ready']);
        $this->assertCount(3, $result['issues']);
    }
}
