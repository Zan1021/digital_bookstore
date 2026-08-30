<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ContentAdvisory extends Model
{
    protected $fillable = ['slug', 'translations'];

    protected $casts = ['translations' => 'array'];

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_content_advisories')
            ->withPivot(['source', 'approved_at'])->withTimestamps();
    }

    public function label(string $lang = 'en'): string
    {
        return $this->translations[$lang] ?? $this->translations['en']
            ?? ucwords(str_replace('_', ' ', $this->slug));
    }
}
