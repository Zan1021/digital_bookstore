<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassificationSuggestion extends Model
{
    protected $fillable = [
        'book_id', 'translation_id', 'field', 'value', 'confidence',
        'requires_confirmation', 'model', 'engine_version',
    ];

    protected $casts = [
        'value' => 'array',
        'confidence' => 'float',
        'requires_confirmation' => 'boolean',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
