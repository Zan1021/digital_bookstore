<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Translation;
use App\Services\IllustrationTextService;
use App\Services\VisualQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * G2 (on-demand generative repair) — applyPageVersion() applies the publisher's per-page
 * pick: keep_cheap is a no-op on the PDF; use_generative splices exactly one page, re-runs
 * the per-page compare, and records an audit entry. ZERO SPEND: the splice + the vision
 * re-compare are overridden by a test subclass (no Python, no OpenAI).
 */
class ApplyPageVersionTest extends TestCase
{
    use RefreshDatabase;

    private const LIVE_REL = 'books/translated/1_af.pdf';
    private const LIVE_BYTES = "%PDF-1.7\nLIVE-EDITION\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::disk('public')->put(self::LIVE_REL, self::LIVE_BYTES);
    }

    private function edition(): Translation
    {
        $book = Book::create([
            'title' => 'Apply Book', 'original_language' => 'en', 'page_count' => 3,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/a.pdf',
        ]);
        return Translation::create([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'AF',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
            'render_fingerprint' => 'fp0123456789abcdef',
            'rendered_pdf_path' => self::LIVE_REL,
        ]);
    }

    private function candidatePath(Translation $tr, int $page): string
    {
        $fp = substr((string) $tr->render_fingerprint, 0, 16);
        $dir = storage_path('app/temp');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return "{$dir}/candidate_{$tr->book_id}_{$tr->language_code}_p{$page}_{$fp}.pdf";
    }

    public function test_keep_cheap_is_a_noop_on_the_pdf_and_approves_page(): void
    {
        $tr = $this->edition();
        $svc = $this->service(VisualQaService::STATUS_PASSED);

        $res = $svc->applyPageVersion($tr->book, $tr, 2, 'keep_cheap');

        $this->assertTrue($res['ok']);
        $this->assertSame('keep_cheap', $res['applied']);
        $this->assertSame(self::LIVE_BYTES, Storage::disk('public')->get(self::LIVE_REL),
            'keep_cheap must not change the edition PDF');

        $tr->refresh();
        $this->assertTrue(($tr->page_approvals['2']['approved'] ?? false));
        // Audit entry recorded.
        $this->assertNotEmpty($tr->qa_report['audit'] ?? []);
        $this->assertSame('keep_cheap', $tr->qa_report['audit'][0]['action']);
        $this->assertFalse($tr->qa_report['audit'][0]['generative_spent']);
    }

    public function test_use_generative_splices_one_page_recompares_and_audits(): void
    {
        $tr = $this->edition();
        // A retained candidate PDF must exist for the apply.
        file_put_contents($this->candidatePath($tr, 2), "%PDF-1.7\nCANDIDATE\n%%EOF");

        $svc = $this->service(VisualQaService::STATUS_PASSED);
        $res = $svc->applyPageVersion($tr->book, $tr, 2, 'use_generative');

        $this->assertTrue($res['ok']);
        $this->assertSame('use_generative', $res['applied']);
        $this->assertSame(VisualQaService::STATUS_PASSED, $res['recompare']);
        $this->assertSame(1, $svc->splicedPage, 'the splice must target the chosen page (0-based index of page 2)');

        $tr->refresh();
        $this->assertTrue(($tr->page_approvals['2']['approved'] ?? false),
            'a passing re-compare approves the page');
        $audit = $tr->qa_report['audit'][0] ?? [];
        $this->assertSame('use_generative', $audit['action']);
        $this->assertTrue($audit['generative_spent']);
        $this->assertSame(VisualQaService::STATUS_PASSED, $audit['recompare']);
    }

    public function test_use_generative_failing_recompare_keeps_page_unapproved(): void
    {
        $tr = $this->edition();
        file_put_contents($this->candidatePath($tr, 2), "%PDF-1.7\nCANDIDATE\n%%EOF");

        $svc = $this->service(VisualQaService::STATUS_FAILED);
        $res = $svc->applyPageVersion($tr->book, $tr, 2, 'use_generative');

        $this->assertTrue($res['ok']);
        $this->assertSame('RECOMPARE_NOT_PASSED', $res['reason']);
        $tr->refresh();
        $this->assertFalse(($tr->page_approvals['2']['approved'] ?? true),
            'a failing re-compare must NOT approve the page');
    }

    public function test_fails_closed_when_no_candidate_exists(): void
    {
        $tr = $this->edition();
        $svc = $this->service(VisualQaService::STATUS_PASSED);
        $res = $svc->applyPageVersion($tr->book, $tr, 2, 'use_generative');

        $this->assertFalse($res['ok']);
        $this->assertSame('NO_CANDIDATE_TO_APPLY', $res['reason']);
        $this->assertSame(self::LIVE_BYTES, Storage::disk('public')->get(self::LIVE_REL));
    }

    public function test_rejects_invalid_choice(): void
    {
        $tr = $this->edition();
        $res = $this->service(VisualQaService::STATUS_PASSED)
            ->applyPageVersion($tr->book, $tr, 2, 'frobnicate');
        $this->assertFalse($res['ok']);
        $this->assertSame('INVALID_CHOICE', $res['reason']);
    }

    /** Service whose splice + vision re-compare are mocked (no Python / no OpenAI). */
    private function service(string $recompareStatus): IllustrationTextService
    {
        $qa = Mockery::mock(VisualQaService::class);
        return new class($qa, $recompareStatus) extends IllustrationTextService {
            public ?int $splicedPage = null;
            public function __construct($qa, private string $recompareStatus)
            {
                parent::__construct($qa);
            }
            protected function splicePageIntoEdition(string $candidatePdf, string $editionPath, int $pageIndex): bool
            {
                // Simulate a successful single-page splice WITHOUT touching other pages:
                // write a marker to the edition (stands in for the one replaced page).
                $this->splicedPage = $pageIndex;
                file_put_contents($editionPath, "%PDF-1.7\nEDITION-WITH-PAGE-{$pageIndex}-SPLICED\n%%EOF");
                return true;
            }
            protected function recomparePage(Book $book, Translation $translation, int $page): string
            {
                return $this->recompareStatus;
            }
        };
    }
}
