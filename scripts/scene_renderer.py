"""
Scene Graph Renderer — Digital Bookstore V8
=============================================
Renders translated text using the canonical DocumentScene as the source of truth.

This replaces the old flow of:
    extract_spans → classify_page → render_by_type (re-processing raw dicts)

With the new flow:
    DocumentScene → iterate pages → iterate regions → render by region type

The scene graph provides:
    - Page classification (already done at scene build time)
    - Regions with containers and policies
    - TextUnits with stable IDs, bboxes, styles
    - No re-extraction needed at render time

Translation input formats supported:
    1. ID-mapped (preferred): {unit_id: translated_text, ...}
    2. Legacy flat text: {page_number: "full page text"} — auto-mapped via IDs

Usage:
    from scene_renderer import render_from_scene
    from document_model import build_document_scene

    scene = build_document_scene("book.pdf")
    translations = {"p03_s0001": "Vertaalde teks", ...}  # ID-mapped
    report = render_from_scene(scene, "output.pdf", translations, fonts_dir="./fonts")
"""

import json
import os
import re
import sys
import time
from collections import Counter
from pathlib import Path

import pymupdf

# Ensure scripts dir is on path
SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPTS_DIR)

from document_model import (
    DocumentScene, PageScene, Region, TextUnit, TextStyle,
    RegionType, TranslationPolicy, build_document_scene,
)
from pdf_translate_v8 import (
    remove_span, insert_translated_span, _detect_bg_at_span,
    _build_font_css, _get_primary_font_family, _clean_story_text,
    _find_font_file,
)
from text_fit_solver import solve_text_fit, FitConstraints, FitResult
from glyph_preflight import preflight_document, preflight_single_text
from font_registry import FontRegistry


# =============================================================================
# MAIN ENTRY POINT
# =============================================================================

