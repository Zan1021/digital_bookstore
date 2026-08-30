<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'slug', 'parent_id', 'group', 'status', 'sort_order',
        'merged_into_id', 'translations', 'created_by', 'approved_by',
    ];

    protected $casts = ['translations' => 'array'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order');
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_categories')
            ->withPivot(['is_primary', 'source', 'confidence', 'approved_at', 'approved_by'])
            ->withTimestamps();
    }

    /** Translated label with English fallback, then slug. */
    public function label(string $lang = 'en'): string
    {
        return $this->translations[$lang]
            ?? $this->translations['en']
            ?? ucwords(str_replace('-', ' ', $this->slug));
    }

    /** The live category to use, following a merge chain. */
    public function effective(): Category
    {
        $c = $this;
        $guard = 0;
        while ($c->merged_into_id && $guard++ < 20) {
            $c = $c->relationLoaded('mergedInto') ? $c->mergedInto : Category::find($c->merged_into_id);
            if (!$c) {
                break;
            }
        }
        return $c ?? $this;
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'merged_into_id');
    }

    public function scopeActive($q)
    {
        return $q->where('status', 'active');
    }
}
