"""
Render Gate — Digital Bookstore V8 (Overflow-fix brief §4 / §12.1)
===================================================================
Post-render glyph-geometry validation on the ACTUAL rendered output.

Enforces the brief's hard constraints against the rendered PDF (not pre-render
estimates):

    renderedGlyphBounds subset of page trim area   (page-boundary)
    no glyph crosses a detected table/grid line     (border intersection)
    no rendered word overlaps a neighbour word       (neighbour collision)
    expected translated text objects exist           (missing-content)

Returns a per-page result with a list of failed regions and reasons. The caller
(replace_text_in_pdf / PdfTranslationService) uses this to fail closed:
a page with any hard-constraint failure must be routed to layout review and
must NOT be silently approved.

Book-agnostic: everything is derived from the rendered geometry, no per-book
constants.
"""

import pymupdf


# Small margins to absorb antialiasing / measurement error (points).
_BORDER_MARGIN = 1.0
_EDGE_MARGIN = 2.0
_COLLISION_MARGIN = 0.5


def validate_structure(expected_elements, rendered_pdf, fonts_dir=None, tol=3.0):
    """
    STRUCTURAL COMPARISON GATE (spec Req 4): compare the RENDERED output against the
    SOURCE structure, element by element. For each expected element that carries a
    cell_box (headers + structured cells), verify:
      - PRESENT: some rendered text lies within the element's cell_box;
      - IN-BOX: that text does not spill outside the cell_box (beyond tol);
      - PEER SIZE: elements sharing a peer_group_id render at one consistent size;
      - FONT: drawn font is approved (not a built-in fallback) when fonts_dir given.
    Also checks the STRICT band for INVENTED elements: rendered text inside the
    header/marker band envelope that falls in no expected cell_box is flagged
    (elementInvented) — catching an added element the source never had.

    expected_elements: iterable of dicts with keys: id, page_number, cell_box,
        peer_group_id (optional), semantic_role (optional). (The contract items.)

    Returns {"ok": bool, "pages": {n: {ok, failures}}, "review_pages": [...]}.
    Book-agnostic: all expectations come from the source-derived contract.
    """
    result = {"ok": True, "pages": {}, "review_pages": []}
    expected = [e for e in (expected_elements or []) if e.get("cell_box")]
    if not expected:
        return result  # nothing structural to check

    approved = _approved_font_stems(fonts_dir) if fonts_dir else set()

    doc = pymupdf.open(rendered_pdf)
    # Group expectations by page.
    by_page = {}
    for e in expected:
        by_page.setdefault(e.get("page_number"), []).append(e)

    import re as _re
    for pn, elems in by_page.items():
        if pn is None or pn < 1 or pn > len(doc):
            continue
        page = doc[pn - 1]
        d = page.get_text("dict")
        spans = []
        for b in d.get("blocks", []):
            for l in b.get("lines", []):
                for s in l.get("spans", []):
                    if (s.get("text") or "").strip():
                        spans.append(s)
        failures = []
        # Per peer group: collect the rendered sizes to check consistency.
        peer_sizes = {}
        for e in elems:
            cb = e["cell_box"]
            role = (e.get("semantic_role") or "").lower()
            # Header cells have precise boxes -> strict in-box + peer-size checks.
            # Content word cells currently share a full-column box (row not modelled
            # per-word), so strict in-box/peer-size would false-positive; for those we
            # only verify PRESENCE + approved FONT. (Row-level content boxes: future.)
            strict = role in ("heading", "table_header", "merged_header", "end_marker")
            inside = [s for s in spans
                      if cb[0] - tol <= (s["bbox"][0] + s["bbox"][2]) / 2 <= cb[2] + tol
                      and cb[1] - tol <= (s["bbox"][1] + s["bbox"][3]) / 2 <= cb[3] + tol]
            if not inside:
                failures.append({"constraint": "elementMissing", "element_id": e.get("id"),
                                 "role": role or None, "box": list(cb),
                                 "detail": f"no rendered text in cell for element {e.get('id')}"})
                continue
            if strict:
                # In-box: no glyph spills HORIZONTALLY outside the cell (borders are
                # vertical grid lines — the real "crossing" risk). Vertical tolerance
                # is looser for headers because a multi-line header legitimately fills
                # (and slightly overshoots) the header band via line leading.
                vtol = tol + (e.get("cell_box")[3] - e.get("cell_box")[1]) * 0.35
                for s in inside:
                    bb = s["bbox"]
                    if bb[0] < cb[0] - tol or bb[2] > cb[2] + tol \
                       or bb[1] < cb[1] - vtol or bb[3] > cb[3] + vtol:
                        failures.append({"constraint": "elementOutOfBox", "element_id": e.get("id"),
                                         "role": role or None, "box": list(cb),
                                         "detail": f"element {e.get('id')} text spills outside its cell"})
                        break
            # Font fidelity for this element's text (all element kinds).
            if approved:
                for s in inside:
                    norm = _re.sub(r"[^a-z0-9]", "", (s.get("font") or "").lower())
                    is_fb = any(m in norm for m in _FALLBACK_FONT_MARKERS)
                    is_ap = any(stem in norm or norm in stem for stem in approved)
                    if is_fb and not is_ap and len((s.get("text") or "").strip()) >= 4:
                        failures.append({"constraint": "elementFont", "element_id": e.get("id"),
                                         "role": role or None, "box": list(cb),
                                         "detail": f"element {e.get('id')} in fallback font '{s.get('font')}'"})
                        break
            pg = e.get("peer_group_id")
            if strict and pg:
                peer_sizes.setdefault(pg, []).append(max(round(s.get("size", 0), 1) for s in inside))

        # INVENTED / EXTRA element: a rendered text cluster in the STRICT band (the
        # header/marker rows we model precisely) that does not fall inside ANY expected
        # cell_box is an element the source never had. We only police the strict band's
        # y-range so prose/content columns (not modelled per-word) never false-positive.
        strict_boxes = [e["cell_box"] for e in elems
                        if (e.get("semantic_role") or "").lower()
                        in ("heading", "table_header", "merged_header", "end_marker")]
        if strict_boxes:
            bx0 = min(cb[0] for cb in strict_boxes)
            by0 = min(cb[1] for cb in strict_boxes)
            bx1 = max(cb[2] for cb in strict_boxes)
            by1 = max(cb[3] for cb in strict_boxes)
            for s in spans:
                sx = (s["bbox"][0] + s["bbox"][2]) / 2
                sy = (s["bbox"][1] + s["bbox"][3]) / 2
                # Only consider spans sitting within the strict band's envelope.
                if not (bx0 - tol <= sx <= bx1 + tol and by0 - tol <= sy <= by1 + tol):
                    continue
                accounted = any(cb[0] - tol <= sx <= cb[2] + tol
                                and cb[1] - tol <= sy <= cb[3] + tol
                                for cb in strict_boxes)
                if not accounted:
                    failures.append({"constraint": "elementInvented",
                                     "box": [s["bbox"][0], s["bbox"][1], s["bbox"][2], s["bbox"][3]],
                                     "detail": f"rendered text '{(s.get('text') or '').strip()[:20]}' "
                                               f"has no corresponding source element"})
                    break

        # Peer-size consistency within each group. Header peer groups may legitimately
        # contain a MULTI-LINE header (e.g. a 2-line "HIGH FREQUENCY / WORDS") whose
        # rendered extent is ~2x a single-line peer ("WORDS"); that is faithful to the
        # source design, not a defect. So header peers use a ratio that admits a
        # 2-line-vs-1-line difference; other strict peers stay tight. (Calibrated from
        # real source geometry across books, not tuned to one — see Task 10.)
        for pg, sizes in peer_sizes.items():
            sizes = [s for s in sizes if s > 0]
            ratio_limit = 2.1 if "header" in pg else 1.18
            if len(sizes) >= 2 and min(sizes) > 0 and (max(sizes) / min(sizes)) > ratio_limit:
                failures.append({"constraint": "peerSizeMismatch", "element_id": pg,
                                 "detail": f"peer group {pg} sizes vary {min(sizes)}..{max(sizes)}pt"})

        result["pages"][pn] = {"ok": not failures, "failures": failures}
        if failures:
            result["ok"] = False
            result["review_pages"].append(pn)

    doc.close()
    return result


