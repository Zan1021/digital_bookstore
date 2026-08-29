"""
Font policy + visual-size (cap-height) tests (brief §9.1/§9.2/§9.3).

Verifies:
  - cap-height-based visual sizing keeps APPARENT size within tolerance across
    two different fonts (§9.2);
  - approved-font resolution records provenance (fontRequested/resolvedFamily/
    fontFileHash/fallbackUsed) and marks approved/unapproved (§9.1/§9.3);
  - an explicit allowlist that excludes the shipped font marks resolution
    unapproved (so the caller fails closed);
  - shaping-based accurate width is available and non-zero.

Book-agnostic. Run: python scripts/test_font_policy.py
"""
import os
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import pymupdf

from pdf_translate_v8 import _visual_size_match, _cap_height_ratio
from font_policy import resolve_font_with_policy, document_font_policy_report

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


def _two_fonts():
    fonts = [os.path.join(FONTS, f) for f in os.listdir(FONTS)
             if f.lower().endswith((".ttf", ".otf"))]
    return fonts[:2] if len(fonts) >= 2 else fonts


def test_cap_height_measured():
    f = pymupdf.Font("helv")
    ratio = _cap_height_ratio(f)
    check("cap-height ratio is a sensible fraction of em", 0.4 < ratio < 1.2)


def test_visual_size_match_apparent_size():
    """Cap-height matching keeps the apparent cap height within ~5% across fonts."""
    fonts = _two_fonts()
    if len(fonts) < 2:
        check("visual size match (skipped: <2 fonts)", True)
        return
    src_file, tgt_file = fonts[0], fonts[1]
    src_size = 20.0
    matched = _visual_size_match(src_size, src_file, tgt_file)

    src = pymupdf.Font(fontfile=src_file)
    tgt = pymupdf.Font(fontfile=tgt_file)
    src_cap_pt = _cap_height_ratio(src) * src_size
    tgt_cap_pt = _cap_height_ratio(tgt) * matched
    rel = abs(src_cap_pt - tgt_cap_pt) / src_cap_pt
    check(f"apparent cap-height within 5% after match (rel={rel:.3f})", rel <= 0.05)


def test_policy_records_provenance():
    res = resolve_font_with_policy("SomeUnknownFont", FONTS, is_bold=False)
    ok = (res["fontRequested"] == "SomeUnknownFont"
          and res["resolvedFamily"]
          and res["fontFileHash"]
          and "fallbackUsed" in res
          and "approved" in res)
    check("policy records §9.1 provenance fields", ok)


def test_shipped_font_is_approved():
    """With no explicit allowlist, every shipped font is approved (no false fail)."""
    rep = document_font_policy_report(FONTS, requested_fonts=["Helvetica", "Arial"])
    check("shipped fonts approved -> no unapproved flag", not rep["any_unapproved"] and not rep["any_unresolved"])


def test_explicit_allowlist_can_fail_closed():
    """An allowlist that excludes every shipped family marks resolution unapproved."""
    tmp = tempfile.mkdtemp(prefix="fontpol_")
    # Copy one shipped font into tmp so there IS a font, but write an allowlist
    # naming a family that does not exist -> resolution is unapproved.
    shipped = _two_fonts()
    if not shipped:
        check("explicit allowlist fail-closed (skipped: no fonts)", True)
        return
    import shutil
    dst = os.path.join(tmp, os.path.basename(shipped[0]))
    shutil.copyfile(shipped[0], dst)
    with open(os.path.join(tmp, "approved_fonts.txt"), "w", encoding="utf-8") as fh:
        fh.write("SomeApprovedFamilyThatIsNotHere\n")
    rep = document_font_policy_report(tmp, requested_fonts=["Whatever"])
    check("allowlist excluding shipped font -> unapproved", rep["any_unapproved"])
    shutil.rmtree(tmp, ignore_errors=True)


def test_accurate_shaping_width():
    from text_shaping import accurate_text_width
    fonts = _two_fonts()
    if not fonts:
        check("shaping width (skipped: no fonts)", True)
        return
    w = accurate_text_width("Hello world", fonts[0], 18.0)
    check("shaping-based accurate width is positive", w and w > 0)


if __name__ == "__main__":
    print("=" * 60)
    print("FONT POLICY + VISUAL-SIZE TESTS (brief §9.1/§9.2/§9.3)")
    print("=" * 60)
    test_cap_height_measured()
    test_visual_size_match_apparent_size()
    test_policy_records_provenance()
    test_shipped_font_is_approved()
    test_explicit_allowlist_can_fail_closed()
    test_accurate_shaping_width()
    print("=" * 60)
    print(f"RESULTS: {_passed} passed, {_failed} failed")
    print("=" * 60)
    sys.exit(1 if _failed else 0)