def render_from_scene(
    scene: DocumentScene,
    output_pdf: str,
    translations: dict,
    fonts_dir: str = None,
    legacy_page_text: dict = None,
) -> dict:
    """
    Render a translated PDF using the DocumentScene as the driving data model.

    Args:
        scene: The canonical DocumentScene (source of truth)
        output_pdf: Path to write the rendered PDF
        translations: Dict mapping unit_id → translated_text (ID-mapped format)
        fonts_dir: Path to fonts directory
        legacy_page_text: Optional dict of {page_number: "flat text"} for backward compat.
                         If translations is empty but this is provided, we auto-map
                         legacy text to unit IDs.

    Returns:
        Render report dict with metrics, errors, coverage.
    """
    doc = pymupdf.open(scene.source_path)

    report = {
        "version": "v8-scene",
        "engine": "scene_renderer",
        "pages_processed": 0,
        "spans_replaced": 0,
        "page_types": {},
        "overflow_warnings": [],
        "errors": [],
        "font_info": {
            "primary_family": _get_primary_font_family(fonts_dir) if fonts_dir else "none",
            "fonts_dir": fonts_dir,
        },
        "coverage": {
            "total_translatable_units": len(scene.get_all_translatable_units()),
            "units_with_translations": 0,
            "units_rendered": 0,
            "pages_with_gaps": [],
        },
    }

    # Initialize font registry
    _font_registry = None
    _primary_font_path = None
    if fonts_dir and os.path.isdir(fonts_dir):
        _font_registry = FontRegistry(fonts_dir)
        _font_registry.scan()
        # Resolve the primary rendering font
        resolved = _font_registry.resolve_font(language="af", weight="regular")
        if resolved:
            _primary_font_path = resolved.filepath
            report["font_info"]["resolved_font"] = resolved.filename

    # If no ID-mapped translations but we have legacy text, convert it
    if not translations and legacy_page_text:
        translations = _map_legacy_to_ids(scene, legacy_page_text)

    # Validate translations against scene graph (Item 16: reject bad IDs)
    validation_result = _validate_translations(scene, translations)
    report["translation_validation"] = validation_result
    if validation_result["errors"]:
        # Log errors but don't abort — render what we can
        for err in validation_result["errors"][:5]:
            report["errors"].append({"page": 0, "error": f"Translation validation: {err}"})

    # Remove unknown IDs from translations (don't render garbage)
    valid_ids = _all_unit_ids(scene)
    translations = {uid: t for uid, t in translations.items() if uid in valid_ids}

    # Glyph preflight: verify all translated chars have glyphs in target font
    if _primary_font_path and translations:
        preflight = preflight_document(translations, _primary_font_path, block_on_missing=False)
        report["glyph_preflight"] = {
            "passed": preflight.passed,
            "coverage_pct": preflight.coverage_pct,
            "missing_count": preflight.chars_missing,
            "severity": preflight.severity,
        }
        if not preflight.passed:
            report["errors"].append({
                "page": 0,
                "error": f"Glyph preflight: {preflight.chars_missing} missing chars ({preflight.severity})",
            })

    # Count how many units have translations
    report["coverage"]["units_with_translations"] = sum(
        1 for uid in translations if uid in valid_ids
    )

    # Process each page that has translatable content
    for page_scene in scene.pages:
        page_num = page_scene.page_number
        page = doc[page_num - 1]

        # Check if this page has any translations
        page_unit_ids = {u.id for u in page_scene.get_translatable_units()}
        page_translations = {uid: t for uid, t in translations.items() if uid in page_unit_ids}

        if not page_translations:
            continue

        report["page_types"][str(page_num)] = page_scene.page_type
        report["pages_processed"] += 1

        pre_count = report["spans_replaced"]

        # Render by page type using scene-graph data
        try:
            _render_page_from_scene(
                page, page_scene, page_translations, fonts_dir, report
            )
        except Exception as e:
            report["errors"].append({
                "page": page_num,
                "error": f"Scene render failed: {str(e)}",
            })

        page_rendered = report["spans_replaced"] - pre_count
        report["coverage"]["units_rendered"] += page_rendered

        # Track coverage gaps
        if page_rendered < len(page_unit_ids) * 0.5:
            report["coverage"]["pages_with_gaps"].append({
                "page": page_num,
                "translatable_units": len(page_unit_ids),
                "rendered": page_rendered,
            })

    # Save output
    output_dir = os.path.dirname(output_pdf)
    if output_dir and not os.path.isdir(output_dir):
        os.makedirs(output_dir, exist_ok=True)

    doc.save(output_pdf, garbage=4, deflate=True)
    doc.close()

    # Post-render validation
    try:
        from pdf_validation import validate_render_output
        validation = validate_render_output(scene.source_path, output_pdf, report)
        report["validation"] = validation
    except (ImportError, Exception) as e:
        report["validation"] = {"skipped": True, "reason": str(e)}

    return report


# =============================================================================
# PAGE RENDERING — Dispatches by page type using scene data
# =============================================================================

def _render_page_from_scene(
    page, page_scene: PageScene, translations: dict, fonts_dir: str, report: dict
):
    """
    Render a single page using scene graph data.
    Dispatches to type-specific renderers based on page_scene.page_type.
    """
    page_type = page_scene.page_type

    if page_type == "story":
        _render_story_from_scene(page, page_scene, translations, fonts_dir, report)
    elif page_type == "vocabulary":
        _render_vocabulary_from_scene(page, page_scene, translations, fonts_dir, report)
    elif page_type == "cover":
        _render_cover_from_scene(page, page_scene, translations, fonts_dir, report)
    elif page_type == "copyright":
        _render_copyright_from_scene(page, page_scene, translations, fonts_dir, report)
    elif page_type == "back_cover":
        _render_back_cover_from_scene(page, page_scene, translations, fonts_dir, report)
    else:
        # Unknown page type — fall back to per-span replacement
        _render_per_span_fallback(page, page_scene, translations, fonts_dir, report)


# =============================================================================
# STORY PAGE — htmlbox paragraph rendering
# =============================================================================

