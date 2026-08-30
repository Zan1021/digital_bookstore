<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReadingLevel extends Model
{
    protected $fillable = ['slug', 'rank', 'numeric_score', 'translations'];

    protected $casts = ['translations' => 'array'];

    public function editions(): HasMany
    {
        return $this->hasMany(Translation::class);
    }

    public function label(string $lang = 'en'): string
    {
        return $this->translations[$lang] ?? $this->translations['en']
            ?? ucwords(str_replace('_', ' ', $this->slug));
    }
}
