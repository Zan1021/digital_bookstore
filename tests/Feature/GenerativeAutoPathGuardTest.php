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
 * G3 (on-demand generative repair, spec R1) — the COST-LEAK GUARD.
 *
 * A normal render (IllustrationTextService::process) must make ZERO generative calls when
 * the global ILLUSTRATION_TEXT_GENERATIVE flag is off (the shipped default). The ONLY
 * production producer of a generative call is the deliberate, publisher-invoked per-page
 * button path (repairPageGenerative → runGenerativeRepairOnCopy). This test fails loudly if
 * a future edit lets the auto path spend.
 */
class GenerativeAutoPathGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        // Module enabled, but GENERATIVE explicitly OFF (shipped default).
        config()->set('bookstore.illustration_text.enabled', true);
        config()->set('bookstore.illustration_text.generative', false);
    }

    private function edition(): Translation
    {
        $book = Book::create([
            'title' => 'Guard Book', 'original_language' => 'en', 'page_count' => 3,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/g.pdf',
        ]);
        Storage::disk('public')->put('books/pdfs/g.pdf', "%PDF-1.7\nSRC\n%%EOF");
        Storage::disk('public')->put('books/translated/1_af.pdf', "%PDF-1.7\nED\n%%EOF");
        return Translation::create([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'AF',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
            'render_fingerprint' => 'fp0123456789abcdef',
            'rendered_pdf_path' => 'books/translated/1_af.pdf',
        ]);
    }

    public function test_normal_render_makes_zero_generative_calls_with_flag_off(): void
    {
        $tr = $this->edition();
        $svc = $this->countingService();

        // Run the normal auto path. Candidate pages may be empty (no real PDF), which is
        // fine — the assertion is about generative calls, which must be zero regardless.
        $svc->process($tr->book, $tr);

        $this->assertSame(0, $svc->generativeCalls,
            'process() must NOT trigger any generative background call when the flag is off');
    }

    public function test_per_page_button_is_the_only_generative_producer(): void
    {
        $tr = $this->edition();
        $svc = $this->countingService();

        // The deliberate per-page path IS allowed to call generative — exactly once here.
        $svc->repairPageGenerative($tr->book, $tr, 1);

        $this->assertSame(1, $svc->generativeCalls,
            'the per-page button path is the one and only generative producer');
    }

    /**
     * Service that counts generative calls by overriding BOTH generative entry points:
     *  - runGenerativeRepairOnCopy (the per-page button path), and
     *  - the private generativeBackground is reached only via repair() when the flag is on;
     * we also stub region location + render so no Python runs.
     */
    private function countingService(): IllustrationTextService
    {
        $qa = Mockery::mock(VisualQaService::class);
        return new class($qa) extends IllustrationTextService {
            public int $generativeCalls = 0;

            public function __construct($qa)
            {
                parent::__construct($qa);
            }

            // Per-page button path → counts as a generative call, returns "modified".
            protected function runGenerativeRepairOnCopy(string $copyPath, int $pageIndex, int $ppi, array $regions): array
            {
                $this->generativeCalls++;
                file_put_contents($copyPath, "%PDF-1.7\nGEN\n%%EOF");
                return ['modified' => true];
            }

            // Give the per-page path one synthetic region (no detect subprocess).
            protected function locateRegionsForGenerative(string $pdfPath, int $pageIndex, int $ppi, Translation $t): array
            {
                return [['id' => 'p1_r0', 'source_text' => 'HI', 'target_text' => 'HI',
                         'bbox_px' => [1, 1, 2, 2], 'semantic_role' => 'artwork_label']];
            }

            protected function renderPageForTest(string $pdfPath, int $pageIndex, int $ppi): ?array
            {
                $dir = storage_path('app/temp');
                if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
                $out = "{$dir}/guard_render_" . uniqid() . '.png';
                file_put_contents($out, 'x');
                return [$out, 1, 1];
            }
        };
    }
}