def _render_story_from_scene(
    page, page_scene: PageScene, translations: dict, fonts_dir: str, report: dict
):
    """
    Story page: prose regions get htmlbox rendering.
    Uses scene graph regions to identify story text vs page numbers.
    """
    page_num = page_scene.page_number

    # Find story prose regions
    prose_regions = [r for r in page_scene.regions if r.region_type == "story_prose"]
    if not prose_regions:
        return

    # Collect all translatable units in prose regions
    prose_units = []
    for region in prose_regions:
        for uid in region.child_ids:
            unit = page_scene.unit_by_id(uid)
            if unit and unit.translation_policy == "translate":
                prose_units.append(unit)

    if not prose_units:
        return

    # Get translations for these units and join into paragraph
    translated_parts = []
    for unit in sorted(prose_units, key=lambda u: u.reading_order):
        if unit.id in translations:
            translated_parts.append(translations[unit.id])

    if not translated_parts:
        return

    # Join into flowing paragraph
    full_text = " ".join(translated_parts)
    clean_text = _clean_story_text(full_text)
    if not clean_text:
        return

    # Get container from region bboxes
    all_bboxes = [u.bbox for u in prose_units]
    min_x = min(b[0] for b in all_bboxes)
    min_y = min(b[1] for b in all_bboxes)
    max_x = max(b[2] for b in all_bboxes)
    max_y = max(b[3] for b in all_bboxes)

    # Extend to page number position for bottom boundary
    page_num_units = [u for u in page_scene.text_units if u.translation_policy == "preserve"]
    container_bottom = min(u.bbox[1] for u in page_num_units) - 10 if page_num_units else page_scene.height_pt - 60

    # Build span dicts for removal (renderer primitives expect span format)
    for unit in prose_units:
        span_dict = _unit_to_span_dict(unit, page_scene)
        remove_span(page, span_dict, fill_color=(1, 1, 1))
    page.apply_redactions()

    # Get average font size from units
    sizes = [_get_unit_font_size(unit, page_scene) for unit in prose_units]
    avg_font_size = sum(sizes) / len(sizes) if sizes else 24

    # Build container and render
    container_rect = pymupdf.Rect(min_x, min_y, max_x, container_bottom)
    html = f'<p>{clean_text}</p>'
    font_css = _build_font_css(fonts_dir)
    font_family = _get_primary_font_family(fonts_dir)
    css = font_css + f"""
    * {{
        font-family: "{font_family}", sans-serif;
        font-size: {avg_font_size}px;
        line-height: 1.17;
        color: #000000;
    }}
    p {{ margin: 0; text-align: left; }}
    """
    arch = pymupdf.Archive(fonts_dir)

    # Measure for vertical centering
    temp_doc = pymupdf.open()
    temp_page = temp_doc.new_page(width=page_scene.width_pt, height=page_scene.height_pt)
    result = temp_page.insert_htmlbox(container_rect, html, css=css, archive=arch, scale_low=1.0)
    temp_doc.close()

    spare_height = result[0] if isinstance(result, tuple) else result

    # If overflows, reduce font size via binary search
    font_size = avg_font_size
    if spare_height < 0:
        low, high = font_size * 0.7, font_size
        while high - low > 0.5:
            mid = (low + high) / 2
            test_css = font_css + f"""
            * {{ font-family: "{font_family}"; font-size: {mid}px; line-height: 1.17; }}
            p {{ margin: 0; text-align: left; }}
            """
            td = pymupdf.open()
            tp = td.new_page(width=page_scene.width_pt, height=page_scene.height_pt)
            r = tp.insert_htmlbox(container_rect, html, css=test_css, archive=arch, scale_low=1.0)
            td.close()
            s = r[0] if isinstance(r, tuple) else r
            if s >= 0:
                low = mid
            else:
                high = mid
        font_size = int(low)
        css = font_css + f"""
        * {{ font-family: "{font_family}"; font-size: {font_size}px; line-height: 1.17; color: #000; }}
        p {{ margin: 0; text-align: left; }}
        """
        spare_height = 0

    # Vertical centering
    vertical_offset = max(0, spare_height / 2) if spare_height > 0 else 0
    final_rect = pymupdf.Rect(
        container_rect.x0, container_rect.y0 + vertical_offset,
        container_rect.x1, container_rect.y1
    )

    try:
        page.insert_htmlbox(final_rect, html, css=css, archive=arch, scale_low=1.0)
        report["spans_replaced"] += 1
    except Exception as e:
        report["errors"].append({"page": page_num, "error": f"Story htmlbox failed: {str(e)}"})


