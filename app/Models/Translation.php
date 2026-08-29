<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Translation extends Model
{
    use HasFactory;

    protected $fillable = [
        'book_id',
        'language_code',
        'language_name',
        'status',
        'rendered_pdf_path',
        'render_status',
        'qa_report',
    ];

    protected $casts = [
        'qa_report' => 'array',
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
}
