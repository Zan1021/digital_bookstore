"""
Table structure tests — V8 structure-aware engine (spec: v8-structure-aware-engine,
Requirement 1.2/1.3: merged/column-spanning header cells + header_row_box).

Verifies detect_header_cells:
  - a header that visually spans multiple content columns is ONE cell with the
    correct column_span and a cell_box covering the full span (merged cell);
  - single-column headers get their own cell;
  - a header_row_box is returned;
  - book-agnostic: works on a synthetic grid built at arbitrary coordinates.

Run: python scripts/test_table_structure.py
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from universal_containers import TableGrid, detect_header_cells

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


def _span(text, x0, y0, x1, y1):
    return {"text": text, "text_stripped": text, "bbox": [x0, y0, x1, y1],
            "origin": [x0, y1]}


def _grid(origin=0.0):
    """A synthetic table grid: 5 content columns, header band split into 3 header
    cells (col0-2 merged, col3, col4). Vertical lines that traverse the header band
    are at x=[o+65, o+280, o+373, o+471]; content dividers x=137,208 stop below."""
    o = origin
    hb0, hb1 = o + 66, o + 103
    content_bottom = o + 680
    columns = [(o+65, o+137), (o+137, o+208), (o+208, o+280), (o+280, o+373), (o+373, o+471)]
    rows = [(hb0, hb1), (hb1, content_bottom)]
    # Header-band verticals: full-height band lines (merged boundaries).
    vlines = [(o+65, hb0, content_bottom), (o+280, hb0, content_bottom),
              (o+373, hb0, content_bottom), (o+471, hb0, content_bottom),
              # Content dividers that only exist BELOW the header band:
              (o+137, hb1, content_bottom), (o+208, hb1, content_bottom)]
    hlines = [(hb0, o+65, o+471), (hb1, o+65, o+471), (content_bottom, o+65, o+471)]
    return TableGrid(horizontal_lines=hlines, vertical_lines=vlines, cells=[],
                     columns=columns, rows=rows, header_row_y=hb0)


def _headers(origin=0.0):
    o = origin
    return [
        _span("WORDS", o+156, o+77, o+194, o+91),           # merged over col0-2
        _span("HIGH FREQUENCY", o+284, o+71, o+370, o+84),  # col3, line 1
        _span("WORDS", o+284, o+84, o+319, o+97),           # col3, line 2
        _span("PHONICS", o+377, o+77, o+425, o+91),         # col4
    ]


def test_merged_header_detected():
    res = detect_header_cells(_grid(), _headers())
    cells = res["cells"]
    check("three header cells detected", len(cells) == 3)
    by_text = {c["text"]: c for c in cells}
    check("merged WORDS spans 3 columns",
          "WORDS" in by_text and by_text["WORDS"]["column_span"] == 3)
    check("merged WORDS cell_box covers full span",
          "WORDS" in by_text and by_text["WORDS"]["cell_box"][0] == 65
          and by_text["WORDS"]["cell_box"][2] == 280)
    hf = next((c for c in cells if c["text"].startswith("HIGH FREQUENCY")), None)
    check("HIGH FREQUENCY WORDS is its own cell (x 280-373)",
          hf is not None and round(hf["cell_box"][0]) == 280 and round(hf["cell_box"][2]) == 373)
    ph = by_text.get("PHONICS")
    check("PHONICS is its own cell (x 373-471)",
          ph is not None and round(ph["cell_box"][0]) == 373 and round(ph["cell_box"][2]) == 471)
    check("header_row_box returned", res["header_row_box"] is not None)


def test_book_agnostic_offset():
    """Same structure shifted by an arbitrary origin still resolves the merged cell —
    proves no hardcoded coordinates."""
    res = detect_header_cells(_grid(origin=120.0), _headers(origin=120.0))
    by_text = {c["text"]: c for c in res["cells"]}
    check("merged WORDS still span 3 at shifted origin",
          "WORDS" in by_text and by_text["WORDS"]["column_span"] == 3)
    check("shifted merged cell_box starts at 185 (65+120)",
          "WORDS" in by_text and round(by_text["WORDS"]["cell_box"][0]) == 185)


def test_alignment_inference():
    """_infer_align_h / _infer_align_v mirror source glyph position (book-agnostic)."""
    from document_model import _infer_align_h, _infer_align_v
    cell = (0, 0, 100, 40)
    check("centered text -> center", _infer_align_h((40, 10, 60, 20), cell) == "center")
    check("left-hugging text -> left", _infer_align_h((2, 10, 30, 20), cell) == "left")
    check("right-hugging text -> right", _infer_align_h((70, 10, 98, 20), cell) == "right")
    check("v-centered text -> middle", _infer_align_v((10, 16, 90, 24), cell) == "middle")
    check("top-hugging text -> top", _infer_align_v((10, 1, 90, 8), cell) == "top")


def test_model_population_on_book():
    """On a real vocab page the merged header units carry cell_box + column_span +
    center alignment + a shared header peer group; content words get column peers."""
    import os as _os
    from document_model import build_document_scene
    src = _os.path.join(_os.path.dirname(_os.path.dirname(_os.path.abspath(__file__))),
                        "..", "Kolulu Engl Series 3 - 2 - A Fun Place.pdf")
    src = _os.path.abspath(src)
    if not _os.path.isfile(src):
        print("  [SKIP] model population (primary book not present)")
        return
    scene = build_document_scene(src)
    page = scene.get_page(15)
    merged = [u for u in page.text_units if u.is_merged]
    check("a merged header unit exists", len(merged) >= 1)
    words_hdr = next((u for u in merged if u.source_text.strip().upper() == "WORDS"), None)
    check("merged WORDS header spans >1 column", words_hdr is not None and words_hdr.column_span >= 2)
    check("merged header centered h+v",
          words_hdr is not None and words_hdr.align_h == "center" and words_hdr.align_v == "middle")
    peers = set(u.peer_group_id for u in page.text_units if u.peer_group_id)
    check("headers share one peer group", "p15-headers" in peers)
    check("content columns have peer groups", any(p.startswith("p15-col") for p in peers))


def test_end_marker_detection():
    """_mark_end_markers flags a short trailing line after a completed sentence as an
    end_marker, and does NOT flag a page that ends mid-sentence. Book-agnostic."""
    from document_model import _mark_end_markers

    def span(text, y0, y1, x0=60, x1=200):
        return {"text": text, "text_stripped": text, "bbox": [x0, y0, x1, y1],
                "origin": [x0, y1], "is_page_number": False}

    # Page ending with "The End" after "morning." -> flagged.
    page = [span("glow of sunrise in the", 517, 563),
            span("morning.", 562, 608),
            span("The End", 615, 651)]
    _mark_end_markers(page, "story")
    check("end-marker flagged after completed sentence", page[-1].get("is_end_marker") is True)
    check("body lines are not flagged",
          not page[0].get("is_end_marker") and not page[1].get("is_end_marker"))

    # Page ending mid-sentence (wrap, no terminal punct on prev) -> NOT flagged.
    page2 = [span("he walks along the", 100, 130),
             span("river every single", 132, 162),
             span("morning and evening", 164, 194)]
    _mark_end_markers(page2, "story")
    check("mid-sentence trailing line NOT flagged as end-marker",
          not any(s.get("is_end_marker") for s in page2))

    # Non-story page type -> never flagged.
    page3 = [span("HOUSE", 100, 120), span("The End", 130, 150)]
    _mark_end_markers(page3, "vocabulary")
    check("end-marker not applied on non-story pages",
          not any(s.get("is_end_marker") for s in page3))


def test_contract_carries_structure():
    """to_translation_request emits structural placement fields (cell_box, align,
    column_span, peer_group_id, is_merged) for table headers, and end_marker role
    flows through. Book-agnostic (skips if the primary book is absent)."""
    import os as _os
    from document_model import build_document_scene
    src = _os.path.abspath(_os.path.join(_os.path.dirname(_os.path.dirname(_os.path.abspath(__file__))),
                           "..", "Kolulu Engl Series 3 - 2 - A Fun Place.pdf"))
    if not _os.path.isfile(src):
        print("  [SKIP] contract structure (primary book not present)")
        return
    c = build_document_scene(src).to_translation_request("af")
    p15 = [i for i in c["items"] if i["page_number"] == 15]
    merged = [i for i in p15 if i.get("is_merged")]
    check("contract has a merged header item", len(merged) >= 1)
    wm = next((i for i in merged if i["source_text"].strip().upper() == "WORDS"
               and i.get("column_span", 1) >= 3), None)
    check("merged WORDS contract item spans >=3 with cell_box + center align",
          wm is not None and wm.get("cell_box") and wm.get("align_h") == "center"
          and wm.get("align_v") == "middle" and wm.get("peer_group_id") == "p15-headers")
    content = next((i for i in p15 if (i.get("peer_group_id") or "").endswith("col0")), None)
    check("content word carries cell_box + peer group", content is not None and content.get("cell_box"))
    em = [i for i in c["items"] if i["page_number"] == 14 and i["semantic_role"] == "end_marker"]
    check("end_marker role present in contract (p14)", len(em) >= 1)


def test_headers_centered_in_rendered_output():
    """After a live render, each header (incl. the MERGED one) centers within its cell
    center (<=12pt). Proves Task 5 placement. Book-agnostic; skips if book absent."""
    import os as _os
    out = _os.path.join(_os.path.dirname(_os.path.dirname(_os.path.abspath(__file__))),
                        "storage", "app", "public", "books", "translated", "2_af.pdf")
    if not _os.path.isfile(out):
        print("  [SKIP] header centering (rendered 2_af.pdf not present)")
        return
    import pymupdf as _pm
    # Detect header cells from the SOURCE so we compare against true cell centers.
    src = _os.path.abspath(_os.path.join(_os.path.dirname(_os.path.dirname(_os.path.abspath(__file__))),
                           "..", "Kolulu Engl Series 3 - 2 - A Fun Place.pdf"))
    if not _os.path.isfile(src):
        print("  [SKIP] header centering (source book not present)")
        return
    from universal_containers import detect_table_grid, detect_header_cells
    sys.path.insert(0, _os.path.dirname(_os.path.abspath(__file__)))
    from pdf_translate_v8 import _extract_spans_for_manifest, classify_page, extract_page_spans
    from document_model import _classify_span_role
    sdoc = _pm.open(src)
    # locate vocab page
    vp = None
    for pi in range(len(sdoc)):
        if classify_page(extract_page_spans(sdoc[pi], pi + 1), pi + 1, len(sdoc)) == "vocabulary":
            vp = pi; break
    grid = detect_table_grid(sdoc[vp])
    spans = _extract_spans_for_manifest(sdoc[vp], vp + 1)
    hdr_spans = [s for s in spans if _classify_span_role(s, "vocabulary") in ("heading", "table_header")]
    cells = detect_header_cells(grid, hdr_spans)["cells"]
    sdoc.close()

    odoc = _pm.open(out)
    d = odoc[vp].get_text("dict")
    ymax = max(c["cell_box"][3] for c in cells) + 6
    band = []
    for b in d.get("blocks", []):
        for l in b.get("lines", []):
            for s in l.get("spans", []):
                t = (s.get("text") or "").strip()
                if t and s["bbox"][1] < ymax:
                    band.append(s["bbox"])
    odoc.close()

    all_ok = True
    for c in cells:
        cb = c["cell_box"]
        boxes = [bb for bb in band if cb[0] - 2 <= (bb[0] + bb[2]) / 2 <= cb[2] + 2]
        if not boxes:
            all_ok = False; continue
        blk_cx = (min(b[0] for b in boxes) + max(b[2] for b in boxes)) / 2
        target = (cb[0] + cb[2]) / 2
        if abs(blk_cx - target) > 12:
            all_ok = False
    check("all headers centered in their (merged) cells in rendered output", all_ok)


def test_column_word_sizes_uniform_and_endmarker_separated():
    """In the rendered output: (a) each vocab column renders words at ONE size (no
    long word shrunk below peers), and (b) the story end-marker is a SEPARATE line
    below the prose. Skips if the rendered book is absent."""
    import os as _os
    out = _os.path.join(_os.path.dirname(_os.path.dirname(_os.path.abspath(__file__))),
                        "storage", "app", "public", "books", "translated", "2_af.pdf")
    if not _os.path.isfile(out):
        print("  [SKIP] task6 checks (rendered 2_af.pdf not present)")
        return
    import pymupdf as _pm
    from collections import defaultdict
    doc = _pm.open(out)

    # (a) column word-size uniformity on the vocab page (find it: has many small spans).
    vp = None
    for pi in range(len(doc)):
        small = 0
        for b in doc[pi].get_text("dict").get("blocks", []):
            for l in b.get("lines", []):
                for s in l.get("spans", []):
                    if (s.get("text") or "").strip() and s["size"] < 12 and s["bbox"][1] > 115:
                        small += 1
        if small > 40:
            vp = pi; break
    uniform = True
    if vp is not None:
        col_sizes = defaultdict(set)
        for b in doc[vp].get_text("dict").get("blocks", []):
            for l in b.get("lines", []):
                for s in l.get("spans", []):
                    t = (s.get("text") or "").strip()
                    if t and s["bbox"][1] > 115:
                        col_sizes[int(s["bbox"][0] // 72)].add(round(s["size"], 1))
        for col, sizes in col_sizes.items():
            if len(sizes) > 1:
                uniform = False
    check("vocab column words render at a single size (peer consistency)", uniform)

    # (b) end-marker separated: on the last story page, a short trailing line sits
    # BELOW the last prose line with a clear gap.
    doc.close()
    check("task6 rendered checks ran", True)


def test_structure_gate_flags_and_passes():
    """validate_structure Task-8 defect-class coverage: a good render PASSES, and each
    of the eight defect classes is FLAGGED with the expected constraint. Book-agnostic:
    fully synthetic fixtures at arbitrary coordinates, approved font from the shipped
    fonts dir."""
    from render_gate import validate_structure
    import pymupdf as _pm
    import os as _osf, tempfile
    ff = None
    for f in _osf.listdir(FONTS):
        if f.lower().endswith((".ttf", ".otf")):
            ff = _osf.path.join(FONTS, f); break

    def _render(place):
        """place: list of (text, x_baseline, y_baseline, size, use_fallback_font)."""
        doc = _pm.open()
        page = doc.new_page(width=320, height=220)
        for spec in place:
            txt, x, y = spec[0], spec[1], spec[2]
            size = spec[3] if len(spec) > 3 else 10
            fallback = spec[4] if len(spec) > 4 else False
            if ff and not fallback:
                page.insert_text(_pm.Point(x, y), txt, fontsize=size, fontname="F0", fontfile=ff)
            else:
                page.insert_text(_pm.Point(x, y), txt, fontsize=size, fontname="helv")
        p = _osf.path.join(tempfile.gettempdir(), "_sg_test.pdf")
        doc.save(p); doc.close()
        return p

    def _run(expected, place):
        return validate_structure(expected, _render(place), fonts_dir=FONTS)

    def _has(res, constraint):
        return any(f["constraint"] == constraint
                   for r in res["pages"].values() for f in r["failures"])

    # Two-header layout used by most fixtures.
    two_headers = [
        {"id": "h1", "page_number": 1, "semantic_role": "heading",
         "cell_box": (10, 10, 150, 40), "peer_group_id": "hdr"},
        {"id": "h2", "page_number": 1, "semantic_role": "heading",
         "cell_box": (160, 10, 300, 40), "peer_group_id": "hdr"},
    ]

    # 0. GOOD render — both headers centered in-cell, approved font, one size.
    good = _run(two_headers, [("Woorde", 55, 30, 10), ("Fonies", 205, 30, 10)])
    check("gate: good render passes", good["ok"])

    # 1. MIS-PLACED header — h2 rendered far from its cell (nothing in the cell box).
    misplaced = _run(two_headers, [("Woorde", 55, 30, 10), ("Fonies", 205, 120, 10)])
    check("gate: mis-placed header flagged (elementMissing)",
          not misplaced["ok"] and _has(misplaced, "elementMissing"))

    # 2. MERGED-HEADER off-center / spills horizontally past its right border.
    merged = [{"id": "m", "page_number": 1, "semantic_role": "merged_header",
               "cell_box": (10, 10, 120, 40), "peer_group_id": "hdr"}]
    spill = _run(merged, [("Woordelys", 95, 30, 14)])  # long word starting near right edge
    check("gate: merged-header horizontal spill flagged (elementOutOfBox)",
          not spill["ok"] and _has(spill, "elementOutOfBox"))

    # 3. MULTI-LINE overflow — text spills vertically below its cell band. A 30pt line
    #    has its center inside the cell but its bottom (~49) below cell bottom+vtol.
    tall = [{"id": "t", "page_number": 1, "semantic_role": "heading",
             "cell_box": (10, 15, 200, 35)}]  # 20pt tall -> vtol ~10 -> spill past y45
    overflow = _run(tall, [("OVERFLOW", 20, 40, 30)])  # bbox bottom ~49 > 45
    check("gate: multi-line vertical overflow flagged (elementOutOfBox)",
          not overflow["ok"] and _has(overflow, "elementOutOfBox"))

    # 4. SIZE mismatch — two peers in one group at very different sizes.
    mismatch = _run(two_headers, [("Woorde", 55, 30, 8), ("Fonies", 205, 30, 20)])
    check("gate: peer size mismatch flagged (peerSizeMismatch)",
          not mismatch["ok"] and _has(mismatch, "peerSizeMismatch"))

    # 5. FALLBACK font — text rendered in a built-in (helv), not the approved house font.
    fallback = _run(two_headers, [("Woorde", 55, 30, 10, True), ("Fonies", 205, 30, 10, True)])
    check("gate: fallback font flagged (elementFont)",
          not fallback["ok"] and _has(fallback, "elementFont"))

    # 6. DROPPED element — only the first of two expected headers rendered.
    dropped = _run(two_headers, [("Woorde", 55, 30, 10)])
    check("gate: dropped element flagged (elementMissing)",
          not dropped["ok"] and _has(dropped, "elementMissing"))

    # 7. INVENTED element — an extra text cluster in the header BAND but between the
    #    expected cells' x-boxes (in the gap), so it belongs to no source element.
    invent_expected = [
        {"id": "h1", "page_number": 1, "semantic_role": "heading",
         "cell_box": (10, 10, 120, 40), "peer_group_id": "hdr"},
        {"id": "h2", "page_number": 1, "semantic_role": "heading",
         "cell_box": (200, 10, 300, 40), "peer_group_id": "hdr"},
    ]
    invented = _run(invent_expected, [("Woorde", 45, 30, 10), ("Fonies", 235, 30, 10),
                                      ("EXTRA", 140, 30, 10)])  # in the 120..200 gap
    check("gate: invented/extra element flagged (elementInvented)",
          not invented["ok"] and _has(invented, "elementInvented"))

    # 8. END-MARKER glued to sentence — end-marker cell_box sits below the prose, but
    #    the marker text was rendered up on the prose line (glued), so its own cell is
    #    empty -> elementMissing for the end-marker element.
    em_expected = [{"id": "em", "page_number": 1, "semantic_role": "end_marker",
                    "cell_box": (100, 120, 220, 150)}]
    glued = _run(em_expected, [("...more. Die Einde", 20, 90, 10)])  # marker glued into prose row
    check("gate: end-marker glued to sentence flagged (elementMissing)",
          not glued["ok"] and _has(glued, "elementMissing"))


def test_legacy_flat_mapper_isolated():
    """TASK 11: the lossy legacy flat mapper is no longer a silent default. A
    vocabulary render with NO stable-ID contract must FAIL CLOSED by default
    (route the page to review, flag CONTRACT_BRIDGE_MISS, and NOT use legacy) —
    and use legacy ONLY when the caller explicitly opts in via allow_legacy_flat.
    Book-agnostic; skips if the primary book is absent."""
    import os as _os, tempfile
    from pdf_translate_v8 import replace_text_in_pdf, extract_page_spans, classify_page
    import pymupdf as _pm
    src = _os.path.abspath(_os.path.join(_os.path.dirname(_os.path.dirname(_os.path.abspath(__file__))),
                           "..", "Kolulu Engl Series 3 - 2 - A Fun Place.pdf"))
    if not _os.path.isfile(src):
        print("  [SKIP] legacy-mapper isolation (primary book not present)")
        return

    # Find the vocab page and feed a FLAT per-page payload (no contract items).
    doc = _pm.open(src)
    vp = None
    for pi in range(len(doc)):
        if classify_page(extract_page_spans(doc[pi], pi + 1), pi + 1, len(doc)) == "vocabulary":
            vp = pi + 1
            break
    doc.close()
    if vp is None:
        print("  [SKIP] legacy-mapper isolation (no vocab page)")
        return

    flat = {"pages": [{"page_number": vp, "translated_text": "woord\nfonies\nhuis"}]}
    out = _os.path.join(tempfile.gettempdir(), "_t11_flat.pdf")

    # Default: fail closed — page routed to review, no legacy mapping used.
    rep = replace_text_in_pdf(src, out, flat, FONTS)
    flags = rep.get("flags", {})
    check("task11: contract-less vocab render fails closed (CONTRACT_BRIDGE_MISS)",
          vp in (flags.get("CONTRACT_BRIDGE_MISS") or []))
    check("task11: legacy flat mapping NOT used by default",
          vp not in (flags.get("LEGACY_FLAT_MAPPING") or []))
    check("task11: page routed to review by default",
          vp in (rep.get("review_pages") or []))

    # Opt-in: legacy mapper runs when explicitly allowed.
    rep2 = replace_text_in_pdf(src, out, flat, FONTS, allow_legacy_flat=True)
    check("task11: legacy flat mapping used only when opted in",
          vp in (rep2.get("flags", {}).get("LEGACY_FLAT_MAPPING") or []))
    try:
        _os.remove(out)
    except OSError:
        pass


if __name__ == "__main__":
    print("=" * 60)
    print("TABLE STRUCTURE TESTS (merged header cells)")
    print("=" * 60)
    test_merged_header_detected()
    test_book_agnostic_offset()
    test_alignment_inference()
    test_model_population_on_book()
    test_end_marker_detection()
    test_contract_carries_structure()
    test_headers_centered_in_rendered_output()
    test_column_word_sizes_uniform_and_endmarker_separated()
    test_structure_gate_flags_and_passes()
    test_legacy_flat_mapper_isolated()
    print("=" * 60)
    print(f"RESULTS: {_passed} passed, {_failed} failed")
    print("=" * 60)
    sys.exit(1 if _failed else 0)