# =============================================================================
# VOCABULARY PAGE — Per-span replacement using scene graph regions
# =============================================================================

def _render_vocabulary_from_scene(
    page, page_scene: PageScene, translations: dict, fonts_dir: str, report: dict
):
    """
    Vocabulary page: per-unit replacement driven by regions.
    Each word-list region has units with stable IDs — we replace each individually.
    Table borders, headers (if preserved) stay untouched.
    """
    page_num = page_scene.page_number

    # Get all word list and table regions
    content_regions = [
        r for r in page_scene.regions
        if r.region_type in ("word_list", "table_header", "table_cell",
                            "high_frequency_words", "phonics")
    ]

    if not content_regions:
        return

    # Collect units to render
    units_to_render = []
    for region in content_regions:
        for uid in region.child_ids:
            unit = page_scene.unit_by_id(uid)
            if unit and unit.id in translations and unit.translation_policy == "translate":
                units_to_render.append((unit, region))

    if not units_to_render:
        return

    # Calculate consistent font size per column (mode-based)
    col_sizes = {}
    for unit, region in units_to_render:
        col_idx = unit.column_index or 0
        size = _get_unit_font_size(unit, page_scene)
        if col_idx not in col_sizes:
            col_sizes[col_idx] = []
        col_sizes[col_idx].append(round(size, 1))

    col_mode_sizes = {}
    for col_idx, sizes in col_sizes.items():
        size_counts = Counter(sizes)
        col_mode_sizes[col_idx] = size_counts.most_common(1)[0][0]

    # Remove all spans that will be replaced
    for unit, region in units_to_render:
        span_dict = _unit_to_span_dict(unit, page_scene)
        remove_span(page, span_dict, fill_color=(1, 1, 1))
    page.apply_redactions()

    # Insert translated text at original positions using text_fit_solver
    replaced = 0
    overflow_units = []
    for unit, region in units_to_render:
        span_dict = _unit_to_span_dict(unit, page_scene)
        translated_text = translations[unit.id]

        # Use consistent column font size
        col_idx = unit.column_index or 0
        consistent_size = col_mode_sizes.get(col_idx, _get_unit_font_size(unit, page_scene))

        # Get container width from design_container_bbox (preferred) or region width
        if region.design_container_bbox:
            container_width = region.design_container_bbox[2] - region.design_container_bbox[0]
        else:
            container_width = region.width if region else unit.width

        # Use text_fit_solver for proper constraint-based fitting
        constraints = FitConstraints(
            container_width=container_width,
            container_height=unit.height * 1.5,  # Allow slight vertical overflow
            source_font_size=consistent_size,
            min_font_size=7.0,
            max_shrink_ratio=0.15,
            single_word=True,  # Vocabulary items are single words/phrases
            allow_multiline=False,
        )
        
        fit_result = solve_text_fit(translated_text, constraints, 
                                     font_path=_find_font_file(span_dict, fonts_dir) if fonts_dir else None)

        # Use the solver's recommended size
        render_size = fit_result.font_size if fit_result.font_size > 0 else consistent_size

        if fit_result.overflow:
            overflow_units.append({
                "unit_id": unit.id,
                "text": translated_text,
                "container_width": container_width,
                "overflow_amount": fit_result.overflow_amount,
            })

        success = insert_translated_span(
            page, span_dict, translated_text, fonts_dir,
            col_width=container_width,
            override_font_size=render_size,
        )
        if success:
            replaced += 1

    if overflow_units:
        report["overflow_warnings"].extend(overflow_units[:5])

    report["spans_replaced"] += replaced


# =============================================================================
# COVER PAGE — Subtitle replacement
# =============================================================================

