<?php

namespace Tests\Feature;

use App\Livewire\Admin\ReviewQueue;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\Translation;
use App\Models\TranslatedPage;
use App\Services\IllustrationTextService;
use App\Services\VisualQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * G4 (on-demand generative repair, spec C-C) — the ReviewQueue "Fix with AI" button and
 * two-version picker. Drives the state machine idle → running → choose → applied, and proves
 * the button is absent on a clean page. The generative + apply calls are MOCKED on the
 * service bound into the container, so NO Python / NO OpenAI runs.
 */
class ReviewQueueFixWithAiTest extends TestCase
{
    use RefreshDatabase;

    private Book $book;
    private Translation $edition;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the illustration service so fixWithAi/applyFix never touch Python/OpenAI.
        $illus = Mockery::mock(IllustrationTextService::class);
        $illus->shouldReceive('repairPageGenerative')->andReturn([
            'ok' => true,
            'candidate_image' => null, // publicUrlForTemp tolerates null → no <img>, fine for the test
            'current_image' => null,
            'candidate_pdf' => null,
            'reason' => null,
        ]);
        $illus->shouldReceive('applyPageVersion')->andReturn([
            'ok' => true, 'applied' => 'use_generative',
            'recompare' => VisualQaService::STATUS_PASSED, 'reason' => null,
        ]);
        $this->app->instance(IllustrationTextService::class, $illus);

        $this->book = Book::create([
            'title' => 'Fix Book', 'original_language' => 'en', 'page_count' => 2,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/x.pdf',
        ]);
        $this->edition = Translation::create([
            'book_id' => $this->book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
            'render_fingerprint' => str_repeat('a', 16),
        ]);
        // Page 1 FLAGGED (red), page 2 CLEAN (green).
        $flags = [1 => 'red', 2 => 'green'];
        foreach (range(1, 2) as $n) {
            $page = BookPage::create(['book_id' => $this->book->id, 'page_number' => $n, 'extracted_text' => "EN {$n}"]);
            TranslatedPage::create([
                'translation_id' => $this->edition->id, 'book_page_id' => $page->id,
                'page_number' => $n, 'translated_text' => "T {$n}", 'quality_flag' => $flags[$n],
            ]);
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_button_shows_on_flagged_page_and_hidden_on_clean_page(): void
    {
        // Page 1 (flagged) selected by default → button visible.
        Livewire::test(ReviewQueue::class, ['book' => $this->book, 'language' => 'af'])
            ->assertSet('currentPage', 1)
            ->assertSeeHtml('wire:click="fixWithAi"')
            // Switch to the clean page 2 → button gone.
            ->call('selectPage', 2)
            ->assertDontSeeHtml('wire:click="fixWithAi"');
    }

    public function test_state_machine_idle_running_choose_applied(): void
    {
        $c = Livewire::test(ReviewQueue::class, ['book' => $this->book, 'language' => 'af'])
            ->assertSet('fixState', 'idle')
            ->call('fixWithAi')
            // repairPageGenerative mocked ok → lands in 'choose'
            ->assertSet('fixState', 'choose')
            ->call('applyFix', 'use_generative')
            ->assertSet('fixState', 'applied')
            ->assertSet('fixRecompare', VisualQaService::STATUS_PASSED);
    }

    public function test_selecting_a_new_page_resets_the_fix_panel(): void
    {
        Livewire::test(ReviewQueue::class, ['book' => $this->book, 'language' => 'af'])
            ->call('fixWithAi')
            ->assertSet('fixState', 'choose')
            ->call('selectPage', 2)
            ->assertSet('fixState', 'idle');
    }
}
