<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Translation;
use App\Services\IllustrationTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * world-class-render-engine spec, Phase 4 — illustration-text REGION-LEVEL ownership +
 * per-ID targets. Exercises the deterministic helpers directly (no PDF / no OpenAI):
 *  - filterContractOwnedRegions keeps a baked-in sign on a page that also has a native
 *    paragraph (region-level, not whole-page).
 *  - tagRegions assigns stable ids + content class.
 *  - attachTargetsById resolves per-id and flags a MISSING target (fail closed) instead
 *    of using a whole-page blob.
 */
class IllustrationRegionOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): IllustrationTextService
    {
        return app(IllustrationTextService::class);
    }

    private function invokePrivate(string $method, array $args)
    {
        $m = new ReflectionMethod(IllustrationTextService::class, $method);
        $m->setAccessible(true);
        return $m->invokeArgs($this->svc(), $args);
    }

    public function test_region_ownership_is_region_level_not_whole_page(): void
    {
        // A page with a native paragraph (owned, top) AND a baked sign (bottom, NOT owned).
        // Owned box is in PDF points; regions are pixels at 300 ppi (scale 300/72).
        $ppi = 300;
        $ownedPt = [[64, 60, 280, 120]];           // native paragraph region (points)
        $regions = [
            ['source_text' => 'native para', 'bbox_px' => [64 * 300 / 72, 60 * 300 / 72, 280 * 300 / 72, 120 * 300 / 72]],
            ['source_text' => 'SHOP SIGN',  'bbox_px' => [64 * 300 / 72, 400 * 300 / 72, 280 * 300 / 72, 460 * 300 / 72]],
        ];
        $kept = $this->invokePrivate('filterContractOwnedRegions', [$regions, $ownedPt, $ppi]);

        $this->assertCount(1, $kept, 'the owned paragraph is dropped, the sign survives');
        $this->assertSame('SHOP SIGN', $kept[0]['source_text']);
    }

    public function test_tag_regions_assigns_stable_ids_and_class(): void
    {
        $tagged = $this->invokePrivate('tagRegions', [
            [['source_text' => 'A'], ['source_text' => 'B']], 7, false,
        ]);
        $this->assertSame('p07_art01', $tagged[0]['id']);
        $this->assertSame('p07_art02', $tagged[1]['id']);
        $this->assertSame('raster_text', $tagged[0]['source_kind']);

        $native = $this->invokePrivate('tagRegions', [[['source_text' => 'A']], 3, true]);
        $this->assertSame('native', $native[0]['source_kind']);
    }

    public function test_attach_targets_by_id_resolves_and_flags_missing(): void
    {
        $book = Book::create([
            'title' => 'Art Book', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/x.pdf',
        ]);
        $t = Translation::create([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'AF',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
            'item_translations' => ['p01_art01' => 'WINKEL TEKEN'],
        ]);

        $regions = [
            ['id' => 'p01_art01', 'source_text' => 'SHOP SIGN'],   // has a per-id target
            ['id' => 'p01_art02', 'source_text' => 'NO TARGET'],   // missing => issue
        ];
        [$out, $issues] = $this->invokePrivate('attachTargetsById', [$t, $regions]);

        $this->assertCount(1, $out, 'only the resolved region is emitted');
        $this->assertSame('WINKEL TEKEN', $out[0]['target_text']);
        $this->assertCount(1, $issues);
        $this->assertSame('MISSING_TARGET', $issues[0]['code']);
        $this->assertSame('p01_art02', $issues[0]['region_id']);
    }

    public function test_preserve_region_is_never_erased(): void
    {
        $book = Book::create([
            'title' => 'Art Book 2', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/x.pdf',
        ]);
        $t = Translation::create([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'AF',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
        ]);
        $regions = [['id' => 'p01_art01', 'source_text' => 'LOGO', 'translation_policy' => 'preserve']];
        [$out, $issues] = $this->invokePrivate('attachTargetsById', [$t, $regions]);

        $this->assertCount(0, $out, 'preserve region is not emitted for erase');
        $this->assertCount(0, $issues, 'preserve region is not an issue');
    }
}