def _render_cover_from_scene(
    page, page_scene: PageScene, translations: dict, fonts_dir: str, report: dict
):
    """
    Cover page: find subtitle region, remove its units, render with htmlbox.
    """
    page_num = page_scene.page_number

    # Find subtitle region
    subtitle_regions = [
        r for r in page_scene.regions
        if r.region_type in ("cover_subtitle", "cover_title")
    ]

    if not subtitle_regions:
        return

    # Collect subtitle units
    subtitle_units = []
    for region in subtitle_regions:
        for uid in region.child_ids:
            unit = page_scene.unit_by_id(uid)
            if unit and unit.id in translations:
                subtitle_units.append(unit)

    if not subtitle_units:
        return

    # Build translated subtitle text
    parts = [translations[u.id] for u in sorted(subtitle_units, key=lambda u: u.reading_order)]
    subtitle_text = " ".join(parts).strip()

    # Filter out publisher symbols
    skip_patterns = [r'studios?', r'mthombothi', r'^[®©]$']
    lines = [l.strip() for l in subtitle_text.split('\n') if l.strip()]
    filtered = [l for l in lines if not any(re.search(pat, l, re.IGNORECASE) for pat in skip_patterns)]
    subtitle_text = ' '.join(filtered) if filtered else subtitle_text

    # Get subtitle area
    all_bboxes = [u.bbox for u in subtitle_units]
    sub_min_y = min(b[1] for b in all_bboxes)
    sub_max_y = max(b[3] for b in all_bboxes)

    # Remove subtitle spans with bg-aware fill
    for unit in subtitle_units:
        span_dict = _unit_to_span_dict(unit, page_scene)
        bg = _detect_bg_at_span(page, span_dict)
        remove_span(page, span_dict, fill_color=bg)
    page.apply_redactions()

    # Render translated subtitle
    font_css = _build_font_css(fonts_dir)
    font_family = _get_primary_font_family(fonts_dir)
    css = font_css + f"""
    * {{ font-family: "{font_family}"; font-size: 49px; line-height: 1.2; color: #3d2c7c; }}
    p {{ margin: 0; text-align: center; }}
    """
    arch = pymupdf.Archive(fonts_dir)
    textbox_rect = pymupdf.Rect(40, sub_min_y - 10, page_scene.width_pt - 40, sub_max_y + 15)

    try:
        page.insert_htmlbox(textbox_rect, f'<p>{subtitle_text}</p>', css=css, archive=arch)
        report["spans_replaced"] += 1
    except Exception as e:
        report["errors"].append({"page": page_num, "error": f"Cover subtitle failed: {str(e)}"})


# =============================================================================
# COPYRIGHT PAGE
# =============================================================================

def _render_copyright_from_scene(
    page, page_scene: PageScene, translations: dict, fonts_dir: str, report: dict
):
    """
    Copyright page: subtitle + two-column info text.
    Uses region structure to identify left/right columns.
    """
    page_num = page_scene.page_number

    # Find subtitle region
    subtitle_regions = [r for r in page_scene.regions if r.region_type == "cover_subtitle"]
    info_regions = [r for r in page_scene.regions
                    if r.region_type in ("publisher_info", "character_bio", "copyright_text")]

    font_css = _build_font_css(fonts_dir)
    font_family = _get_primary_font_family(fonts_dir)
    arch = pymupdf.Archive(fonts_dir)

    # Handle subtitle
    if subtitle_regions:
        subtitle_units = []
        for region in subtitle_regions:
            for uid in region.child_ids:
                unit = page_scene.unit_by_id(uid)
                if unit and unit.id in translations:
                    subtitle_units.append(unit)

        if subtitle_units:
            parts = [translations[u.id] for u in sorted(subtitle_units, key=lambda u: u.reading_order)]
            subtitle_text = " ".join(parts).strip()

            all_bboxes = [u.bbox for u in subtitle_units]
            sub_min_y = min(b[1] for b in all_bboxes)
            sub_max_y = max(b[3] for b in all_bboxes)

            for unit in subtitle_units:
                span_dict = _unit_to_span_dict(unit, page_scene)
                remove_span(page, span_dict, fill_color=(1, 1, 1))
            page.apply_redactions()

            css = font_css + f"""
            * {{ font-family: "{font_family}"; font-size: 49px; line-height: 1.2; color: #3d2c7c; }}
            p {{ margin: 0; text-align: center; }}
            """
            rect = pymupdf.Rect(60, sub_min_y - 5, page_scene.width_pt - 60, sub_max_y + 10)
            try:
                page.insert_htmlbox(rect, f'<p>{subtitle_text}</p>', css=css, archive=arch)
                report["spans_replaced"] += 1
            except Exception:
                pass

    # Handle info regions (left = publisher, right = bio)
    left_regions = [r for r in info_regions if r.bbox[0] < page_scene.width_pt / 2]
    right_regions = [r for r in info_regions if r.bbox[0] >= page_scene.width_pt / 2]

    for regions, x_start, x_end, align in [
        (left_regions, 64, 300, "left"),
        (right_regions, 321, 477, "justify"),
    ]:
        if not regions:
            continue

        units = []
        for region in regions:
            for uid in region.child_ids:
                unit = page_scene.unit_by_id(uid)
                if unit and unit.id in translations:
                    units.append(unit)

        if not units:
            continue

        # Remove spans
        for unit in units:
            span_dict = _unit_to_span_dict(unit, page_scene)
            remove_span(page, span_dict, fill_color=(1, 1, 1))
        page.apply_redactions()

        # Build text
        parts = [translations[u.id] for u in sorted(units, key=lambda u: u.reading_order)]
        text = " ".join(parts)

        all_bboxes = [u.bbox for u in units]
        min_y = min(b[1] for b in all_bboxes)
        max_y = max(b[3] for b in all_bboxes)

        css = font_css + f"""
        * {{ font-family: "{font_family}"; font-size: 7.5px; line-height: 1.4; color: #000; }}
        p {{ margin: 0; text-align: {align}; }}
        """
        rect = pymupdf.Rect(x_start, min_y, x_end, max_y + 30)
        html = f'<p>{text}</p>'
        try:
            page.insert_htmlbox(rect, html, css=css, archive=arch)
            report["spans_replaced"] += 1
        except Exception:
            pass


