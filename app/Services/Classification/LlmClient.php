<?php

namespace App\Services\Classification;

/**
 * Provider-agnostic LLM interface for the subjective classification pass and the
 * store-description draft. Keeping this behind an interface means the model/provider
 * is swappable and AI cost stays admin-only. Tests bind a fake implementation.
 */
interface LlmClient
{
    /**
     * Given the book's context (title, author, sample text, deterministic signals),
     * return subjective suggestions:
     *   ['themes' => string[], 'topics' => string[], 'characters' => string[],
     *    'setting' => string|null, 'mood' => string|null, 'genres' => string[],
     *    'tags' => string[]]
     */
    public function classify(array $context): array;

    /**
     * Draft an age-appropriate store description.
     * Returns ['short' => string, 'long' => string].
     */
    public function describe(array $context): array;
}
