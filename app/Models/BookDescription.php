<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookDescription extends Model
{
    protected $fillable = [
        'book_id', 'translation_id', 'language_code', 'short_text', 'long_text',
        'status', 'source', 'model', 'approved_by', 'approved_at',
    ];

    protected $casts = ['approved_at' => 'datetime'];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function scopeApproved($q)
    {
        return $q->where('status', 'approved');
    }
}
