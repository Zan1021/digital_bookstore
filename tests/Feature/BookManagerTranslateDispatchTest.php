<?php

namespace Tests\Feature;

use App\Jobs\TranslateEditionJob;
use App\Livewire\Admin\BookManager;
use App\Models\Book;
use App\Models\Translation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * BookManager dispatches translation to the queue and returns immediately
 * (spec Req 3.1/3.2), rather than translating synchronously in-request.
 */
class BookManagerTranslateDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function book(): Book
    {
        return Book::create([
            'title' => 'Manager Book', 'original_language' => 'en', 'page_count' => 3,
            'status' => 'draft', 'pdf_path' => 'books/pdfs/x.pdf',
            'narration_start_page' => 3, 'narration_end_page' => 1,
            'crop_percent' => 0, 'crop_enabled' => false,
        ]);
    }

    public function test_translate_queues_job_and_creates_translating_edition(): void
    {
        Queue::fake();
        $book = $this->book();

        Livewire::test(BookManager::class, ['book' => $book])
            ->set('selectedLanguage', 'af')
            ->call('translate')
            ->assertHasNoErrors();

        Queue::assertPushed(TranslateEditionJob::class);

        $edition = Translation::where('book_id', $book->id)->where('language_code', 'af')->first();
        $this->assertNotNull($edition);
        $this->assertSame(Translation::STATE_TRANSLATING, $edition->render_status);
    }

    public function test_retry_requeues_job_for_existing_edition(): void
    {
        Queue::fake();
        $book = $this->book();
        $edition = Translation::create([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans',
            'status' => 'processing', 'render_status' => Translation::STATE_NEEDS_LANGUAGE_REVIEW,
        ]);

        Livewire::test(BookManager::class, ['book' => $book])
            ->call('retryTranslation', $edition->id)
            ->assertHasNoErrors();

        Queue::assertPushed(TranslateEditionJob::class);
        $this->assertSame(Translation::STATE_TRANSLATING, $edition->fresh()->render_status);
    }
}
