"""
2nd-book end-to-end verification (overflow-fix brief §5 DoD / R1 — the DoD blocker).

Proves the engine is genuinely BOOK-AGNOSTIC by running the full pipeline on a
SECOND, structurally different book (not the primary sample):

  build region-graph contract -> ID-mapped round-trip translation -> render -> gate

and asserting:
  - per-page classification is produced for every page and is structurally sane
    (page 1 = cover, last page = back_cover, interior mostly story/vocabulary);
  - the stable-ID contract maps 1:1 (id_mapped, item_count == contract items);
  - the render completes and the gate returns a boolean verdict per page;
  - a per-region diagnostic manifest is produced;
  - NO book-specific coupling: identical code path across books.

Book-agnostic: auto-discovers additional book PDFs; SKIPS cleanly if none are
present (e.g. on CI without the sample corpus), so the suite never hard-fails for
lack of data — but RUNS and asserts whenever a corpus is available.

Run: python scripts/test_second_book.py
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import pymupdf
from document_model import build_document_scene, summarize_document_scene
from pdf_translate_v8 import replace_text_in_pdf, extract_page_spans, classify_page

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FONTS = os.path.join(BASE, "storage", "app", "fonts")
OUT_DIR = os.path.join(BASE, "storage", "app", "temp")

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


PRIMARY = os.path.join(os.path.dirname(BASE), "Kolulu Engl Series 3 - 2 - A Fun Place.pdf")


def _discover_second_books(limit=2):
    """Find structurally different books OTHER than the primary sample."""
    roots = [
        os.path.join(os.path.dirname(BASE), "Book_Pdfs"),
        os.path.dirname(BASE),
    ]
    found = []
    seen = set()
    for root in roots:
        if not os.path.isdir(root):
            continue
        for dirpath, _dirs, files in os.walk(root):
            if any(skip in dirpath for skip in ("translated", "node_modules", "vendor",
                                                "test_fixtures", "overlays", "temp")):
                continue
            for f in sorted(files):
                if not f.lower().endswith(".pdf"):
                    continue
                full = os.path.join(dirpath, f)
                if os.path.abspath(full) == os.path.abspath(PRIMARY):
                    continue
                if f in seen:
                    continue
                seen.add(f)
                found.append(full)
                if len(found) >= limit:
                    return found
    return found


def _classify_all(src):
    doc = pymupdf.open(src)
    total = len(doc)
    types = {}
    for pi in range(total):
        types[pi + 1] = classify_page(extract_page_spans(doc[pi], pi + 1), pi + 1, total)
    doc.close()
    return total, types


def run_book(src):
    label = os.path.basename(src)
    total, types = _classify_all(src)

    # Structural sanity of classification (book-agnostic expectations).
    check(f"[{label}] page 1 classified as cover", types.get(1) == "cover")
    check(f"[{label}] last page classified as back_cover", types.get(total) == "back_cover")
    interior = [t for pn, t in types.items() if 1 < pn < total]
    story_like = sum(1 for t in interior if t in ("story", "vocabulary"))
    check(f"[{label}] interior mostly story/vocabulary",
          interior and story_like >= max(1, int(0.6 * len(interior))))

    # Full pipeline: contract -> ID-mapped round-trip -> render -> gate.
    scene = build_document_scene(src)
    contract = scene.to_translation_request("af")
    items = contract["items"]
    id_translations = [
        {"id": it["id"], "page_number": it["page_number"], "reading_order": i,
         "translation": (it["source_text"] or "x")}
        for i, it in enumerate(items)
    ]
    os.makedirs(OUT_DIR, exist_ok=True)
    out = os.path.join(OUT_DIR, "second_book_render.pdf")
    rep = replace_text_in_pdf(src, out, {"items": id_translations}, FONTS)

    tc = rep.get("translation_contract", {})
    check(f"[{label}] contract maps 1:1 by ID",
          tc.get("id_mapped") is True and tc.get("item_count") == len(items))
    check(f"[{label}] every page processed", rep["pages_processed"] == total)
    gate_pages = rep.get("render_gate", {}).get("pages", {})
    check(f"[{label}] gate returns a boolean verdict per page",
          len(gate_pages) == total and all(isinstance(v.get("ok"), bool) for v in gate_pages.values()))
    check(f"[{label}] per-region diagnostic manifest produced",
          bool(rep.get("diagnostic_manifest", {}).get("pages")))
    try:
        os.remove(out)
    except OSError:
        pass


def main():
    books = _discover_second_books(limit=2)
    if not books:
        print("SKIP: no second-book corpus available (nothing to verify)")
        return 0
    for src in books:
        print(f"--- {os.path.basename(src)} ---")
        run_book(src)
    return 0


if __name__ == "__main__":
    print("=" * 60)
    print("2ND-BOOK END-TO-END VERIFICATION (brief §5 DoD / R1)")
    print("=" * 60)
    main()
    print("=" * 60)
    print(f"RESULTS: {_passed} passed, {_failed} failed")
    print("=" * 60)
    sys.exit(1 if _failed else 0)
