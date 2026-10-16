<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A saved AI result, reused when the same input comes again (task 7.2, Req 3.5). */
class AiCacheEntry extends Model
{
    protected $table = 'ai_cache';

    protected $fillable = ['input_hash', 'kind', 'provider', 'model', 'units', 'text', 'alignment', 'blob_path'];

    protected function casts(): array
    {
        return ['units' => 'array', 'alignment' => 'array'];
    }
}
