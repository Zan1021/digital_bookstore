<?php

namespace Tests\Feature;

use App\Livewire\Admin\ReviewQueue;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\Narration;
use App\Models\Translation;
use App\Models\TranslatedPage;
use App\Services\PdfTranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Compare-and-edit review flow (spec Req 4, 5.2, 6.1 — Tasks 8, 9, 11).
 */
class ReviewQueueFlowTest extends TestCase
{
    use RefreshDatabase;

    private Book $book;
    private Translation $edition;

    protected function setUp(): void
    {
        parent::setUp();

        // Stub the re-render so edits don't invoke the real Python engine.
        $renderer = Mockery::mock(PdfTranslationService::class);
        $renderer->shouldReceive('createTranslatedPdf')->andReturn('books/translated/x.pdf');
        $this->app->instance(PdfTranslationService::class, $renderer);

        $this->book = Book::create([
            'title' => 'Review Book', 'original_language' => 'en', 'page_count' => 2,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/x.pdf',
        ]);
        $this->edition = Translation::create([
            'book_id' => $this->book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
            'qa_report' => ['publishable' => true],
        ]);
        foreach (range(1, 2) as $n) {
            $page = BookPage::create(['book_id' => $this->book->id, 'page_number' => $n, 'extracted_text' => "EN {$n}"]);
            TranslatedPage::create([
                'translation_id' => $this->edition->id, 'book_page_id' => $page->id,
                'page_number' => $n, 'translated_text' => "T {$n}",
            ]);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_approving_pages_mirrors_into_edition_and_enables_approval(): void
    {
        $p1 = TranslatedPage::where('translation_id', $this->edition->id)->where('page_number', 1)->first();
        $p2 = TranslatedPage::where('translation_id', $this->edition->id)->where('page_number', 2)->first();

        Livewire::test(ReviewQueue::class, ['book' => $this->book, 'language' => 'af'])
            ->call('approvePage', $p1->id)
            ->call('approvePage', $p2->id)
            ->call('approveEdition');

        $edition = $this->edition->fresh();
        $this->assertSame(Translation::STATE_APPROVED, $edition->render_status);
        $this->assertNotNull($edition->approved_at);
        $this->assertTrue($edition->isApprovedForNarration());
    }

    public function test_cannot_approve_edition_until_all_pages_approved(): void
    {
        $p1 = TranslatedPage::where('translation_id', $this->edition->id)->where('page_number', 1)->first();

        Livewire::test(ReviewQueue::class, ['book' => $this->book, 'language' => 'af'])
            ->call('approvePage', $p1->id)
            ->call('approveEdition');

        $this->assertNotSame(Translation::STATE_APPROVED, $this->edition->fresh()->render_status);
    }

    public function test_editing_a_page_invalidates_existing_narration_and_unapproves_page(): void
    {
        // Approve both pages first.
        foreach (range(1, 2) as $n) {
            $this->edition->fresh()->setPageApproval($n, true);
        }
        // Existing completed narration for the edition.
        $narration = Narration::create([
            'book_id' => $this->book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans',
            'voice_id' => 'v', 'voice_name' => 'V', 'status' => 'completed', 'is_outdated' => false,
        ]);

        $p1 = TranslatedPage::where('translation_id', $this->edition->id)->where('page_number', 1)->first();

        Livewire::test(ReviewQueue::class, ['book' => $this->book, 'language' => 'af'])
            ->call('updateTranslation', $p1->id, 'Nuwe teks');

        // Narration flagged outdated (Req 6.1).
        $this->assertTrue($narration->fresh()->is_outdated);
        // Edited page no longer approved (Req 4.3 consequence).
        [$approved] = $this->edition->fresh()->pageApprovalProgress();
        $this->assertSame(1, $approved);
        // Text persisted.
        $this->assertSame('Nuwe teks', $p1->fresh()->translated_text);
    }
}