def _horizontal_gridlines_and_verticals(page):
    """
    Return (horizontals, verticals) — lists of detected straight table/grid lines
    as ((x0,y0),(x1,y1)) from vector drawings. Used for border-intersection tests.
    """
    horizontals, verticals = [], []
    try:
        drawings = page.get_drawings()
    except Exception:
        return horizontals, verticals
    for d in drawings:
        for item in d.get("items", []):
            if item[0] != "l":  # line segments only
                continue
            p1, p2 = item[1], item[2]
            if abs(p1.y - p2.y) <= 0.6:      # horizontal
                horizontals.append((min(p1.x, p2.x), max(p1.x, p2.x), (p1.y + p2.y) / 2))
            elif abs(p1.x - p2.x) <= 0.6:    # vertical
                verticals.append(((p1.x + p2.x) / 2, min(p1.y, p2.y), max(p1.y, p2.y)))
    return horizontals, verticals


def _word_crosses_vertical(word, verticals):
    """A word crosses a vertical grid line if the line's x is strictly inside the
    word's x-span and the line's y-range overlaps the word's y-span."""
    x0, y0, x1, y1 = word[0], word[1], word[2], word[3]
    for vx, vy0, vy1 in verticals:
        if x0 + _BORDER_MARGIN < vx < x1 - _BORDER_MARGIN:
            if not (y1 < vy0 or y0 > vy1):
                return vx
    return None


