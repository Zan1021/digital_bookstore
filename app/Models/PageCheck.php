<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Gate results for one page of an edition in one run (task 9.1, Req 11). */
class PageCheck extends Model
{
    protected $fillable = ['translation_id', 'page_id', 'run_id', 'results', 'passed'];

    protected function casts(): array
    {
        return ['results' => 'array', 'passed' => 'boolean'];
    }

    protected static function booted(): void
    {
        // Fail closed (R2): a page passes only when every recorded gate passed.
        static::saving(function (PageCheck $check) {
            $results = (array) $check->results;
            $check->passed = $results !== [] && collect($results)->every(fn ($r) => ($r['passed'] ?? false) === true);
        });
    }

    public function edition(): BelongsTo
    {
        return $this->belongsTo(Translation::class, 'translation_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(SourcePage::class, 'page_id');
    }
}
