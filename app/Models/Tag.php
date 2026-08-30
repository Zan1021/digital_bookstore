<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tag extends Model
{
    protected $fillable = [
        'slug', 'canonical_name', 'group', 'parent_id', 'status',
        'merged_into_id', 'pinned', 'created_by', 'approved_by',
    ];

    protected $casts = ['pinned' => 'boolean'];

    public function translationsRel(): HasMany
    {
        return $this->hasMany(TagTranslation::class);
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(TagAlias::class);
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_tags')
            ->withPivot(['source', 'confidence', 'approved_at', 'approved_by'])
            ->withTimestamps();
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'merged_into_id');
    }

    public function label(string $lang = 'en'): string
    {
        $t = $this->translationsRel->firstWhere('language_code', $lang)
            ?? $this->translationsRel->firstWhere('language_code', 'en');
        return $t->label ?? $this->canonical_name;
    }

    public function scopeActive($q)
    {
        return $q->where('status', 'active');
    }

    public function scopePending($q)
    {
        return $q->where('status', 'pending');
    }
}