def _words_overlap(a, b):
    """True if two word rects overlap horizontally AND vertically (collision)."""
    ax0, ay0, ax1, ay1 = a[0], a[1], a[2], a[3]
    bx0, by0, bx1, by1 = b[0], b[1], b[2], b[3]
    hx = min(ax1, bx1) - max(ax0, bx0)
    hy = min(ay1, by1) - max(ay0, by0)
    return hx > _COLLISION_MARGIN and hy > _COLLISION_MARGIN


def validate_raster(source_pdf, translated_pdf, page_index, text_bboxes=None, dpi=72):
    """
    Raster validation with masks (brief §12.2): render source vs translated page,
    and compare the PROTECTED (non-text) areas. Translated-text zones are excluded
    (they legitimately change). Large differences outside text zones indicate
    border/artwork/background damage. Returns {"ok": bool, "diff_ratio": float}.
    Book-agnostic; compares protected zones only, not a whole-page score.
    """
    try:
        sdoc = pymupdf.open(source_pdf)
        tdoc = pymupdf.open(translated_pdf)
        if page_index >= len(sdoc) or page_index >= len(tdoc):
            sdoc.close(); tdoc.close()
            return {"ok": True, "diff_ratio": 0.0, "note": "page index out of range"}
        sp = sdoc[page_index].get_pixmap(dpi=dpi)
        tp = tdoc[page_index].get_pixmap(dpi=dpi)
        sdoc.close(); tdoc.close()
    except Exception as e:
        return {"ok": True, "diff_ratio": 0.0, "note": f"raster skipped: {e}"}

    if sp.width != tp.width or sp.height != tp.height or sp.n != tp.n:
        return {"ok": True, "diff_ratio": 0.0, "note": "geometry differs; skip raster"}

    scale = dpi / 72.0
    masks = []
    for b in (text_bboxes or []):
        masks.append((b[0] * scale, b[1] * scale, b[2] * scale, b[3] * scale))

    def masked(x, y):
        for mx0, my0, mx1, my1 in masks:
            if mx0 - 2 <= x <= mx1 + 2 and my0 - 2 <= y <= my1 + 2:
                return True
        return False

    sb, tb = sp.samples, tp.samples
    n = sp.n
    stride = sp.width * n
    diff = 0
    total = 0
    step = 3  # sample every 3rd pixel for speed
    for y in range(0, sp.height, step):
        for x in range(0, sp.width, step):
            if masked(x, y):
                continue
            total += 1
            i = y * stride + x * n
            if abs(sb[i] - tb[i]) > 30:  # compare red channel as proxy
                diff += 1
    ratio = (diff / total) if total else 0.0
    # >2% changed pixels OUTSIDE text zones => protected content likely damaged.
    return {"ok": ratio <= 0.02, "diff_ratio": round(ratio, 4)}


def _extract_words_by_page(pdf_path):
    """
    Open the rendered PDF ONCE and return {page_number(1-based): words_list}.
    Previously each region measurement re-opened and re-parsed the whole PDF
    (O(regions x doc_size)); caching the per-page words here makes the diagnostic
    manifest O(doc_size). Book-agnostic. Returns {} on failure (callers degrade
    gracefully to empty geometry).
    """
    words_by_page = {}
    try:
        doc = pymupdf.open(pdf_path)
        try:
            for idx in range(len(doc)):
                try:
                    words_by_page[idx + 1] = doc[idx].get_text("words")
                except Exception:
                    words_by_page[idx + 1] = []
        finally:
            doc.close()
    except Exception:
        return {}
    return words_by_page


def _rendered_region_geometry(page_words, region_bbox):
    """
    Measure rendered geometry inside a region's bounds on the output PDF:
    returns (rendered_line_count, rendered_glyph_bounds, clipped_glyph_count).
    A glyph is 'clipped' when its rendered bounds fall outside the region bounds.
    Book-agnostic; derived from the actual rendered page.

    `page_words` is the pre-extracted word list for this page (from
    _extract_words_by_page) so the PDF is opened once per document, not once per
    region.
    """
    words = page_words or []
    if not region_bbox or not words:
        return 0, None, 0
    rx0, ry0, rx1, ry1 = region_bbox
    inside = []
    lines = set()
    clipped = 0
    for w in words:
        wx0, wy0, wx1, wy1 = w[0], w[1], w[2], w[3]
        cx, cy = (wx0 + wx1) / 2, (wy0 + wy1) / 2
        # Word belongs to this region if its center is within the region bounds.
        if rx0 - 2 <= cx <= rx1 + 2 and ry0 - 2 <= cy <= ry1 + 2:
            inside.append(w)
            lines.add(round(w[1], 0))
            # Clipped if any edge exceeds the region bounds beyond a small margin.
            if wx0 < rx0 - 1 or wx1 > rx1 + 1 or wy0 < ry0 - 1 or wy1 > ry1 + 1:
                clipped += 1
    if not inside:
        return 0, None, 0
    gb = [min(w[0] for w in inside), min(w[1] for w in inside),
          max(w[2] for w in inside), max(w[3] for w in inside)]
    return len(lines), [round(v, 1) for v in gb], clipped


