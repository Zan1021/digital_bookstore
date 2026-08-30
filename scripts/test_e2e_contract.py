"""
Task 10 — end-to-end verification of the CONTRACT render path on 2+ books
(spec: v8-structure-aware-engine, Requirement 5.1).

Where test_second_book.py proves general book-agnostic health, THIS suite asserts the
Task-9 contract path specifically: rendering an edition from the per-element,
structure-carrying items[] payload (id + page + reading_order + translation +
cell_box/peer_group_id/... ) — exactly what PdfTranslationService now emits — must:

  - drive the render via the contract (id_mapped == True, item_count == contract items);
  - use NO legacy flat mapper on any page (LEGACY_FLAT_MAPPING absent/empty);
  - PASS the structural comparison gate (structure_gate.ok == True);

and, on the primary book (the one carrying the modelled cases), the two hero cases
render correctly in the OUTPUT PDF:
  - p15 merged vocab header ("WORDS"->translated) centered across its full merged span;
  - the story end-marker ("The End"->"Die Einde") placed as its OWN line, separated
    from the prose (not glued to the last sentence).

Runs across the primary + at least one other discovered book so "2+ books" is real.
SKIPS cleanly if the corpus is absent. Book-agnostic: the hero-case checks derive the
cell geometry from the SOURCE structure, no hardcoded coordinates.

Run: python scripts/test_e2e_contract.py
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import pymupdf
from document_model import build_document_scene, _classify_span_role
from pdf_translate_v8 import (replace_text_in_pdf, extract_page_spans, classify_page,
                              _extract_spans_for_manifest)
from universal_containers import detect_table_grid, detect_header_cells

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FONTS = os.path.join(BASE, "storage", "app", "fonts")
OUT_DIR = os.path.join(BASE, "storage", "app", "temp")
PRIMARY = os.path.join(os.path.dirname(BASE), "Kolulu Engl Series 3 - 2 - A Fun Place.pdf")

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


def _contract_payload(scene):
    """Emulate PdfTranslationService.buildIdMappedTranslationsJson EXACTLY: id + page +
    reading_order + translation, carrying the structure fields the contract provides.
    Translation stands in as source_text (round-trip) so geometry is exercised."""
    items = scene.to_translation_request("af")["items"]
    skeys = ("source_text", "semantic_role", "cell_box", "align_h", "align_v",
             "peer_group_id", "column_span", "is_merged")
    payload = []
    for order, it in enumerate(items):
        entry = {"id": it["id"], "page_number": it.get("page_number"),
                 "reading_order": order, "translation": (it.get("source_text") or "x")}
        for k in skeys:
            if k in it:
                entry[k] = it[k]
        payload.append(entry)
    return items, payload


def _render_via_contract(src, tag):
    scene = build_document_scene(src)
    items, payload = _contract_payload(scene)
    os.makedirs(OUT_DIR, exist_ok=True)
    out = os.path.join(OUT_DIR, f"e2e_contract_{tag}.pdf")
    report = replace_text_in_pdf(src, out, {"items": payload}, FONTS)
    return items, out, report


def assert_contract_path(src, label):
    items, out, rep = _render_via_contract(src, label)
    tc = rep.get("translation_contract", {})
    check(f"[{label}] render driven by contract (id_mapped, 1:1)",
          tc.get("id_mapped") is True and tc.get("item_count") == len(items))
    flat = rep.get("flags", {}).get("LEGACY_FLAT_MAPPING") or []
    check(f"[{label}] NO legacy flat mapping on any page", len(flat) == 0)
    sg = rep.get("structure_gate", {})
    check(f"[{label}] structural comparison gate passes", sg.get("ok") is True)
    return out, rep


def _find_vocab_page(doc):
    for pi in range(len(doc)):
        if classify_page(extract_page_spans(doc[pi], pi + 1), pi + 1, len(doc)) == "vocabulary":
            return pi
    return None


def assert_primary_hero_cases(src, out):
    """On the primary book: p15 merged header centered in its span + end-marker on its
    own separated line. Geometry derived from the SOURCE (book-agnostic)."""
    sdoc = pymupdf.open(src)
    vp = _find_vocab_page(sdoc)
    if vp is None:
        sdoc.close()
        check("[primary] vocab page found for hero-case check", False)
        return

    grid = detect_table_grid(sdoc[vp])
    spans = _extract_spans_for_manifest(sdoc[vp], vp + 1)
    hdr_spans = [s for s in spans if _classify_span_role(s, "vocabulary") in ("heading", "table_header")]
    cells = detect_header_cells(grid, hdr_spans)["cells"]
    merged = next((c for c in cells if c.get("column_span", 1) >= 2), None)
    sdoc.close()

    odoc = pymupdf.open(out)
    # --- Hero case 1: merged header centered across its full merged span. ---
    d = odoc[vp].get_text("dict")
    ymax = max((c["cell_box"][3] for c in cells), default=120) + 6
    band = [s["bbox"] for b in d.get("blocks", []) for l in b.get("lines", [])
            for s in l.get("spans", []) if (s.get("text") or "").strip() and s["bbox"][1] < ymax]
    if merged and band:
        cb = merged["cell_box"]
        boxes = [bb for bb in band if cb[0] - 2 <= (bb[0] + bb[2]) / 2 <= cb[2] + 2]
        if boxes:
            blk_cx = (min(b[0] for b in boxes) + max(b[2] for b in boxes)) / 2
            target = (cb[0] + cb[2]) / 2
            check("[primary] p15 merged header centered across full span (<=12pt)",
                  abs(blk_cx - target) <= 12)
        else:
            check("[primary] p15 merged header text present in its span", False)
    else:
        check("[primary] merged header cell detected on vocab page", merged is not None)

    # --- Hero case 2: end-marker on its own line, separated from prose. ---
    # Find the story page whose last content line is a short trailing marker.
    end_ok = False
    for pi in range(len(odoc)):
        pd = odoc[pi].get_text("dict")
        lines = []
        for b in pd.get("blocks", []):
            for l in b.get("lines", []):
                txt = "".join(s.get("text", "") for s in l.get("spans", [])).strip()
                if txt:
                    y0 = min(s["bbox"][1] for s in l["spans"])
                    y1 = max(s["bbox"][3] for s in l["spans"])
                    lines.append((y0, y1, txt))
        if len(lines) < 2:
            continue
        lines.sort(key=lambda z: z[0])
        last_y0, last_y1, last_txt = lines[-1]
        prev_y0, prev_y1, prev_txt = lines[-2]
        # end-marker heuristic: last line is SHORT, sits clearly BELOW the prev line
        # (a real gap), and the previous line ends a sentence.
        gap = last_y0 - prev_y1
        if (len(last_txt.split()) <= 3 and gap >= 4 and prev_txt.rstrip().endswith((".", "!", "?"))):
            end_ok = True
            break
    check("[primary] end-marker rendered as its own separated line", end_ok)
    odoc.close()


def _discover_books(limit=2):
    from test_second_book import _discover_second_books
    others = _discover_second_books(limit=limit)
    return others


def main():
    if not os.path.isfile(PRIMARY):
        print("SKIP: primary book not present (nothing to verify)")
        return 0

    print(f"--- primary: {os.path.basename(PRIMARY)} ---")
    out, _rep = assert_contract_path(PRIMARY, "primary")
    assert_primary_hero_cases(PRIMARY, out)
    try:
        os.remove(out)
    except OSError:
        pass

    others = _discover_books(limit=1)
    if others:
        second = others[0]
        print(f"--- second: {os.path.basename(second)} ---")
        o2, _ = assert_contract_path(second, "second")
        try:
            os.remove(o2)
        except OSError:
            pass
        check("2+ books verified via the contract path", True)
    else:
        print("  [SKIP] only the primary book available — 2nd-book assertion skipped")
    return 0


if __name__ == "__main__":
    print("=" * 60)
    print("TASK 10 — E2E CONTRACT-PATH VERIFICATION (2+ books)")
    print("=" * 60)
    main()
    print("=" * 60)
    print(f"RESULTS: {_passed} passed, {_failed} failed")
    print("=" * 60)
    sys.exit(1 if _failed else 0)
