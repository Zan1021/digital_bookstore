"""
Page-centred title placement tests — pdf_translate_v8.page_centered_title_box
=============================================================================
Guards the "63px-left subtitle" fix (vault 2026-10-07). A short centred title/subtitle
has a narrow ink box, so the alignment inference mis-tags it "left" and the translated
line renders off-centre. The fix detects page-centring from SOURCE GEOMETRY and returns
a PAGE-SYMMETRIC box so "center" alignment lands on the true page midline.

Reproduces the real measured case: My House p2 subtitle on a 538-wide page, source
"My House" centred at x≈269 (page centre 269.0); the translated "My Huis" previously
landed at centre x≈205.8 (63px left). This asserts the fix produces a box whose centre
IS the page midline, so the regression can't silently return.

Pure numbers — no PyMuPDF, no page, no fonts, no DB. Runs anywhere.
Run: python scripts/test_page_centered_title.py
"""

import os
import sys

SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
if SCRIPTS_DIR not in sys.path:
    sys.path.insert(0, SCRIPTS_DIR)

from pdf_translate_v8 import page_centered_title_box  # noqa: E402

_passed = _failed = 0


def check(label, cond):
    global _passed, _failed
    if cond:
        _passed += 1
        print(f"  [OK] {label}")
    else:
        _failed += 1
        print(f"  [FAIL] {label}")


def _box_center_x(box):
    return (box[0] + box[2]) / 2.0


def main():
    print("=" * 60)
    print("PAGE-CENTRED TITLE PLACEMENT TESTS (63px-left subtitle fix)")
    print("=" * 60)

    PAGE_W = 538.0            # My House page width (measured)
    PAGE_MID = PAGE_W / 2.0   # 269.0

    # --- 1. The real bug: a narrow, page-centred subtitle -----------------------
    # Source "My House" ink box centred on the page but only ~90px wide. Its own box
    # centre is the page midline, but the NARROW box is what caused the off-centre
    # placement before the fix. The returned box must be symmetric about the midline.
    sub_bbox = (224.0, 60.0, 314.0, 92.0)  # cx = 269.0 == page mid
    is_c, box = page_centered_title_box(sub_bbox, PAGE_W, 58.0, 96.0, "subtitle")
    check("subtitle on midline: detected as page-centred", is_c is True)
    check("subtitle on midline: returns a box", box is not None)
    check("subtitle box centre == page midline (not 63px left)",
          box is not None and abs(_box_center_x(box) - PAGE_MID) < 0.001)
    check("subtitle box is symmetric about the midline",
          box is not None and abs((PAGE_MID - box[0]) - (box[2] - PAGE_MID)) < 0.001)
    check("subtitle box preserves the given vertical extent",
          box is not None and box[1] == 58.0 and box[3] == 96.0)

    # --- 2. An OFF-centre title is left alone (no false centring) ---------------
    # A title whose source sits in the left third is genuinely left-aligned; the fix
    # must NOT hijack it to the midline.
    left_bbox = (20.0, 60.0, 150.0, 92.0)  # cx = 85.0, far from 269.0
    is_c2, box2 = page_centered_title_box(left_bbox, PAGE_W, 58.0, 96.0, "book_title")
    check("off-centre title: NOT treated as page-centred", is_c2 is False)
    check("off-centre title: no box returned (caller keeps its own)", box2 is None)

    # --- 3. Tolerance boundary: just inside vs just outside 8% ------------------
    tol = PAGE_W * 0.08  # 43.04
    inside_cx = PAGE_MID + tol - 1.0
    outside_cx = PAGE_MID + tol + 1.0
    inside = (inside_cx - 10, 60.0, inside_cx + 10, 92.0)
    outside = (outside_cx - 10, 60.0, outside_cx + 10, 92.0)
    check("within 8% of midline: centred",
          page_centered_title_box(inside, PAGE_W, 58.0, 96.0, "heading")[0] is True)
    check("beyond 8% of midline: not centred",
          page_centered_title_box(outside, PAGE_W, 58.0, 96.0, "heading")[0] is False)

    # --- 4. Non-title roles are never page-centred ------------------------------
    # A paragraph that happens to sit on the midline must NOT be forced to a symmetric
    # title box — only title-ish roles get this treatment.
    para = (224.0, 60.0, 314.0, 92.0)  # same geometry as the subtitle, different role
    check("paragraph role on midline: NOT page-centred",
          page_centered_title_box(para, PAGE_W, 58.0, 96.0, "paragraph")[0] is False)
    for role in ("book_title", "subtitle", "heading", "label"):
        check(f"title-ish role '{role}' on midline: page-centred",
              page_centered_title_box(para, PAGE_W, 58.0, 96.0, role)[0] is True)

    # --- 5. A WIDE centred title clamps to the page (never past the edges) ------
    wide = (10.0, 60.0, 528.0, 92.0)  # cx = 269.0, nearly full width
    is_c5, box5 = page_centered_title_box(wide, PAGE_W, 58.0, 96.0, "book_title")
    check("wide centred title: still centred", is_c5 is True)
    check("wide centred title: box stays within the page width",
          box5 is not None and box5[0] >= 0.0 and box5[2] <= PAGE_W)
    check("wide centred title: still symmetric about midline",
          box5 is not None and abs(_box_center_x(box5) - PAGE_MID) < 0.001)

    print("=" * 60)
    print(f"RESULTS: {_passed} passed, {_failed} failed")
    print("=" * 60)
    return 1 if _failed else 0


if __name__ == "__main__":
    sys.exit(main())
