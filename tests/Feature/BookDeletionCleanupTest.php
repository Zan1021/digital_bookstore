<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookPage;
use App\Models\Narration;
use App\Models\ProcessingJob;
use App\Models\Translation;
use App\Models\TranslatedPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression tests for the Book teardown contract.
 *
 * BUG (2026-09-08): deleting a book from the admin dashboard left orphaned files
 * on disk — narration MP3s + timing JSONs (per-book directory tree), engine
 * comparison renders, and the manifest — because the delete method only removed a
 * few guessed paths. Cleanup now lives in Book::deleting() so every delete path
 * (UI, tinker, tests) tears down files AND child records completely.
 *
 * "Delete" must mean: no book row, no child rows, and zero files left on disk.
 */
class BookDeletionCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function makeBookWithEverything(): Book
    {
        $book = Book::create([
            'title' => 'Teardown Test',
            'original_language' => 'en',
            'page_count' => 3,
            'status' => 'ready',
            'pdf_path' => 'books/pdfs/teardown.pdf',
            'cover_image' => 'covers/teardown.png',
            'manifest_path' => 'books/manifests/custom_manifest.json',
        ]);

        // Source + book-level files.
        Storage::disk('public')->put($book->pdf_path, 'PDF');
        Storage::disk('public')->put($book->cover_image, 'PNG');
        Storage::disk('public')->put($book->manifest_path, '{}');
        // Manifest by naming convention (books/manifests/{id}_manifest.json).
        Storage::disk('public')->put("books/manifests/{$book->id}_manifest.json", '{}');

        // Pages.
        $pageIds = [];
        foreach (range(1, 3) as $n) {
            $pageIds[$n] = BookPage::create([
                'book_id' => $book->id,
                'page_number' => $n,
                'extracted_text' => "Page {$n}",
            ])->id;
        }

        // A processing job.
        ProcessingJob::create([
            'book_id' => $book->id,
            'type' => 'translation',
            'status' => 'completed',
        ]);

        // Afrikaans translation with translated pages + rendered PDF + comparison dir.
        $translation = Translation::create([
            'book_id' => $book->id,
            'language_code' => 'af',
            'language_name' => 'Afrikaans',
            'status' => 'draft',
            'rendered_pdf_path' => "books/translated/{$book->id}_af.pdf",
        ]);
        Storage::disk('public')->put($translation->rendered_pdf_path, 'PDF');
        // Legacy guessed path too.
        Storage::disk('public')->put("books/translated/{$book->id}_af.pdf", 'PDF');
        foreach (range(1, 3) as $n) {
            TranslatedPage::create([
                'translation_id' => $translation->id,
                'book_page_id' => $pageIds[$n],
                'page_number' => $n,
                'translated_text' => "Bladsy {$n}",
            ]);
        }
        Storage::disk('public')->put("books/comparison/{$book->id}_af/source/source_001.png", 'PNG');
        Storage::disk('public')->put("books/comparison/{$book->id}_af/translated/translated_001.png", 'PNG');
        Storage::disk('public')->put("books/comparison/{$book->id}_af/comparison_report.json", '{}');

        // Narration audio tree (the exact shape that used to orphan).
        $audioPaths = [];
        foreach (range(1, 3) as $n) {
            $mp3 = "narrations/book-{$book->id}/en/page-{$n}.mp3";
            $timing = "narrations/book-{$book->id}/en/page-{$n}-timing.json";
            Storage::disk('public')->put($mp3, 'MP3');
            Storage::disk('public')->put($timing, '{}');
            $audioPaths[] = $mp3;
        }
        Narration::create([
            'book_id' => $book->id,
            'language_code' => 'en',
            'language_name' => 'English',
            'voice_id' => 'test-voice',
            'voice_name' => 'Test Voice',
            'status' => 'completed',
            'page_audio_paths' => $audioPaths,
        ]);

        return $book->fresh();
    }

    public function test_deleting_a_book_removes_all_files_and_records(): void
    {
        $book = $this->makeBookWithEverything();
        $bookId = $book->id;

        // Sanity: everything exists first.
        Storage::disk('public')->assertExists("narrations/book-{$bookId}/en/page-1.mp3");
        Storage::disk('public')->assertExists("books/comparison/{$bookId}_af/source/source_001.png");
        $this->assertDatabaseCount('translated_pages', 3);

        $book->delete();

        // --- Database: no book, no children ---
        $this->assertDatabaseMissing('books', ['id' => $bookId]);
        $this->assertDatabaseCount('book_pages', 0);
        $this->assertDatabaseCount('translations', 0);
        $this->assertDatabaseCount('translated_pages', 0);
        $this->assertDatabaseCount('narrations', 0);
        $this->assertDatabaseCount('processing_jobs', 0);

        // --- Disk: nothing left behind ---
        Storage::disk('public')->assertMissing('books/pdfs/teardown.pdf');
        Storage::disk('public')->assertMissing('covers/teardown.png');
        Storage::disk('public')->assertMissing('books/manifests/custom_manifest.json');
        Storage::disk('public')->assertMissing("books/manifests/{$bookId}_manifest.json");
        Storage::disk('public')->assertMissing("books/translated/{$bookId}_af.pdf");
        Storage::disk('public')->assertMissing("narrations/book-{$bookId}");
        Storage::disk('public')->assertMissing("narrations/book-{$bookId}/en/page-1.mp3");
        Storage::disk('public')->assertMissing("narrations/book-{$bookId}/en/page-3-timing.json");
        Storage::disk('public')->assertMissing("books/comparison/{$bookId}_af");
        Storage::disk('public')->assertMissing("books/comparison/{$bookId}_af/translated/translated_001.png");
    }

    public function test_deleting_one_book_leaves_another_books_files_intact(): void
    {
        $keep = $this->makeBookWithEverything();
        $remove = $this->makeBookWithEverything();

        $remove->delete();

        // Removed book gone.
        Storage::disk('public')->assertMissing("narrations/book-{$remove->id}");
        Storage::disk('public')->assertMissing("books/comparison/{$remove->id}_af");

        // Kept book untouched.
        Storage::disk('public')->assertExists("narrations/book-{$keep->id}/en/page-1.mp3");
        Storage::disk('public')->assertExists("books/comparison/{$keep->id}_af/source/source_001.png");
        $this->assertDatabaseHas('books', ['id' => $keep->id]);
    }
}
