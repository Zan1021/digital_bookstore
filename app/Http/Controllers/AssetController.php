<?php

namespace App\Http\Controllers;

use App\Assets\AssetAccess;
use App\Assets\AssetRef;
use App\Assets\AssetResolver;
use App\Models\Book;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only way to fetch a protected book file (Req 20.1, 20.2; design §21).
 *
 *   GET /assets/books/{book}/{kind}?lang=&page=   checks staff / entitlement / preview
 *       → 302 to a signed URL valid for a few minutes, bound to the requesting user
 *   GET /assets/serve/{book}/{kind}?...&u=&signature=   streams the file
 *
 * The file path never appears in a URL: both steps resolve it from the database.
 */
class AssetController extends Controller
{
    public function __construct(private AssetResolver $resolver, private AssetAccess $access) {}

    public function show(Request $request, Book $book, string $kind): RedirectResponse
    {
        $asset = $this->assetOrFail($request, $book, $kind);
        $this->authorizeOrFail($request, $asset);

        return redirect()->away(URL::temporarySignedRoute(
            'assets.serve',
            now()->addMinutes(config('assets.signed_url_minutes')),
            array_filter([
                'book' => $book->id,
                'kind' => $kind,
                'lang' => $asset->language,
                'page' => $asset->page,
                'u' => $request->user()?->id ?? 0, // bound to this user (0 = guest preview)
            ], fn ($v) => $v !== null),
        ));
    }

    public function serve(Request $request, Book $book, string $kind): Response
    {
        // The signature covers u, so a link handed to someone else does not work for them.
        abort_unless((int) $request->query('u') === (int) ($request->user()?->id ?? 0), 403);

        $asset = $this->assetOrFail($request, $book, $kind);
        $this->authorizeOrFail($request, $asset); // access may have been revoked since

        return Storage::disk(config('assets.disk'))->response($asset->path, null, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    /**
     * Staff-only working file (review renders, engine compare, upload previews). The
     * path comes from a link the server signed for this staff member; it must stay
     * inside books/ or narrations/ and is served only to staff with books.view.
     */
    public function file(Request $request): Response
    {
        abort_unless((int) $request->query('u') === (int) ($request->user()?->id ?? 0), 403);

        $path = (string) $request->query('path');
        $inAssetTree = str_starts_with($path, 'books/') || str_starts_with($path, 'narrations/');
        abort_if(! $inAssetTree || str_contains($path, '..') || str_contains($path, '\\'), 404);

        $user = $request->user();
        if (! $user || $user->isDeactivated() || ! $user->can('admin.access') || ! $user->can('books.view')) {
            Log::warning('Asset access denied', ['user_id' => $user?->id, 'path' => $path, 'ip' => $request->ip()]);
            abort(403);
        }

        $disk = Storage::disk(config('assets.disk'));
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    private function assetOrFail(Request $request, Book $book, string $kind): AssetRef
    {
        $page = $request->query('page');
        $asset = $this->resolver->resolve($book, $kind, $request->query('lang'),
            is_numeric($page) ? (int) $page : null);

        abort_if($asset === null || ! Storage::disk(config('assets.disk'))->exists($asset->path), 404);

        return $asset;
    }

    private function authorizeOrFail(Request $request, AssetRef $asset): void
    {
        if ($this->access->reason($request->user(), $asset) !== null) {
            return;
        }

        Log::warning('Asset access denied', [
            'user_id' => $request->user()?->id,
            'book_id' => $asset->book->id,
            'kind' => $asset->kind,
            'lang' => $asset->language,
            'page' => $asset->page,
            'ip' => $request->ip(),
        ]);

        abort(403);
    }
}
