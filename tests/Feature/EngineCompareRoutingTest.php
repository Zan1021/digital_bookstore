<?php

namespace Tests\Feature;

use App\Livewire\Admin\EngineCompare;
use App\Models\Book;
use App\Models\Translation;
use App\Services\PdfTranslationService;
use App\Services\Qa\QaReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * unified-rendering-and-testing spec Req 1 / task A2.5 — EngineCompare must route through
 * the shared PdfTranslationService (NOT a direct Python subprocess) and must derive its
 * status from readiness, never from file existence.
 */
class EngineCompareRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();
        $this->book = Book::create([
            'title' => 'Compare Book', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/x.pdf', 'manifest_path' => 'books/manifests/x.json',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function edition(array $overrides = []): Translation
    {
        return Translation::create(array_merge([
            'book_id' => $this->book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
        ], $overrides));
    }

    public function test_render_routes_through_shared_service_not_python(): void
    {
        $this->edition();

        // The shared service is the ONLY render path. If EngineCompare still shelled out to
        // Python, this expectation would never be met.
        $renderer = Mockery::mock(PdfTranslationService::class);
        $renderer->shouldReceive('createTranslatedPdf')
            ->once()
            ->andReturn('books/translated/x.pdf');
        $this->app->instance(PdfTranslationService::class, $renderer);

        Livewire::test(EngineCompare::class, ['book' => $this->book])
            ->call('renderV8');

        // Mockery verifies the single call on tearDown.
        $this->assertTrue(true);
    }

    public function test_existing_pdf_without_passed_checks_is_not_ready(): void
    {
        // A rendered PDF exists but has no bound/passed checks → must NOT read "ready" (A2.3).
        $this->edition([
            'rendered_pdf_path' => 'books/translated/x.pdf',
            'render_fingerprint' => null,
            'output_sha256' => null,
        ]);

        Livewire::test(EngineCompare::class, ['book' => $this->book])
            ->assertSet('v8Status', 'testing-pending');
    }

    public function test_blocking_render_state_reads_needs_review(): void
    {
        $this->edition([
            'rendered_pdf_path' => 'books/translated/x.pdf',
            'render_status' => Translation::STATE_NEEDS_LAYOUT_REVIEW,
        ]);

        Livewire::test(EngineCompare::class, ['book' => $this->book])
            ->assertSet('v8Status', 'needs-review');
    }

    public function test_bound_passed_candidate_reads_ready(): void
    {
        $this->edition([
            'rendered_pdf_path' => 'books/translated/x.pdf',
            'qa_report' => (new QaReport())->pass('structure')->pass('fit')->pass('target_mapping')->toArray(),
            'render_fingerprint' => str_repeat('a', 16),
            'output_sha256' => str_repeat('b', 16),
        ]);

        Livewire::test(EngineCompare::class, ['book' => $this->book])
            ->assertSet('v8Status', 'ready');
    }

    public function test_no_render_yet_has_null_status(): void
    {
        $this->edition(); // no rendered_pdf_path

        Livewire::test(EngineCompare::class, ['book' => $this->book])
            ->assertSet('v8Status', null);
    }
}
