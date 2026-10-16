<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Services\PdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A book's page manifest holds its full text, so it lives on the private asset disk — the
 * same place every reader looks for it (Req 20.1). Found 2026-10-16: it was written to the
 * public disk, which made it downloadable AND invisible to translation, so every new book
 * fell back to V8's legacy flat translation path.
 */
class ManifestStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_manifest_is_written_to_the_private_disk_only(): void
    {
        Storage::fake(config('assets.disk'));
        Storage::fake('public');
        $private = Storage::disk(config('assets.disk'));

        $pdf = $private->path('books/pdfs/m.pdf');
        @mkdir(dirname($pdf), 0775, true);
        $make = new Process(['python', '-c', <<<'PY'
import sys, pymupdf
doc = pymupdf.open(); page = doc.new_page(width=420, height=595)
page.insert_text((40, 300), "Kolulu lives in a world of imagination.", fontsize=14)
doc.save(sys.argv[1])
PY, $pdf]);
        $make->run();
        if (! $make->isSuccessful()) {
            $this->markTestSkipped('Python with PyMuPDF is needed: '.$make->getErrorOutput());
        }

        $book = Book::create(['title' => 'M', 'page_count' => 1, 'pdf_path' => 'books/pdfs/m.pdf', 'original_language' => 'en']);

        $this->assertTrue(app(PdfService::class)->rebuildManifest($book), 'manifest build failed');

        $book->refresh();
        $this->assertSame("books/manifests/{$book->id}_manifest.json", $book->manifest_path);
        $this->assertTrue($private->exists($book->manifest_path), 'manifest must be on the private disk');
        $this->assertStringContainsString('imagination', $private->get($book->manifest_path));
        $this->assertSame([], Storage::disk('public')->allFiles('books'), 'nothing of the book may land on the public disk');
    }

    public function test_no_code_writes_book_files_to_the_public_disk(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path())) as $file) {
            if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), 'MoveAssetsToPrivate')) {
                continue; // the move command reads the old public files on purpose
            }
            foreach (file($file->getPathname()) as $n => $line) {
                if (preg_match('~app/public/(books|narrations)|disk\(\s*[\'"]public[\'"]\s*\)->(put|write|copy|move)~', $line)) {
                    $offenders[] = $file->getFilename().':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'protected book files belong on AssetStorage::disk()');
    }
}
