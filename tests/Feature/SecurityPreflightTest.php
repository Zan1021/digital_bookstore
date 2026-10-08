<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Services\PdfTranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * engine-wiring-and-activation C7-T23 — the PDF SECURITY PRE-FLIGHT wired into the render
 * service (R-W1). Proves the PHP side: runSecurityPreflight() runs scripts/security.py over
 * the SOURCE pdf and returns a verdict the render merge folds in:
 *   - a CLEAN pdf → safe, pass;
 *   - a pdf with embedded JavaScript / OpenAction JS → unsafe, fail closed (PDF_SECURITY_THREAT);
 *   - config-disable and missing-input are safe no-ops (never a false block).
 *
 * The method is private; we drive it via reflection with hermetic fixtures (no network).
 */
class SecurityPreflightTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sec_test_' . bin2hex(random_bytes(5));
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

    private function cleanPdf(string $path): void
    {
        $p = new \Symfony\Component\Process\Process([
            'python', '-c',
            "import pymupdf,sys; d=pymupdf.open(); d.new_page().insert_text((72,72),'clean content'); d.save(sys.argv[1]); d.close()",
            $path,
        ]);
        $p->run();
        $this->assertTrue($p->isSuccessful(), 'clean fixture failed: ' . $p->getErrorOutput());
    }

    private function jsThreatPdf(string $path): void
    {
        // Hand-built PDF with an OpenAction /JavaScript — the scanner flags /JS in the xref.
        $pdf = "%PDF-1.4\n"
            . "1 0 obj<</Type/Catalog/Pages 2 0 R/OpenAction<</S/JavaScript/JS(app.alert\\(1\\);)>>>>endobj\n"
            . "2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            . "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 200 200]>>endobj\n"
            . "trailer<</Root 1 0 R>>\n%%EOF";
        file_put_contents($path, $pdf);
    }

    private function book(): Book
    {
        return Book::create([
            'title' => 'Security Book',
            'original_language' => 'en',
            'page_count' => 1,
            'status' => 'ready',
            'pdf_path' => 'books/pdfs/x.pdf',
        ]);
    }

    private function runPreflight(Book $book, string $src): array
    {
        $svc = new PdfTranslationService();
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('runSecurityPreflight');
        $m->setAccessible(true);
        return $m->invoke($svc, $book, $src);
    }

    public function test_clean_pdf_is_safe(): void
    {
        $src = $this->tmp . '/clean.pdf';
        $this->cleanPdf($src);

        $r = $this->runPreflight($this->book(), $src);
        $this->assertTrue($r['ran']);
        $this->assertTrue($r['safe']);
        $this->assertEmpty($r['threats'] ?? []);
    }

    public function test_javascript_pdf_fails_closed(): void
    {
        $src = $this->tmp . '/threat.pdf';
        $this->jsThreatPdf($src);

        $r = $this->runPreflight($this->book(), $src);
        $this->assertTrue($r['ran']);
        $this->assertFalse($r['safe']);
        $types = array_map(fn ($t) => $t['type'] ?? '', $r['threats'] ?? []);
        $this->assertContains('javascript', $types);
    }

    public function test_disabled_by_config_is_noop(): void
    {
        config()->set('bookstore.security.enabled', false);
        $src = $this->tmp . '/clean.pdf';
        $this->cleanPdf($src);

        $r = $this->runPreflight($this->book(), $src);
        $this->assertFalse($r['ran']);
        $this->assertSame('disabled', $r['reason']);
    }

    public function test_missing_input_is_noop(): void
    {
        $r = $this->runPreflight($this->book(), $this->tmp . '/nope.pdf');
        $this->assertFalse($r['ran']);
        $this->assertSame('missing_inputs', $r['reason']);
    }
}
