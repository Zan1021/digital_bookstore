<?php

namespace App\Services\Qa;

/**
 * CandidateReadiness — the SINGLE edition-readiness authority
 * (unified-rendering-and-testing spec Req 2, brief §10).
 *
 * Before this class the codebase had THREE independent publish shortcuts
 * (Translation::isPublishable / canBePublished / pageApprovalProgress), none of
 * which bound their decision to the exact file that was tested. An approval could
 * survive a re-render; a "publishable" boolean could outlive the content it
 * described. This authority replaces that scatter with one rule:
 *
 *   An edition is READY only when EVERY required check AND EVERY required approval
 *   is satisfied AGAINST the candidate currently being gated — identified by BOTH
 *   its input fingerprint AND its output file SHA-256.
 *
 *   - input fingerprint (candidate_fingerprint): describes the dependency set that
 *     produced the render (source/manifest/targets/fonts/policy/backend/config).
 *   - output_sha256: identifies the actual PDF on disk. Both are required — a
 *     matching fingerprint with a different file (or vice-versa) is STALE.
 *
 * Fail closed: empty fingerprint, empty output hash, or an empty required-check set
 * yields { ready: false, [INVALID_GATE_INPUT] }. A check/approval that is missing,
 * not `passed`/`approved`, or bound to a different candidate is UNRESOLVED_OR_STALE —
 * never silently treated as satisfied.
 *
 * Pure and I/O-free: fully unit-testable. The server (never the browser) chooses the
 * required-check and required-approval key sets; this class only evaluates them.
 */
final class CandidateReadiness
{
    public const CODE_INVALID_INPUT = 'INVALID_GATE_INPUT';
    public const CODE_CHECK_STALE = 'CHECK_UNRESOLVED_OR_STALE';
    public const CODE_APPROVAL_STALE = 'APPROVAL_PENDING_OR_STALE';

    public const STATUS_PASSED = 'passed';
    public const STATUS_APPROVED = 'approved';

    /**
     * Evaluate readiness for ONE candidate.
     *
     * @param list<string> $requiredChecks       check keys that must all be passed+bound
     * @param array<string,array<string,mixed>> $checks     key => {status, candidate_fingerprint, output_sha256, ...}
     * @param list<string> $requiredApprovals    approval keys that must all be approved+bound
     * @param array<string,array<string,mixed>> $approvals  key => {status, candidate_fingerprint, output_sha256, ...}
     * @param string $fingerprint                 the candidate input fingerprint being gated
     * @param string $outputSha256                SHA-256 of the exact output file being gated
     *
     * @return array{ready: bool, issues: list<array<string,string>>}
     */
    public static function evaluate(
        array $requiredChecks,
        array $checks,
        array $requiredApprovals,
        array $approvals,
        string $fingerprint,
        string $outputSha256
    ): array {
        // Fail closed on degenerate input. An edition with no identity and no required
        // checks cannot be "ready" — that would be a vacuous pass.
        if ($fingerprint === '' || $outputSha256 === '' || $requiredChecks === []) {
            return ['ready' => false, 'issues' => [['code' => self::CODE_INVALID_INPUT]]];
        }

        $issues = [];

        foreach (array_unique($requiredChecks) as $key) {
            if (! self::isBoundAndSatisfied($checks[$key] ?? null, self::STATUS_PASSED, $fingerprint, $outputSha256)) {
                $issues[] = ['code' => self::CODE_CHECK_STALE, 'check' => $key];
            }
        }

        foreach (array_unique($requiredApprovals) as $key) {
            if (! self::isBoundAndSatisfied($approvals[$key] ?? null, self::STATUS_APPROVED, $fingerprint, $outputSha256)) {
                $issues[] = ['code' => self::CODE_APPROVAL_STALE, 'approval' => $key];
            }
        }

        return ['ready' => $issues === [], 'issues' => $issues];
    }

    /**
     * A record satisfies the gate only when it is an array with the expected status
     * AND is bound to BOTH the current candidate fingerprint and output hash. Any
     * mismatch (missing record, wrong status, stale fingerprint, stale file) fails.
     *
     * @param mixed $record
     */
    private static function isBoundAndSatisfied(
        $record,
        string $expectedStatus,
        string $fingerprint,
        string $outputSha256
    ): bool {
        if (! is_array($record)) {
            return false;
        }
        return ($record['status'] ?? null) === $expectedStatus
            && ($record['candidate_fingerprint'] ?? null) === $fingerprint
            && ($record['output_sha256'] ?? null) === $outputSha256;
    }
}
