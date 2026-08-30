<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassificationReview extends Model
{
    protected $fillable = [
        'book_id', 'reviewer_id', 'field', 'decision', 'final_value', 'reviewed_at',
    ];

    protected $casts = [
        'final_value' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
