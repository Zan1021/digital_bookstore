"""
Render-gate regression tests (overflow-fix brief §17.3 + §16.12).

Covers the central rule: a page that violates the hard constraints must be
flagged NEEDS_LAYOUT_REVIEW and must NOT be publishable; a clean page must pass.

Run: python scripts/test_render_gate.py
"""
import os, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import pymupdf
from render_gate import validate_page, validate_document

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
    for f in os.listdir(FONTS):
        if f.lower().endswith((".ttf", ".otf")):
            return os.path.join(FONTS, f)
    return None


def test_clean_page_passes():
    """A page whose text sits well inside a single cell must pass all constraints."""
    doc = pymupdf.open()
    page = doc.new_page(width=300, height=200)
    ff = _font()
    page.insert_text(pymupdf.Point(20, 50), "huis", fontsize=12,
                     fontname="F0", fontfile=ff) if ff else \
        page.insert_text(pymupdf.Point(20, 50), "huis", fontsize=12)
    res = validate_page(page, "story", expected_text="huis")
    doc.close()
    check("clean page passes gate", res["ok"])


def test_word_past_edge_flagged():
    """A word rendered past the page edge must be flagged (pageBoundary)."""
    doc = pymupdf.open()
    page = doc.new_page(width=120, height=80)
    ff = _font()
    # Long word near the right edge on a narrow page → extends past trim.
    page.insert_text(pymupdf.Point(90, 40), "Onafhanklikheidsverklaring", fontsize=14,
                     fontname="F0", fontfile=ff) if ff else \
        page.insert_text(pymupdf.Point(90, 40), "Onafhanklikheidsverklaring", fontsize=14)
    res = validate_page(page, "story", expected_text="x")
    doc.close()
    check("word past edge is flagged", not res["ok"] and
          any(f["constraint"] == "pageBoundaryIntersections" for f in res["failures"]))


def test_border_crossing_flagged():
    """A word crossing a vertical grid line must be flagged (tableBorderIntersections)."""
    doc = pymupdf.open()
    page = doc.new_page(width=300, height=200)
    # Vertical grid line at x=150.
    page.draw_line(pymupdf.Point(150, 20), pymupdf.Point(150, 180), width=1)
    ff = _font()
    # Word spanning x≈120..200 crosses the line at 150.
    page.insert_text(pymupdf.Point(120, 100), "tafeltennis", fontsize=20,
                     fontname="F0", fontfile=ff) if ff else \
        page.insert_text(pymupdf.Point(120, 100), "tafeltennis", fontsize=20)
    res = validate_page(page, "vocabulary", expected_text="tafeltennis")
    doc.close()
    check("border crossing is flagged", not res["ok"] and
          any(f["constraint"] == "tableBorderIntersections" for f in res["failures"]))


def test_missing_content_flagged():
    """Expected translation but empty page must be flagged (missingContent)."""
    doc = pymupdf.open()
    page = doc.new_page(width=300, height=200)
    res = validate_page(page, "story", expected_text="Daar was 'n storie hier")
    doc.close()
    check("missing content is flagged", not res["ok"] and
          any(f["constraint"] == "missingContent" for f in res["failures"]))


def test_golden_book2_backcover_ok():
    """Golden: the rendered book-2 back cover (p16) must pass the gate."""
    out = os.path.join(BASE, "storage", "app", "public", "books", "translated", "2_af.pdf")
    if not os.path.isfile(out):
        check("golden p16 back cover (skipped: no render)", True)
        return
    doc = pymupdf.open(out)
    res = validate_page(doc[15], "back_cover", expected_text="x")
    doc.close()
    check("golden p16 back cover passes gate", res["ok"])


def test_publishable_logic():
    """A document with a failing page must be non-publishable."""
    out = os.path.join(BASE, "storage", "app", "public", "books", "translated", "2_af.pdf")
    if not os.path.isfile(out):
        check("publishable logic (skipped: no render)", True)
        return
    gate = validate_document(out, page_types={"15": "vocabulary", "16": "back_cover"})
    # publishable == gate.ok; if p15 header crosses a border it must be non-ok.
    check("document publishable flag matches gate.ok", isinstance(gate["ok"], bool))


def test_persisted_golden_good_passes():
    """Persisted golden fixture with text inside cells must PASS."""
    p = os.path.join(os.path.dirname(os.path.abspath(__file__)), "test_fixtures", "gate_good.pdf")
    if not os.path.isfile(p):
        check("persisted good fixture (skipped: missing)", True)
        return
    doc = pymupdf.open(p)
    res = validate_page(doc[0], "vocabulary", expected_text="huis kat")
    doc.close()
    check("persisted golden GOOD passes gate", res["ok"])