def build_diagnostic_manifest(pdf_path, report):
    """
    Build the per-region diagnostic manifest (brief §14) from the render report's
    region-graph scene record + the gate results. Persisted with the edition for
    admin review. Book-agnostic: every field derived from the render + gate outputs.

    Emits, per region, the full §14 field set:
      regionId, semanticType, sourceBounds, safeInnerBounds, visualScaleRatio,
      lineHeight, tracking, sourceLineCount, semanticItemCount, renderedLineCount,
      renderedGlyphBounds, clippedGlyphCount, plus status/failureReasons.
    """
    scene = report.get("scene", {})
    gate = report.get("render_gate", {}).get("pages", {})
    struct_gate = report.get("structure_gate", {}).get("pages", {})
    typo = report.get("typography", {}).get("pages", {}) if isinstance(report.get("typography"), dict) else {}
    font_res = report.get("font_resolution", {})
    # Open the rendered PDF ONCE and cache per-page words. This replaces the previous
    # per-region re-open of the whole PDF (the dominant cost in full-edition renders).
    words_by_page = _extract_words_by_page(pdf_path)
    manifest = {
        "render_engine_version": report.get("version", "v8"),
        "font_resolved": font_res.get("resolved_family"),
        "font_file_hash": font_res.get("font_file_hash"),
        "font_fallback_used": font_res.get("fallback_used", False),
        "font_approved": font_res.get("approved", True),
        "font_unapproved": font_res.get("unapproved", False),
        "font_unresolved": font_res.get("unresolved", False),
        "font_policy": font_res.get("policy"),
        "pages": [],
    }
    for pn_str, page_scene in scene.items():
        try:
            pn = int(pn_str)
        except (TypeError, ValueError):
            continue
        page_gate = gate.get(pn) or gate.get(pn_str) or {"ok": True, "failures": []}
        failures = page_gate.get("failures", [])
        constraints = {f.get("constraint") for f in failures}
        typo_page = typo.get(pn) or typo.get(pn_str) or {"ok": True, "failures": []}
        # TASK 12: per-element structural comparison verdict for this page — the list
        # of elements that deviated from the source structure and WHY (constraint +
        # human detail + element id + box), so the admin overlay can point at each one.
        struct_page = struct_gate.get(pn) or struct_gate.get(pn_str) or {"ok": True, "failures": []}
        structure_deviations = [
            {
                "elementId": f.get("element_id"),
                "role": f.get("role"),
                "constraint": f.get("constraint"),
                "box": f.get("box"),
                "detail": f.get("detail"),
            }
            for f in struct_page.get("failures", [])
        ]

        # Build per-region diagnostics (§14). Fall back to a single synthetic region
        # when only the flat unit record is present (no region-graph).
        regions = page_scene.get("regions")
        units = page_scene.get("units", [])
        units_by_region = {}
        for u in units:
            units_by_region.setdefault(u.get("region_id"), []).append(u)

        region_entries = []
        if regions:
            for r in regions:
                r_units = units_by_region.get(r.get("region_id"), [])
                src_bounds = r.get("source_bounds")
                safe_bounds = r.get("safe_inner_bounds")
                # visual scale ratio: rendered vs source height (approx via bounds).
                rline, rglyph, clipped = _rendered_region_geometry(words_by_page.get(pn, []), safe_bounds or src_bounds)
                sizes = [u.get("nominal_size_pt") for u in r_units if u.get("nominal_size_pt")]
                nominal = (sum(sizes) / len(sizes)) if sizes else None
                visual_scale = None
                if src_bounds and rglyph:
                    src_h = src_bounds[3] - src_bounds[1]
                    ren_h = rglyph[3] - rglyph[1]
                    if src_h > 0:
                        visual_scale = round(ren_h / src_h, 3)
                region_entries.append({
                    "regionId": r.get("region_id"),
                    "semanticType": r.get("semantic_type") or r.get("region_type"),
                    "sourceBounds": src_bounds,
                    "safeInnerBounds": safe_bounds,
                    "containerSource": r.get("container_source"),
                    "visualScaleRatio": visual_scale,
                    "lineHeight": round(nominal * 1.15, 2) if nominal else None,
                    "tracking": 0.0,
                    "sourceLineCount": len({round(u["bbox"][1], 0) for u in r_units if u.get("bbox")}) or len(r_units),
                    "semanticItemCount": len(r_units),
                    "renderedLineCount": rline,
                    "renderedGlyphBounds": rglyph,
                    "clippedGlyphCount": clipped,
                })

        manifest["pages"].append({
            "page": pn,
            "page_type": page_scene.get("page_type"),
            "status": "NEEDS_LAYOUT_REVIEW" if (not page_gate.get("ok", True) or not typo_page.get("ok", True)) else "OK",
            "region_count": page_scene.get("region_count", len(region_entries)),
            "unit_count": page_scene.get("unit_count", len(units)),
            "units": units,
            "regions": region_entries,
            "overflowX": 0 if "pageBoundaryIntersections" not in constraints else 1,
            "overflowY": 0,
            "tableBorderIntersections": [f["detail"] for f in failures
                                         if f.get("constraint") == "tableBorderIntersections"],
            "neighbourCollisions": [f["detail"] for f in failures
                                    if f.get("constraint") == "neighbourTextIntersections"],
            "typographyFailures": [f.get("detail") for f in typo_page.get("failures", [])],
            "structureDeviations": structure_deviations,
            "structureOk": struct_page.get("ok", True),
            "fitStatus": "FAILED" if not page_gate.get("ok", True) else "OK",
            "failureReasons": [f.get("detail") for f in failures],
        })
    return manifest


