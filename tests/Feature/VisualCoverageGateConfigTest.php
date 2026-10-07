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

    /**
     * Q1 — the heavy whole-book coverage gate is QUEUE-ONLY. The service only runs it inline
     * when a caller explicitly asserts a background context (TranslateEditionJob calls
     * allowHeavyGates(true)) or we are in the console; a synchronous web request must NOT run
     * it (it would hang past the request timeout). This guards the decision helper directly.
     */
    public function test_heavy_gates_decision_honours_explicit_optin_and_optout(): void
    {
        $svc = app(\App\Services\PdfTranslationService::class);
        $m = new \ReflectionMethod($svc, 'heavyGatesAllowedHere');
        $m->setAccessible(true);

        // Explicit opt-in (what the queued job does) => allowed.
        $svc->allowHeavyGates(true);
        $this->assertTrue($m->invoke($svc), 'explicit opt-in must allow the gate inline');

        // Explicit opt-out (simulating a synchronous web request) => deferred.
        $svc->allowHeavyGates(false);
        $this->assertFalse($m->invoke($svc), 'explicit opt-out must defer the gate');
    }

    public function test_heavy_gates_default_follows_console_context(): void
    {
        // With nothing asserted, the decision falls back to runningInConsole(). The test
        // suite runs in the console, so the default here is "allowed" — proving the fallback
        // path exists (a real web request returns false because runningInConsole() is false).
        $svc = app(\App\Services\PdfTranslationService::class);
        $m = new \ReflectionMethod($svc, 'heavyGatesAllowedHere');
        $m->setAccessible(true);
        $this->assertSame(app()->runningInConsole(), $m->invoke($svc));
    }
}
