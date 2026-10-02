<?php

namespace Tests\Feature;

use App\Livewire\Admin\ReviewQueue;
use App\Models\Book;
use App\Models\BookPage;
use App\Models\Translation;
use App\Models\TranslatedPage;
use App\Services\PdfTranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Phase 7.2/7.3 + UI1/UI2/XL1 — per-region edits to the canonical store, container drag
 * conversion, approval tracks, and cross-language artwork reuse.
 */
class ReviewQueueOverlayTest extends TestCase
{
    use RefreshDatabase;

    private Book $book;
    private Translation $edition;

    protected function setUp(): void
    {
        parent::setUp();
        $renderer = Mockery::mock(PdfTranslationService::class);
        $renderer->shouldReceive('createTranslatedPdf')->andReturn('books/translated/x.pdf');
        $this->app->instance(PdfTranslationService::class, $renderer);

        $this->book = Book::create([
            'title' => 'Overlay Book', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/x.pdf',
        ]);
        $this->edition = Translation::create([
            'book_id' => $this->book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
            'qa_report' => ['publishable' => true], 'render_fingerprint' => 'fp-1',
        ]);
        $bp = BookPage::create(['book_id' => $this->book->id, 'page_number' => 1, 'text' => 'x']);
        TranslatedPage::create([
            'translation_id' => $this->edition->id, 'book_page_id' => $bp->id, 'page_number' => 1,
            'translated_text' => 'Hallo', 'review_status' => 'unreviewed',
        ]);
    }

    public function test_updateRegion_writes_to_canonical_overrides_and_invalidates_tracks(): void
    {
        // approve layout + artwork first, then a region edit must invalidate them
        $this->edition->approveTrack('layout');
        $this->edition->approveTrack('artwork');

        Livewire::test(ReviewQueue::class, ['book' => $this->book, 'language' => 'af'])
            ->call('updateRegion', 'p01_s0001', ['translation' => 'Totsiens', 'font_role' => 'body']);

        $fresh = $this->edition->fresh();
        $ov = $fresh->layout_overrides['p01_s0001'] ?? null;
        $this->assertNotNull($ov);
        $this->assertSame('Totsiens', $ov['translation']);
        $this->assertSame('body', $ov['font_role']);
        $this->assertTrue($ov['edited_by_reviewer']);
        // layout + artwork approvals dropped by the edit
        $this->assertFalse($fresh->isTrackApproved('layout'));
        $this->assertFalse($fresh->isTrackApproved('artwork'));
    }

    public function test_updateRegionContainer_converts_px_to_points(): void
    {
        // seed the overlay dpi so px->pt uses the right scale
        $cmp = Livewire::test(ReviewQueue::class, ['book' => $this->book, 'language' => 'af'])
            ->set('overlay', ['dpi' => 144]) // scale 72/144 = 0.5
            ->call('updateRegionContainer', 'p01_s0001', 100.0, 200.0, 300.0, 400.0);

        $ov = $this->edition->fresh()->layout_overrides['p01_s0001'] ?? null;
        $this->assertNotNull($ov);
        // 100px@144dpi -> 50pt, 300->150, 200->100, 400->200
        $this->assertEquals([50.0, 100.0, 150.0, 200.0], $ov['container']);
    }

    public function test_degenerate_container_drag_ignored(): void
    {
        Livewire::test(ReviewQueue::class, ['book' => $this->book, 'language' => 'af'])
            ->set('overlay', ['dpi' => 72])
            ->call('updateRegionContainer', 'p01_s0001', 100.0, 100.0, 100.0, 150.0); // zero width

        $this->assertArrayNotHasKey('p01_s0001', $this->edition->fresh()->layout_overrides ?? []);
    }

    public function test_reuse_artwork_promotes_to_book_metadata(): void
    {
        $this->edition->forceFill([
            'layout_overrides' => [
                'p05_art01' => ['container' => [1, 2, 3, 4], 'mask' => [1, 1, 2, 2], 'translation' => 'per-lang'],
            ],
        ])->save();
        $this->edition->approveTrack('artwork');

        Livewire::test(ReviewQueue::class, ['book' => $this->book, 'language' => 'af'])
            ->call('reuseArtworkAcrossLanguages');

        $shared = $this->book->fresh()->metadata['shared_artwork'] ?? null;
        $this->assertNotNull($shared);
        $this->assertSame('af', $shared['approved_from_language']);
        // language-independent bits kept, per-language text dropped
        $this->assertArrayHasKey('container', $shared['overrides']['p05_art01']);
        $this->assertArrayHasKey('mask', $shared['overrides']['p05_art01']);
        $this->assertArrayNotHasKey('translation', $shared['overrides']['p05_art01']);
    }

    public function test_reuse_artwork_requires_artwork_approval(): void
    {
        Livewire::test(ReviewQueue::class, ['book' => $this->book, 'language' => 'af'])
            ->call('reuseArtworkAcrossLanguages');
        $this->assertArrayNotHasKey('shared_artwork', $this->book->fresh()->metadata ?? []);
    }

    public function test_toggle_overlay_layer(): void
    {
        Livewire::test(ReviewQueue::class, ['book' => $this->book, 'language' => 'af'])
            ->assertSet('overlayToggles.eraseMask', false)
            ->call('toggleOverlayLayer', 'eraseMask')
            ->assertSet('overlayToggles.eraseMask', true);
    }
}