# =============================================================================
# BACK COVER
# =============================================================================

def _render_back_cover_from_scene(
    page, page_scene: PageScene, translations: dict, fonts_dir: str, report: dict
):
    """
    Back cover: title list rendering using scene regions.
    """
    page_num = page_scene.page_number

    # Get all translatable units on this page
    content_units = [u for u in page_scene.get_translatable_units() if u.id in translations]
    if not content_units:
        return

    # Detect background color from first unit
    first_span = _unit_to_span_dict(content_units[0], page_scene)
    bg_color = _detect_bg_at_span(page, first_span)

    # Remove all content spans
    for unit in content_units:
        span_dict = _unit_to_span_dict(unit, page_scene)
        remove_span(page, span_dict, fill_color=bg_color)
    page.apply_redactions()

    # Build translated text (first = header, rest = titles)
    parts = [translations[u.id] for u in sorted(content_units, key=lambda u: u.reading_order)]

    if parts:
        header = parts[0]
        titles = parts[1:]
        html = f'<p class="header">{header}</p>'
        for title in titles:
            html += f'<p class="title">{title}</p>'
    else:
        return

    # Get text zone from unit positions
    all_bboxes = [u.bbox for u in content_units]
    min_x = min(b[0] for b in all_bboxes)
    min_y = min(b[1] for b in all_bboxes)
    max_x = max(b[2] for b in all_bboxes)
    max_y = max(b[3] for b in all_bboxes)

    font_css = _build_font_css(fonts_dir)
    font_family = _get_primary_font_family(fonts_dir)
    css = font_css + f"""
    * {{ font-family: "{font_family}"; color: #000; }}
    .header {{ font-size: 24px; text-align: center; margin-bottom: 20px; line-height: 1.2; }}
    .title {{ font-size: 24px; text-align: center; margin: 0; line-height: 1.2; }}
    """
    arch = pymupdf.Archive(fonts_dir)
    textbox_rect = pymupdf.Rect(min_x - 20, min_y - 5, max_x + 20, max_y + 30)

    try:
        page.insert_htmlbox(textbox_rect, html, css=css, archive=arch)
        report["spans_replaced"] += 1
    except Exception as e:
        report["errors"].append({"page": page_num, "error": f"Back cover failed: {str(e)}"})


# =============================================================================
# FALLBACK — Per-span replacement for unknown page types
# =============================================================================

