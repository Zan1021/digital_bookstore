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
}
