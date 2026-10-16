<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A text block on a source page (task 9.1, design §4–5, Req 4). Its ULID stays the same
 * across re-analysis, so translations and reviewer edits keep pointing at it.
 */
class Block extends Model
{
    use HasUlids;

    /** Req 4.3/4.8: exactly these eight; a new one needs a spec change. */
    public const TREATMENTS = ['translate_reflow', 'translate_fixed', 'keep', 'template', 'derive', 'rewrite', 'structure', 'in_art'];

    /** Design §4 kinds; `table` and `list` are the parents that cells and list items point to. */
    public const KINDS = ['paragraph', 'heading', 'label', 'cell', 'list_item', 'bubble', 'in_art', 'table', 'list'];

    /** Req 7.1 text roles, mapped to fonts by the publisher's font profile. */
    public const ROLES = ['body', 'heading', 'title', 'educational', 'caption', 'label', 'legal'];

    protected $fillable = ['book_id', 'page_id', 'parent_id', 'kind', 'role', 'treatment', 'treatment_confidence',
        'treatment_confirmed', 'bbox', 'allowed_area', 'reading_order', 'style', 'visual_size_factor', 'source_text', 'structure'];

    protected function casts(): array
    {
        return ['bbox' => 'array', 'allowed_area' => 'array', 'style' => 'array', 'structure' => 'array',
            'treatment_confidence' => 'float', 'treatment_confirmed' => 'boolean', 'visual_size_factor' => 'float'];
    }

    protected static function booted(): void
    {
        // Fail closed (R2): an unknown kind, role or treatment is a bug, not data.
        static::saving(function (Block $block) {
            foreach (['kind' => self::KINDS, 'role' => self::ROLES] as $field => $allowed) {
                if (! in_array($block->{$field}, $allowed, true)) {
                    throw new \InvalidArgumentException("Unknown block {$field} '{$block->{$field}}'.");
                }
            }
            if ($block->treatment !== null && ! in_array($block->treatment, self::TREATMENTS, true)) {
                throw new \InvalidArgumentException("Unknown treatment '{$block->treatment}' (Req 4.8 allows only the eight).");
            }
        });
    }

    /** Hash of the source text; a translation made from a different hash is stale. */
    public static function hashText(string $text): string
    {
        return hash('sha256', $text);
    }

    public function sourceHash(): string
    {
        return self::hashText((string) $this->source_text);
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(SourcePage::class, 'page_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('reading_order');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(BlockTranslation::class);
    }
}
