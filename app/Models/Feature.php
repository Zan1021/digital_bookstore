<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Feature extends Model
{
    protected $fillable = ['slug', 'group', 'translations'];

    protected $casts = ['translations' => 'array'];

    public function editions(): BelongsToMany
    {
        return $this->belongsToMany(Translation::class, 'edition_features')->withTimestamps();
    }

    public function label(string $lang = 'en'): string
    {
        return $this->translations[$lang] ?? $this->translations['en']
            ?? ucwords(str_replace('_', ' ', $this->slug));
    }
}
