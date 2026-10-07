<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Services\PdfTranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * engine-wiring-and-activation T9 — the OUTPUT TEXT-LAYER GATE wired into the render
 * service (R-W4). Proves the PHP side: runTextLayerGate() runs the Python gate over the
 * SAVED pdf + translations payload and returns a verdict the render merge folds in:
 *   - a GOOD output (searchable text matching the translation) → pass;
 *   - an IMAGE-ONLY output (no text layer, the ToUnicode-corruption class) → fail closed;
 *   - a WRONG-content output (searchable but not the translation) → fail closed;
 *   - config-disable and missing-input are safe no-ops (never a false block).
 *
 * The gate method is private; we drive it via reflection with hermetic PyMuPDF fixtures
 * (no external assets, no network).
 */
class TextLayerGateTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tl_test_' . bin2hex(random_bytes(5));
        @mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            foreach (glob($this->tmp . DIRECTORY_SEPARATOR . '*') as $f) {
                @unlink($f);
            }
            @rmdir($this->tmp);
        }
        parent::tearDown();
    }

    private function py(string $code, array $args = []): void
    {
        $p = new \Symfony\Component\Process\Process(array_merge(['python', '-c', $code], $args));
        $p->run();
        $this->assertTrue($p->isSuccessful(), 'python fixture failed: ' . $p->getErrorOutput());
    }

    private function goodPdf(string $path, string $text): void
    {
        $this->py(
            "import pymupdf,sys; d=pymupdf.open(); p=d.new_page(); p.insert_text((72,72),sys.argv[2],fontsize=14); d.save(sys.argv[1]); d.close()",
            [$path, $text]
        );
    }

    private function imageOnlyPdf(string $path): void
    {
        $this->py(
            "import pymupdf,sys\n" .
            "s=pymupdf.open(); sp=s.new_page(); sp.insert_text((72,72),'Die vinnige bruin jakkals',fontsize=14); pix=sp.get_pixmap(dpi=150); s.close()\n" .
            "d=pymupdf.open(); pg=d.new_page(width=pix.width,height=pix.height); pg.insert_image(pg.rect,pixmap=pix); d.save(sys.argv[1]); d.close()",
            [$path]
        );
    }

    private function translations(string $path, string $text): void
    {
        file_put_contents($path, json_encode(
            ['items' => [['id' => 'p01_s001', 'translated_text' => $text]]],
            JSON_UNESCAPED_UNICODE
        ));
    }

    private function book(): Book
    {
        return Book::create([
            'title' => 'Text Layer Book',
            'original_language' => 'en',
            'page_count' => 1,
            'status' => 'ready',
            'pdf_path' => 'books/pdfs/x.pdf',
        ]);
    }

    private function runGate(Book $book, string $out, string $trans): array
    {
        $svc = new PdfTranslationService();
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('runTextLayerGate');
        $m->setAccessible(true);
        return $m->invoke($svc, $book, 'af', $out, $trans);
    }

    public function test_good_output_passes(): void
    {
        $out = $this->tmp . '/out.pdf';
        $trans = $this->tmp . '/t.json';
        $this->goodPdf($out, 'Die vinnige bruin jakkals spring oor die lui hond');
        $this->translations($trans, 'Die vinnige bruin jakkals spring oor die lui hond');

        $r = $this->runGate($this->book(), $out, $trans);
        $this->assertTrue($r['ran']);
        $this->assertTrue($r['searchable']);
        $this->assertTrue($r['pass']);
        $this->assertNull($r['reason']);
    }

    public function test_image_only_output_fails_closed(): void
    {
        $out = $this->tmp . '/img.pdf';
        $trans = $this->tmp . '/t.json';
        $this->imageOnlyPdf($out);
        $this->translations($trans, 'Die vinnige bruin jakkals');

        $r = $this->runGate($this->book(), $out, $trans);
        $this->assertTrue($r['ran']);
        $this->assertFalse($r['searchable']);
        $this->assertFalse($r['pass']);
        $this->assertStringContainsStringIgnoringCase('text layer', $r['reason']);
    }

    public function test_wrong_content_fails_closed(): void
    {
        $out = $this->tmp . '/wrong.pdf';
        $trans = $this->tmp . '/t.json';
        $this->goodPdf($out, 'completely different words zzz qqq xxx');
        $this->translations($trans, 'Die vinnige bruin jakkals spring oor die lui hond');

        $r = $this->runGate($this->book(), $out, $trans);
        $this->assertTrue($r['ran']);
        $this->assertTrue($r['searchable']);
        $this->assertLessThan(0.6, $r['match_rate']);
        $this->assertFalse($r['pass']);
    }

    public function test_disabled_by_config_is_noop(): void
    {
        config()->set('bookstore.text_layer.enabled', false);
        $out = $this->tmp . '/out.pdf';
        $trans = $this->tmp . '/t.json';
        $this->goodPdf($out, 'anything');
        $this->translations($trans, 'anything');

        $r = $this->runGate($this->book(), $out, $trans);
        $this->assertFalse($r['ran']);
        $this->assertSame('disabled', $r['reason']);
    }

    public function test_missing_inputs_is_noop(): void
    {
        $r = $this->runGate($this->book(), $this->tmp . '/nope.pdf', $this->tmp . '/nope.json');
        $this->assertFalse($r['ran']);
        $this->assertSame('missing_inputs', $r['reason']);
    }
}