def _render_per_span_fallback(
    page, page_scene: PageScene, translations: dict, fonts_dir: str, report: dict
):
    """
    Fallback renderer: replace each unit individually at its original position.
    Used for page types that don't have specialized renderers.
    """
    page_num = page_scene.page_number

    units_to_render = [
        u for u in page_scene.get_translatable_units()
        if u.id in translations
    ]

    if not units_to_render:
        return

    # Remove all
    for unit in units_to_render:
        span_dict = _unit_to_span_dict(unit, page_scene)
        remove_span(page, span_dict, fill_color=(1, 1, 1))
    page.apply_redactions()

    # Insert translations
    replaced = 0
    for unit in units_to_render:
        span_dict = _unit_to_span_dict(unit, page_scene)
        success = insert_translated_span(
            page, span_dict, translations[unit.id], fonts_dir,
        )
        if success:
            replaced += 1

    report["spans_replaced"] += replaced


# =============================================================================
# LEGACY TEXT MAPPING — Convert flat page text to ID-mapped translations
# =============================================================================

def _map_legacy_to_ids(scene: DocumentScene, legacy_page_text: dict) -> dict:
    """
    Map legacy flat-text translations to stable unit IDs.

    Strategy per page type:
    - story: All prose units get the full page text (joined into paragraph)
    - vocabulary: Split text into lines, match to units by reading order
    - cover/copyright/back_cover: Split by lines, map to units in order

    This is the backward-compat bridge until all translations are ID-mapped.
    """
    translations = {}

    for page_scene in scene.pages:
        page_num = page_scene.page_number
        text = legacy_page_text.get(page_num, "")
        if not text or not text.strip():
            continue

        translatable = page_scene.get_translatable_units()
        if not translatable:
            continue

        page_type = page_scene.page_type

        if page_type == "story":
            # Story: all units get the full text (renderer will join them)
            for unit in translatable:
                translations[unit.id] = text.strip()
        elif page_type == "vocabulary":
            # Vocabulary: split into lines, assign by reading order
            lines = [l.strip() for l in text.split('\n') if l.strip()]
            sorted_units = sorted(translatable, key=lambda u: u.reading_order)
            for i, unit in enumerate(sorted_units):
                if i < len(lines):
                    translations[unit.id] = lines[i]
        else:
            # Cover, copyright, back_cover: split lines, assign in order
            lines = [l.strip() for l in text.split('\n') if l.strip()]
            sorted_units = sorted(translatable, key=lambda u: u.reading_order)
            for i, unit in enumerate(sorted_units):
                if i < len(lines):
                    translations[unit.id] = lines[i]
                elif lines:
                    # More units than lines — use last line
                    translations[unit.id] = lines[-1]

    return translations


# =============================================================================
# HELPERS
# =============================================================================

def _unit_to_span_dict(unit: TextUnit, page_scene: PageScene) -> dict:
    """
    Convert a TextUnit back to the span dict format expected by V8 primitives.
    This is the adapter layer — the rendering primitives still work with dicts.
    """
    # Look up style for font info
    style = page_scene.styles.get(unit.style_id)

    font_size = style.nominal_size_pt if style else 12.0
    font_name = style.font.family if style and style.font else "unknown"
    is_bold = style.font.weight >= 700 if style and style.font else False
    color = "#{:02x}{:02x}{:02x}".format(
        int(style.fill_rgb[0] * 255),
        int(style.fill_rgb[1] * 255),
        int(style.fill_rgb[2] * 255),
    ) if style else "#000000"

    return {
        "id": unit.id,
        "text": unit.source_text,
        "text_stripped": unit.source_text.strip(),
        "origin": [unit.baseline[0], unit.baseline[1]],
        "bbox": list(unit.bbox),
        "font_size": font_size,
        "font_name": font_name,
        "color": color,
        "is_bold": is_bold,
        "is_italic": False,
        "is_page_number": unit.translation_policy == "preserve",
        "page_number": page_scene.page_number,
        "rotation_angle": unit.rotation_deg,
        "is_rotated": unit.rotation_deg != 0,
    }


def _get_unit_font_size(unit: TextUnit, page_scene: PageScene) -> float:
    """Get the font size for a unit from its style."""
    style = page_scene.styles.get(unit.style_id)
    if style:
        return style.nominal_size_pt
    # Fallback: estimate from bbox height
    return max(8.0, unit.height * 0.8)


