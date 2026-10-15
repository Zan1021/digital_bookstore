<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Publisher;
use Database\Seeders\PublisherSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 6.1 (Req 25.2): single-publisher launch, tenancy-ready. Mthombothi Studios
 * exists after migrating; books carry publisher_id and default to it.
 */
class PublisherTest extends TestCase
{
    use RefreshDatabase;

    private function book(array $attrs = []): Book
    {
        return Book::create($attrs + [
            'title' => 'Owned', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/o.pdf',
        ]);
    }

    public function test_the_launch_publisher_exists_after_migrating(): void
    {
        $publisher = Publisher::default();

        $this->assertSame('Mthombothi Studios', $publisher->name);
        $this->assertSame('mthombothi-studios', $publisher->slug);
    }

    public function test_a_new_book_belongs_to_the_default_publisher(): void
    {
        $book = $this->book();

        $this->assertTrue($book->publisher->is(Publisher::default()));
        $this->assertTrue(Publisher::default()->books->contains($book));
    }

    public function test_a_book_can_name_another_publisher(): void
    {
        $other = Publisher::create(['name' => 'Second Press', 'slug' => 'second-press']);

        $this->assertTrue($this->book(['publisher_id' => $other->id])->publisher->is($other));
    }

    public function test_a_publisher_with_books_cannot_be_deleted(): void
    {
        $this->book();

        $this->expectException(QueryException::class);
        Publisher::default()->delete();
    }

    public function test_the_seeder_is_safe_to_run_again(): void
    {
        $this->seed(PublisherSeeder::class);
        $this->seed(PublisherSeeder::class);

        $this->assertSame(1, Publisher::where('slug', 'mthombothi-studios')->count());
    }
}
