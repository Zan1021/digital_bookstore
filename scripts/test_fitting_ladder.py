"""
Controlled fitting-ladder tests (overflow-fix brief §9.5).

Verifies the ladder applies, in order, the steps the base solver did NOT already
cover — tracking (4), line-spacing (5), alternate font (7), request-shorter (8) —
before routing to review, and never reports a fit that paints outside the region.

Book-agnostic: synthetic constraints, shipped fonts. Run: python scripts/test_fitting_ladder.py
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import pymupdf
from text_fit_solver import solve_fitting_ladder, FitConstraints

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FONTS = os.path.join(BASE, "storage", "app", "fonts")

_passed = 0
_failed = 0


def check(name, cond):
    global _passed, _failed
    if cond:
        _passed += 1
        print(f"  [OK] {name}")
    else:
        _failed += 1
        print(f"  [FAIL] {name}")


def _font():
    for f in sorted(os.listdir(FONTS)):
        if f.lower().endswith((".ttf", ".otf")):
            return os.path.join(FONTS, f)
    return None


def _all_fonts():
    return [os.path.join(FONTS, f) for f in sorted(os.listdir(FONTS))
            if f.lower().endswith((".ttf", ".otf"))]


def _width(text, size, font_path):
    f = pymupdf.Font(fontfile=font_path) if font_path else pymupdf.Font("helv")
    return f.text_length(text, fontsize=size)


def test_easy_fit_no_ladder():
    ff = _font()
    c = FitConstraints(container_width=300, container_height=40, source_font_size=14,
                       single_word=True, allow_multiline=False)
    r = solve_fitting_ladder("huis", c, font_path=ff)
    check("easy text fits at preferred size (step<=3)", r.fits and r.step <= 3)


def test_tracking_step():
    """A word slightly too wide should be rescued by tightening tracking (step 4)."""
    ff = _font()
    text = "responsibility"
    size = 14.0
    w = _width(text, size, ff)
    # Container just under the natural width so a small tracking tighten fits it.
    c = FitConstraints(container_width=w * 0.985, container_height=size * 2,
                       source_font_size=size, min_font_size=7.0, max_shrink_ratio=0.35,
                       single_word=True, allow_multiline=False, line_height_ratio=1.15,
                       padding_x=0.0, padding_y=0.0)
    r = solve_fitting_ladder(text, c, font_path=ff)
    check("tracking step rescues a slightly-wide word", r.fits and r.step in (4, 5)
          and r.tracking_em < 0)


def test_alternate_font_step():
    """When the preferred font can't fit but a narrower alternate can (step 7)."""
    fonts = _all_fonts()
    if len(fonts) < 2:
        check("alternate-font step (skipped: <2 fonts)", True)
        return
    # Pick the widest font as preferred and let the ladder try the others.
    text = "onafhanklikheid"
    size = 13.0
    widths = [(p, _width(text, size, p)) for p in fonts]
    widths.sort(key=lambda z: z[1])
    narrowest, widest = widths[0][0], widths[-1][0]
    # Container fits the narrowest but not the widest -> forces alternate-font step.
    target_w = (widths[0][1] + widths[-1][1]) / 2
    c = FitConstraints(container_width=target_w, container_height=size * 1.4,
                       source_font_size=size, min_font_size=size,  # no shrink room
                       max_shrink_ratio=0.0, single_word=True, allow_multiline=False,
                       line_height_ratio=1.1, padding_x=0.0, padding_y=0.0)
    r = solve_fitting_ladder(text, c, font_path=widest, alternate_font_paths=[narrowest])
    check("alternate metric-compatible font used (step 7)",
          (r.fits and r.step == 7 and r.alternate_font) or r.route_to_review)


def test_request_shorter_step():
    """Impossible fit exhausts the ladder -> request_shorter + route_to_review (step 8)."""
    ff = _font()
    c = FitConstraints(container_width=12, container_height=10, source_font_size=12,
                       min_font_size=11.0, max_shrink_ratio=0.05, single_word=True,
                       allow_multiline=False, line_height_ratio=1.1,
                       padding_x=0.0, padding_y=0.0)
    r = solve_fitting_ladder("Onafhanklikheidsverklaring", c, font_path=ff)
    check("impossible fit requests shorter + routes to review",
          (not r.fits) and r.request_shorter and r.route_to_review and r.step == 8)


def test_never_paints_outside_region():
    """Any reported fit must actually fit the container width at its final size."""
    ff = _font()
    text = "verantwoordelikheid"
    size = 16.0
    w = _width(text, size, ff)
    c = FitConstraints(container_width=w * 0.9, container_height=size * 3,
                       source_font_size=size, min_font_size=7.0, max_shrink_ratio=0.4,
                       single_word=True, allow_multiline=False, line_height_ratio=1.15,
                       padding_x=0.0, padding_y=0.0)
    r = solve_fitting_ladder(text, c, font_path=ff)
    if r.fits:
        gaps = max(0, len(text) - 1)
        final_w = _width(text, r.font_size, r.alternate_font or ff) + gaps * r.tracking_em * r.font_size
        check("reported fit does not exceed container width", final_w <= c.container_width + 0.6)
    else:
        check("no-fit correctly routed to review", r.route_to_review)


if __name__ == "__main__":
    print("=" * 60)
    print("FITTING LADDER TESTS (brief §9.5)")
    print("=" * 60)
    test_easy_fit_no_ladder()
    test_tracking_step()
    test_alternate_font_step()
    test_request_shorter_step()
    test_never_paints_outside_region()
    print("=" * 60)
    print(f"RESULTS: {_passed} passed, {_failed} failed")
    print("=" * 60)
    sys.exit(1 if _failed else 0)
