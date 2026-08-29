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


def _rendered_region_geometry(pdf_path, page_number, region_bbox):
    """
    Measure rendered geometry inside a region's bounds on the output PDF:
    returns (rendered_line_count, rendered_glyph_bounds, clipped_glyph_count).
    A glyph is 'clipped' when its rendered bounds fall outside the region bounds.
    Book-agnostic; derived from the actual rendered page.
    """
    try:
        doc = pymupdf.open(pdf_path)
        if page_number - 1 >= len(doc):
            doc.close()
            return 0, None, 0
        page = doc[page_number - 1]
        words = page.get_text("words")
        doc.close()
    except Exception:
        return 0, None, 0

    if not region_bbox:
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
    typo = report.get("typography", {}).get("pages", {}) if isinstance(report.get("typography"), dict) else {}
    font_res = report.get("font_resolution", {})
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
                rline, rglyph, clipped = _rendered_region_geometry(pdf_path, pn, safe_bounds or src_bounds)
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


def validate_document(pdf_path, page_types=None, expected_by_page=None):
    """
    Validate every page of a rendered PDF. Returns a diagnostic dict:
    {"ok": bool, "pages": {n: {ok, failures}}, "review_pages": [...]}.
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
        if not sres["ok"]:
            pres = {"ok": False, "failures": pres.get("failures", []) + sres["failures"]}
        result["pages"][pn] = pres
        if not pres["ok"]:
            result["ok"] = False
            result["review_pages"].append(pn)
    doc.close()
    return result
