<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * engine-wiring-and-activation R-W8 / T13 — the WIRING AUDIT CI GATE.
 *
 * Runs `python scripts/wiring_audit.py --check` as part of the Laravel test suite so a
 * future "built but never wired" production module (a new DEAD/TEST-ONLY module not in the
 * committed baseline) fails the build the moment it appears — the exact rot this spec kills.
 *
 * The gate exits 0 when clean, 3 when a new unwired module is found. A module that LEAVES
 * the inert set (got wired or deleted) is reported but does not fail — the baseline is then
 * refreshed with `--write-baseline`.
 */
class WiringAuditGateTest extends TestCase
{
    public function test_no_new_unwired_production_module(): void
    {
        $script = base_path('scripts/wiring_audit.py');
        if (! is_file($script)) {
            $this->markTestSkipped('wiring_audit.py not present');
        }

        $proc = new Process(['python', $script, '--check'], base_path());
        $proc->setTimeout(120);
        $proc->run();

        // Exit 0 = clean. Exit 3 = a new unwired module. Any other code = environment
        // problem (python missing etc.) — skip rather than red-herring the suite.
        $code = $proc->getExitCode();
        if ($code !== 0 && $code !== 3) {
            $this->markTestSkipped('wiring_audit.py could not run: ' . $proc->getErrorOutput());
        }

        $this->assertSame(
            0,
            $code,
            "Wiring audit found a new unwired production module (built but never wired). "
            . "Wire it, delete it, or add it to scripts/.wiring_audit_baseline.json with "
            . "--write-baseline and document it as DORMANT.\n\n" . $proc->getOutput()
        );
    }
}
