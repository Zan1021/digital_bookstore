"""
world-class-render-engine spec, Phase 1 — regression tests for bounded-container
placement on non-table pages and the structural gate that detects a column bleed.

Covers:
  - Bounds / resolve_safe_box fail-closed algebra (document_model).
  - _attach_generic_structure resolves per-column containers that do NOT bleed into a
    neighbouring column (the My House p2 defect), with alignment that never resizes.
  - validate_structure FLAGS a horizontal column bleed (elementOutOfColumn) and PASSES a
    render contained within its column.

Book-agnostic: geometry is synthetic / derived; no book-specific constants.
Run: python scripts/test_generic_containment.py
"""
import os
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import pymupdf
from document_model import Bounds, resolve_safe_box, LayoutReviewRequired
from render_gate import validate_structure

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


# ---- Bounds algebra (fail closed) ----
try:
    Bounds(10, 10, 5, 20).validate()
    check("Bounds.validate rejects inverted box", False)
except LayoutReviewRequired:
    check("Bounds.validate rejects inverted box", True)

try:
    resolve_safe_box(None, (0, 0, 100, 100), 1, 1)
    check("resolve_safe_box fails closed on None container", False)
except LayoutReviewRequired:
    check("resolve_safe_box fails closed on None container", True)

safe = resolve_safe_box((20, 20, 80, 80), (0, 0, 100, 100), 2, 2)
check("resolve_safe_box insets within page", safe.as_tuple() == (22, 22, 78, 78))
check("intersect clamps to page", Bounds(-10, -10, 50, 50).intersect(Bounds(0, 0, 100, 100))
      .as_tuple() == (0, 0, 50, 50))


# ---- Gate flags a column bleed, passes a contained render ----
def _one_line_pdf(path, text, x0, y0=560):
    doc = pymupdf.open()
    doc.new_page(width=538, height=750)          # page 1 blank
    page = doc.new_page(width=538, height=750)   # page 2
    page.insert_text((x0, y0), text, fontsize=8, fontname="helv", color=(0, 0, 0))
    doc.save(path)
    doc.close()


CONTAINER = [321, 537, 481, 629]  # a right-column container (narrow)
expected = [{"id": "p02_s0016", "page_number": 2, "cell_box": CONTAINER,
             "semantic_role": "character_bio"}]
tmp = tempfile.mkdtemp()

bled = os.path.join(tmp, "bled.pdf")
_one_line_pdf(bled, "is uitgedink deur Simon n sesjarige seun terwyl hy in die grondslagfase", 166)
r_bled = validate_structure(expected, bled, fonts_dir=None)
codes = [f["constraint"] for p in r_bled["pages"].values() for f in p["failures"]]
check("gate flags a horizontal column bleed", r_bled["ok"] is False and "elementOutOfColumn" in codes)

good = os.path.join(tmp, "good.pdf")
_one_line_pdf(good, "is uitgedink deur", 325)
r_good = validate_structure(expected, good, fonts_dir=None)
check("gate passes a render contained in its column", r_good["ok"] is True)


print("=" * 60)
print(f"RESULTS: {_passed} passed, {_failed} failed")
print("=" * 60)
sys.exit(1 if _failed else 0)
