<?php

namespace App\Services\Qa;

/**
 * QaReport — machine-readable, fail-closed, monotonically-merged QA result
 * (world-class-render-engine spec R8.3/R8.4, Phase 6.1/6.2).
 *
 * The old render pipeline scattered `$publishable = false` assignments across the
 * orchestrator and persisted a loose associative array (double-encoded, see
 * Translation::decodeQaReport). This value object makes the QA verdict EXPLICIT:
 *
 *   - `checks`  : map of check-name => one of CHECK_NOT_RUN / CHECK_PASSED / CHECK_FAILED.
 *                 A check that never ran is DISTINCT from one that passed (R8.3). Missing/
 *                 not-run required checks keep the edition NOT publishable (R8.4, I3).
 *   - `issues`  : list of {code, stage, page, region_id, detail} — the exact defects.
 *   - `requires_artwork_approval` : generative/ambiguous artwork needs human sign-off (R6.6).
 *
 * MONOTONIC MERGE (R8.4): merging another report can only ever ADD failures/issues and
 * downgrade a check (passed->failed), NEVER clear an earlier failure. Publish eligibility
 * is computed LAST, from the merged state — a later pass's success cannot un-fail an
 * earlier pass.
 *
 * Fail closed: an empty report (no checks run) is NOT publishable.
 */
class QaReport
{
    public const CHECK_NOT_RUN = 'not_run';
    public const CHECK_PASSED = 'passed';
    public const CHECK_FAILED = 'failed';

    /** Required checks: all must be PASSED for an edition to be publishable (R8.4). */
    public const REQUIRED_CHECKS = ['structure', 'fit', 'target_mapping'];

    /** @var array<string,string> check name => status */
    private array $checks;

    /** @var list<array<string,mixed>> */
    private array $issues;

    private bool $requiresArtworkApproval;

    /** @var array<string,mixed> opaque diagnostics carried through unchanged (manifest etc.) */
    private array $extra;

    public function __construct(
        array $checks = [],
        array $issues = [],
        bool $requiresArtworkApproval = false,
        array $extra = []
    ) {
        $this->checks = $checks;
        $this->issues = array_values($issues);
        $this->requiresArtworkApproval = $requiresArtworkApproval;
        $this->extra = $extra;
    }

    /** Record a check result. Monotonic: once FAILED, a later PASSED cannot clear it. */
    public function setCheck(string $name, string $status): self
    {
        $existing = $this->checks[$name] ?? self::CHECK_NOT_RUN;
        if ($existing === self::CHECK_FAILED && $status !== self::CHECK_FAILED) {
            return $this; // never un-fail (R8.4)
        }
        $this->checks[$name] = $status;
        return $this;
    }

    public function pass(string $name): self
    {
        return $this->setCheck($name, self::CHECK_PASSED);
    }

    /** Record a failed check + the issue(s) that caused it. */
    public function fail(string $name, string $code, string $stage, array $context = []): self
    {
        $this->checks[$name] = self::CHECK_FAILED;
        $this->issues[] = array_merge([
            'code' => $code,
            'stage' => $stage,
            'page' => $context['page'] ?? null,
            'region_id' => $context['region_id'] ?? null,
        ], array_diff_key($context, array_flip(['page', 'region_id'])));
        return $this;
    }

    public function requireArtworkApproval(bool $v = true): self
    {
        // monotonic: once required, stays required
        $this->requiresArtworkApproval = $this->requiresArtworkApproval || $v;
        return $this;
    }

    public function withExtra(array $extra): self
    {
        $this->extra = array_merge($this->extra, $extra);
        return $this;
    }

    /**
     * Monotonic merge: adds the other report's issues, downgrades checks on conflict
     * (any FAILED wins), ORs the artwork-approval flag, and merges extras. Never clears a
     * failure (R8.4).
     */
    public function merge(QaReport $other): self
    {
        foreach ($other->checks as $name => $status) {
            $this->setCheck($name, $status);
        }
        foreach ($other->issues as $iss) {
            $this->issues[] = $iss;
        }
        $this->requiresArtworkApproval = $this->requiresArtworkApproval || $other->requiresArtworkApproval;
        $this->extra = array_merge($this->extra, $other->extra);
        return $this;
    }

    public function hasFailure(): bool
    {
        foreach ($this->checks as $status) {
            if ($status === self::CHECK_FAILED) {
                return true;
            }
        }
        return !empty($this->issues);
    }

    /**
     * Publish eligibility, computed LAST from the merged state (R8.4).
     * NOT publishable when: any check failed, any issue recorded, any REQUIRED check did
     * not run/pass, or artwork approval is outstanding, or no checks ran at all (fail
     * closed — missing QA defaults to NOT publishable, R8.4/I3).
     */
    public function isPublishable(): bool
    {
        if (empty($this->checks)) {
            return false; // no QA ran => not publishable
        }
        if ($this->hasFailure()) {
            return false;
        }
        foreach (self::REQUIRED_CHECKS as $req) {
            if (($this->checks[$req] ?? self::CHECK_NOT_RUN) !== self::CHECK_PASSED) {
                return false; // a required check didn't demonstrably pass
            }
        }
        if ($this->requiresArtworkApproval) {
            return false; // generative/ambiguous artwork needs human sign-off
        }
        return true;
    }

    public function status(): string
    {
        return $this->isPublishable() ? 'publishable' : 'not_publishable';
    }

    /** @return array<string,string> */
    public function checks(): array
    {
        return $this->checks;
    }

    /** @return list<array<string,mixed>> */
    public function issues(): array
    {
        return $this->issues;
    }

    public function requiresArtworkApproval(): bool
    {
        return $this->requiresArtworkApproval;
    }

    /** Serializable for the array-cast qa_report field (NOT json_encoded — R8.5). */
    public function toArray(): array
    {
        return array_merge($this->extra, [
            'status' => $this->status(),
            'publishable' => $this->isPublishable(),
            'checks' => $this->checks,
            'issues' => $this->issues,
            'requires_artwork_approval' => $this->requiresArtworkApproval,
        ]);
    }

    /** Rehydrate from a persisted array (new or legacy shape). */
    public static function fromArray(?array $data): self
    {
        if (!is_array($data)) {
            return new self();
        }
        $known = ['status', 'publishable', 'checks', 'issues', 'requires_artwork_approval'];
        $extra = array_diff_key($data, array_flip($known));
        return new self(
            is_array($data['checks'] ?? null) ? $data['checks'] : [],
            is_array($data['issues'] ?? null) ? $data['issues'] : [],
            (bool) ($data['requires_artwork_approval'] ?? false),
            $extra
        );
    }
}
