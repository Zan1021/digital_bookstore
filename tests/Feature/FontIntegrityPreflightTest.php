<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Services\PdfTranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * engine-wiring-and-activation T7/T7b — the FONT-ASSET-INTEGRITY PREFLIGHT wired into
 * the render service. Proves the PHP side of R-W3/R-W3.1:
 *   - requestedFontFamilies() extracts the families a book's typography policy requests;
 *   - runFontIntegrityPreflight() flags a COUNTERFEIT required font (file whose embedded
 *     internal name does not match the requested family), fails the edition closed, and
 *     surfaces an actionable upload prompt;
 *   - an all-honest set is publishable;
 *   - a book with no policy is a no-op (never a false block);
 *   - the preflight can be disabled by config.
 *
 * The preflight method + fontsDir are private; we drive them via reflection against a
 * TEMP fonts dir seeded with real PyMuPDF built-in fonts written to disk (hermetic — no
 * external font library, no network). The counterfeit is a genuine font byte-stream
 * written under a LYING filename, reproducing the real "AdLibBT file is actually
 * Bangers" bug.
 */
class FontIntegrityPreflightTest extends TestCase
{
    use RefreshDatabase;

    private string $tmpFonts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpFonts = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'fi_test_' . bin2hex(random_bytes(5));
        @mkdir($this->tmpFonts, 0755, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpFonts)) {
            foreach (glob($this->tmpFonts . DIRECTORY_SEPARATOR . '*') as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpFonts);
        }
        parent::tearDown();
    }

    /** Write a PyMuPDF built-in font's bytes to a real file at $this->tmpFonts/$filename. */
    private function writeFont(string $builtin, string $filename): string
    {
        $dest = $this->tmpFonts . DIRECTORY_SEPARATOR . $filename;
        $py = "import pymupdf,sys; open(sys.argv[1],'wb').write(pymupdf.Font(sys.argv[2]).buffer)";
        $p = new \Symfony\Component\Process\Process(['python', '-c', $py, $dest, $builtin]);
        $p->run();
        $this->assertTrue($p->isSuccessful() && is_file($dest),
            "failed to materialise built-in font '{$builtin}': " . $p->getErrorOutput());
        return $dest;
    }

    /** Read a font file's embedded internal name via the module under test. */
    private function internalName(string $path): string
    {
        $py = "import pymupdf,sys; print((pymupdf.Font(fontfile=sys.argv[1]).name or '').strip())";
        $p = new \Symfony\Component\Process\Process(['python', '-c', $py, $path]);
        $p->run();
        return trim($p->getOutput());
    }

    private function book(): Book
    {
        return Book::create([
            'title' => 'Font Integrity Book',
            'original_language' => 'en',
            'page_count' => 1,
            'status' => 'ready',
            'pdf_path' => 'books/pdfs/x.pdf',
        ]);
    }

    /** Set a raw typography policy on a book (bypassing setRoleFont's approved-only guard). */
    private function setRawPolicy(Book $book, array $policy): void
    {
        $meta = $book->metadata ?? [];
        $meta['typography_policy'] = $policy;
        $book->forceFill(['metadata' => $meta])->save();
    }

    /** Invoke the private preflight against our temp fonts dir via reflection. */
    private function runPreflight(Book $book, string $language = 'af'): array
    {
        $svc = new PdfTranslationService();

        $ref = new \ReflectionClass($svc);
        $fontsDir = $ref->getProperty('fontsDir');
        $fontsDir->setAccessible(true);
        $fontsDir->setValue($svc, $this->tmpFonts);

        $m = $ref->getMethod('runFontIntegrityPreflight');
        $m->setAccessible(true);
        return $m->invoke($svc, $book, $language);
    }

    public function test_requested_families_extracted_from_policy(): void
    {
        $book = $this->book();
        $this->setRawPolicy($book, [
            'version' => 1,
            'roles' => [
                'body' => ['font_asset_id' => 'PlaypenSans'],
                'title' => ['font_asset_id' => 'StoryDisplay'],
            ],
            'language_overrides' => [
                'af' => ['body' => ['font_asset_id' => 'PatrickHand']],
            ],
        ]);

        $families = $book->fresh()->requestedFontFamilies();
        sort($families);
        $this->assertSame(['PatrickHand', 'PlaypenSans', 'StoryDisplay'], $families);
    }

    public function test_no_policy_is_a_noop(): void
    {
        $result = $this->runPreflight($this->book());
        $this->assertFalse($result['ran']);
        $this->assertSame('no_requested_families', $result['reason']);
    }

    public function test_counterfeit_required_font_fails_closed_with_upload_prompt(): void
    {
        // Lie: genuine font bytes under a filename claiming to be 'StoryDisplay'.
        $counterfeit = $this->writeFont('helv', 'StoryDisplay-Regular.ttf');
        $realName = $this->internalName($counterfeit);           // e.g. "Nimbus Sans Regular"
        $this->assertNotSame('', $realName);
        $this->assertStringNotContainsStringIgnoringCase('storydisplay', $realName);

        $book = $this->book();
        $this->setRawPolicy($book, [
            'version' => 1,
            'roles' => ['title' => ['font_asset_id' => 'StoryDisplay']],
        ]);

        $result = $this->runPreflight($book->fresh());

        $this->assertTrue($result['ran']);
        $this->assertFalse($result['publishable']);
        $this->assertTrue($result['needsReview']);
        $this->assertContains('StoryDisplay', $result['offending']);
        $this->assertNotEmpty($result['uploadPrompts']);
        $this->assertStringContainsString('StoryDisplay', $result['uploadPrompts'][0]);
    }

    public function test_honest_font_set_is_publishable(): void
    {
        // Write a genuine font, then request it under its OWN real family name.
        $path = $this->writeFont('helv', 'Nimbus-Regular.ttf');
        $realFamily = explode(' ', $this->internalName($path))[0]; // "Nimbus"

        $book = $this->book();
        $this->setRawPolicy($book, [
            'version' => 1,
            'roles' => ['body' => ['font_asset_id' => $realFamily]],
        ]);

        $result = $this->runPreflight($book->fresh());

        $this->assertTrue($result['ran']);
        $this->assertTrue($result['publishable']);
        $this->assertSame([], $result['offending']);
    }

    public function test_disabled_by_config_is_a_noop(): void
    {
        config()->set('bookstore.font_integrity.enabled', false);

        $book = $this->book();
        $this->setRawPolicy($book, [
            'version' => 1,
            'roles' => ['title' => ['font_asset_id' => 'StoryDisplay']],
        ]);

        $result = $this->runPreflight($book->fresh());
        $this->assertFalse($result['ran']);
        $this->assertSame('disabled', $result['reason']);
    }
}
