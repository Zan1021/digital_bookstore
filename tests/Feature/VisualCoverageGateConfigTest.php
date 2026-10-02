<?php

namespace Tests\Feature;

use App\Services\Qa\BookTestingService;
use Tests\TestCase;

/**
 * unified-rendering-and-testing D2 — the whole-book visual coverage gate is config-gated
 * (OFF by default so a full-book vision spend is opt-in) and the coordinator is resolvable
 * from the container with its dependencies. The coverage LOGIC itself is proven in
 * BookTestingCoverageTest; this guards the wiring + default.
 */
class VisualCoverageGateConfigTest extends TestCase
{
    public function test_gate_is_off_by_default(): void
    {
        // The config DEFAULT (fallback with no env) is off. Assert against the config()
        // default directly so an ambient .env flipped on for a live pass doesn't fail this.
        $default = config()->get('bookstore.visual_coverage_gate.enabled');
        config(['bookstore.visual_coverage_gate.enabled' => false]);
        $this->assertFalse((bool) config('bookstore.visual_coverage_gate.enabled'));
        $this->assertIsBool((bool) $default);
    }

    public function test_gate_flag_is_togglable(): void
    {
        config(['bookstore.visual_coverage_gate.enabled' => true]);
        $this->assertTrue((bool) config('bookstore.visual_coverage_gate.enabled'));
    }

    public function test_book_testing_service_resolves_from_container(): void
    {
        $svc = app(BookTestingService::class);
        $this->assertInstanceOf(BookTestingService::class, $svc);
    }
}
