<?php

namespace Tests\Feature;

use App\Livewire\Admin\BookManager;
use App\Livewire\Admin\Dashboard;
use App\Models\Book;
use App\Models\Translation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ActsAsSuperAdmin;
use Tests\TestCase;

/**
 * Task 6.4 (reduced, agreed with Zan): the book's own language is the book, not a
 * translation. A leftover source-language edition row (still created at publish until
 * task 17 moves its store data) must not appear as a translation or be counted twice.
 */
class SourceLanguageDisplayTest extends TestCase
{
    use ActsAsSuperAdmin, RefreshDatabase;

    private function book(array $editions): Book
    {
        $book = Book::create(['title' => 'Kolulu', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'published', 'pdf_path' => 'books/pdfs/k.pdf']);
        foreach ($editions as $code => $name) {
            Translation::create(['book_id' => $book->id, 'language_code' => $code, 'language_name' => $name,
                'status' => 'draft', 'publication_status' => 'published']);
        }

        return $book->load('translations');
    }

    public function test_the_source_row_is_not_a_translated_edition(): void
    {
        $book = $this->book(['en' => 'English', 'af' => 'Afrikaans']);

        $this->assertSame(['af'], $book->translatedEditions()->pluck('language_code')->all());
        $this->assertSame(2, $book->languageCount());
        $this->assertSame(1, $this->book(['en' => 'English'])->languageCount());
    }

    public function test_the_store_page_lists_each_language_once(): void
    {
        $book = $this->book(['en' => 'English', 'af' => 'Afrikaans']);

        $html = $this->get(route('store.book', $book))->assertOk()->getContent();
        $available = substr($html, strpos($html, 'Available in:'), 600);

        $this->assertSame(1, substr_count($available, 'English'), 'English must be listed once');
        $this->assertSame(1, substr_count($available, 'Afrikaans'));
        $this->assertStringContainsString('2 Languages', $html);
    }

    public function test_dashboard_counts_only_real_translations(): void
    {
        $this->book(['en' => 'English']);                        // source row only
        $this->book(['en' => 'English', 'af' => 'Afrikaans']);   // really translated

        Livewire::test(Dashboard::class)->assertViewHas('translatedBooks', 1);
        Livewire::test(Dashboard::class)->set('filter', 'translated')->assertViewHas('recentBooks', fn ($books) => $books->count() === 1);
        $this->assertSame(1, Book::withTranslatedEditions()->count());
    }

    public function test_book_manager_does_not_show_english_as_a_translation(): void
    {
        $book = $this->book(['en' => 'English']);

        Livewire::test(BookManager::class, ['book' => $book])
            ->assertSee('No translations yet');
    }
}
