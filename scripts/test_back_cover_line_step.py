"""
Back-cover line-step tests — pdf_translate_v8.line_step_for_metrics
===================================================================
Guards the tall-script descender-overlap fix (vault 2026-10-07 back-cover follow-up).
A substitute face like Playwrite ZA has taller ascenders/descenders than the source
rhythm allowed, so stacking translated lines on the source tops made glyphs overlap.
line_step_for_metrics() returns the minimum vertical step from the font's real metrics.

Pure numbers — no PyMuPDF, no page, no fonts. Runs anywhere.
Run: python scripts/test_back_cover_line_step.py
"""

import os
import sys

SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
if SCRIPTS_DIR not in sys.path:
    sys.path.insert(0, SCRIPTS_DIR)

from pdf_translate_v8 import line_step_for_metrics  # noqa: E402

_passed = _failed = 0


def check(label, cond):
    global _passed, _failed
    if cond:
        _passed += 1
        print(f"  [OK] {label}")
    else:
        _failed += 1
        print(f"  [FAIL] {label}")


def main():
    print("=" * 60)
    print("BACK-COVER LINE-STEP TESTS (tall-script overlap fix)")
    print("=" * 60)

    SIZE = 24.0

    # 1. A normal face is governed by the 1.3x floor (its metrics are modest).
    normal = line_step_for_metrics(SIZE, 0.8, 0.2)  # (0.8+0.2)*1.05 = 1.05 < 1.3
    check("normal face uses the 1.3x minimum floor", abs(normal - SIZE * 1.3) < 1e-6)

    # 2. A TALL script face (Playwrite-like) exceeds the floor → larger step.
    tall = line_step_for_metrics(SIZE, 1.1, 0.5)     # (1.1+0.5)*1.05 = 1.68 > 1.3
    check("tall script face exceeds the floor", tall > SIZE * 1.3)
    check("tall step == (asc+desc)*1.05*size", abs(tall - SIZE * (1.6 * 1.05)) < 1e-6)
    check("tall step is strictly larger than a normal face's step", tall > normal)

    # 3. Out-of-range / unreadable metrics fall back to safe defaults (0.8 / 0.3).
    bad_hi = line_step_for_metrics(SIZE, 9.9, 9.9)    # implausible → defaults
    safe = line_step_for_metrics(SIZE, 0.8, 0.3)
    check("implausible metrics fall back to defaults", abs(bad_hi - safe) < 1e-6)
    check("zero metrics fall back to defaults",
          abs(line_step_for_metrics(SIZE, 0, 0) - safe) < 1e-6)
    check("None metrics fall back to defaults",
          abs(line_step_for_metrics(SIZE, None, None) - safe) < 1e-6)

    # 4. Negative descender (PyMuPDF reports descender as negative) is handled by abs().
    check("negative descender handled via abs()",
          abs(line_step_for_metrics(SIZE, 1.1, -0.5) - tall) < 1e-6)

    # 5. Scales linearly with font size.
    check("step scales with size",
          abs(line_step_for_metrics(48.0, 1.1, 0.5) - 2 * tall) < 1e-6)

    print("=" * 60)
    print(f"RESULTS: {_passed} passed, {_failed} failed")
    print("=" * 60)
    return 1 if _failed else 0


if __name__ == "__main__":
    sys.exit(main())
