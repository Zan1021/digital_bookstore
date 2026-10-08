#!/usr/bin/env python3
"""
test_optical_calibration_wiring.py — Phase C7/T24 optical calibration WIRING.

Not re-testing optical_calibration's math (it has its own module); this proves it is
actually WIRED into font resolution: resolve_font_with_policy returns an opticalSizeFactor,
it is 1.0 when there is no distinct measurable source (never a guess), and the compute path
produces a sane (0.7..1.3-bounded) factor between two real different fonts when both files
are present.

Hermetic-ish: uses whatever approved fonts ship in storage/app/fonts; SKIPS cleanly if the
dir lacks ≥2 distinct fonts.

Run:  python scripts/test_optical_calibration_wiring.py
"""
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from font_policy import resolve_font_with_policy, load_approved_fonts  # noqa: E402

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FONTS = os.path.join(BASE, "storage", "app", "fonts")


class OpticalCalibrationWiring(unittest.TestCase):
    def setUp(self):
        if not os.path.isdir(FONTS):
            self.skipTest("no fonts dir")
        self.registry = load_approved_fonts(FONTS)
        if not self.registry.get("files"):
            self.skipTest("no approved fonts")

    def test_result_always_carries_optical_size_factor(self):
        """Every resolution reports opticalSizeFactor (default 1.0), so downstream can
        always apply it without a None check."""
        res = resolve_font_with_policy("PlaypenSans", FONTS, is_bold=False)
        self.assertIn("opticalSizeFactor", res)
        self.assertIsInstance(res["opticalSizeFactor"], float)
        self.assertGreater(res["opticalSizeFactor"], 0)

    def test_unmeasurable_source_is_identity(self):
        """A requested font with no distinct file on disk (pure substitution) cannot be
        measured → factor is exactly 1.0 (never a guessed scale)."""
        res = resolve_font_with_policy("TotallyUnknownFontXYZ", FONTS, is_bold=False)
        # It fell back to a substitute, but the source isn't measurable → identity.
        self.assertTrue(res.get("fallbackUsed"))
        self.assertEqual(res["opticalSizeFactor"], 1.0)

    def test_factor_is_bounded_when_measured(self):
        """If two DISTINCT real font files exist, the computed factor stays within the
        module's ±30% guard rails (sanity, not a magic number)."""
        fams = list(self.registry["families"].items())
        if len(fams) < 2:
            self.skipTest("need >=2 distinct families")
        # Request family A by name; the resolver will match A — to force a substitute with
        # a measurable source we call the internal factor directly between two real files.
        from font_policy import _optical_size_factor
        fam_a_name, path_a = fams[0]
        _, path_b = fams[1]
        factor = _optical_size_factor(fam_a_name, path_b, self.registry)
        self.assertGreaterEqual(factor, 0.7)
        self.assertLessEqual(factor, 1.3)


if __name__ == "__main__":
    unittest.main(verbosity=2)
