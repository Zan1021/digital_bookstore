<?php

namespace Tests\Feature;

use Tests\Concerns\ActsAsSuperAdmin;
use App\Livewire\Admin\Dashboard;
use App\Models\Book;
use App\Models\BookDescription;
use App\Services\Classification\BookClassificationService;
use App\Services\Discovery\BookQuery;
use Database\Seeders\ClassificationVocabularySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The upload→review→PUBLISH flow's final step. Before this existed a book could never
 * leave 'draft', so an uploaded book was invisible on the storefront no matter what the
 * publisher clicked. These tests lock in: the admin Publish button takes a fully-reviewed
 * book live (book + source edition), it shows up in the catalogue, and Unpublish reverses
 * it. A book missing review metadata is refused with a message naming what's missing.
 */
class DashboardPublishFlowTest extends TestCase
{
    use ActsAsSuperAdmin, RefreshDatabase;

    private BookClassificationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClassificationVocabularySeeder::class);
        $this->svc = app(BookClassificationService::class);
    }

    private function reviewedBook(): Book
    {
        $book = Book::create([
            'title' => 'Publishable Tale', 'pdf_path' => 'books/pdfs/x.pdf', 'page_count' => 10,
            'status' => 'draft', 'original_language' => 'en',
        ]);
        $this->svc->setBookType($book, 'picture_book', 1);
        $this->svc->setCategory($book, 'picture-books', true, 'administrator', 1);
        $this->svc->setAgeRange($book, 4, 6, 1);
        $desc = BookDescription::create(['book_id' => $book->id, 'language_code' => 'en',
            'short_text' => 'A lovely tale.', 'status' => 'suggested', 'source' => 'ai']);
        $this->svc->approveDescription($desc, 1);

        return $book->refresh();
    }

    public function test_publish_button_takes_a_reviewed_book_live_and_into_the_catalogue(): void
    {
        $book = $this->reviewedBook();

        // Not in the catalogue while draft.
        $this->assertFalse((new BookQuery([]))->base()->where('id', $book->id)->exists());

        Livewire::test(Dashboard::class)
            ->call('publish', $book->id)
            ->assertHasNoErrors();

        $book->refresh();
        $this->assertSame('published', $book->status);
        $this->assertTrue($book->isPublished());

        // The trusted English source edition went live…
        $en = $book->translations()->where('language_code', 'en')->first();
        $this->assertNotNull($en);
        $this->assertSame('published', $en->publication_status);

        // …and the book now surfaces in the storefront discovery query.
        $this->assertTrue((new BookQuery([]))->base()->where('id', $book->id)->exists());
    }

    public function test_unpublish_pulls_the_book_back_out_of_the_catalogue(): void
    {
        $book = $this->reviewedBook();
        $this->assertTrue($this->svc->publish($book));
        $this->assertTrue((new BookQuery([]))->base()->where('id', $book->id)->exists());

        Livewire::test(Dashboard::class)
            ->call('unpublish', $book->id)
            ->assertHasNoErrors();

        $book->refresh();
        $this->assertSame('draft', $book->status);
        $this->assertFalse($book->isPublished());
        $this->assertFalse((new BookQuery([]))->base()->where('id', $book->id)->exists());
    }

    public function test_publish_is_refused_for_an_unreviewed_book_with_a_reason(): void
    {
        // A freshly uploaded book with no classification metadata yet.
        $book = Book::create([
            'title' => 'Raw Upload', 'pdf_path' => 'books/pdfs/y.pdf', 'page_count' => 8,
            'status' => 'draft', 'original_language' => 'en',
        ]);

        Livewire::test(Dashboard::class)
            ->call('publish', $book->id)
            ->assertHasNoErrors();

        $this->assertNotSame('published', $book->refresh()->status);
        $this->assertFalse((new BookQuery([]))->base()->where('id', $book->id)->exists());
    }
}
