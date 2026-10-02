<?php

namespace Tests\Feature;

use App\Livewire\Admin\BookReviewer;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\Translation;
use App\Models\TranslatedPage;
use App\Services\PdfTranslationService;
use App\Services\Qa\QaReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * unified-rendering-and-testing spec Req 3 / task A3 — one approval authority.
 *
 * A human page-approval must NOT be able to publish an edition whose automated checks
 * failed, and a manual whole-page edit must UN-approve + re-render (never silently
 * approve). Readiness flows only through CandidateReadiness (A1).
 */
class ReviewApprovalConsolidationTest extends TestCase
{
    use RefreshDatabase;

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();
        $this->book = Book::create([
            'title' => 'Consolidation Book', 'original_language' => 'en', 'page_count' => 2,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/x.pdf',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function editionWith(array $overrides): Translation
    {
        $t = Translation::create(array_merge([
            'book_id' => $this->book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
        ], $overrides));
        foreach (range(1, 2) as $n) {
            $page = BookPage::create(['book_id' => $this->book->id, 'page_number' => $n, 'extracted_text' => "EN {$n}"]);
            TranslatedPage::create([
                'translation_id' => $t->id, 'book_page_id' => $page->id,
                'page_number' => $n, 'translated_text' => "T {$n}",
            ]);
        }
        return $t->fresh();
    }

    public function test_human_page_approval_cannot_publish_when_a_required_check_failed(): void
    {
        // Every page approved by a human, BUT target_mapping failed → gate must refuse.
        $qa = (new QaReport())->pass('structure')->pass('fit')
            ->fail('target_mapping', 'MISSING_TARGET', 'illustration');
        $t = $this->editionWith([
            'qa_report' => $qa->toArray(),
            'render_fingerprint' => str_repeat('a', 16),
            'output_sha256' => str_repeat('b', 16),
        ]);
        foreach (range(1, 2) as $n) {
            $t->fresh()->setPageApproval($n, true);
        }

        $this->assertTrue($t->fresh()->allPagesApproved(), 'human approved every page');
        $this->assertFalse($t->fresh()->markApproved(), 'but a failed automated check blocks approval');
        $this->assertNotSame(Translation::STATE_APPROVED, $t->fresh()->render_status);
    }

    public function test_fully_bound_passing_candidate_can_be_approved_by_human(): void
    {
        $t = $this->editionWith([
            'qa_report' => (new QaReport())->pass('structure')->pass('fit')->pass('target_mapping')->toArray(),
            'render_fingerprint' => str_repeat('a', 16),
            'output_sha256' => str_repeat('b', 16),
        ]);
        foreach (range(1, 2) as $n) {
            $t->fresh()->setPageApproval($n, true);
        }
        $this->assertTrue($t->fresh()->markApproved());
    }

    public function test_whole_page_edit_unapproves_and_rerenders_not_approves(): void
    {
        // Stub the renderer so the edit doesn't invoke the real Python engine.
        $renderer = Mockery::mock(PdfTranslationService::class);
        $renderer->shouldReceive('createTranslatedPdf')->once()->andReturn('books/translated/x.pdf');
        $this->app->instance(PdfTranslationService::class, $renderer);

        $t = $this->editionWith([
            'render_fingerprint' => str_repeat('a', 16),
            'output_sha256' => str_repeat('b', 16),
        ]);
        // Pre-approve page 1 so we can prove the edit REMOVES the approval.
        $t->fresh()->setPageApproval(1, true);
        $p1 = TranslatedPage::where('translation_id', $t->id)->where('page_number', 1)->first();

        Livewire::test(BookReviewer::class, ['book' => $this->book])
            ->call('startEdit', $p1->id)
            ->set('editingText', 'Edited text')
            ->call('saveEdit');

        $p1->refresh();
        $this->assertSame('edited', $p1->review_status, 'edit marks the page edited, NOT approved');

        [$approved] = $t->fresh()->pageApprovalProgress();
        $this->assertSame(0, $approved, 'the edit un-approved the previously approved page');
    }
}
