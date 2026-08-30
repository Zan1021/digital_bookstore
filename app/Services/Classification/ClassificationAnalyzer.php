<?php

namespace App\Services\Classification;

use App\Models\Book;
use App\Models\ClassificationSuggestion;
use App\Models\Category;
use App\Models\ReadingLevel;

/**
 * Orchestrates classification for a Book: deterministic signals first (SceneSignals over
 * the already-extracted page text), then a subjective LLM pass (themes/mood/tags/etc.).
 * Writes classification_suggestions rows (admin-only, confidence-scored). Sensitive
 * fields (age range) are flagged requires_confirmation and never auto-published.
 *
 * Book-agnostic and V8-safe: reads book_pages.extracted_text only; never touches the
 * render engine.
 */
class ClassificationAnalyzer
{
    public function __construct(
        private LlmClient $llm,
        private TagResolver $tagResolver,
    ) {
    }

    /** Build the page array SceneSignals expects from the book's extracted text. */
    private function pagesFor(Book $book): array
    {
        return $book->pages()
            ->orderBy('page_number')
            ->get(['page_number', 'extracted_text'])
            ->map(fn ($p) => [
                'page_number' => $p->page_number,
                'text' => (string) $p->extracted_text,
            ])->all();
    }

    /**
     * Run analysis and persist suggestions. Returns the suggestion set (also stored).
     * Does NOT publish anything — suggestions await review.
     */
    public function analyze(Book $book): array
    {
        // Clear stale suggestions for a clean re-run.
        ClassificationSuggestion::where('book_id', $book->id)->delete();

        $pages = $this->pagesFor($book);
        $signals = new SceneSignals($pages);

        $suggestions = [];

        // --- Deterministic dimensions ---
        $bt = $signals->bookType();
        $suggestions[] = $this->store($book, 'book_type', ['value' => $bt['value']], $bt['confidence']);

        $rl = $signals->readingLevel();
        $suggestions[] = $this->store($book, 'reading_level', ['value' => $rl['value']], $rl['confidence']);

        $age = $signals->ageHint();
        // Age is sensitive -> requires explicit human confirmation, never auto-published.
        $suggestions[] = $this->store($book, 'age_range',
            ['min' => $age['min'], 'max' => $age['max']], $age['confidence'], requiresConfirmation: true);

        // Primary category derived from book type (a safe default mapping).
        $catSlug = $this->categoryForBookType($bt['value']);
        if ($catSlug) {
            $suggestions[] = $this->store($book, 'primary_category', ['value' => $catSlug], $bt['confidence'] * 0.9);
        }

        // --- Subjective LLM pass ---
        $context = [
            'title' => $book->title,
            'author' => $book->author,
            'sample_text' => $this->sampleText($pages),
            'signals' => $signals->all(),
        ];
        $subjective = $this->llm->classify($context);

        foreach (['themes' => 'theme', 'topics' => 'topic', 'genres' => 'genre'] as $key => $field) {
            foreach (($subjective[$key] ?? []) as $val) {
                $suggestions[] = $this->store($book, $field, ['value' => $val], 0.6);
            }
        }
        if (!empty($subjective['setting'])) {
            $suggestions[] = $this->store($book, 'setting', ['value' => $subjective['setting']], 0.6);
        }
        if (!empty($subjective['mood'])) {
            $suggestions[] = $this->store($book, 'mood', ['value' => $subjective['mood']], 0.6);
        }
        foreach (($subjective['characters'] ?? []) as $val) {
            $suggestions[] = $this->store($book, 'character', ['value' => $val], 0.55);
        }

        // Tags: map to canonical identities; unmatched become PENDING (approval queue).
        $tagStrings = $subjective['tags'] ?? [];
        $resolved = $this->tagResolver->resolveMany($tagStrings);
        foreach ($resolved['matched'] as $tag) {
            $suggestions[] = $this->store($book, 'tag', ['tag_id' => $tag->id, 'slug' => $tag->slug, 'pending' => false], 0.7);
        }
        foreach ($resolved['pending'] as $tag) {
            $suggestions[] = $this->store($book, 'tag', ['tag_id' => $tag->id, 'slug' => $tag->slug, 'pending' => true], 0.5);
        }

        return $suggestions;
    }

    private function store(Book $book, string $field, array $value, ?float $confidence, bool $requiresConfirmation = false): ClassificationSuggestion
    {
        return ClassificationSuggestion::create([
            'book_id' => $book->id,
            'field' => $field,
            'value' => $value,
            'confidence' => $confidence !== null ? round($confidence, 3) : null,
            'requires_confirmation' => $requiresConfirmation,
            'model' => 'deterministic+llm',
            'engine_version' => 'classify-v1',
        ]);
    }

    private function categoryForBookType(string $type): ?string
    {
        $map = [
            'picture_book' => 'picture-books',
            'early_reader' => 'early-readers',
            'storybook' => 'childrens-fiction',
            'chapter_book' => 'childrens-fiction',
        ];
        $slug = $map[$type] ?? null;
        return ($slug && Category::where('slug', $slug)->exists()) ? $slug : null;
    }

    private function sampleText(array $pages, int $limit = 4000): string
    {
        $text = trim(implode("\n", array_map(fn ($p) => $p['text'], $pages)));
        return mb_substr($text, 0, $limit);
    }
}
