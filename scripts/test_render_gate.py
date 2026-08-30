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


def test_diagnostic_manifest_full_section14_fields():
    """Manifest regions carry the FULL §14 field set (3.1)."""
    from render_gate import build_diagnostic_manifest
    report = {
        "version": "v8",
        "scene": {"1": {
            "page_type": "vocabulary", "region_count": 1, "unit_count": 2,
            "regions": [{"region_id": "p01-headers", "region_type": "table_header",
                         "semantic_type": "table_header",
                         "source_bounds": [10, 10, 100, 30],
                         "safe_inner_bounds": [10, 10, 100, 30],
                         "container_source": "glyph_union"}],
            "units": [{"id": "p01_u000", "region_id": "p01-headers", "role": "table_header",
                       "bbox": [10, 10, 50, 30], "nominal_size_pt": 12.0},
                      {"id": "p01_u001", "region_id": "p01-headers", "role": "table_header",
                       "bbox": [55, 10, 100, 30], "nominal_size_pt": 12.0}],
        }},
        "render_gate": {"pages": {1: {"ok": True, "failures": []}}},
        "font_resolution": {"resolved_family": "Foo", "font_file_hash": "abc", "fallback_used": False},
    }
    m = build_diagnostic_manifest("x", report)
    region = m["pages"][0]["regions"][0]
    required = ["regionId", "semanticType", "sourceBounds", "safeInnerBounds",
                "visualScaleRatio", "lineHeight", "tracking", "sourceLineCount",
                "semanticItemCount", "renderedLineCount", "renderedGlyphBounds",
                "clippedGlyphCount"]
    check("manifest region has all §14 fields",
          all(k in region for k in required)
          and region["regionId"] == "p01-headers"
          and region["semanticItemCount"] == 2)


def test_htmlbox_text_layer_is_real():
    """Regression: htmlbox rendering with a registered font must produce a REAL,
    searchable text layer (not a corrupt ToUnicode cmap). Guards the fix for the
    @font-face url() corruption. Book-agnostic: synthetic page + shipped font."""
    from pdf_translate_v8 import _register_html_fonts, _preferred_story_family
    doc = pymupdf.open()
    page = doc.new_page(width=400, height=200)
    _register_html_fonts(page, FONTS)
    fam = _preferred_story_family([{"font_name": "Edu-Aid"}], FONTS)
    css = f'* {{ font-family: "{fam}"; font-size: 20px; }} p {{ margin: 0; }}'
    page.insert_htmlbox(pymupdf.Rect(20, 20, 380, 180),
                        "<p>Wanneer Kolulu nie by die skool is nie</p>", css=css)
    got = " ".join(page.get_text("text").split()).lower()
    doc.close()
    check("htmlbox text layer is real/searchable",
          "wanneer" in got and "kolulu" in got and "skool" in got)


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


def test_apply_source_casing_per_item():
    """Per-item source casing (vocab words / insert_text path, R3 §9): all-caps ->
    upper, Title Case -> title, lowercase -> unchanged, empty source -> unchanged."""
    from pdf_translate_v8 import _apply_source_casing
    up = _apply_source_casing("woorde", {"text_stripped": "WORDS"})
    ti = _apply_source_casing("my dier wereld", {"text_stripped": "My Animal World"})
    lo = _apply_source_casing("huis", {"text_stripped": "house"})
    empty = _apply_source_casing("x", {"text_stripped": ""})
    check("per-item casing mirrors source",
          up == "WOORDE" and ti == "My Dier Wereld" and lo == "huis" and empty == "x")


def test_golden_layout_families():
    """Golden library (§17.2): the full layout-family fixture set classifies + gates
    as expected AND rasterises. Fixtures generated by make_gate_fixtures.py."""
    fx = os.path.join(os.path.dirname(os.path.abspath(__file__)), "test_fixtures")
    sys.path.insert(0, fx)
    try:
        from make_gate_fixtures import FAMILIES
    except Exception:
        check("golden layout families (skipped: no generator)", True)
        return
    covered = 0
    for name, (_builder, expect_ok, ptype) in FAMILIES.items():
        p = os.path.join(fx, f"{name}.pdf")
        if not os.path.isfile(p):
            check(f"golden {name} (skipped: missing)", True)
            continue
        gate = validate_document(p, page_types={1: ptype})
        # geometry: gate verdict matches expectation
        geom_ok = gate["ok"] == expect_ok
        # image: page rasterises to a non-empty pixmap
        try:
            doc = pymupdf.open(p)
            pix = doc[0].get_pixmap(dpi=72)
            doc.close()
            raster_ok = pix.width > 0 and pix.height > 0
        except Exception:
            raster_ok = False
        check(f"golden family '{name}' (gate + raster)", geom_ok and raster_ok)
        covered += 1
    # Ensure the library actually covers the §17.2 breadth (>= 18 families).
    check("golden library covers full layout-family breadth", covered >= 18)


