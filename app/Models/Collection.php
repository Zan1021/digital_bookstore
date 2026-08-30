<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Collection extends Model
{
    protected $fillable = ['slug', 'type', 'translations', 'rules', 'active', 'sort_order'];

    protected $casts = [
        'translations' => 'array',
        'rules' => 'array',
        'active' => 'boolean',
    ];

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'collection_books')
            ->withPivot(['pinned', 'sort_order'])->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function label(string $lang = 'en'): string
    {
        return $this->translations[$lang] ?? $this->translations['en']
            ?? ucwords(str_replace('-', ' ', $this->slug));
    }
}
