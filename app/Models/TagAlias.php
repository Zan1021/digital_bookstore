<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TagAlias extends Model
{
    protected $fillable = ['tag_id', 'alias'];

    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class);
    }
}
