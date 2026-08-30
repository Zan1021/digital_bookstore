"""
Stable-ID translation contract tests (overflow-fix brief §2.2 / §7 / §8).

Verifies:
  - the engine emits a page->region->item contract with stable IDs;
  - the engine consumes ID-mapped translations DIRECTLY (no string reconstruction);
  - a per-item / single-page re-render touches only the scoped page(s);
  - round-trip validation of a translation response against the scene graph.

Book-agnostic (R1): uses a synthetic in-memory PDF, no specific book.
Run: python scripts/test_stable_id_contract.py
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import pymupdf

from document_model import build_document_scene
from pdf_translate_v8 import replace_text_in_pdf

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


def _make_two_page_pdf(path):
    """A minimal 2-page prose PDF so we can build a scene + contract."""
    doc = pymupdf.open()
    for pnum in range(2):
        page = doc.new_page(width=400, height=300)
        page.insert_text(pymupdf.Point(40, 60),
                         f"This is page {pnum + 1} with a flowing sentence of prose.",
                         fontsize=18)
        page.insert_text(pymupdf.Point(40, 100),
                         "It has more than enough words to classify as a story page here.",
                         fontsize=18)
    doc.save(path)
    doc.close()


def main():
    import tempfile
    tmp = tempfile.mkdtemp(prefix="v8contract_")
    src = os.path.join(tmp, "sample.pdf")
    out = os.path.join(tmp, "out.pdf")
    out2 = os.path.join(tmp, "out2.pdf")
    _make_two_page_pdf(src)

    # 1) Contract emission with stable IDs.
    scene = build_document_scene(src)
    contract = scene.to_translation_request("af")
    items = contract["items"]
    check("contract emits items with stable IDs",
          len(items) > 0 and all("id" in it and "source_text" in it for it in items))
    check("contract carries a schema version", bool(contract.get("schema_version")))
    check("each item has a page number", all(it.get("page_number") for it in items))

    # 2) ID-mapped consumption (engine maps by ID, no reconstruction).
    id_translations = [
        {"id": it["id"], "page_number": it["page_number"], "reading_order": i,
         "translation": "AF_" + (it["source_text"][:10] or "x")}
        for i, it in enumerate(items)
    ]
    rep = replace_text_in_pdf(src, out, {"items": id_translations}, fonts_dir=None)
    tc = rep.get("translation_contract", {})
    check("engine reports id_mapped contract", tc.get("id_mapped") is True)
    check("engine mapped every item by ID", tc.get("item_count") == len(id_translations))

    # 3) Per-item / single-page re-render scope.
    page2_ids = [it["id"] for it in items if it["page_number"] == 2]
    rep2 = replace_text_in_pdf(src, out2, {"items": id_translations},
                               fonts_dir=None, only_item_ids=page2_ids)
    scoped = rep2.get("translation_contract", {}).get("scoped_pages")
    check("per-item re-render scopes to owning page", scoped == [2])
    check("per-item re-render processes exactly one page", rep2["pages_processed"] == 1)

    # 4) Round-trip validation against the scene graph.
    mock_response = {"items": [{"id": it["id"], "translation": "x"} for it in items]}
    result = scene.validate_translation_response(mock_response)
    check("full response validates against scene graph", result["valid"])
    partial = {"items": mock_response["items"][:-1]} if len(items) > 1 else {"items": []}
    result2 = scene.validate_translation_response(partial)
    check("incomplete response is rejected", not result2["valid"])

    # cleanup
    import shutil
    shutil.rmtree(tmp, ignore_errors=True)


def test_continuation_merge():
    """_merge_continuation_spans must merge a wrapped multi-line phrase into ONE
    logical unit, while keeping single-word list items and self-contained
    "<pattern> - <examples>" rule lines separate. Book-agnostic."""
    from document_model import _merge_continuation_spans

    def span(sid, text, x0, y0, x1, y1):
        return {"id": sid, "text": text, "text_stripped": text,
                "origin": [x0, y1], "bbox": [x0, y0, x1, y1],
                "font_size": 10, "font_name": "F", "color": "#000000",
                "is_bold": False, "is_italic": False, "is_page_number": False}

    wrap = [
        span("p1_s1", "3 letter consonant blends at the", 100, 100, 200, 112),
        span("p1_s2", "beginning of words:", 100, 113, 190, 125),
    ]
    merged = _merge_continuation_spans(wrap, "vocabulary")
    check("wrapped phrase merges to one unit", len(merged) == 1)
    check("merged text is the full phrase",
          bool(merged) and "3 letter consonant blends at the beginning of words:" == merged[0]["text_stripped"])

    words = [
        span("p1_s3", "house", 100, 200, 140, 212),
        span("p1_s4", "bigger", 100, 213, 145, 225),
    ]
    check("single-word column is not merged", len(_merge_continuation_spans(words, "vocabulary")) == 2)

    rules = [
        span("p1_s5", "ai   -  plain, rain", 100, 300, 200, 312),
        span("p1_s6", "ay  -  say, play", 100, 313, 195, 325),
    ]
    check("pattern-rule lines stay separate", len(_merge_continuation_spans(rules, "vocabulary")) == 2)


if __name__ == "__main__":
    print("=" * 60)
    print("STABLE-ID CONTRACT TESTS (brief §2.2 / §7 / §8)")
    print("=" * 60)
    main()
    test_continuation_merge()
    print("=" * 60)
    print(f"RESULTS: {_passed} passed, {_failed} failed")
    print("=" * 60)
    sys.exit(1 if _failed else 0)
