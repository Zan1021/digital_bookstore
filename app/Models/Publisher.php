<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A publisher (task 6.1, Req 25.2). The store launches with one — Mthombothi Studios —
 * but publisher-owned records carry publisher_id so more can be added without redesign.
 */
class Publisher extends Model
{
    protected $fillable = ['name', 'slug', 'settings'];

    protected $casts = ['settings' => 'array'];

    /** The publisher new records belong to while the store is single-publisher. */
    public static function default(): self
    {
        return static::where('slug', config('publisher.default_slug'))->firstOrFail();
    }

    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }
}
