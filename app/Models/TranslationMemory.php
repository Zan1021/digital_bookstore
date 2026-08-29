<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TranslationMemory extends Model
{
    use HasFactory;

    protected $table = 'translation_memory';

    protected $fillable = [
        'book_id',
        'language_code',
        'source_term',
        'translated_term',
        'context',
        'category',
        'first_page',
        'confidence',
        'review_required',
        'locked',
        'notes',
    ];

    protected $casts = [
        'confidence' => 'float',
        'review_required' => 'boolean',
        'locked' => 'boolean',
        'first_page' => 'integer',
    ];

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * Get all memory entries for a specific book and language.
     */
    public static function forBook(int $bookId, string $languageCode): \Illuminate\Database\Eloquent\Collection
    {
        return static::where('book_id', $bookId)
            ->where('language_code', $languageCode)
            ->orderBy('source_term')
            ->get();
    }

    /**
     * Get the approved translation for a specific term.
     */
    public static function lookup(int $bookId, string $languageCode, string $sourceTerm): ?string
    {
        $entry = static::where('book_id', $bookId)
            ->where('language_code', $languageCode)
            ->where('source_term', $sourceTerm)
            ->first();

        return $entry?->translated_term;
    }

    /**
     * Store or update a translation memory entry.
     */
    public static function remember(
        int $bookId,
        string $languageCode,
        string $sourceTerm,
        string $translatedTerm,
        string $category = 'general',
        ?int $pageNumber = null,
        ?string $context = null,
    ): static {
        return static::updateOrCreate(
            [
                'book_id' => $bookId,
                'language_code' => $languageCode,
                'source_term' => $sourceTerm,
            ],
            [
                'translated_term' => $translatedTerm,
                'category' => $category,
                'first_page' => $pageNumber,
                'context' => $context,
            ]
        );
    }

    /**
     * Format the translation memory as a prompt-friendly string.
     */
    public static function asPromptContext(int $bookId, string $languageCode): string
    {
        $entries = static::forBook($bookId, $languageCode);

        if ($entries->isEmpty()) {
            return '';
        }

        $lines = ["TRANSLATION MEMORY (use these approved translations consistently):"];
        foreach ($entries as $entry) {
            $line = "- \"{$entry->source_term}\" → \"{$entry->translated_term}\"";
            if ($entry->context) {
                $line .= " ({$entry->context})";
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