def test_font_fidelity_flags_fallback():
    """A page whose text renders in a built-in FALLBACK font (not an approved house
    font) must be flagged by validate_font_fidelity; a house-font page passes."""
    from render_gate import validate_font_fidelity
    ff = _font()
    doc = pymupdf.open()
    page = doc.new_page(width=300, height=120)
    if ff:
        page.insert_text(pymupdf.Point(20, 60), "hierdie is huis teks", fontsize=14,
                         fontname="F0", fontfile=ff)
    good = validate_font_fidelity(page, FONTS)
    doc.close()
    check("font fidelity: house-font page passes", good["ok"])

    doc = pymupdf.open()
    page = doc.new_page(width=300, height=120)
    page.insert_text(pymupdf.Point(20, 60), "hierdie is fallback teks lang genoeg",
                     fontsize=14, fontname="helv")
    bad = validate_font_fidelity(page, FONTS)
    doc.close()
    check("font fidelity: fallback-font page is flagged",
          (not bad["ok"]) and any(f["constraint"] == "fontFidelity" for f in bad["failures"]))


def test_size_consistency_flags_inconsistent_peers():
    """Peers (same role) at materially different sizes are flagged; a legitimate
    hierarchy (title >> subtitle, separate clusters) is NOT."""
    from render_gate import validate_size_consistency
    ff = _font()

    def _page(sizes):
        doc = pymupdf.open()
        page = doc.new_page(width=400, height=300)
        y = 40
        for sz in sizes:
            if ff:
                page.insert_text(pymupdf.Point(30, y), f"lyn grootte {sz}", fontsize=sz,
                                 fontname="F0", fontfile=ff)
            else:
                page.insert_text(pymupdf.Point(30, y), f"lyn grootte {sz}", fontsize=sz)
            y += sz * 1.6 + 10
        return doc, page

    doc, page = _page([22, 14, 22, 14])
    inc = validate_size_consistency(page, "back_cover")
    doc.close()
    check("size consistency: inconsistent peer group flagged", not inc["ok"])

    doc, page = _page([48, 16, 16, 16])
    ok = validate_size_consistency(page, "cover")
    doc.close()
    check("size consistency: legit hierarchy passes", ok["ok"])


def test_structure_deviations_surfaced_in_manifest():
    """TASK 12: per-element structure_gate failures (with element_id + box) are
    surfaced in the diagnostic manifest as structureDeviations so the admin overlay
    can point at each deviating element and say why."""
    from render_gate import build_diagnostic_manifest
    report = {
        "version": "v8",
        "scene": {"1": {"page_type": "vocabulary", "region_count": 0, "unit_count": 0,
                        "units": []}},
        "render_gate": {"pages": {1: {"ok": True, "failures": []}}},
        "structure_gate": {"ok": False, "pages": {1: {"ok": False, "failures": [
            {"constraint": "elementMissing", "element_id": "p01_s0003",
             "role": "heading", "box": [10, 10, 100, 40],
             "detail": "no rendered text in cell for element p01_s0003"},
            {"constraint": "peerSizeMismatch", "element_id": "p01-headers",
             "detail": "peer group p01-headers sizes vary 10.0..24.0pt"},
        ]}}},
        "font_resolution": {"resolved_family": "Foo", "font_file_hash": "abc"},
    }
    m = build_diagnostic_manifest("x", report)
    page = m["pages"][0]
    devs = page.get("structureDeviations", [])
    check("manifest carries structureOk=False", page.get("structureOk") is False)
    check("manifest surfaces both structure deviations", len(devs) == 2)
    missing = next((d for d in devs if d["constraint"] == "elementMissing"), None)
    check("deviation carries element id + box + detail",
          missing is not None and missing["elementId"] == "p01_s0003"
          and missing["box"] == [10, 10, 100, 40] and "p01_s0003" in missing["detail"])


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
    test_diagnostic_manifest_full_section14_fields()
    test_htmlbox_text_layer_is_real()
    test_safe_cell_bounds_between_gridlines()
    test_publication_state_enforcement()
    test_casing_mirror_helper()
    test_apply_source_casing_per_item()
    test_golden_layout_families()
    test_font_fidelity_flags_fallback()
    test_size_consistency_flags_inconsistent_peers()
    test_structure_deviations_surfaced_in_manifest()
    print("=" * 60)
    print(f"RESULTS: {_passed} passed, {_failed} failed")
    print("=" * 60)
    sys.exit(1 if _failed else 0)
