<?php

namespace App\Assets;

use App\Models\Book;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Where protected book files live and how browsers reach them (Req 20.1, 20.2).
 *
 *  disk()              the private asset disk (config assets.disk) for source PDFs,
 *                      editions, manifests, comparison renders, plates and audio
 *  url(...)            gatekeeper URL for a book asset (AssetController::show)
 *  staffFileUrl($path) signed, staff-only link to a working file (review images,
 *                      engine compare, upload previews); the server picks the path
 *  coverUrl($book)     public cover image — the only book file the store may expose
 */
class AssetStorage
{
    public static function disk(): Filesystem
    {
        return Storage::disk(config('assets.disk'));
    }

    /** Gatekeeper URL for a book asset; the browser is redirected to a signed URL. */
    public static function url(Book|int $book, string $kind, ?string $language = null, ?int $page = null): string
    {
        return route('assets.show', array_filter([
            'book' => $book instanceof Book ? $book->id : $book,
            'kind' => $kind,
            'lang' => $language,
            'page' => $page,
        ], fn ($v) => $v !== null));
    }

    /** URL for a book's PDF in a language: the edition when it exists, else the source. */
    public static function pdfUrl(Book $book, ?string $language): string
    {
        $hasEdition = $language && $language !== $book->original_language
            && $book->translations()->where('language_code', $language)->whereNotNull('rendered_pdf_path')->exists();

        return $hasEdition ? self::url($book, 'edition-pdf', $language) : self::url($book, 'source-pdf');
    }

    /** Signed link to a staff-only working file on the asset disk. */
    public static function staffFileUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return URL::temporarySignedRoute('assets.file', now()->addMinutes(config('assets.staff_file_minutes')), [
            'path' => $path,
            'u' => auth()->id() ?? 0,
        ]);
    }

    /** Public cover image URL, or null when the book has none yet. */
    public static function coverUrl(Book $book): ?string
    {
        return $book->cover_image ? asset('storage/'.$book->cover_image) : null;
    }
}