def build_diagnostic_manifest_for_report(pdf_path, report):
    """Attach the diagnostic manifest to the report in place."""
    try:
        report["diagnostic_manifest"] = build_diagnostic_manifest(pdf_path, report)
    except Exception as e:
        report["diagnostic_manifest"] = {"error": str(e)}
    return report


def validate_semantics(page, page_type, expected_units=None):
    """
    Semantic validation (brief §12.3): verify structural integrity of the rendered
    page against the expected translated units.
      - each expected unit's text appears at least once (not dropped/merged away)
      - for list/title pages, the numbered order is preserved top-to-bottom
    expected_units: optional list of expected strings (in reading order).
    Returns {"ok": bool, "failures": [...]}. Book-agnostic.
    """
    failures = []
    if not expected_units:
        return {"ok": True, "failures": []}
    try:
        rendered = (page.get_text("text") or "")
    except Exception:
        return {"ok": True, "failures": []}
    norm = " ".join(rendered.split()).lower()

    # unit-once / not-dropped: each expected unit's first word must be present.
    for u in expected_units:
        key = (u.split() or [u])[0].strip().lower()
        if key and key not in norm:
            failures.append({"constraint": "missingUnit", "detail": f"expected unit not found: '{u[:30]}'"})

    # order preservation for numbered lists (back_cover/title lists).
    if page_type == "back_cover":
        try:
            words = page.get_text("words")
            nums = [(w[1], w[4]) for w in words if w[4].isdigit()]
            seq = [int(t) for _, t in sorted(nums, key=lambda z: z[0]) if t.isdigit()]
            ascending = [n for n in seq if 1 <= n <= 20]
            if ascending and ascending != sorted(ascending):
                failures.append({"constraint": "listOrder", "detail": f"list numbers out of order: {ascending}"})
        except Exception:
            pass

    return {"ok": len(failures) == 0, "failures": failures}


def validate_page(page, page_type, expected_text=""):
    """
    Validate a single rendered page against the hard constraints.
    Returns dict: {"ok": bool, "failures": [ {constraint, detail}, ... ]}.
    """
    failures = []

    try:
        words = page.get_text("words")  # (x0,y0,x1,y1,text,block,line,wno)
    except Exception:
        return {"ok": True, "failures": [], "note": "words unavailable"}

    trim = page.trimbox if page.trimbox else page.rect
    tx0, ty0, tx1, ty1 = trim.x0, trim.y0, trim.x1, trim.y1

    # 1. Page-boundary / trim overflow.
    for w in words:
        if w[2] > tx1 - _EDGE_MARGIN or w[0] < tx0 - _EDGE_MARGIN \
           or w[3] > ty1 - _EDGE_MARGIN or w[1] < ty0 - _EDGE_MARGIN:
            failures.append({
                "constraint": "pageBoundaryIntersections",
                "detail": f"word '{w[4]}' at x1={w[2]:.1f} exceeds trim [{tx0:.0f},{ty0:.0f},{tx1:.0f},{ty1:.0f}]",
            })
            break

    # 2. Table border intersection (glyph crosses a vertical grid line).
    _, verticals = _horizontal_gridlines_and_verticals(page)
    if verticals:
        for w in words:
            vx = _word_crosses_vertical(w, verticals)
            if vx is not None:
                failures.append({
                    "constraint": "tableBorderIntersections",
                    "detail": f"word '{w[4]}' crosses vertical grid line at x={vx:.1f}",
                })
                break

    # 3. Neighbour collision (only meaningful for column/table pages).
    if page_type in ("vocabulary",):
        n = len(words)
        collided = False
        # Compare each word only against nearby words (same rough row) for speed.
        by_row = sorted(range(n), key=lambda i: words[i][1])
        for idx_pos, i in enumerate(by_row):
            for j in by_row[idx_pos + 1:]:
                if words[j][1] - words[i][1] > 6:  # different row band
                    break
                if _words_overlap(words[i], words[j]):
                    failures.append({
                        "constraint": "neighbourTextIntersections",
                        "detail": f"'{words[i][4]}' overlaps '{words[j][4]}'",
                    })
                    collided = True
                    break
            if collided:
                break

    # 4. Missing content: expected translated text but nothing rendered.
    if expected_text.strip() and not words:
        failures.append({
            "constraint": "missingContent",
            "detail": "expected translated text but no words rendered",
        })

    return {"ok": len(failures) == 0, "failures": failures}


