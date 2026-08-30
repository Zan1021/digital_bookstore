<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TagTranslation extends Model
{
    protected $fillable = ['tag_id', 'language_code', 'label'];

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
