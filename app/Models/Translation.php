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
        'layout_overrides',
        'translation_contract',
        'item_translations',
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
        'layout_overrides' => 'array',
        'translation_contract' => 'array',
        'item_translations' => 'array',
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
     * Whether this translation may be approved/published. A render flagged for
     * layout review must never proceed silently (overflow-fix brief central rule).
     */
    public function isPublishable(): bool
    {
        return !in_array($this->render_status, self::BLOCKING_RENDER_STATES, true);
    }

    /**
     * Independent publish-gate check (§13): the publish API must verify state
     * itself, not rely on a disabled UI button. Returns true only when layout QA
     * passed and the edition reached a publish-eligible state.
     */
    public function canBePublished(): bool
    {
        if (in_array($this->render_status, self::BLOCKING_RENDER_STATES, true)) {
            return false;
        }
        // qa_report must exist and report the render as publishable.
        $qa = $this->qa_report;
        if (is_array($qa) && array_key_exists('publishable', $qa)) {
            return (bool) $qa['publishable'];
        }
        return in_array($this->render_status, [
            self::STATE_READY_FOR_REVIEW, self::STATE_APPROVED, self::STATE_PUBLISHABLE,
        ], true);
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
