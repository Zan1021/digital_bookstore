<?php

namespace Tests\Feature;

use App\Livewire\Store\Catalog;
use App\Models\Book;
use Database\Seeders\ClassificationVocabularySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Verifies the customer-facing catalogue UI is actually WIRED to the BookQuery discovery
 * engine: filters narrow results, chips clear, search works, unpublished books stay hidden.
 */
class StoreCatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClassificationVocabularySeeder::class);
    }

    private function publishedBook(array $attrs = [], array $editionAttrs = []): Book
    {
        $book = Book::create(array_merge([
            'title' => 'Book', 'pdf_path' => 'x.pdf', 'page_count' => 10,
            'status' => 'published', 'original_language' => 'en',
            'book_type' => 'picture_book', 'age_min' => 4, 'age_max' => 6,
        ], $attrs));
        $book->translations()->create(array_merge([
            'language_code' => 'af', 'language_name' => 'Afrikaans', 'status' => 'approved',
            'publication_status' => 'published', 'price' => 0,
        ], $editionAttrs));
        return $book;
    }

    public function test_catalogue_lists_only_published_books(): void
    {
        $this->publishedBook(['title' => 'Published One']);
        Book::create(['title' => 'Secret Draft', 'pdf_path' => 'd.pdf', 'page_count' => 5,
            'status' => 'draft', 'original_language' => 'en']);

        Livewire::test(Catalog::class)
            ->assertSee('Published One')
            ->assertDontSee('Secret Draft');
    }

    public function test_book_type_filter_narrows_results(): void
    {
        $this->publishedBook(['title' => 'Picture Book A']);
        $this->publishedBook(['title' => 'Chapter Book B', 'book_type' => 'chapter_book',
            'age_min' => 10, 'age_max' => 12], ['language_code' => 'en', 'language_name' => 'English']);

        Livewire::test(Catalog::class)
            ->set('book_type', 'chapter_book')
            ->assertSee('Chapter Book B')
            ->assertDontSee('Picture Book A');
    }

    public function test_language_filter_and_clear_all(): void
    {
        $this->publishedBook(['title' => 'Afrikaans Edition Book']); // af edition
        $this->publishedBook(['title' => 'English Only Book'],
            ['language_code' => 'en', 'language_name' => 'English']);

        $c = Livewire::test(Catalog::class)
            ->set('language', 'en')
            ->assertSee('English Only Book')
            ->assertDontSee('Afrikaans Edition Book');

        $c->call('clearAll')
            ->assertSee('English Only Book')
            ->assertSee('Afrikaans Edition Book');
    }

    public function test_search_matches_title(): void
    {
        $this->publishedBook(['title' => 'The Lonely Elephant']);
        $this->publishedBook(['title' => 'Rocket to Mars'],
            ['language_code' => 'en', 'language_name' => 'English']);

        Livewire::test(Catalog::class)
            ->set('search', 'Elephant')
            ->assertSee('The Lonely Elephant')
            ->assertDontSee('Rocket to Mars');
    }

    public function test_clear_single_filter(): void
    {
        $this->publishedBook(['title' => 'Picture Book A']);

        Livewire::test(Catalog::class)
            ->set('book_type', 'chapter_book')
            ->assertDontSee('Picture Book A')
            ->call('clearFilter', 'book_type')
            ->assertSee('Picture Book A');
    }

    public function test_v2_education_phase_filter(): void
    {
        // Foundation-phase edition vs a senior-phase edition.
        $this->publishedBook(['title' => 'Foundation Reader'],
            ['education_phase' => 'foundation']);
        $this->publishedBook(['title' => 'Senior Reader'],
            ['language_code' => 'en', 'language_name' => 'English', 'education_phase' => 'senior']);

        Livewire::test(Catalog::class)
            ->set('education_phase', 'foundation')
            ->assertSee('Foundation Reader')
            ->assertDontSee('Senior Reader');
    }

    public function test_v2_theme_tag_filter(): void
    {
        $book = $this->publishedBook(['title' => 'A Story About Friends']);
        $book->tags()->attach(\App\Models\Tag::where('slug', 'friendship')->first()->id,
            ['source' => 'administrator', 'approved_at' => now()]);
        $this->publishedBook(['title' => 'Unrelated Book'],
            ['language_code' => 'en', 'language_name' => 'English']);

        Livewire::test(Catalog::class)
            ->set('tag', 'friendship')
            ->assertSee('A Story About Friends')
            ->assertDontSee('Unrelated Book');
    }

    public function test_v2_feature_filter(): void
    {
        $book = $this->publishedBook(['title' => 'Narrated Book']);
        $book->translations()->first()->features()
            ->attach(\App\Models\Feature::where('slug', 'narrated')->first()->id);
        $this->publishedBook(['title' => 'Plain Book'],
            ['language_code' => 'en', 'language_name' => 'English']);

        Livewire::test(Catalog::class)
            ->set('feature', 'narrated')
            ->assertSee('Narrated Book')
            ->assertDontSee('Plain Book');
    }

    public function test_v2_manual_collection_scopes_results(): void
    {
        $inCol = $this->publishedBook(['title' => 'In The Collection']);
        $this->publishedBook(['title' => 'Not In Collection'],
            ['language_code' => 'en', 'language_name' => 'English']);

        $col = \App\Models\Collection::create([
            'slug' => 'staff-picks', 'type' => 'manual',
            'translations' => ['en' => 'Staff Picks'],
        ]);
        $col->books()->attach($inCol->id, ['pinned' => true, 'sort_order' => 0]);

        Livewire::test(Catalog::class)
            ->set('collection', 'staff-picks')
            ->assertSee('In The Collection')
            ->assertDontSee('Not In Collection');
    }
}
