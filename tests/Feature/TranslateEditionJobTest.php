<?php

namespace Tests\Feature;

use App\Jobs\TranslateEditionJob;
use App\Models\Book;
use App\Models\ProcessingJob;
use App\Models\Translation;
use App\Services\PdfTranslationService;
use App\Services\TranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Orchestration tests for the queued translation job (spec Req 3). The heavy
 * TranslationService + PdfTranslationService are mocked; we assert the job drives
 * edition state, writes ProcessingJob progress, and fails closed.
 */
class TranslateEditionJobTest extends TestCase
{
    use RefreshDatabase;

    private function edition(): Translation
    {
        $book = Book::create([
            'title' => 'Job Book', 'original_language' => 'en', 'page_count' => 3,
            'status' => 'draft', 'pdf_path' => 'books/pdfs/x.pdf',
        ]);
        return Translation::create([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans',
            'status' => 'processing', 'render_status' => Translation::STATE_TRANSLATING,
        ]);
    }

    public function test_successful_job_sets_ready_for_review_and_completes_processing_job(): void
    {
        $edition = $this->edition();

        $translator = Mockery::mock(TranslationService::class);
        $translator->shouldReceive('translateWithManifest')->once();

        $renderer = Mockery::mock(PdfTranslationService::class);
        $renderer->shouldReceive('createTranslatedPdf')->once()
            ->andReturnUsing(function () use ($edition) {
                // Simulate the render step persisting a passing gate verdict.
                Translation::whereKey($edition->id)->update([
                    'render_status' => Translation::STATE_READY_FOR_REVIEW,
                    'qa_report' => json_encode(['publishable' => true]),
                ]);
                return 'books/translated/x.pdf';
            });

        (new TranslateEditionJob($edition->id))->handle($translator, $renderer);

        $this->assertSame(Translation::STATE_READY_FOR_REVIEW, $edition->fresh()->render_status);

        $job = ProcessingJob::where('book_id', $edition->book_id)->where('type', 'translation')->first();
        $this->assertNotNull($job);
        $this->assertSame('completed', $job->status);
        $this->assertSame(100, $job->progress);
    }

    public function test_failed_job_fails_closed_and_marks_processing_job_failed(): void
    {
        $edition = $this->edition();

        $translator = Mockery::mock(TranslationService::class);
        $translator->shouldReceive('translateWithManifest')->once()->andThrow(new \RuntimeException('OpenAI down'));

        $renderer = Mockery::mock(PdfTranslationService::class);
        $renderer->shouldNotReceive('createTranslatedPdf');

        try {
            (new TranslateEditionJob($edition->id))->handle($translator, $renderer);
            $this->fail('Expected exception to propagate for retry.');
        } catch (\RuntimeException $e) {
            $this->assertSame('OpenAI down', $e->getMessage());
        }

        // Fail-closed: not left in a publishable state.
        $edition = $edition->fresh();
        $this->assertSame(Translation::STATE_NEEDS_LANGUAGE_REVIEW, $edition->render_status);
        $this->assertFalse($edition->isApprovedForNarration());

        $job = ProcessingJob::where('book_id', $edition->book_id)->where('type', 'translation')->first();
        $this->assertSame('failed', $job->status);
        $this->assertStringContainsString('OpenAI down', $job->error_message);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
