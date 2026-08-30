<?php

namespace Tests\Unit;

use App\Services\PdfTranslationService;
use Tests\TestCase;
use ReflectionMethod;

/**
 * Regression tests for the contract-path translation resolver.
 *
 * BUG (2026-08-30): resolveItemTranslations assigned the ENTIRE page's
 * translated_text to EVERY span on the page, so a page with N spans rendered the
 * same blob N times (overlapping / smeared — the WOORDE page, back-cover title
 * list, and triplicated paragraphs). The fix splits the page text into one segment
 * per span in reading order. These tests lock the "no duplicated blob across spans"
 * guarantee at the segmentation level (pure, DB-free).
 */
class ResolveItemTranslationsTest extends TestCase
{
    private function split(?string $text, int $spanCount): ?array
    {
        $m = new ReflectionMethod(PdfTranslationService::class, 'splitPageTextIntoSegments');
        $m->setAccessible(true);
        return $m->invoke(new PdfTranslationService(), $text, $spanCount);
    }

    /** One line per span => clean 1:1 mapping, each span its own text. */
    public function test_line_per_span_maps_one_to_one(): void
    {
        $text = "MTHOMBOTHI STUDIOS\n'n Plek Vol Pret";
        $segments = $this->split($text, 2);

        $this->assertSame(['MTHOMBOTHI STUDIOS', "'n Plek Vol Pret"], $segments);
        // The core regression guarantee: the two spans do NOT get identical text.
        $this->assertNotSame($segments[0], $segments[1]);
    }

    /**
     * Single-span page with a single line => clean 1:1 (that one line to that one
     * span). A multi-line blob on a single span returns null so the caller keeps the
     * whole-page text (the already-correct single-span case).
     */
    public function test_single_span_behaviour(): void
    {
        // one line, one span => exact 1:1
        $this->assertSame(
            ['Wanneer Kolulu nie by die skool is nie.'],
            $this->split('Wanneer Kolulu nie by die skool is nie.', 1)
        );
        // multi-line text but only one span => null (caller uses full page text)
        $this->assertNull($this->split("reël een\nreël twee", 1));
    }

    /**
     * Vocabulary/table page: many lines, few columns. Each span gets a DISTINCT,
     * non-empty group of lines — never the whole blob repeated (the smear bug).
     */
    public function test_more_lines_than_spans_distributes_without_duplication(): void
    {
        $lines = [];
        for ($i = 1; $i <= 12; $i++) {
            $lines[] = "woord{$i}";
        }
        $segments = $this->split(implode("\n", $lines), 4);

        $this->assertCount(4, $segments);
        foreach ($segments as $seg) {
            $this->assertNotSame('', trim($seg), 'no span may be empty');
        }
        // No two spans share identical content (the duplication that caused the smear).
        $this->assertSame(count($segments), count(array_unique($segments)));
        // Every source line is placed exactly once across the spans.
        $recombined = [];
        foreach ($segments as $seg) {
            foreach (explode("\n", $seg) as $l) {
                $recombined[] = $l;
            }
        }
        $this->assertSame($lines, $recombined);
    }

    /** Fewer lines than spans: extra spans get '' (caller falls back to source_text). */
    public function test_fewer_lines_than_spans_leaves_trailing_empty_not_blob(): void
    {
        $segments = $this->split("een\ntwee", 4);

        $this->assertSame(['een', 'twee', '', ''], $segments);
        // Crucially the populated spans are NOT the whole blob.
        $this->assertNotSame("een\ntwee", $segments[0]);
    }

    /** Null / empty input yields null (caller uses its own fallbacks). */
    public function test_empty_input_returns_null(): void
    {
        $this->assertNull($this->split(null, 3));
        $this->assertNull($this->split("   \n  ", 3));
    }
}