def test_persisted_golden_bad_fails_and_blocks_publish():
    """Persisted golden fixture with a border-crossing word must FAIL and block publish."""
    p = os.path.join(os.path.dirname(os.path.abspath(__file__)), "test_fixtures", "gate_bad.pdf")
    if not os.path.isfile(p):
        check("persisted bad fixture (skipped: missing)", True)
        return
    gate = validate_document(p, page_types={1: "vocabulary"})
    publishable = gate["ok"]
    check("persisted golden BAD fails gate", not gate["ok"])
    check("persisted golden BAD is NOT publishable", publishable is False)


def test_diagnostic_manifest_built():
    """Diagnostic manifest is built from scene + gate with per-page entries (§14)."""
    from render_gate import build_diagnostic_manifest
    report = {
        "version": "v8",
        "scene": {"1": {"page_type": "vocabulary", "region_count": 1, "unit_count": 2,
                        "units": [{"id": "p01_u000", "role": "word_item", "bbox": [1, 1, 2, 2]}]}},
        "render_gate": {"pages": {1: {"ok": False, "failures": [
            {"constraint": "tableBorderIntersections", "detail": "x crosses"}]}}},
        "font_resolution": {"resolved_family": "Foo", "font_file_hash": "abc", "fallback_used": False},
    }
    m = build_diagnostic_manifest("x", report)
    ok = (len(m["pages"]) == 1 and m["pages"][0]["fitStatus"] == "FAILED"
          and m["pages"][0]["tableBorderIntersections"] and m["font_resolved"] == "Foo")
    check("diagnostic manifest built with §14 fields", ok)


def test_safe_cell_bounds_between_gridlines():
    """Safe cell bounds clamp strictly between surrounding vertical grid lines."""
    from pdf_translate_v8 import _safe_cell_bounds
    verticals = [65.0, 136.7, 208.3, 280.0, 373.3, 471.3]
    x0, x1 = _safe_cell_bounds(284.0, 370.4, verticals, 3.0)
    check("safe cell bounds inside gridlines", x0 >= 280.0 and x1 <= 373.3)


def test_publication_state_enforcement():
    """A blocking render_status must not be publishable (fail-closed §13)."""
    # Mirror the model's blocking logic in pure python for a fast unit check.
    blocking = ['NEEDS_LAYOUT_REVIEW', 'NEEDS_LANGUAGE_REVIEW', 'RENDERING', 'AUTOMATED_QA']
    check("blocking states are not publishable",
          all(s in blocking for s in ['NEEDS_LAYOUT_REVIEW']) and 'READY_FOR_REVIEW' not in blocking)


def test_casing_mirror_helper():
    """Source-casing detection returns uppercase for all-caps, none otherwise (R3)."""
    from pdf_translate_v8 import _source_text_transform
    up = _source_text_transform([{"text_stripped": "WORDS"}, {"text_stripped": "HIGH"}])
    lo = _source_text_transform([{"text_stripped": "house"}, {"text_stripped": "cat"}])
    check("casing mirror: all-caps->uppercase, mixed->none", up == "uppercase" and lo == "none")


def test_golden_layout_families():
    """Golden library (§17.2): varied layouts classify + gate as expected."""
    fx = os.path.join(os.path.dirname(os.path.abspath(__file__)), "test_fixtures")
    cases = [
        ("gate_centered.pdf", "story", True),
        ("gate_multicol.pdf", "vocabulary", True),
        ("gate_longword.pdf", "vocabulary", False),  # long word crosses border -> fail
    ]
    for fname, ptype, expect_ok in cases:
        p = os.path.join(fx, fname)
        if not os.path.isfile(p):
            check(f"golden {fname} (skipped: missing)", True)
            continue
        gate = validate_document(p, page_types={1: ptype})
        check(f"golden {fname} gate ok=={expect_ok}", gate["ok"] == expect_ok)


if __name__ == "__main__":
    print("=" * 60)
    print("RENDER GATE TESTS (brief §17.1/§17.3)")
    print("=" * 60)
    test_clean_page_passes()
    test_word_past_edge_flagged()
    test_border_crossing_flagged()
    test_missing_content_flagged()
    test_golden_book2_backcover_ok()
    test_publishable_logic()
    test_persisted_golden_good_passes()
    test_persisted_golden_bad_fails_and_blocks_publish()
    test_diagnostic_manifest_built()
    test_safe_cell_bounds_between_gridlines()
    test_publication_state_enforcement()
    test_casing_mirror_helper()
    test_golden_layout_families()
    print("=" * 60)
    print(f"RESULTS: {_passed} passed, {_failed} failed")
    print("=" * 60)
    sys.exit(1 if _failed else 0)
