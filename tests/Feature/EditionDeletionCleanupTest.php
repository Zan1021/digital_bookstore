<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookPage;
use App\Models\Narration;
use App\Models\Translation;
use App\Models\TranslatedPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Per-edition teardown (spec: gated-translation-narration-flow Req 7.2). Deleting one
 * translated edition removes only that edition's pages, rendered PDF, comparison
 * renders, and its narration — leaving the original and sibling editions intact.
 */
class EditionDeletionCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function edition(Book $book, string $lang, BookPage $page): Translation
    {
        $t = Translation::create([
            'book_id' => $book->id,
            'language_code' => $lang,
            'language_name' => strtoupper($lang),
            'status' => 'draft',
            'rendered_pdf_path' => "books/translated/{$book->id}_{$lang}.pdf",
        ]);
        Storage::disk('public')->put($t->rendered_pdf_path, 'PDF');
        Storage::disk('public')->put("books/comparison/{$book->id}_{$lang}/source/source_001.png", 'PNG');

        TranslatedPage::create([
            'translation_id' => $t->id, 'book_page_id' => $page->id,
            'page_number' => 1, 'translated_text' => 'T',
        ]);

        // Edition narration + audio tree.
        Storage::disk('public')->put("narrations/book-{$book->id}/{$lang}/page-1.mp3", 'MP3');
        Narration::create([
            'book_id' => $book->id, 'language_code' => $lang, 'language_name' => strtoupper($lang),
            'voice_id' => 'v', 'voice_name' => 'V', 'status' => 'completed',
            'page_audio_paths' => ["narrations/book-{$book->id}/{$lang}/page-1.mp3"],
        ]);

        return $t->fresh();
    }

    public function test_deleting_one_edition_leaves_siblings_and_original_intact(): void
    {
        $book = Book::create([
            'title' => 'Multi', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/multi.pdf',
        ]);
        Storage::disk('public')->put($book->pdf_path, 'PDF');
        // English narration (source) — must survive an af-edition delete.
        Storage::disk('public')->put("narrations/book-{$book->id}/en/page-1.mp3", 'MP3');
        Narration::create([
            'book_id' => $book->id, 'language_code' => 'en', 'language_name' => 'English',
            'voice_id' => 'v', 'voice_name' => 'V', 'status' => 'completed',
            'page_audio_paths' => ["narrations/book-{$book->id}/en/page-1.mp3"],
        ]);

        $page = BookPage::create(['book_id' => $book->id, 'page_number' => 1, 'extracted_text' => 'EN']);
        $af = $this->edition($book, 'af', $page);
        $zu = $this->edition($book, 'zu', $page);

        $af->delete();

        // af gone.
        $this->assertDatabaseMissing('translations', ['id' => $af->id]);
        Storage::disk('public')->assertMissing("books/translated/{$book->id}_af.pdf");
        Storage::disk('public')->assertMissing("books/comparison/{$book->id}_af");
        Storage::disk('public')->assertMissing("narrations/book-{$book->id}/af");
        $this->assertDatabaseMissing('narrations', ['book_id' => $book->id, 'language_code' => 'af']);
        $this->assertSame(0, TranslatedPage::where('translation_id', $af->id)->count());

        // zu edition intact.
        $this->assertDatabaseHas('translations', ['id' => $zu->id]);
        Storage::disk('public')->assertExists("books/translated/{$book->id}_zu.pdf");
        Storage::disk('public')->assertExists("narrations/book-{$book->id}/zu/page-1.mp3");

        // Original book + English narration intact.
        $this->assertDatabaseHas('books', ['id' => $book->id]);
        Storage::disk('public')->assertExists($book->pdf_path);
        Storage::disk('public')->assertExists("narrations/book-{$book->id}/en/page-1.mp3");
        $this->assertDatabaseHas('narrations', ['book_id' => $book->id, 'language_code' => 'en']);
    }
}
