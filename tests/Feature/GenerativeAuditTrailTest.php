<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Translation;
use App\Models\User;
use App\Services\IllustrationTextService;
use App\Services\VisualQaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * G5 (on-demand generative repair, spec C-D) — audit + cost trail.
 *
 * Every applyPageVersion appends an audit entry to the edition's qa_report['audit'] with the
 * page, action, re-compare verdict, generative_spent flag, and acting user id — so per-page
 * generative spend is traceable. Mocked splice + re-compare → zero real spend.
 */
class GenerativeAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private const LIVE_REL = 'books/translated/1_af.pdf';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::disk('public')->put(self::LIVE_REL, "%PDF-1.7\nED\n%%EOF");
    }

    private function edition(): Translation
    {
        $book = Book::create([
            'title' => 'Audit Book', 'original_language' => 'en', 'page_count' => 3,
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
        if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
        return "{$dir}/candidate_{$tr->book_id}_{$tr->language_code}_p{$page}_{$fp}.pdf";
    }

    private function service(string $recompare): IllustrationTextService
    {
        $qa = Mockery::mock(VisualQaService::class);
        return new class($qa, $recompare) extends IllustrationTextService {
            public function __construct($qa, private string $recompare) { parent::__construct($qa); }
            protected function splicePageIntoEdition(string $c, string $e, int $i): bool
            {
                file_put_contents($e, "%PDF-1.7\nSPLICED-{$i}\n%%EOF");
                return true;
            }
            protected function recomparePage(Book $b, Translation $t, int $p): string
            {
                return $this->recompare;
            }
        };
    }

    public function test_use_generative_records_a_spend_audit_entry_with_user(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $tr = $this->edition();
        file_put_contents($this->candidatePath($tr, 2), "%PDF\nCAND");

        $this->service(VisualQaService::STATUS_PASSED)
            ->applyPageVersion($tr->book, $tr, 2, 'use_generative');

        $tr->refresh();
        $audit = $tr->qa_report['audit'] ?? [];
        $this->assertCount(1, $audit);
        $entry = $audit[0];
        $this->assertSame(2, $entry['page']);
        $this->assertSame('use_generative', $entry['action']);
        $this->assertTrue($entry['generative_spent']);
        $this->assertSame(VisualQaService::STATUS_PASSED, $entry['recompare']);
        $this->assertSame($user->id, $entry['user_id']);
        $this->assertArrayHasKey('at', $entry);
    }

    public function test_keep_cheap_records_a_no_spend_audit_entry(): void
    {
        $tr = $this->edition();
        $this->service(VisualQaService::STATUS_PASSED)
            ->applyPageVersion($tr->book, $tr, 1, 'keep_cheap');

        $tr->refresh();
        $entry = ($tr->qa_report['audit'] ?? [])[0] ?? [];
        $this->assertSame('keep_cheap', $entry['action'] ?? null);
        $this->assertFalse($entry['generative_spent'] ?? true);
        $this->assertArrayHasKey('recompare', $entry);
        $this->assertNull($entry['recompare']);
    }

    public function test_audit_entries_accumulate_across_pages(): void
    {
        $tr = $this->edition();
        file_put_contents($this->candidatePath($tr, 2), "%PDF\nCAND");

        $svc = $this->service(VisualQaService::STATUS_PASSED);
        $svc->applyPageVersion($tr->book, $tr->fresh(), 1, 'keep_cheap');
        $svc->applyPageVersion($tr->book, $tr->fresh(), 2, 'use_generative');

        $tr->refresh();
        $audit = $tr->qa_report['audit'] ?? [];
        $this->assertCount(2, $audit, 'both actions are recorded, not overwritten');
        $this->assertSame([1, 2], collect($audit)->pluck('page')->all());
    }
}
