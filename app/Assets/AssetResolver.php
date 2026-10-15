<?php

namespace App\Assets;

use App\Models\Book;

/**
 * Turns (book, kind, language, page) into the stored file, using the paths recorded on
 * the book, its editions and its narrations. Returns null when no such asset exists.
 * Kinds: source-pdf, edition-pdf (language), audio (language + page).
 * Tasks 3.2/3.3 add plates and page text as they move onto the private disk.
 */
class AssetResolver
{
    public const KINDS = ['source-pdf', 'edition-pdf', 'audio'];

    public function resolve(Book $book, string $kind, ?string $language = null, ?int $page = null): ?AssetRef
    {
        $path = match ($kind) {
            'source-pdf' => $book->pdf_path,
            'edition-pdf' => $language
                ? $book->translations()->where('language_code', $language)->value('rendered_pdf_path')
                : null,
            'audio' => $this->audioPath($book, $language, $page),
            default => null,
        };

        if (! is_string($path) || $path === '') {
            return null;
        }

        return new AssetRef($book, $kind, $path,
            $kind === 'source-pdf' ? null : $language,
            $kind === 'audio' ? $page : null);
    }

    private function audioPath(Book $book, ?string $language, ?int $page): ?string
    {
        if (! $language || ! $page) {
            return null;
        }

        // Only current, completed narration (an outdated one must not be served, Req 6.2).
        $narration = $book->narrations()
            ->where('language_code', $language)
            ->where('status', 'completed')
            ->where('is_outdated', false)
            ->latest('id')
            ->first();

        $paths = $narration?->page_audio_paths ?? [];

        return $paths[$page] ?? $paths[(string) $page] ?? null;
    }
}
