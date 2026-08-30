<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AudienceRange extends Model
{
    protected $fillable = ['band', 'min_age', 'max_age', 'sort_order', 'translations'];

    protected $casts = ['translations' => 'array'];

    /** Find the band that contains a given age (kids-domain age bucketing). */
    public static function forAge(?int $age): ?self
    {
        if ($age === null) {
            return null;
        }
        return static::where('min_age', '<=', $age)->where('max_age', '>=', $age)
            ->orderBy('sort_order')->first();
    }
}
