<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A language the platform can work in (task 6.3, design §4): name, text direction,
 * script and hyphenation for layout, and whether it is offered. Replaces the language
 * lists that were hard-coded in TranslationService and the upload wizard.
 */
class Language extends Model
{
    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'name', 'direction', 'script', 'hyphenation', 'enabled', 'sort_order'];

    protected $casts = ['hyphenation' => 'boolean', 'enabled' => 'boolean'];

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true)->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Enabled languages a book can be translated into: code => name, excluding the
     * book's own language (English by default).
     *
     * @return array<string, string>
     */
    public static function targetOptions(?string $sourceCode = 'en'): array
    {
        return static::enabled()->where('code', '!=', $sourceCode ?? 'en')->pluck('name', 'code')->all();
    }

    /**
     * Name of a language a book may be translated into: enabled and not the book's own
     * language. Anything else is refused (fail closed), as the hard-coded list did.
     */
    public static function targetNameOrFail(string $code, ?string $sourceCode = 'en'): string
    {
        $name = $code === ($sourceCode ?? 'en') ? null : static::enabled()->whereKey($code)->value('name');

        return $name ?? throw new \InvalidArgumentException("Unsupported language: {$code}");
    }

    /** Display name for a code; the code itself when unknown (never fails a job). */
    public static function nameFor(string $code): string
    {
        return static::whereKey($code)->value('name') ?? $code;
    }

    public function isRtl(): bool
    {
        return $this->direction === 'rtl';
    }
}
