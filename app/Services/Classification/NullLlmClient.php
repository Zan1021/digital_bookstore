<?php

namespace App\Services\Classification;

/**
 * Safe default LLM client used until a real provider is configured. It NEVER calls an
 * external API. It produces conservative, empty-ish suggestions so the pipeline runs
 * end-to-end (deterministic signals still populate) without incurring cost or emitting
 * unreviewed content. Swap the container binding for a real provider in production.
 */
class NullLlmClient implements LlmClient
{
    public function classify(array $context): array
    {
        return [
            'themes' => [],
            'topics' => [],
            'characters' => [],
            'setting' => null,
            'mood' => null,
            'genres' => [],
            'tags' => [],
        ];
    }

    public function describe(array $context): array
    {
        // Neutral placeholder draft (still requires admin approval before publishing).
        $title = $context['title'] ?? 'This book';
        return [
            'short' => "{$title} — a children's book.",
            'long' => '',
        ];
    }
}
