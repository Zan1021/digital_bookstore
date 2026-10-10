<?php

namespace Tests\Feature;

use App\Livewire\Admin\BookManager;
use App\Models\Book;
use App\Models\Translation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Auto-open review (2026-10-10): once a translation the publisher started finishes, the book
 * page should redirect to that edition's review queue. pollTranslationStatus() watches the
 * dispatched edition and redirects when it reaches a review-ready state; stays put while
 * processing or on failure.
 */
class AutoOpenReviewTest extends TestCase
{
    use RefreshDatabase;

    private function book(): Book
    {
        return Book::create([
            'title' => 'Auto Open Book', 'original_language' => 'en', 'page_count' => 3,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/a.pdf',
        ]);
    }

    private function edition(Book $book, string $status): Translation
    {
        return Translation::create([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans',
            'status' => 'processing', 'render_status' => $status,
        ]);
    }

    public function test_redirects_to_review_queue_when_watched_edition_ready(): void
    {
        $book = $this->book();
        $edition = $this->edition($book, Translation::STATE_READY_FOR_REVIEW);

        Livewire::test(BookManager::class, ['book' => $book])
            ->set('watchEditionId', $edition->id)
            ->set('watchLanguage', 'af')
            ->call('pollTranslationStatus')
            ->assertRedirect(route('admin.review-queue', ['book' => $book->id, 'language' => 'af']));
    }

    public function test_redirects_also_on_needs_layout_review(): void
    {
        $book = $this->book();
        $edition = $this->edition($book, Translation::STATE_NEEDS_LAYOUT_REVIEW);

        Livewire::test(BookManager::class, ['book' => $book])
            ->set('watchEditionId', $edition->id)
            ->set('watchLanguage', 'af')
            ->call('pollTranslationStatus')
            ->assertRedirect(route('admin.review-queue', ['book' => $book->id, 'language' => 'af']));
    }

    public function test_does_not_redirect_while_still_translating(): void
    {
        $book = $this->book();
        $edition = $this->edition($book, Translation::STATE_TRANSLATING);

        Livewire::test(BookManager::class, ['book' => $book])
            ->set('watchEditionId', $edition->id)
            ->set('watchLanguage', 'af')
            ->call('pollTranslationStatus')
            ->assertNoRedirect();
    }

    public function test_does_not_redirect_without_a_watched_edition(): void
    {
        $book = $this->book();

        Livewire::test(BookManager::class, ['book' => $book])
            ->call('pollTranslationStatus')
            ->assertNoRedirect();
    }
}
