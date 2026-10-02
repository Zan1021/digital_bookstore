<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Translation extends Model
{
    use HasFactory;

    /**
     * Teardown hook: when a single edition is deleted (not via the book), remove its
     * translated pages, rendered PDF, comparison renders, and edition-specific
     * narration + audio — without touching the original or sibling editions.
     * (gated-translation-narration-flow Req 7.2)
     */
    protected static function booted(): void
    {
        static::deleting(function (Translation $translation) {
            $translation->deleteAssociatedFiles();
            $translation->translatedPages()->delete();
            $translation->editionNarrations()->get()->each->delete();
        });
    }

    /**
     * Remove this edition's files: rendered PDF (real column + legacy path) and the
     * engine comparison render tree. deleteDirectory is a no-op when absent.
     */
    public function deleteAssociatedFiles(): void
    {
        $disk = Storage::disk('public');

        $pdfs = array_filter([
            $this->rendered_pdf_path,
            "books/translated/{$this->book_id}_{$this->language_code}.pdf",
        ]);
        foreach ($pdfs as $pdf) {
            if ($disk->exists($pdf)) {
                $disk->delete($pdf);
            }
        }

        $disk->deleteDirectory("books/comparison/{$this->book_id}_{$this->language_code}");

        // Edition-specific narration audio tree (narrations/book-{id}/{lang}).
        $disk->deleteDirectory("narrations/book-{$this->book_id}/{$this->language_code}");
    }

    /**
     * Narration rows belonging to this edition (same book + language). A narration
     * is keyed by book_id + language_code, so English and each translated edition
     * own distinct rows.
     */
    public function editionNarrations(): HasMany
    {
        return $this->hasMany(Narration::class, 'book_id', 'book_id')
            ->where('language_code', $this->language_code);
    }

    protected $fillable = [
        'book_id',
        'language_code',
        'language_name',
        'status',
        'rendered_pdf_path',
        'render_status',
        'qa_report',
        'render_fingerprint',
        'narration_fingerprint',
        'output_sha256',
        'approval_tracks',
        'layout_overrides',
        'translation_contract',
        'item_translations',
        'exercise_contract',
        'page_approvals',
        'approved_at',
        // Edition-level classification/discovery fields
        'reading_level_id',
        'education_phase',
        'language_role',
        'price',
        'publication_status',
    ];

    protected $casts = [
        'qa_report' => 'array',
        'approval_tracks' => 'array',
        'layout_overrides' => 'array',
        'translation_contract' => 'array',
        'item_translations' => 'array',
        'exercise_contract' => 'array',
        'page_approvals' => 'array',
        'approved_at' => 'datetime',
    ];

    /**
     * Layout QA states that are NOT allowed to publish (fail-closed, §13).
     */
    public const BLOCKING_RENDER_STATES = ['NEEDS_LAYOUT_REVIEW', 'NEEDS_LANGUAGE_REVIEW', 'RENDERING', 'AUTOMATED_QA'];

    /**
     * Full edition state machine (brief §13).
     */
    public const STATE_ANALYSING = 'ANALYSING';
    public const STATE_TRANSLATING = 'TRANSLATING';
    public const STATE_RENDERING = 'RENDERING';
    public const STATE_AUTOMATED_QA = 'AUTOMATED_QA';
    public const STATE_NEEDS_LAYOUT_REVIEW = 'NEEDS_LAYOUT_REVIEW';
    public const STATE_NEEDS_LANGUAGE_REVIEW = 'NEEDS_LANGUAGE_REVIEW';
    public const STATE_READY_FOR_REVIEW = 'READY_FOR_REVIEW';
    public const STATE_APPROVED = 'APPROVED';
    public const STATE_PUBLISHABLE = 'PUBLISHABLE';

    /**
     * The SINGLE readiness decision for this edition (unified-rendering-and-testing Req 2,
     * A1.5). Delegates to App\Services\Qa\CandidateReadiness — the one authority — binding
     * every required check and approval to the CURRENT render_fingerprint + output_sha256.
     *
     * Required checks are derived from the persisted QA report: a required QA check counts
     * as `passed` only when the report records it passed AND the edition carries a
     * fingerprint + output hash (so a stale/absent render fails closed). Required approval
     * tracks come from server policy ($requiredApprovals); each is bound through the same
     * identities as approveTrack() stores.
     *
     * @param list<string> $requiredApprovals approval-track keys policy requires (default none)
     * @return array{ready: bool, issues: list<array<string,string>>}
     */
    public function readiness(array $requiredApprovals = []): array
    {
        $fingerprint = (string) ($this->render_fingerprint ?? '');
        $outputSha256 = (string) ($this->output_sha256 ?? '');

        // Blocking render state is an immediate, explicit fail (keeps the fail-closed
        // contract even before per-check binding).
        if (in_array($this->render_status, self::BLOCKING_RENDER_STATES, true)) {
            return ['ready' => false, 'issues' => [['code' => 'BLOCKING_RENDER_STATE', 'check' => $this->render_status]]];
        }

        $qa = $this->decodeQaReport();
        $qaChecks = is_array($qa) && isset($qa['checks']) && is_array($qa['checks']) ? $qa['checks'] : [];

        // Build CandidateReadiness-shaped check records from the QA report, binding the
        // edition's current identities. A check absent/!passed in the report → not bound →
        // stale.
        $required = \App\Services\Qa\QaReport::REQUIRED_CHECKS;
        $checks = [];
        foreach ($required as $key) {
            if (($qaChecks[$key] ?? null) === \App\Services\Qa\QaReport::CHECK_PASSED) {
                $checks[$key] = [
                    'status' => \App\Services\Qa\CandidateReadiness::STATUS_PASSED,
                    'candidate_fingerprint' => $fingerprint,
                    'output_sha256' => $outputSha256,
                ];
            }
        }

        // Build approval records from the stored tracks, carrying their bound identities.
        $approvals = [];
        foreach ($this->approval_tracks ?? [] as $name => $entry) {
            if (is_array($entry) && ($entry['approved'] ?? false)) {
                $approvals[$name] = [
                    'status' => \App\Services\Qa\CandidateReadiness::STATUS_APPROVED,
                    'candidate_fingerprint' => $entry['fingerprint'] ?? null,
                    'output_sha256' => $entry['output_sha256'] ?? null,
                ];
            }
        }

        return \App\Services\Qa\CandidateReadiness::evaluate(
            $required, $checks, $requiredApprovals, $approvals, $fingerprint, $outputSha256
        );
    }

    /**
     * Whether this translation may be approved/published. A render flagged for
     * layout review must never proceed silently (overflow-fix brief central rule).
     */
    public function isPublishable(): bool
    {
        return !in_array($this->render_status, self::BLOCKING_RENDER_STATES, true);
    }

    /**
     * Independent publish-gate check (§13): the publish API must verify state itself,
     * not rely on a disabled UI button. Now delegates to the single CandidateReadiness
     * authority via readiness() (A1.5), so a check or approval bound to a stale
     * fingerprint/output hash can never pass. Policy-required approval tracks default to
     * none here; callers enforcing track approval pass them to readiness() directly.
     */
    public function canBePublished(): bool
    {
        return $this->readiness()['ready'];
    }

    /**
     * Robustly decode qa_report regardless of how it was persisted (spec R8.5, Phase 6.3).
     *
     * qa_report is an `array` cast. The correct way to write it is `['qa_report' => $array]`
     * (Laravel JSON-encodes once). A legacy bug wrote `json_encode($array)` INTO the cast
     * field, so Laravel encoded it AGAIN — the cast then returns a STRING (the inner JSON),
     * and every `is_array($qa)` check silently failed, losing all diagnostics. This decoder
     * transparently unwraps either shape so old editions keep their QA data.
     *
     * @return array|null
     */
    public function decodeQaReport(): ?array
    {
        $raw = $this->getAttribute('qa_report');
        // Already an array (correct new path).
        if (is_array($raw)) {
            return $raw;
        }
        // Legacy double-encoded: the cast handed back a JSON string. Decode once more.
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }

    // ---- Gated review + narration (gated-translation-narration-flow) ----

    /**
     * The English source edition is trusted (publisher-proofed) and needs no
     * compare/approval gate. Every other language is machine-produced.
     */
    public function isSourceLanguage(): bool
    {
        return $this->language_code === 'en';
    }

    /**
     * Per-page review progress for this edition: [approved, total]. The total is
     * the number of translated pages (the reviewable unit).
     */
    public function pageApprovalProgress(): array
    {
        $total = $this->translatedPages()->count();
        $approvals = $this->page_approvals ?? [];
        $approved = 0;
        foreach ($approvals as $entry) {
            if (is_array($entry) && ($entry['approved'] ?? false)) {
                $approved++;
            }
        }
        // Never report more approved than exist (stale approvals after re-render).
        return [min($approved, $total), $total];
    }

    /**
     * True when every translated page of this edition is approved. An edition with
     * no pages yet is NOT approvable (nothing has been reviewed).
     */
    public function allPagesApproved(): bool
    {
        [$approved, $total] = $this->pageApprovalProgress();
        return $total > 0 && $approved === $total;
    }

    /**
     * Record a single page's approval state, keyed by page number.
     */
    public function setPageApproval(int $pageNumber, bool $approved): void
    {
        $approvals = $this->page_approvals ?? [];
        $approvals[(string) $pageNumber] = [
            'approved' => $approved,
            'approved_at' => $approved ? now()->toIso8601String() : null,
        ];
        $this->page_approvals = $approvals;
        $this->save();
    }

    /**
     * Promote the edition to APPROVED once all pages are approved and layout QA is
     * clear. Guarded so an edition can never be approved with a blocking render
     * state or unreviewed pages. Returns true when the transition happened.
     */
    public function markApproved(): bool
    {
        if (! $this->allPagesApproved() || ! $this->canBePublished()) {
            return false;
        }
        $this->render_status = self::STATE_APPROVED;
        $this->approved_at = now();
        $this->save();
        return true;
    }

    /**
     * The narration gate. The source language narrates freely; a translated edition
     * may only be narrated once it has been reviewed and APPROVED. Re-checked at
     * narration execution time so a hidden/disabled button is not the only guard.
     */
    public function isApprovedForNarration(): bool
    {
        if ($this->isSourceLanguage()) {
            return true;
        }
        return $this->render_status === self::STATE_APPROVED;
    }

    // ---- Separate approval TRACKS (spec R10.4/R10.5, Phase 7.4) ------------
    public const APPROVAL_TRACKS = ['language', 'layout', 'artwork'];

    /**
     * Approve one track (language | layout | artwork), binding it to the CURRENT render
     * fingerprint. If the content later changes, the stored fingerprint no longer matches
     * and the track reads as NOT approved (auto-invalidation, R10.5) — no stale sign-off.
     */
    public function approveTrack(string $track): void
    {
        if (!in_array($track, self::APPROVAL_TRACKS, true)) {
            throw new \InvalidArgumentException("Unknown approval track: {$track}");
        }
        $tracks = $this->approval_tracks ?? [];
        $tracks[$track] = [
            'approved' => true,
            'approved_at' => now()->toIso8601String(),
            'fingerprint' => $this->render_fingerprint, // the content this approval covers
            'output_sha256' => $this->output_sha256,    // the exact file this approval covers (A1.4)
        ];
        $this->approval_tracks = $tracks;
        $this->save();
    }

    /** Whether a track is approved AGAINST THE CURRENT fingerprint (stale = not approved). */
    public function isTrackApproved(string $track): bool
    {
        $entry = ($this->approval_tracks ?? [])[$track] ?? null;
        if (!is_array($entry) || !($entry['approved'] ?? false)) {
            return false;
        }
        // Fingerprint AND output hash must still match the current render — any content or
        // file change invalidates the sign-off (unified-rendering-and-testing Req 2).
        return ($entry['fingerprint'] ?? null) === $this->render_fingerprint
            && ($entry['output_sha256'] ?? null) === $this->output_sha256;
    }

    /** Explicitly clear a track (e.g. on a reviewer edit). */
    public function invalidateTrack(string $track): void
    {
        $tracks = $this->approval_tracks ?? [];
        unset($tracks[$track]);
        $this->approval_tracks = $tracks;
        $this->save();
    }

    /** Clear ALL tracks whose stored fingerprint OR output hash no longer matches (R10.5 sweep). */
    public function invalidateStaleApprovalTracks(): void
    {
        $tracks = $this->approval_tracks ?? [];
        foreach ($tracks as $name => $entry) {
            $staleFingerprint = ($entry['fingerprint'] ?? null) !== $this->render_fingerprint;
            $staleOutput = ($entry['output_sha256'] ?? null) !== $this->output_sha256;
            if ($staleFingerprint || $staleOutput) {
                unset($tracks[$name]);
            }
        }
        $this->approval_tracks = $tracks;
        $this->save();
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function translatedPages(): HasMany
    {
        return $this->hasMany(TranslatedPage::class)->orderBy('page_number');
    }

    public function getFullText(): string
    {
        return $this->translatedPages()
            ->orderBy('page_number')
            ->pluck('translated_text')
            ->implode("\n\n");
    }

    // ---- Edition-level classification/discovery (book-classification-discovery) ----

    public function readingLevel(): BelongsTo
    {
        return $this->belongsTo(ReadingLevel::class);
    }

    public function features(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'edition_features')->withTimestamps();
    }

    public function rights(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EditionRight::class);
    }

    public function hasFeature(string $slug): bool
    {
        return $this->features->contains('slug', $slug);
    }
}
