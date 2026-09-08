<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Narration extends Model
{
    use HasFactory;

    protected $fillable = [
        'book_id',
        'language_code',
        'language_name',
        'voice_id',
        'voice_name',
        'status',
        'is_outdated',
        'audio_path',
        'page_audio_paths',
        'duration_seconds',
    ];

    protected $casts = [
        'page_audio_paths' => 'array',
        'is_outdated' => 'boolean',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