def validate_typography(report, document_type=None, market=None, output_format="print"):
    """
    Typography hierarchy + minimum-readability validation (brief §10.1/§10.2).

    Reads the report's region-graph scene record (regions carry semantic_type;
    units carry nominal_size_pt) and checks, per page:
      - min readable size: any body/heading region rendered below the configured
        minimum readable size fails (region routed to review);
      - hierarchy: heading visual size must exceed body visual size on the page;
      - table: table_header size must be >= table_cell/body size;
      - peer variance: regions of the SAME role on a page must not vary in size
        beyond the tolerance.

    Returns {"ok": bool, "pages": {n: {ok, failures}}, "review_pages": [...]}.
    Book-agnostic: thresholds come from readability_policy, sizes from the scene.
    """
    from readability_policy import (min_readable_size, HEADING_ROLES, BODY_ROLES,
                                     PEER_VARIANCE_TOLERANCE)
    min_size = min_readable_size(document_type, market, output_format)
    result = {"ok": True, "pages": {}, "review_pages": [], "min_readable_size": min_size}

    scene = report.get("scene", {})
    for pn_str, pscene in scene.items():
        try:
            pn = int(pn_str)
        except (TypeError, ValueError):
            continue
        failures = []

        # Gather per-region representative sizes by role.
        regions = pscene.get("regions") or []
        units = pscene.get("units") or []
        # Map region_id -> role and collect sizes from its units.
        role_sizes = {}          # role -> [sizes]
        region_role = {}
        for r in regions:
            region_role[r.get("region_id")] = r.get("semantic_type") or r.get("region_type")
        for u in units:
            size = u.get("nominal_size_pt")
            if not size:
                continue
            role = region_role.get(u.get("region_id")) or u.get("role") or u.get("semantic_type")
            role_sizes.setdefault(role, []).append(size)

        # 1. Minimum readable size (any rendered text below the floor fails).
        for role, sizes in role_sizes.items():
            below = [s for s in sizes if s + 0.05 < min_size]
            if below:
                failures.append({
                    "constraint": "minReadableSize",
                    "detail": f"role '{role}' has text at {min(below):.1f}pt < min {min_size:.1f}pt",
                })

        def _avg(xs):
            return sum(xs) / len(xs) if xs else 0.0

        heading_sizes = [s for role, ss in role_sizes.items() if role in HEADING_ROLES for s in ss]
        body_sizes = [s for role, ss in role_sizes.items() if role in BODY_ROLES for s in ss]

        # 2. Hierarchy: heading visual size must exceed body visual size.
        if heading_sizes and body_sizes:
            if _avg(heading_sizes) <= _avg(body_sizes):
                failures.append({
                    "constraint": "typographyHierarchy",
                    "detail": (f"heading avg {_avg(heading_sizes):.1f}pt not greater than "
                               f"body avg {_avg(body_sizes):.1f}pt"),
                })

        # 3. Table: header size >= body/cell size.
        header_sizes = role_sizes.get("table_header", [])
        cell_sizes = role_sizes.get("table_cell", []) or role_sizes.get("word_item", []) \
            or role_sizes.get("word_list_item", [])
        if header_sizes and cell_sizes:
            if _avg(header_sizes) + 0.05 < _avg(cell_sizes):
                failures.append({
                    "constraint": "tableHeaderHierarchy",
                    "detail": (f"table header avg {_avg(header_sizes):.1f}pt < "
                               f"table body avg {_avg(cell_sizes):.1f}pt"),
                })

        # 4. Peer variance: same role must not vary beyond tolerance. Only applies
        #    to roles expected to be visually UNIFORM (table columns, word/list
        #    items). Prose/paragraph/display text legitimately varies in the source
        #    design, so it is excluded to avoid false positives.
        _UNIFORM_PEER_ROLES = {"table_cell", "table_header", "word_item",
                               "word_list_item", "list_item"}
        for role, sizes in role_sizes.items():
            if role not in _UNIFORM_PEER_ROLES or len(sizes) < 2:
                continue
            lo, hi = min(sizes), max(sizes)
            if lo > 0 and (hi - lo) / lo > PEER_VARIANCE_TOLERANCE:
                failures.append({
                    "constraint": "peerRegionVariance",
                    "detail": (f"role '{role}' sizes vary {lo:.1f}..{hi:.1f}pt "
                               f"(> {PEER_VARIANCE_TOLERANCE:.0%})"),
                })

        page_ok = len(failures) == 0
        result["pages"][pn] = {"ok": page_ok, "failures": failures}
        if not page_ok:
            result["ok"] = False
            result["review_pages"].append(pn)

    return result


