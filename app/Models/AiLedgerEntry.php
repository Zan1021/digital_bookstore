<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** One AI call (or cache hit) and what it cost (task 7.2, Req 22.3). */
class AiLedgerEntry extends Model
{
    protected $table = 'ai_ledger';

    public $timestamps = false;

    protected $fillable = ['provider', 'model', 'operation', 'book_id', 'translation_id', 'units',
        'cost_zar', 'duration_ms', 'cache_hit', 'input_hash', 'at'];

    protected function casts(): array
    {
        return ['units' => 'array', 'cost_zar' => 'float', 'cache_hit' => 'boolean', 'at' => 'datetime'];
    }

    /** Plain names for the operation labels (config/ai.php operations); unknown labels show as stored. */
    public const OPERATIONS = [
        'translate' => 'Translation',
        'metadata' => 'Book details suggestions',
        'visual_qa' => 'Page checks',
        'locate_art_text' => 'Finding text in artwork',
        'art_repair' => 'Artwork repair',
        'narrate' => 'Narration',
    ];

    public static function operationName(string $operation): string
    {
        return self::OPERATIONS[$operation] ?? ucfirst(str_replace('_', ' ', $operation));
    }

    public function book(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function translation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Translation::class);
    }

    public function scopeThisMonth(Builder $query): Builder
    {
        return $query->where('at', '>=', now()->startOfMonth());
    }

    /** Rand spent so far this calendar month, optionally for one operation. */
    public static function spentThisMonth(?string $operation = null): float
    {
        return (float) static::query()->thisMonth()
            ->when($operation !== null, fn ($q) => $q->where('operation', $operation))
            ->sum('cost_zar');
    }
}
