<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where the layout service put a block's translated text (task 9.1, Req 8.6–8.7): the reader
 * and exports render from this without measuring again. ladder_step is the Req 8.2 step used.
 */
class ResolvedLayout extends Model
{
    protected $fillable = ['block_translation_id', 'font_family', 'font_size', 'line_height', 'letter_spacing',
        'box', 'ladder_step', 'lines', 'fits'];

    protected function casts(): array
    {
        return ['font_size' => 'float', 'line_height' => 'float', 'letter_spacing' => 'float', 'box' => 'array',
            'lines' => 'array', 'fits' => 'boolean', 'ladder_step' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (ResolvedLayout $layout) {
            if ($layout->ladder_step < 1 || $layout->ladder_step > 7) {
                throw new \InvalidArgumentException("Fit ladder step must be 1–7 (Req 8.2), got {$layout->ladder_step}.");
            }
        });
    }

    public function blockTranslation(): BelongsTo
    {
        return $this->belongsTo(BlockTranslation::class);
    }
}