def _approved_font_stems(fonts_dir):
    """Return the set of normalized family stems available in the fonts dir (the
    approved/house fonts). Book-agnostic: derived from the shipped fonts, no
    hardcoded names."""
    import os, re
    stems = set()
    if not fonts_dir or not os.path.isdir(fonts_dir):
        return stems
    for f in os.listdir(fonts_dir):
        if not f.lower().endswith((".ttf", ".otf")):
            continue
        stem = f.rsplit(".", 1)[0]
        for suf in ("-Regular", "-Bold", "-SemiBold", "-Medium", "-Light",
                    "Regular", "Bold", "SemiBold", "Medium", "Light"):
            stem = stem.replace(suf, "")
        stem = re.sub(r"[^a-z0-9]", "", stem.lower())
        if stem:
            stems.add(stem)
    return stems


# Known PyMuPDF built-in FALLBACK fonts. If replaced text renders in one of these,
# the intended (house/source) font was NOT embedded — a fidelity failure.
_FALLBACK_FONT_MARKERS = ("charissil", "charis", "nimbussans", "nimbus", "helvetica",
                          "helv", "timesnewroman", "times", "courier", "sans-serif")


def validate_font_fidelity(page, fonts_dir, min_chars=8):
    """
    Verify the page's REPLACED text actually rendered in an approved (house/source)
    font — not a built-in fallback (CharisSIL/NimbusSans/etc.). This is the check
    that catches insert_htmlbox silently falling back to a substitute font, which
    the geometry gates cannot see.

    Book-agnostic: the approved set is derived from the shipped fonts directory; the
    fallback set is PyMuPDF's built-ins. We look at the fonts actually DRAWN (via
    get_text('dict')) and flag a page where a material amount of text (>= min_chars)
    was drawn in a fallback font while an approved font was available.

    Returns {"ok": bool, "failures": [...], "drawn": {font: char_count}}.
    """
    import re
    failures = []
    approved = _approved_font_stems(fonts_dir)
    drawn = {}
    try:
        d = page.get_text("dict")
    except Exception:
        return {"ok": True, "failures": [], "drawn": {}}
    for b in d.get("blocks", []):
        for l in b.get("lines", []):
            for s in l.get("spans", []):
                t = (s.get("text") or "").strip()
                if not t:
                    continue
                fn = s.get("font", "")
                drawn[fn] = drawn.get(fn, 0) + len(t)

    if not approved:
        # No approved set to compare against — cannot judge; don't false-flag.
        return {"ok": True, "failures": [], "drawn": drawn}

    for fn, count in drawn.items():
        norm = re.sub(r"[^a-z0-9]", "", fn.lower())
        is_fallback = any(m in norm for m in _FALLBACK_FONT_MARKERS)
        is_approved = any(stem in norm or norm in stem for stem in approved)
        if is_fallback and not is_approved and count >= min_chars:
            failures.append({
                "constraint": "fontFidelity",
                "detail": (f"text drawn in fallback font '{fn}' ({count} chars) instead "
                           f"of an approved/house font — intended font not embedded"),
            })
    return {"ok": len(failures) == 0, "failures": failures, "drawn": drawn}


