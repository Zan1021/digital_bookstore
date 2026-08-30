<?php

namespace App\Services\Classification;

use App\Models\Book;
use App\Models\BookDescription;

/**
 * Drafts an age-appropriate store description for a Book (per Work, optionally per
 * edition/language). The draft is stored with status 'suggested' — it is NEVER
 * published until an admin approves it. Enforces length bounds and strips obvious PII
 * patterns; the LLM prompt (in a real provider) is responsible for no-spoiler tone.
 */
class DescriptionWriter
{
    private const SHORT_MAX = 300;
    private const LONG_MAX = 1200;

    public function __construct(private LlmClient $llm)
    {
    }

    /**
     * Draft (or re-draft) a description. Returns the persisted BookDescription (suggested).
     * If an APPROVED description already exists for the language it is left untouched and
     * returned — we never overwrite human-approved copy.
     */
    public function draft(Book $book, string $language = 'en', ?int $translationId = null): BookDescription
    {
        $existingApproved = $book->descriptions()
            ->where('language_code', $language)->where('status', 'approved')->first();
        if ($existingApproved) {
            return $existingApproved;
        }

        $context = [
            'title' => $book->title,
            'author' => $book->author,
            'language' => $language,
            'sample_text' => $this->sample($book),
        ];
        $out = $this->llm->describe($context);

        $short = $this->clean($out['short'] ?? '', self::SHORT_MAX);
        $long = $this->clean($out['long'] ?? '', self::LONG_MAX);

        return BookDescription::updateOrCreate(
            ['book_id' => $book->id, 'language_code' => $language, 'status' => 'suggested'],
            [
                'translation_id' => $translationId,
                'short_text' => $short,
                'long_text' => $long,
                'source' => 'ai',
                'model' => 'llm',
            ]
        );
    }

    /** Trim to bound, collapse whitespace, remove obvious email/phone PII. */
    private function clean(string $text, int $max): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
        // Strip emails + long digit runs (phone-like) — defensive, no PII in store copy.
        $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/', '', $text) ?? $text;
        $text = preg_replace('/\+?\d[\d ()-]{7,}\d/', '', $text) ?? $text;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        return mb_substr($text, 0, $max);
    }

    private function sample(Book $book, int $limit = 4000): string
    {
        return mb_substr($book->getExtractedText(), 0, $limit);
    }
}
