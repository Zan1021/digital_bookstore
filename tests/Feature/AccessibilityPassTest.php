<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Services\PdfTranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * engine-wiring-and-activation T17/T18 — the TAGGED-PDF / ACCESSIBILITY PASS wired into
 * the render service (R-W10). Proves the PHP side: runAccessibilityPass() runs the Python
 * `accessibility.py pass` over the SAVED pdf and returns a verdict the render merge folds
 * in:
 *   - a normal output → pass; /Lang stamped to the edition language; score recorded;
 *   - a missing structure tree is a RECOMMENDATION, never a block;
 *   - a language-write failure (unwritable target) → fail closed (ACCESSIBILITY_LANG_UNSET);
 *   - config-disable and missing-input are safe no-ops (never a false block).
 *
 * The pass method is private; we drive it via reflection with hermetic PyMuPDF fixtures
 * (no external assets, no network).
 */
class AccessibilityPassTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'acc_test_' . bin2hex(random_bytes(5));
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

    private function langInCatalog(string $path): bool
    {
        $p = new \Symfony\Component\Process\Process([
            'python', '-c',
            "import pymupdf,sys; d=pymupdf.open(sys.argv[1]); print('1' if '/Lang' in d.xref_object(d.pdf_catalog()) else '0')",
            $path,
        ]);
        $p->run();
        return trim($p->getOutput()) === '1';
    }

    private function book(): Book
    {
        return Book::create([
            'title' => 'Accessibility Book',
            'original_language' => 'en',
            'page_count' => 1,
            'status' => 'ready',
            'pdf_path' => 'books/pdfs/x.pdf',
        ]);
    }

    private function runPass(Book $book, string $out, string $lang = 'af'): array
    {
        $svc = new PdfTranslationService();
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('runAccessibilityPass');
        $m->setAccessible(true);
        return $m->invoke($svc, $book, $lang, $out);
    }

    public function test_normal_output_passes_and_stamps_language(): void
    {
        $out = $this->tmp . '/out.pdf';
        $this->goodPdf($out, 'Die vinnige bruin jakkals spring oor die lui hond');

        $r = $this->runPass($this->book(), $out, 'af');
        $this->assertTrue($r['ran']);
        $this->assertTrue($r['pass']);
        $this->assertSame('af-ZA', $r['language_set']);
        $this->assertTrue($this->langInCatalog($out), '/Lang must be stamped on the output');
    }

    public function test_missing_structure_is_recommendation_not_block(): void
    {
        $out = $this->tmp . '/plain.pdf';
        $this->goodPdf($out, 'A plain page with no structure tree');

        $r = $this->runPass($this->book(), $out, 'en');
        $this->assertTrue($r['ran']);
        $this->assertTrue($r['pass']); // not blocked
        $this->assertFalse($r['has_structure_tree']);
        $this->assertNotEmpty($r['recommendations']);
    }

    public function test_language_write_failure_fails_closed(): void
    {
        // A path whose parent directory does not exist cannot be written -> lang_write_failed.
        $out = $this->tmp . '/out.pdf';
        $this->goodPdf($out, 'content');
        // Point the pass at a missing file so set_document_language raises internally.
        $missing = $this->tmp . '/does_not_exist.pdf';
        // guard: ensure it really is absent
        @unlink($missing);

        $svc = new PdfTranslationService();
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod('runAccessibilityPass');
        $m->setAccessible(true);
        // missing input is caught earlier as a no-op; to exercise lang_write_failed we need an
        // existing but unwritable-as-full-save target. Simplest deterministic path: a 0-byte
        // file that pymupdf cannot open as a PDF -> set_document_language throws -> pass=false.
        $broken = $this->tmp . '/broken.pdf';
        file_put_contents($broken, 'not a pdf');
        $r = $m->invoke($svc, $this->book(), 'af', $broken);
        $this->assertTrue($r['ran']);
        $this->assertFalse($r['pass']);
        $this->assertSame('lang_write_failed', $r['reason']);
    }

    public function test_disabled_by_config_is_noop(): void
    {
        config()->set('bookstore.accessibility.enabled', false);
        $out = $this->tmp . '/out.pdf';
        $this->goodPdf($out, 'anything');

        $r = $this->runPass($this->book(), $out, 'af');
        $this->assertFalse($r['ran']);
        $this->assertSame('disabled', $r['reason']);
    }

    public function test_missing_inputs_is_noop(): void
    {
        $r = $this->runPass($this->book(), $this->tmp . '/nope.pdf', 'af');
        $this->assertFalse($r['ran']);
        $this->assertSame('missing_inputs', $r['reason']);
    }
}
