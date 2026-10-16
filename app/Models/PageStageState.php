<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** The last completed run of one pipeline stage for one page (task 9.4, Req 3.6). */
class PageStageState extends Model
{
    protected $fillable = ['page_id', 'translation_id', 'edition_key', 'stage', 'inputs_hash', 'completed_at'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }
}
