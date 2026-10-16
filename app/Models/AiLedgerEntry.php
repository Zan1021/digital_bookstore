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
