<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A monthly AI spending limit in rand (task 7.2, Req 22.5): operation null = the total across
 * all operations, otherwise that operation alone. No row = no limit. Set in admin settings
 * (task 41.3).
 */
class AiSpendingLimit extends Model
{
    protected $fillable = ['operation', 'monthly_limit_zar'];

    protected function casts(): array
    {
        return ['monthly_limit_zar' => 'float'];
    }
}
