<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Services\PdfTranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * engine-wiring-and-activation C7-T25 + C7-T26.
 *
 * T25 — PDF/A archival-conformance SIGNAL (runConformanceSignal): informational, recorded,
 *       never blocking. The one quality_gates check not already covered by the live stack.
 * T26 — AUDIT-TRAIL render log (logAuditTrail): appends a timestamped provenance entry to a
 *       per-book audit log; best-effort, non-blocking; reuses the render fingerprint.
 *
 * Both methods are private; driven via reflection with hermetic PyMuPDF fixtures.
 */
class ConformanceAndAuditTest extends TestCase
{
    use RefreshDatabase;

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ca_test_' . bin2hex(random_bytes(5));
        @mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            foreach (glob($this->tmp . DIRECTORY_SEPARATOR . '*') as $f) {
                is_dir($f) ? $this->rrmdir($f) : @unlink($f);
            }
            @rmdir($this->tmp);
        }
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*') as $f) {
            is_dir($f) ? $this->rrmdir($f) : @unlink($f);
        }
        @rmdir($dir);
    }

    private function pdf(string $path): void
    {
        $p = new \Symfony\Component\Process\Process([
            'python', '-c',
            "import pymupdf,sys; d=pymupdf.open(); d.new_page().insert_text((72,72),'content'); d.set_metadata({'producer':'DBv8'}); d.save(sys.argv[1]); d.close()",
            $path,
        ]);
        $p->run();
        $this->assertTrue($p->isSuccessful(), 'fixture failed: ' . $p->getErrorOutput());
    }

    private function book(): Book
    {
        return Book::create([
            'title' => 'CA Book',
            'original_language' => 'en',
            'page_count' => 1,
            'status' => 'ready',
            'pdf_path' => 'books/pdfs/x.pdf',
        ]);
    }

    private function invoke(string $method, array $args)
    {
        $svc = new PdfTranslationService();
        $ref = new \ReflectionClass($svc);
        $m = $ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($svc, ...$args);
    }

    // ---- T25: conformance signal ----

    public function test_conformance_signal_runs_and_is_informational(): void
    {
        $out = $this->tmp . '/out.pdf';
        $this->pdf($out);
        $r = $this->invoke('runConformanceSignal', [$this->book(), $out]);
        $this->assertTrue($r['ran']);
        $this->assertSame('pdfa_conformance', $r['gate']);
        $this->assertArrayHasKey('passed', $r);     // recorded
        $this->assertSame('info', $r['severity']);   // never blocking
    }

    public function test_conformance_disabled_is_noop(): void
    {
        config()->set('bookstore.conformance.enabled', false);
        $out = $this->tmp . '/out.pdf';
        $this->pdf($out);
        $r = $this->invoke('runConformanceSignal', [$this->book(), $out]);
        $this->assertFalse($r['ran']);
        $this->assertSame('disabled', $r['reason']);
    }

    // ---- T26: audit trail ----

    public function test_audit_trail_appends_render_entry(): void
    {
        config()->set('bookstore.audit_trail.enabled', true);
        $book = $this->book();
        $report = ['pages_processed' => 3, 'spans_replaced' => 40];

        // Point the audit storage at our temp dir by using the real storage path the method
        // writes to, then assert a log file appears for this book.
        $this->invoke('logAuditTrail', [$book, 'af', $report, 'fp_test_hash']);

        $auditFile = storage_path('app/audit/audit_' . $book->id . '.json');
        $this->assertFileExists($auditFile);
        $data = json_decode(file_get_contents($auditFile), true);
        $this->assertNotEmpty($data['renders']);
        $this->assertSame('af', $data['renders'][count($data['renders']) - 1]['language']);

        @unlink($auditFile);
    }

    public function test_audit_trail_disabled_is_noop(): void
    {
        config()->set('bookstore.audit_trail.enabled', false);
        $book = $this->book();
        $this->invoke('logAuditTrail', [$book, 'af', ['pages_processed' => 1], 'fp']);
        $this->assertFileDoesNotExist(storage_path('app/audit/audit_' . $book->id . '.json'));
    }
}
