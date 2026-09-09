<?php

namespace Tests\Feature;

use App\Services\IllustrationTextService;
use App\Services\VisualQaService;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

/**
 * Tests for the illustration-text vision module orchestrator. No live OpenAI calls:
 * we exercise the deterministic guards (coordinate validation) and the config gating.
 * The GPT-4o detect path is covered by the Python + integration layers; here we prove
 * the safety-critical validation and that the module is inert when disabled.
 */
class IllustrationTextServiceTest extends TestCase
{
    private function service(): IllustrationTextService
    {
        // VisualQa is never reached in these tests; mock it so no client is constructed.
        $qa = Mockery::mock(VisualQaService::class);
        return new IllustrationTextService($qa);
    }

    private function invokeValidate(IllustrationTextService $svc, $bbox)
    {
        $ref = new ReflectionClass($svc);
        $m = $ref->getMethod('validateNormalized');
        $m->setAccessible(true);
        return $m->invoke($svc, $bbox);
    }

    public function test_normalized_bbox_validation_accepts_good_box(): void
    {
        $svc = $this->service();
        $this->assertSame([0.1, 0.2, 0.5, 0.6], $this->invokeValidate($svc, [0.1, 0.2, 0.5, 0.6]));
    }

    public function test_normalized_bbox_rejects_out_of_range(): void
    {
        $svc = $this->service();
        $this->assertNull($this->invokeValidate($svc, [-0.1, 0.2, 0.5, 0.6]));
        $this->assertNull($this->invokeValidate($svc, [0.1, 0.2, 1.5, 0.6]));
    }

    public function test_normalized_bbox_rejects_zero_or_inverted_area(): void
    {
        $svc = $this->service();
        $this->assertNull($this->invokeValidate($svc, [0.5, 0.5, 0.5, 0.5]));
        $this->assertNull($this->invokeValidate($svc, [0.6, 0.6, 0.2, 0.2]));
    }

    public function test_normalized_bbox_rejects_malformed(): void
    {
        $svc = $this->service();
        $this->assertNull($this->invokeValidate($svc, null));
        $this->assertNull($this->invokeValidate($svc, [0.1, 0.2]));
        $this->assertNull($this->invokeValidate($svc, 'nope'));
    }

    public function test_module_is_disabled_by_default(): void
    {
        // The default-off flag means the render pipeline never constructs/uses the module.
        $this->assertFalse((bool) config('bookstore.illustration_text.enabled'));
        $this->assertFalse((bool) config('bookstore.illustration_text.generative'));
        $this->assertTrue((bool) config('bookstore.illustration_text.verify'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
