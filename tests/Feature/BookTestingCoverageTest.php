<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Translation;
use App\Services\Qa\BookTestingService;
use App\Services\VisualQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * unified-rendering-and-testing spec Req 4 / task B1.5 — the whole-book coverage ledger.
 * A render whose visual QA only covered SOME pages (e.g. just the cover) must be reported
 * incomplete; coverage is derived from the full PDF page set, not the checked subset.
 */
class BookTestingCoverageTest extends TestCase
{
    use RefreshDatabase;

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();
        $this->book = Book::create([
            'title' => 'Coverage Book', 'original_language' => 'en', 'page_count' => 4,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/x.pdf',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function edition(): Translation
    {
        return Translation::create([
            'book_id' => $this->book->id, 'language_code' => 'af', 'language_name' => 'AF',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
            'rendered_pdf_path' => 'books/translated/x.pdf',
            'render_fingerprint' => 'fp-current',
        ]);
    }

    /** A BookTestingService whose expected page set is fixed (no real PDF needed). */
    private function serviceWithPages(array $pages, VisualQaService $qa): BookTestingService
    {
        return new class($pages, $qa) extends BookTestingService {
            public function __construct(private array $pages, VisualQaService $qa)
            {
                parent::__construct($qa);
            }
            protected function pdfPageNumbers(string $pdfPath): array
            {
                return $this->pages;
            }
        };
    }

    public function test_cover_only_check_reports_book_incomplete(): void
    {
        $edition = $this->edition();

        // Visual QA only returns a passing record for page 1 (the cover).
        $qa = Mockery::mock(VisualQaService::class);
        $qa->shouldReceive('review')->andReturn([
            'ok' => false,
            'flagged_pages' => [],
            'records' => [
                ['page_number' => 1, 'status' => 'passed', 'candidate_fingerprint' => 'fp-current'],
            ],
            'pages' => [],
        ]);

        $service = $this->serviceWithPages([1, 2, 3, 4], $qa);
        $result = $service->visualCoverage($this->book, $edition);

        $this->assertFalse($result['covered'], 'cover-only coverage is NOT complete');
        $codes = array_column($result['issues'], 'code');
        $this->assertContains('PAGE_NOT_CHECKED', $codes);
        // Pages 2,3,4 unchecked.
        $missing = array_column(array_filter($result['issues'], fn ($i) => $i['code'] === 'PAGE_NOT_CHECKED'), 'page_number');
        $this->assertEqualsCanonicalizing([2, 3, 4], $missing);
    }

    public function test_all_pages_passed_is_covered(): void
    {
        $edition = $this->edition();

        $records = [];
        foreach (range(1, 4) as $n) {
            $records[] = ['page_number' => $n, 'status' => 'passed', 'candidate_fingerprint' => 'fp-current'];
        }
        $qa = Mockery::mock(VisualQaService::class);
        $qa->shouldReceive('review')->andReturn(['ok' => true, 'flagged_pages' => [], 'records' => $records, 'pages' => []]);

        $service = $this->serviceWithPages([1, 2, 3, 4], $qa);
        $result = $service->visualCoverage($this->book, $edition);

        $this->assertTrue($result['covered']);
        $this->assertSame([], $result['issues']);
    }

    public function test_missing_pdf_empty_page_set_fails_closed(): void
    {
        $edition = $this->edition();

        $qa = Mockery::mock(VisualQaService::class);
        $qa->shouldReceive('review')->andReturn(['ok' => false, 'flagged_pages' => [], 'records' => [], 'pages' => []]);

        // Empty expected set (could not count the PDF) must NOT read as covered.
        $service = $this->serviceWithPages([], $qa);
        $result = $service->visualCoverage($this->book, $edition);

        $this->assertFalse($result['covered']);
        $this->assertSame('EMPTY_EXPECTED_PAGE_SET', $result['issues'][0]['code']);
    }

    public function test_stale_fingerprint_record_blocks_coverage(): void
    {
        $edition = $this->edition();

        $qa = Mockery::mock(VisualQaService::class);
        $qa->shouldReceive('review')->andReturn([
            'ok' => true, 'flagged_pages' => [],
            'records' => [
                ['page_number' => 1, 'status' => 'passed', 'candidate_fingerprint' => 'fp-OLD'],
            ],
            'pages' => [],
        ]);

        $service = $this->serviceWithPages([1], $qa);
        $result = $service->visualCoverage($this->book, $edition);

        $this->assertFalse($result['covered']);
        $this->assertSame('STALE_VISUAL_RESULT', $result['issues'][0]['code']);
    }
}
