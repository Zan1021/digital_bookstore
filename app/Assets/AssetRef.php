<?php

namespace App\Assets;

use App\Models\Book;

/**
 * One protected file of a book: what it is (kind, language, page) and where it lives on
 * the asset disk. Built only by AssetResolver from database paths, never from a path in
 * a request, so a URL can never point at an arbitrary file.
 */
final class AssetRef
{
    public function __construct(
        public readonly Book $book,
        public readonly string $kind,
        public readonly string $path,
        public readonly ?string $language = null,
        public readonly ?int $page = null,
    ) {}

    /** A single-page asset can be a preview; a whole-book file never is. */
    public function isPageScoped(): bool
    {
        return $this->page !== null;
    }
}