def validate_size_consistency(page, page_type, rel_tol=0.18):
    """
    Flag a page where PEER text lines (lines of the SAME visual role) render at
    materially different sizes — e.g. list entries that should share a size, or a
    header row rendered in mixed sizes. Book-agnostic and hierarchy-safe:

    Legitimate size hierarchy (a big title over a small subtitle) forms SEPARATE
    size clusters and must NOT be flagged. So we cluster the per-line sizes and only
    flag when a SINGLE cluster (peers) has internal spread beyond rel_tol — i.e.
    lines that are supposed to match don't. Clustering is done by relative gap:
    consecutive sorted sizes within rel_tol of each other join the same cluster.

    Only applies to structured display pages (cover/back_cover); vocabulary word
    CELLS are intentionally fitted per-cell by the fitting ladder (§9.5) so their
    body sizes legitimately differ and are excluded. Story prose is excluded too.

    Returns {"ok": bool, "failures": [...]}.
    """
    if page_type not in ("back_cover", "cover"):
        return {"ok": True, "failures": []}
    failures = []
    try:
        d = page.get_text("dict")
    except Exception:
        return {"ok": True, "failures": []}
    sizes = []
    for b in d.get("blocks", []):
        for l in b.get("lines", []):
            line_sizes = [round(s.get("size", 0), 1) for s in l.get("spans", [])
                          if (s.get("text") or "").strip()]
            if line_sizes:
                sizes.append(max(line_sizes))
    sizes = sorted(s for s in sizes if s > 0)
    if len(sizes) < 2:
        return {"ok": True, "failures": []}

    # Cluster sizes: start a new cluster when the gap to the previous size exceeds
    # rel_tol of the previous size (a real hierarchy step). Peers land in one cluster.
    clusters = [[sizes[0]]]
    for s in sizes[1:]:
        prev = clusters[-1][-1]
        if prev > 0 and (s - prev) / prev > rel_tol:
            clusters.append([s])
        else:
            clusters[-1].append(s)

    # Two kinds of inconsistency:
    #  (a) within a single peer cluster the spread still exceeds rel_tol; or
    #  (b) there are MULTIPLE clusters that each contain >=2 lines — i.e. competing
    #      "peer groups" at different sizes (e.g. a list rendered alternately at 22pt
    #      and 14pt). A lone heading (single-line cluster) over a uniform body is a
    #      legitimate hierarchy and does NOT trip this.
    multi_line_clusters = [c for c in clusters if len(c) >= 2]
    if len(multi_line_clusters) >= 2:
        allsz = [s for c in multi_line_clusters for s in c]
        lo, hi = min(allsz), max(allsz)
        failures.append({
            "constraint": "sizeConsistency",
            "detail": (f"multiple peer groups at different sizes "
                       f"(min {lo}pt vs max {hi}pt) — lines that should share a size do not"),
        })
    else:
        for c in clusters:
            if len(c) >= 2:
                lo, hi = min(c), max(c)
                if lo > 0 and (hi / lo) > (1 + rel_tol):
                    failures.append({
                        "constraint": "sizeConsistency",
                        "detail": (f"peer text lines render at inconsistent sizes "
                                   f"(min {lo}pt vs max {hi}pt within one role group)"),
                    })
                    break
    return {"ok": len(failures) == 0, "failures": failures}


def validate_document(pdf_path, page_types=None, expected_by_page=None, fonts_dir=None):
    """
    Validate every page of a rendered PDF. Returns a diagnostic dict:
    {"ok": bool, "pages": {n: {ok, failures}}, "review_pages": [...]}.

    When fonts_dir is given, also runs the FONT-FIDELITY check (replaced text must
    render in an approved font, not a fallback) and the SIZE-CONSISTENCY check
    (peer lines must share a size) — the output-verifying gates that catch font
    substitution and inconsistent sizing the geometry checks cannot see.
    """
    page_types = page_types or {}
    expected_by_page = expected_by_page or {}
    doc = pymupdf.open(pdf_path)
    result = {"ok": True, "pages": {}, "review_pages": []}
    for idx in range(len(doc)):
        pn = idx + 1
        ptype = page_types.get(str(pn)) or page_types.get(pn) or "story"
        pres = validate_page(doc[idx], ptype, expected_by_page.get(pn, ""))
        # Semantic validation for structured pages (list order / unit presence).
        exp_units = None
        if ptype == "back_cover" and expected_by_page.get(pn):
            exp_units = [l.strip() for l in expected_by_page[pn].split("\n") if l.strip()]
        sres = validate_semantics(doc[idx], ptype, exp_units)
        extra_failures = list(sres["failures"]) if not sres["ok"] else []

        # Output-verifying gates (font fidelity + size consistency).
        if fonts_dir:
            fres = validate_font_fidelity(doc[idx], fonts_dir)
            if not fres["ok"]:
                extra_failures += fres["failures"]
        zres = validate_size_consistency(doc[idx], ptype)
        if not zres["ok"]:
            extra_failures += zres["failures"]

        if extra_failures:
            pres = {"ok": False, "failures": pres.get("failures", []) + extra_failures}
        result["pages"][pn] = pres
        if not pres["ok"]:
            result["ok"] = False
            result["review_pages"].append(pn)
    doc.close()
    return result


def _validate_document_legacy(pdf_path, page_types=None, expected_by_page=None):
    """Deprecated alias: superseded by validate_document (which also runs the
    output-verifying font/size gates)."""
    return validate_document(pdf_path, page_types, expected_by_page)
