<?php

namespace Tests\Unit;

use App\Services\PdfTranslationService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * unified-rendering-and-testing — robust engine-report parse (the empty-qa_report bug the
 * LV live pass caught). The engine prints JSON on stderr but real runs wrap it in noise;
 * a naive json_decode returned null and the structured QA was silently lost.
 */
class EngineReportParseTest extends TestCase
{
    private function parse(string $stderr): array
    {
        $m = new ReflectionMethod(PdfTranslationService::class, 'parseEngineReport');
        $m->setAccessible(true);
        return $m->invoke(new PdfTranslationService(), $stderr);
    }

    public function test_clean_json_parses(): void
    {
        $r = $this->parse('{"publishable": true, "render_status": "READY_FOR_REVIEW"}');
        $this->assertTrue($r['publishable']);
    }

    public function test_json_with_leading_noise_parses(): void
    {
        $r = $this->parse("WARNING: font fallback\nprogress 50%\n{\"publishable\": false}");
        $this->assertFalse($r['publishable']);
    }

    public function test_json_with_trailing_noise_parses(): void
    {
        $r = $this->parse('{"render_status": "NEEDS_LAYOUT_REVIEW"}\nDone.\n');
        $this->assertSame('NEEDS_LAYOUT_REVIEW', $r['render_status']);
    }

    public function test_utf8_bom_is_stripped(): void
    {
        $r = $this->parse("\xEF\xBB\xBF{\"ok\": 1}");
        $this->assertSame(1, $r['ok']);
    }

    public function test_last_object_wins_when_multiple(): void
    {
        $r = $this->parse('{"stage":"first"} ... {"stage":"final","publishable":true}');
        $this->assertSame('final', $r['stage']);
    }

    public function test_nested_braces_handled(): void
    {
        $r = $this->parse('noise {"a": {"b": {"c": 1}}, "publishable": true} noise');
        $this->assertTrue($r['publishable']);
        $this->assertSame(1, $r['a']['b']['c']);
    }

    public function test_total_garbage_returns_empty_array_not_null(): void
    {
        $r = $this->parse('this is not json at all');
        $this->assertSame([], $r);
    }

    public function test_empty_string_returns_empty_array(): void
    {
        $this->assertSame([], $this->parse(''));
    }

    /**
     * REGRESSION (the real empty-qa_report cause, found 2026-10-07 via a live render):
     * the engine emitted a raw Latin-1 0xAE "®" byte inside a source_text field, making the
     * ENTIRE 167KB stderr invalid UTF-8. The prior parser's json_decode returned null for
     * both the whole-string attempt and the balanced-block scan, so qa_report persisted as [].
     * This feeds the EXACT captured real stderr and asserts the structured report survives.
     */
    public function test_real_engine_stderr_with_invalid_utf8_byte_parses(): void
    {
        $fixture = __DIR__ . '/../Fixtures/engine/real_stderr_my_house_af.txt';
        $this->assertFileExists($fixture, 'real engine stderr fixture missing');
        $raw = file_get_contents($fixture);

        // Precondition: the fixture really is invalid UTF-8 (otherwise it is not guarding the bug).
        $this->assertFalse(mb_check_encoding($raw, 'UTF-8'),
            'fixture should contain the invalid-UTF8 byte that caused the bug');

        $r = $this->parse($raw);

        $this->assertNotSame([], $r, 'parser must not return empty on real (invalid-UTF8) stderr');
        $this->assertArrayHasKey('page_types', $r);
        $this->assertArrayHasKey('render_status', $r);
        $this->assertArrayHasKey('publishable', $r);
        $this->assertArrayHasKey('review_pages', $r);
        $this->assertFalse($r['publishable']);
        $this->assertSame('NEEDS_LAYOUT_REVIEW', $r['render_status']);
    }

    public function test_bare_latin1_byte_in_json_string_is_recovered(): void
    {
        // Minimal reproduction of the real failure: a raw 0xAE inside a JSON string.
        $r = $this->parse("{\"source_text\": \"R\xAE\", \"publishable\": true}");
        $this->assertTrue($r['publishable'], 'a stray Latin-1 byte must not zero out the report');
    }
}
