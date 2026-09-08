<?php

namespace App\Console\Commands;

use App\Models\Book;
use App\Services\PdfService;
use Illuminate\Console\Command;

/**
 * Regenerate a book's V8 page manifest from the scene graph (builder=scene_graph),
 * so structured pages (vocabulary/table) carry merged-header structure. Existing books
 * uploaded before the scene-graph builder have STALE flat manifests; run this to fix them.
 */
class RebuildManifest extends Command
{
    protected $signature = 'book:rebuild-manifest {book? : Book ID (omit for ALL books)}';
    protected $description = 'Regenerate the V8 page manifest from the scene graph (fixes stale/flat manifests)';

    public function handle(PdfService $pdfService): int
    {
        $bookId = $this->argument('book');

        $books = $bookId
            ? Book::where('id', $bookId)->get()
            : Book::whereNotNull('pdf_path')->get();

        if ($books->isEmpty()) {
            $this->error($bookId ? "Book #{$bookId} not found." : "No books with a source PDF found.");
            return 1;
        }

        $ok = 0;
        $failed = 0;
        foreach ($books as $book) {
            $this->info("Rebuilding manifest: #{$book->id} — {$book->title}");
            if ($pdfService->rebuildManifest($book)) {
                $this->line("  ✓ scene-graph manifest written → {$book->manifest_path}");
                $ok++;
            } else {
                $this->error("  ✗ failed (see log). Source PDF present? {$book->pdf_path}");
                $failed++;
            }
        }

        $this->newLine();
        $this->info("Done. {$ok} rebuilt, {$failed} failed.");
        return $failed === 0 ? 0 : 1;
    }
}
