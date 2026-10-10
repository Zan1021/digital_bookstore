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
 * G1 (on-demand generative repair) — repairPageGenerative() produces a per-page
 * candidate WITHOUT touching the live edition PDF, and fails closed on error.
 *
 * ZERO SPEND: the generative seam (runGenerativeRepairOnCopy) and the region-location
 * helpers are overridden by a test subclass, so no Python subprocess and no OpenAI call
 * ever runs. We assert behaviour: a candidate image is produced, the live PDF bytes are
 * unchanged, and the error path returns ok=false with no side effects.
 */
class GenerativeRepairCandidateTest extends TestCase
{
    use RefreshDatabase;

    private const LIVE_REL = 'books/translated/1_af.pdf';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        // A minimal, valid-enough PDF stand-in for the live edition. The repair + render
        // are mocked, so the bytes only need to be stable and identifiable.
        Storage::disk('public')->put(self::LIVE_REL, "%PDF-1.7\nLIVE-EDITION-BYTES\n%%EOF");
    }

    private function edition(): Translation
    {
        $book = Book::create([
            'title' => 'Gen Repair Book', 'original_language' => 'en', 'page_count' => 3,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/gen.pdf',
        ]);
        return Translation::create([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'AF',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
            'render_fingerprint' => 'fp0123456789abcdef',
            'rendered_pdf_path' => self::LIVE_REL,
        ]);
    }

    private function liveBytes(): string
    {
        return Storage::disk('public')->get(self::LIVE_REL);
    }

    public function test_produces_candidate_without_touching_live_edition(): void
    {
        $tr = $this->edition();
        $before = $this->liveBytes();

        $svc = $this->serviceThatRepairs(modifies: true);
        $res = $svc->repairPageGenerative($tr->book, $tr, 1);

        $this->assertTrue($res['ok'], 'expected a successful candidate');
        $this->assertNotNull($res['candidate_image']);
        $this->assertNotNull($res['current_image']);
        $this->assertFileExists($res['candidate_image']);
        $this->assertFileExists($res['current_image']);

        // THE core guarantee: the live edition is byte-identical.
        $this->assertSame($before, $this->liveBytes(),
            'live edition PDF must be unchanged by a candidate render');

        @unlink($res['candidate_image']);
        @unlink($res['current_image']);
    }

    public function test_fails_closed_when_generative_repair_makes_no_change(): void
    {
        $tr = $this->edition();
        $before = $this->liveBytes();

        // Repair seam reports no modification → candidate must fail closed, no image.
        $svc = $this->serviceThatRepairs(modifies: false);
        $res = $svc->repairPageGenerative($tr->book, $tr, 1);

        $this->assertFalse($res['ok']);
        $this->assertNull($res['candidate_image']);
        $this->assertSame('GENERATIVE_REPAIR_PRODUCED_NO_CHANGE', $res['reason']);
        $this->assertSame($before, $this->liveBytes());
    }

    public function test_fails_closed_when_edition_pdf_missing(): void
    {
        $tr = $this->edition();
        Storage::disk('public')->delete(self::LIVE_REL);

        $svc = $this->serviceThatRepairs(modifies: true);
        $res = $svc->repairPageGenerative($tr->book, $tr, 1);

        $this->assertFalse($res['ok']);
        $this->assertSame('MISSING_EDITION_PDF', $res['reason']);
    }

    public function test_rejects_invalid_page(): void
    {
        $tr = $this->edition();
        $res = $this->serviceThatRepairs(modifies: true)->repairPageGenerative($tr->book, $tr, 0);
        $this->assertFalse($res['ok']);
        $this->assertSame('INVALID_PAGE', $res['reason']);
    }

    /**
     * Build a service whose Python/OpenAI touch-points are all overridden:
     *  - region location returns one synthetic region (no detect/native subprocess),
     *  - the generative repair seam writes a marker into the COPY (simulating a repair)
     *    and reports modified=$modifies — NO OpenAI, NO Python,
     *  - renderPage returns a real tiny PNG file written to temp (no PyMuPDF).
     */
    private function serviceThatRepairs(bool $modifies): IllustrationTextService
    {
        $qa = Mockery::mock(VisualQaService::class);

        return new class($qa, $modifies) extends IllustrationTextService {
            public function __construct($qa, private bool $modifies)
            {
                parent::__construct($qa);
            }

            // No detect/native subprocess — hand back one synthetic region.
            protected function locateRegionsForGenerative(string $pdfPath, int $pageIndex, int $ppi, Translation $t): array
            {
                return [[
                    'id' => 'p1_r0', 'source_text' => 'STOP', 'target_text' => 'STOP',
                    'bbox_px' => [10, 10, 60, 30], 'semantic_role' => 'artwork_label',
                ]];
            }

            // The ONLY paid step in production — fully replaced here. Prove it writes to
            // the COPY (never the live edition, which the caller passed by copying first).
            protected function runGenerativeRepairOnCopy(string $copyPath, int $pageIndex, int $ppi, array $regions): array
            {
                if ($this->modifies) {
                    file_put_contents($copyPath, "%PDF-1.7\nGENERATIVE-CANDIDATE\n%%EOF");
                }
                return ['modified' => $this->modifies];
            }

            // Replace PyMuPDF render with a tiny real PNG so file assertions are meaningful.
            protected function renderPageForTest(string $pdfPath, int $pageIndex, int $ppi): ?array
            {
                $dir = storage_path('app/temp');
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                $out = "{$dir}/test_render_" . uniqid() . '.png';
                // 1x1 PNG.
                file_put_contents($out, base64_decode(
                    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
                ));
                return [$out, 1, 1];
            }
        };
    }
}
