<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A book series (task 6.2, Req 16.2): name, description, cover, age band, status and
 * the order of its books (books.series_position). Publisher-owned (Req 25.2).
 */
class Series extends Model
{
    protected $table = 'series';

    protected $fillable = [
        'publisher_id', 'name', 'slug', 'description', 'cover_path',
        'age_min', 'age_max', 'status', 'curriculum_plan',
    ];

    protected $casts = ['curriculum_plan' => 'array'];

    protected static function booted(): void
    {
        static::creating(function (Series $series) {
            $series->publisher_id ??= Publisher::default()->id;
            $series->slug = $series->slug ?: (Str::slug($series->name) ?: 'series');
        });
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    /** The series' books in reading order. */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class)->orderBy('series_position');
    }
}
