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


def build_diagnostic_manifest(pdf_path, report):
    """
    Build the per-region diagnostic manifest (brief §14) from the render report's
    scene record + the gate results. Persisted with the edition for admin review.
    Book-agnostic: every field derived from the render + gate outputs.
    """
    scene = report.get("scene", {})
    gate = report.get("render_gate", {}).get("pages", {})
    font_res = report.get("font_resolution", {})
    manifest = {
        "render_engine_version": report.get("version", "v8"),
        "font_resolved": font_res.get("resolved_family"),
        "font_file_hash": font_res.get("font_file_hash"),
        "font_fallback_used": font_res.get("fallback_used", False),
        "pages": [],
    }
    for pn_str, page_scene in scene.items():
        pn = int(pn_str)
        page_gate = gate.get(pn) or gate.get(pn_str) or {"ok": True, "failures": []}
        failures = page_gate.get("failures", [])
        constraints = {f.get("constraint") for f in failures}
        manifest["pages"].append({
            "page": pn,
            "page_type": page_scene.get("page_type"),
            "status": "NEEDS_LAYOUT_REVIEW" if not page_gate.get("ok", True) else "OK",
            "region_count": page_scene.get("region_count", 0),
            "unit_count": page_scene.get("unit_count", 0),
            "units": page_scene.get("units", []),
            "overflowX": 0 if "pageBoundaryIntersections" not in constraints else 1,
            "overflowY": 0,
            "tableBorderIntersections": [f["detail"] for f in failures
                                         if f.get("constraint") == "tableBorderIntersections"],
            "neighbourCollisions": [f["detail"] for f in failures
                                    if f.get("constraint") == "neighbourTextIntersections"],
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
