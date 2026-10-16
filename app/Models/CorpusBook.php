<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A golden-corpus entry and the book imported from it (task 8.1, Req 26.2). */
class CorpusBook extends Model
{
    protected $fillable = ['key', 'file', 'source', 'features', 'book_id', 'source_sha256', 'imported_at'];

    protected function casts(): array
    {
        return ['features' => 'array', 'imported_at' => 'datetime'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