def _all_unit_ids(scene: DocumentScene) -> set:
    """Get all unit IDs in the scene."""
    ids = set()
    for page in scene.pages:
        for unit in page.text_units:
            ids.add(unit.id)
    return ids


def _validate_translations(scene: DocumentScene, translations: dict) -> dict:
    """
    Validate translation IDs against the scene graph.
    
    Checks for:
    - Unknown IDs (not in scene) — will be stripped before rendering
    - Duplicate translations (same ID appears twice — shouldn't happen with dict)
    - Empty translations
    - Missing IDs (translatable units with no translation)
    
    Returns validation report dict.
    """
    all_ids = _all_unit_ids(scene)
    translatable_ids = {u.id for u in scene.get_all_translatable_units()}
    
    errors = []
    warnings = []
    
    # Check for unknown IDs
    unknown_ids = set(translations.keys()) - all_ids
    if unknown_ids:
        errors.append(f"Unknown IDs rejected ({len(unknown_ids)}): {sorted(list(unknown_ids))[:5]}")
    
    # Check for empty translations
    empty_ids = [uid for uid, text in translations.items() if not text or not text.strip()]
    if empty_ids:
        warnings.append(f"Empty translations ({len(empty_ids)}): {empty_ids[:5]}")
    
    # Check for missing translatable IDs
    provided_ids = set(translations.keys()) & translatable_ids
    missing_ids = translatable_ids - provided_ids
    if missing_ids:
        warnings.append(f"Missing translations ({len(missing_ids)}/{len(translatable_ids)} units)")
    
    return {
        "valid": len(errors) == 0,
        "errors": errors,
        "warnings": warnings,
        "unknown_ids_rejected": len(unknown_ids),
        "empty_translations": len(empty_ids),
        "coverage_pct": round(len(provided_ids) / len(translatable_ids) * 100, 1) if translatable_ids else 0,
    }


# =============================================================================
# CLI — For testing
# =============================================================================

def main():
    """CLI for testing scene-graph rendering."""
    import argparse

    parser = argparse.ArgumentParser(description="Scene Graph Renderer V8")
    parser.add_argument("--input", "-i", required=True, help="Source PDF path")
    parser.add_argument("--output", "-o", required=True, help="Output PDF path")
    parser.add_argument("--translations", "-t", required=True,
                       help="Translations JSON (ID-mapped or legacy format)")
    parser.add_argument("--fonts-dir", "-f", help="Fonts directory")

    args = parser.parse_args()

    # Build scene
    print(f"Building scene graph from: {args.input}")
    scene = build_document_scene(args.input)
    print(f"  Pages: {scene.total_pages}")
    print(f"  Translatable units: {len(scene.get_all_translatable_units())}")

    # Load translations
    with open(args.translations, 'r', encoding='utf-8') as f:
        trans_data = json.load(f)

    # Detect format: ID-mapped vs legacy
    if "pages" in trans_data:
        # Legacy format — convert
        print("  Detected legacy translation format, converting to ID-mapped...")
        legacy_map = {p["page_number"]: p.get("translated_text", "")
                     for p in trans_data["pages"]}
        translations = _map_legacy_to_ids(scene, legacy_map)
    elif "items" in trans_data:
        # ID-mapped format
        translations = {item["id"]: item["translation"] for item in trans_data["items"]}
    else:
        # Assume it's already a flat dict {id: text}
        translations = trans_data

    print(f"  Mapped translations: {len(translations)}")

    # Render
    print(f"\nRendering...")
    start = time.time()
    report = render_from_scene(scene, args.output, translations, fonts_dir=args.fonts_dir)
    elapsed = time.time() - start

    print(f"\nDone in {elapsed:.1f}s")
    print(f"  Pages processed: {report['pages_processed']}")
    print(f"  Spans replaced: {report['spans_replaced']}")
    print(f"  Coverage: {report['coverage']['units_rendered']}/{report['coverage']['total_translatable_units']}")

    if report['errors']:
        print(f"\n  Errors:")
        for err in report['errors']:
            print(f"    Page {err.get('page', '?')}: {err.get('error')}")

    print(json.dumps(report, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
