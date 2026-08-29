<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranslatedPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'translation_id',
        'book_page_id',
        'page_number',
        'translated_text',
        'back_translation',
        'confidence_score',
        'quality_flag',
        'quality_notes',
        'reviewer_notes',
        'review_status',
    ];

    protected $casts = [
        'confidence_score' => 'float',
    ];

    public function translation(): BelongsTo
    {
        return $this->belongsTo(Translation::class);
    }

    public function bookPage(): BelongsTo
    {
        return $this->belongsTo(BookPage::class);
    }
}
