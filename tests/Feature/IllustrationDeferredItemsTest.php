<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Translation;
use App\Services\IllustrationTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * world-class-render-engine — the 3 DEFERRED Phase-4 detection items, now implemented:
 *   1. source-based 3-class content classifier (native / outlined_vector / raster_text);
 *   2. candidate heuristic is TRIAGE-ONLY — every page gets a recorded coverage result;
 *   3. artwork regions emitted into the translation request UP FRONT (inventory method).
 *
 * Deterministic parts only (no OpenAI). The classifier uses the real Python helper against
 * a PDF we build on the fly; config-gated inventory is checked for its off-by-default path.
 */
class IllustrationDeferredItemsTest extends TestCase
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

    /** Build a 1-page PDF with a native text line + vector lettering + a plain image. */
    private function makeClassifierPdf(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'clsf') . '.pdf';
        $py = <<<PY
import pymupdf
doc = pymupdf.open()
page = doc.new_page(width=400, height=560)
page.insert_text((60, 115), "Native title here", fontsize=16)
for i in range(6):
    x = 60 + i * 24
    page.draw_rect(pymupdf.Rect(x, 250, x + 18, 285), fill=(0.1, 0.1, 0.1), color=None)
pix = pymupdf.Pixmap(pymupdf.csRGB, pymupdf.IRect(0, 0, 200, 80), False)
pix.set_rect(pix.irect, (200, 120, 40))
page.insert_image(pymupdf.Rect(60, 380, 260, 460), pixmap=pix)
doc.save(r"{$path}")
doc.close()
PY;
        $p = new \Symfony\Component\Process\Process(['python', '-c', $py]);
        $p->run();
        return $path;
    }

    // ---- ITEM 1: source-based content class --------------------------------
    public function test_tag_regions_sets_source_based_content_class(): void
    {
        $pdf = $this->makeClassifierPdf();
        $ppi = 150;
        $s = $ppi / 72.0;
        $regions = [
            ['source_text' => 'Native title here', 'bbox_px' => [60 * $s, 100 * $s, 220 * $s, 122 * $s]],
            ['source_text' => 'LOGO',              'bbox_px' => [55 * $s, 245 * $s, 210 * $s, 290 * $s]],
            ['source_text' => 'SIGN',              'bbox_px' => [90 * $s, 400 * $s, 230 * $s, 440 * $s]],
        ];
        // page index 0, usedNative=false so the class comes PURELY from the source classifier
        $tagged = $this->invokePrivate('tagRegions', [$regions, 1, false, $pdf, 0, $ppi]);

        $this->assertSame('native', $tagged[0]['content_class']);
        $this->assertSame('outlined_vector', $tagged[1]['content_class']);
        $this->assertSame('raster_text', $tagged[2]['content_class']);
        // source_kind stays backward-compatible: native vs non-native
        $this->assertSame('native', $tagged[0]['source_kind']);
        $this->assertSame('raster_text', $tagged[1]['source_kind']);
        @unlink($pdf);
    }

    public function test_tag_regions_falls_back_to_binary_without_pdf(): void
    {
        // No pdf path => classifier not run => binary class from usedNative (backward compat)
        $tagged = $this->invokePrivate('tagRegions', [[['source_text' => 'A']], 2, true]);
        $this->assertSame('native', $tagged[0]['content_class']);
        $this->assertSame('p02_art01', $tagged[0]['id']);
    }

    // ---- ITEM 2: candidate heuristic is triage-only (page-count helper) -----
    public function test_pdf_page_count_helper(): void
    {
        $pdf = $this->makeClassifierPdf();
        $count = $this->invokePrivate('pdfPageCount', [$pdf]);
        $this->assertSame(1, $count);
        @unlink($pdf);
    }

    // ---- ITEM 3: up-front artwork inventory (config gate) -------------------
    public function test_inventory_is_off_by_default(): void
    {
        config(['bookstore.illustration_text.enabled' => false]);
        $book = Book::create([
            'title' => 'Art Book 3', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/x.pdf',
        ]);
        $t = Translation::create([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'AF',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
        ]);
        $this->assertSame([], $this->svc()->inventoryArtworkRegions($book, $t));
    }

    public function test_inventory_returns_empty_when_source_missing(): void
    {
        config(['bookstore.illustration_text.enabled' => true]);
        $book = Book::create([
            'title' => 'Art Book 4', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/does_not_exist.pdf',
        ]);
        $t = Translation::create([
            'book_id' => $book->id, 'language_code' => 'af', 'language_name' => 'AF',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
        ]);
        // no real source file => graceful empty, never throws
        $this->assertSame([], $this->svc()->inventoryArtworkRegions($book, $t));
        config(['bookstore.illustration_text.enabled' => false]);
    }
}
