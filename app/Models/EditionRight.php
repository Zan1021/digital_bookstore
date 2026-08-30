<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EditionRight extends Model
{
    protected $fillable = [
        'translation_id', 'rights_holder', 'territories', 'languages',
        'licence_start', 'licence_end', 'digital_rights', 'audio_rights',
        'animation_rights', 'print_rights', 'store_visibility',
    ];

    protected $casts = [
        'territories' => 'array',
        'languages' => 'array',
        'store_visibility' => 'array',
        'licence_start' => 'date',
        'licence_end' => 'date',
        'digital_rights' => 'boolean',
        'audio_rights' => 'boolean',
        'animation_rights' => 'boolean',
        'print_rights' => 'boolean',
    ];

    public function edition(): BelongsTo
    {
        return $this->belongsTo(Translation::class, 'translation_id');
    }

    /**
     * Whether this edition may be shown/sold in a given territory today.
     * Digital store => requires digital_rights, an in-window licence, and the
     * territory permitted (explicit list or wildcard "*").
     */
    public function visibleInTerritory(string $territory, ?\DateTimeInterface $on = null): bool
    {
        $on = $on ?? now();
        if (!$this->digital_rights) {
            return false;
        }
        if ($this->licence_start && $on < $this->licence_start) {
            return false;
        }
        if ($this->licence_end && $on > $this->licence_end) {
            return false;
        }
        $terr = $this->territories ?? ['*'];
        return in_array('*', $terr, true) || in_array($territory, $terr, true);
    }
}
