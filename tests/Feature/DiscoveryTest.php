<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Category;
use App\Models\Collection;
use App\Models\EditionRight;
use App\Models\Feature;
use App\Models\ReadingLevel;
use App\Models\Tag;
use App\Models\Translation;
use App\Services\Discovery\BookQuery;
use App\Services\Discovery\CollectionResolver;
use Database\Seeders\ClassificationVocabularySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscoveryTest extends TestCase
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
        $edition = $book->translations()->create(array_merge([
            'language_code' => 'af', 'language_name' => 'Afrikaans', 'status' => 'approved',
            'publication_status' => 'published', 'price' => 0,
        ], $editionAttrs));
        $book->setRelation('lastEdition', $edition);
        return $book;
    }

    public function test_filters_by_language_age_and_book_type(): void
    {
        $this->publishedBook(); // af, age 4-6, picture_book
        $this->publishedBook(['book_type' => 'chapter_book', 'age_min' => 10, 'age_max' => 12],
            ['language_code' => 'en', 'language_name' => 'English']);

        $this->assertSame(1, (new BookQuery(['language' => 'af']))->base()->count());
        $this->assertSame(1, (new BookQuery(['age' => 5]))->base()->count());
        $this->assertSame(1, (new BookQuery(['book_type' => 'chapter_book']))->base()->count());
        $this->assertSame(2, (new BookQuery([]))->base()->count());
    }

    public function test_unpublished_books_are_excluded(): void
    {
        Book::create(['title' => 'Draft', 'pdf_path' => 'd.pdf', 'page_count' => 5,
            'status' => 'draft', 'original_language' => 'en']);
        $this->assertSame(0, (new BookQuery([]))->base()->count());
    }

    public function test_search_matches_tag_alias_via_canonical(): void
    {
        $book = $this->publishedBook(['title' => 'Unrelated Title']);
        // attach the canonical 'friendship' tag; search by its alias 'friends'.
        $book->tags()->attach(Tag::where('slug', 'friendship')->first()->id,
            ['source' => 'administrator', 'approved_at' => now()]);

        $results = (new BookQuery([]))->search('friends')->get();
        $this->assertTrue($results->contains('id', $book->id));
    }

    public function test_narrated_filter_uses_edition_features(): void
    {
        $book = $this->publishedBook();
        $book->translations()->first()->features()->attach(Feature::where('slug', 'narrated')->first()->id);

        $this->assertSame(1, (new BookQuery(['narrated' => true]))->base()->count());
    }

    public function test_facet_counts_hide_zero_and_count_correctly(): void
    {
        $this->publishedBook(); // picture_book
        $this->publishedBook(['book_type' => 'early_reader'],
            ['language_code' => 'en', 'language_name' => 'English']);

        $facets = (new BookQuery([]))->facetCounts('book_type');
        $this->assertSame(1, $facets['picture_book']);
        $this->assertSame(1, $facets['early_reader']);
        $this->assertArrayNotHasKey('chapter_book', $facets); // zero-count omitted
    }

    public function test_rule_based_collection_resolves_without_code_change(): void
    {
        $afBook = $this->publishedBook(); // language af
        $enBook = $this->publishedBook([], ['language_code' => 'en', 'language_name' => 'English']);

        $collection = Collection::create([
            'slug' => 'learn-afrikaans', 'type' => 'rule',
            'translations' => ['en' => 'Learn Afrikaans'],
            'rules' => ['language' => ['af']],
        ]);

        $books = app(CollectionResolver::class)->books($collection);
        $this->assertTrue($books->contains('id', $afBook->id));
        $this->assertFalse($books->contains('id', $enBook->id));
    }

    public function test_manual_collection_pins_first(): void
    {
        $a = $this->publishedBook(['title' => 'A']);
        $b = $this->publishedBook(['title' => 'B'], ['language_code' => 'en', 'language_name' => 'English']);

        $collection = Collection::create(['slug' => 'staff-picks', 'type' => 'manual',
            'translations' => ['en' => 'Staff Picks']]);
        $collection->books()->attach($a->id, ['pinned' => false, 'sort_order' => 2]);
        $collection->books()->attach($b->id, ['pinned' => true, 'sort_order' => 1]);

        $books = app(CollectionResolver::class)->books($collection);
        $this->assertSame($b->id, $books->first()->id); // pinned first
    }

    public function test_edition_rights_visibility_gate(): void
    {
        $book = $this->publishedBook();
        $edition = $book->translations()->first();
        $rights = EditionRight::create([
            'translation_id' => $edition->id, 'territories' => ['ZA'],
            'digital_rights' => true, 'licence_start' => now()->subDay(), 'licence_end' => now()->addYear(),
        ]);
        $this->assertTrue($rights->visibleInTerritory('ZA'));
        $this->assertFalse($rights->visibleInTerritory('US'));

        $expired = EditionRight::create([
            'translation_id' => $book->translations()->create([
                'language_code' => 'zu', 'language_name' => 'isiZulu', 'status' => 'approved',
            ])->id,
            'territories' => ['*'], 'digital_rights' => true, 'licence_end' => now()->subDay(),
        ]);
        $this->assertFalse($expired->visibleInTerritory('ZA')); // licence expired
    }

    public function test_series_progression_next_book(): void
    {
        $b1 = $this->publishedBook(['title' => 'Vol1', 'series' => 'Kolulu', 'series_volume' => 1]);
        $b2 = $this->publishedBook(['title' => 'Vol2', 'series' => 'Kolulu', 'series_volume' => 2],
            ['language_code' => 'en', 'language_name' => 'English']);

        $next = $b1->nextInSeries();
        $this->assertNotNull($next);
        $this->assertSame($b2->id, $next->id);
        $this->assertNull($b2->nextInSeries()); // no later volume
    }
}
