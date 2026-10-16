<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One page of a book's source PDF in the Scene Engine (task 9.1, design §4). Points, top-left origin. */
class SourcePage extends Model
{
    protected $fillable = ['book_id', 'number', 'width_pt', 'height_pt', 'trim_box', 'has_live_text',
        'plate_pdf_path', 'plate_screen_path'];

    protected function casts(): array
    {
        return ['width_pt' => 'float', 'height_pt' => 'float', 'trim_box' => 'array', 'has_live_text' => 'boolean'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /** The page's blocks in reading order. */
    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class, 'page_id')->orderBy('reading_order');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(PageCheck::class, 'page_id');
    }
}
