<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class DiscoveryEvent extends Model
{
    protected $fillable = ['type', 'payload', 'day', 'count'];

    protected $casts = ['payload' => 'array', 'day' => 'date'];

    /**
     * Record a privacy-safe aggregate event. Payload MUST NOT contain user
     * identifiers — only search terms, filter names, book/collection ids.
     */
    public static function track(string $type, array $payload = []): void
    {
        static::create([
            'type' => $type,
            'payload' => $payload,
            'day' => Carbon::today(),
            'count' => 1,
        ]);
    }
}
