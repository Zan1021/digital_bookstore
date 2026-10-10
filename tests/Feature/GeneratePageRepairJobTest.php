<?php

namespace Tests\Feature;

use App\Jobs\GeneratePageRepairJob;
use App\Models\Book;
use App\Models\ProcessingJob;
use App\Models\Translation;
use App\Services\IllustrationTextService;
use App\Services\VisualQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * G6 (on-demand generative repair) — the ASYNC per-page repair job. Proves the queued job
 * runs the (mocked) generative repair and records the result on a ProcessingJob row the UI
 * polls, so the ~1-min call never blocks the request. No Python / no OpenAI.
 */
class GeneratePageRepairJobTest extends TestCase
{
    use RefreshDatabase;

    private function edition(): Translation
    {
        $book = Book::create([
            'title' => 'Job Book', 'original_language' => 'en', 'page_count' => 3,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/j.pdf',
        ]);
        return Translation::create([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'AF',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
            'render_fingerprint' => 'fp0123456789abcdef',
        ]);
    }

    private function bindService(bool $ok): void
    {
        $svc = Mockery::mock(IllustrationTextService::class);
        $svc->shouldReceive('repairPageGenerative')->andReturn([
            'ok' => $ok,
            'candidate_image' => $ok ? '/tmp/cand.png' : null,
            'current_image' => $ok ? '/tmp/cur.png' : null,
            'candidate_pdf' => $ok ? '/tmp/cand.pdf' : null,
            'reason' => $ok ? null : 'NO_ARTWORK_TEXT_ON_PAGE',
        ]);
        $this->app->instance(IllustrationTextService::class, $svc);
    }

    public function test_job_records_a_completed_result_row_for_the_ui_to_poll(): void
    {
        $this->bindService(true);
        $tr = $this->edition();

        GeneratePageRepairJob::dispatchSync($tr->book_id, $tr->id, 2);

        $row = ProcessingJob::where('book_id', $tr->book_id)->where('type', 'page_repair')->first();
        $this->assertNotNull($row);
        $this->assertSame('completed', $row->status);
        $this->assertSame(GeneratePageRepairJob::jobKey($tr->id, 2), $row->details['key']);
        $this->assertTrue($row->details['ok']);
        $this->assertSame(2, $row->details['page']);
        $this->assertSame('/tmp/cand.png', $row->details['candidate_image']);
    }

    public function test_job_records_failure_when_repair_not_ok(): void
    {
        $this->bindService(false);
        $tr = $this->edition();

        GeneratePageRepairJob::dispatchSync($tr->book_id, $tr->id, 1);

        $row = ProcessingJob::where('book_id', $tr->book_id)->where('type', 'page_repair')->first();
        $this->assertSame('failed', $row->status);
        $this->assertFalse($row->details['ok']);
        $this->assertSame('NO_ARTWORK_TEXT_ON_PAGE', $row->details['reason']);
    }

    public function test_jobkey_is_stable_per_edition_and_page(): void
    {
        $this->assertSame('page_repair:7:3', GeneratePageRepairJob::jobKey(7, 3));
        $this->assertNotSame(GeneratePageRepairJob::jobKey(7, 3), GeneratePageRepairJob::jobKey(7, 4));
    }
}
