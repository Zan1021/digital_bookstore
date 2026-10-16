<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A language edition's text for one block (task 9.1, design §4, Req 6, 12). */
class BlockTranslation extends Model
{
    public const STATUSES = ['draft', 'approved'];

    protected $fillable = ['translation_id', 'block_id', 'text', 'alt_short_text', 'status', 'source_hash',
        'edited_by', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (BlockTranslation $bt) {
            if (! in_array($bt->status, self::STATUSES, true)) {
                throw new \InvalidArgumentException("Unknown block translation status '{$bt->status}'.");
            }
            // Stamp the source it was made from, so a later source change marks it stale.
            $bt->source_hash ??= $bt->block?->sourceHash();
        });
    }

    /** The block's source text changed after this translation was made (design §4). */
    public function isStale(): bool
    {
        return $this->source_hash !== $this->block->sourceHash();
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(Translation::class, 'translation_id');
    }

    public function block(): BelongsTo
    {
        return $this->belongsTo(Block::class);
    }

    public function layout(): HasOne
    {
        return $this->hasOne(ResolvedLayout::class);
    }
}
