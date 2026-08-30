<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\BookDescription;
use App\Models\Tag;
use App\Services\Classification\BookClassificationService;
use Database\Seeders\ClassificationVocabularySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublishGateTest extends TestCase
{
    use RefreshDatabase;

    private BookClassificationService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ClassificationVocabularySeeder::class);
        $this->svc = app(BookClassificationService::class);
    }

    private function book(): Book
    {
        return Book::create(['title' => 'T', 'pdf_path' => 'x.pdf', 'page_count' => 10,
            'status' => 'ready', 'original_language' => 'en']);
    }

    public function test_incomplete_book_cannot_publish_and_lists_missing(): void
    {
        $book = $this->book();
        $missing = $this->svc->missingRequirements($book);
        $this->assertContains('primary_category', $missing);
        $this->assertContains('book_type', $missing);
        $this->assertContains('age_range', $missing);
        $this->assertContains('description', $missing);
        $this->assertFalse($this->svc->publish($book));
        $book->refresh();
        $this->assertNotSame('published', $book->status);
    }

    public function test_full_review_enables_publish_and_records_source(): void
    {
        $book = $this->book();

        $this->svc->setBookType($book, 'picture_book', 1);
        $this->svc->setCategory($book, 'picture-books', true, 'administrator', 1);
        $this->svc->setAgeRange($book, 4, 6, 1);
        $this->svc->addTag($book, Tag::where('slug', 'friendship')->first()->id, 'administrator', 1);
        $desc = BookDescription::create(['book_id' => $book->id, 'language_code' => 'en',
            'short_text' => 'A lovely tale.', 'status' => 'suggested', 'source' => 'ai']);
        $this->svc->approveDescription($desc, 1);

        $this->assertTrue($this->svc->canPublish($book->refresh()));
        $this->assertTrue($this->svc->publish($book));
        $book->refresh();
        $this->assertSame('published', $book->status);
        $this->assertSame('reviewed', $book->classification_status);

        // Source + primary recorded on the pivot.
        $pivot = $book->categories()->wherePivot('is_primary', true)->first();
        $this->assertSame('picture-books', $pivot->slug);
        $this->assertSame('administrator', $pivot->pivot->source);
    }

    public function test_setting_primary_category_demotes_previous_primary(): void
    {
        $book = $this->book();
        $this->svc->setCategory($book, 'picture-books', true, 'ai', 1);
        $this->svc->setCategory($book, 'early-readers', true, 'administrator', 1);

        $primaries = $book->categories()->wherePivot('is_primary', true)->get();
        $this->assertCount(1, $primaries);
        $this->assertSame('early-readers', $primaries->first()->slug);
    }

    public function test_adding_same_tag_twice_does_not_duplicate(): void
    {
        $book = $this->book();
        $id = Tag::where('slug', 'animals')->first()->id;
        $this->svc->addTag($book, $id, 'ai', 1);
        $this->svc->addTag($book, $id, 'administrator', 1);
        $this->assertSame(1, $book->tags()->count());
    }

    public function test_age_range_is_ordered_and_recorded_as_review(): void
    {
        $book = $this->book();
        $this->svc->setAgeRange($book, 9, 5, 1); // reversed input
        $book->refresh();
        $this->assertSame(5, $book->age_min);
        $this->assertSame(9, $book->age_max);
        $this->assertDatabaseHas('classification_reviews',
            ['book_id' => $book->id, 'field' => 'age_range', 'decision' => 'accepted']);
    }
}
