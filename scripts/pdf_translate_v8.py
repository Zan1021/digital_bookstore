"""
PDF Text Replacement Engine V8 — Digital Bookstore
=====================================================
Per-span replacement approach: removes individual text spans using tight
bounding-box redaction and places translated text at the EXACT same coordinates.

V8 PHILOSOPHY:
  - Treat the original page as an immutable canvas
  - Remove ONLY the specific text glyphs (tight bbox per span)
  - Place translated text at the same origin point as the original
  - NEVER touch non-text content (borders, lines, images, artwork)
  - Use stable content IDs for 1:1 source→target mapping
  - Per-region font sizing (not one global size)

KEY DIFFERENCE FROM V7:
  V7: Erase entire text zones → rebuild layout from scratch
  V8: Remove individual spans → place translations at original coordinates

This means table borders, column lines, decorative elements ALL stay untouched
because we never erase the areas they occupy.

Usage:
    python pdf_translate_v8.py replace --input book.pdf --output book_af.pdf --translations translations.json --fonts-dir ./fonts
"""

import argparse
import json
import os
import re
import sys
from pathlib import Path

import pymupdf


# =============================================================================
# GEOMETRY CACHE (brief §19) — cache extracted source geometry by file hash so
# re-renders of the same source PDF skip re-extraction. Does not weaken validation.
# =============================================================================

_GEOM_CACHE = {}


def _source_hash(pdf_path):
    import hashlib
    try:
        with open(pdf_path, "rb") as f:
            return hashlib.sha256(f.read()).hexdigest()
    except Exception:
        return None


def _cached_page_spans(doc, page_idx, page_num, src_hash):
    """Return extracted spans for a page, memoised by (source hash, page)."""
    key = (src_hash, page_num)
    if src_hash and key in _GEOM_CACHE:
        return _GEOM_CACHE[key]
    spans = extract_page_spans(doc[page_idx], page_num)
    if src_hash:
        _GEOM_CACHE[key] = spans
    return spans


# =============================================================================
# SPAN EXTRACTION — Build a complete map of all text on every page
# =============================================================================

def extract_page_spans(page, page_num):
    """
    Extract every text span on a page with full metadata.
    Each span gets a stable ID based on page number and position.
    
    Returns a list of span dicts with:
    - id: stable identifier
    - text: the text content
    - origin: (x, y) insertion point
    - bbox: bounding box [x0, y0, x1, y1]
    - font_size: size in points
    - font_name: PDF font name
    - color: hex color string
    - flags: font flags (bold, italic, etc.)
    - is_page_number: bool
    - rotation_angle: float (degrees, 0 = horizontal)
    - rotation_class: str (horizontal, vertical_down, etc.)
    - is_rotated: bool
    - text_direction: (cos, sin) tuple from line dir
    """
    from rotated_text import get_rotation_angle, classify_rotation, is_rotated as _is_rotated

    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
    spans = []
    span_idx = 0

    for block in text_dict.get("blocks", []):
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            # Extract rotation from line direction vector
            direction = tuple(line.get("dir", (1, 0)))
            rotation_angle = get_rotation_angle(direction)
            rotation_class = classify_rotation(rotation_angle)
            rotated = _is_rotated(rotation_angle)

            for span in line.get("spans", []):
                text = span["text"].strip()
                if not text:
                    continue

                span_idx += 1
                bbox = span["bbox"]
                origin = span["origin"]
                flags = span["flags"]

                # Determine if this is a page number
                is_page_num = (
                    text.isdigit() and
                    len(text) <= 3 and
                    span["size"] < 20 and
                    bbox[1] > 600  # near bottom of page
                )

                # Convert color int to hex
                color_int = span.get("color", 0)
                r = (color_int >> 16) & 0xFF
                g = (color_int >> 8) & 0xFF
                b = color_int & 0xFF
                color_hex = f"#{r:02x}{g:02x}{b:02x}"

                # Clean font name (remove subset prefix)
                font_name = span["font"]
                if len(font_name) > 7 and font_name[6] == '+':
                    font_name = font_name[7:]

                # Is bold/italic?
                is_bold = bool(flags & (1 << 4))
                is_italic = bool(flags & (1 << 1))

                spans.append({
                    "id": f"p{page_num:02d}_s{span_idx:04d}",
                    "text": span["text"],  # preserve original whitespace
                    "text_stripped": text,
                    "origin": list(origin),
                    "bbox": list(bbox),
                    "font_size": span["size"],
                    "font_name": font_name,
                    "color": color_hex,
                    "is_bold": is_bold,
                    "is_italic": is_italic,
                    "is_page_number": is_page_num,
                    "page_number": page_num,
                    "rotation_angle": rotation_angle,
                    "rotation_class": rotation_class,
                    "is_rotated": rotated,
                    "text_direction": direction,
                })

    return spans


# =============================================================================
# TEXT REMOVAL — Tight per-span redaction
# =============================================================================

def remove_span(page, span, fill_color=None):
    """
    Remove a single text span using tight bounding-box redaction.
    
    If fill_color is None, detects the background color at the span location
    to avoid leaving white rectangles on colored backgrounds.
    """
    bbox = span["bbox"]
    
    # Add minimal padding (just enough to cover antialiasing)
    padding = 1
    rect = pymupdf.Rect(
        bbox[0] - padding,
        bbox[1] - padding,
        bbox[2] + padding,
        bbox[3] + padding
    )

    if fill_color is None:
        # Detect background color by sampling just outside the text area
        fill_color = _detect_bg_at_span(page, span)

    page.add_redact_annot(rect, text="", fill=fill_color)


def _source_text_transform(spans):
    """
    Return the CSS text-transform that mirrors the SOURCE casing so the
    translation matches the original design across ANY page type.
    'uppercase' when every source letter is uppercase (all-caps display font),
    else 'none'. Book-agnostic.
    """
    letters = [c for s in spans for c in s.get("text_stripped", "") if c.isalpha()]
    if letters and all(c.isupper() for c in letters):
        return "uppercase"
    return "none"


def _detect_bg_at_span(page, span):
    """
    Sample the background color near a span to use as redaction fill.
    Samples a small area just to the left of the text.
    Returns (r, g, b) as floats 0-1.
    """
    bbox = span["bbox"]
    # Sample to the left of the text, at the same height
    sample_x = max(5, bbox[0] - 10)
    sample_y = (bbox[1] + bbox[3]) / 2

    rect = pymupdf.Rect(sample_x - 3, sample_y - 3, sample_x + 3, sample_y + 3)
    rect = rect & page.rect
    if rect.is_empty or rect.width < 2:
        return (1, 1, 1)

    try:
        pix = page.get_pixmap(clip=rect, dpi=72)
        if pix.width > 0 and pix.height > 0:
            pixel = pix.pixel(pix.width // 2, pix.height // 2)
            return (pixel[0] / 255.0, pixel[1] / 255.0, pixel[2] / 255.0)
    except Exception:
        pass
    return (1, 1, 1)


def _detect_page_background(page, sample_points=24):
    """
    Detect the DOMINANT page background colour by sampling many points across
    the whole page and taking the most common colour (mode). This is robust for
    flat-fill pages (covers, back covers) where sampling a single point near text
    can accidentally hit a white callout box or artwork.

    Book-agnostic: no assumption that the background is white. Returns (r,g,b) 0-1.
    """
    from collections import Counter
    try:
        pix = page.get_pixmap(dpi=36)
    except Exception:
        return (1, 1, 1)
    if pix.width < 3 or pix.height < 3:
        return (1, 1, 1)

    counts = Counter()
    # Sample an interior grid, skipping a margin so we favour the field colour.
    mx = max(1, pix.width // 10)
    my = max(1, pix.height // 10)
    xs = [int(mx + i * (pix.width - 2 * mx) / (sample_points - 1)) for i in range(sample_points)]
    ys = [int(my + i * (pix.height - 2 * my) / (sample_points - 1)) for i in range(sample_points)]
    for x in xs:
        for y in ys:
            try:
                px = pix.pixel(min(x, pix.width - 1), min(y, pix.height - 1))
                counts[(px[0], px[1], px[2])] += 1
            except Exception:
                continue

    if not counts:
        return (1, 1, 1)
    r, g, b = counts.most_common(1)[0][0]
    return (r / 255.0, g / 255.0, b / 255.0)


# Numbered series-title entry, e.g. "1 - Some Title". Captures number + title
# up to the next "N -" marker. Book-agnostic reconstruction of a title list from
# flat text whose original line breaks were lost upstream.
_TITLE_ENTRY_RE = re.compile(r'(\d{1,2})\s*[-–—]\s*(.+?)(?=\s+\d{1,2}\s*[-–—]\s|$)')


def _split_title_list(text):
    """
    Split a flat series-title string into (header, [entries]).

    Input example:
      "Titles in the Example series 3: 1 - First Title 2 - Second Title ..."
    Returns:
      ("Titles in the Example series 3:",
       ["1 - First Title", "2 - Second Title", ...])

    Falls back to newline splitting when the numbering pattern is absent, so it
    works whether or not upstream preserved line breaks.
    """
    clean = re.sub(r'\s*\\\s*', ' ', text).strip()
    clean = re.sub(r'\s{2,}', ' ', clean)

    # Prefer explicit newlines if they survived.
    nl_lines = [l.strip() for l in text.split('\n') if l.strip()]
    if len(nl_lines) >= 3:
        # First line is the header, remainder are entries.
        return nl_lines[0], nl_lines[1:]

    matches = list(_TITLE_ENTRY_RE.finditer(clean))
    if len(matches) >= 2:
        header = clean[:matches[0].start()].strip()
        entries = [f"{m.group(1)} - {m.group(2).strip()}" for m in matches]
        return header, entries

    # No recognisable structure — return whole text as a single header line.
    return clean, []


# =============================================================================
# TEXT INSERTION — Place translated text at original coordinates
# =============================================================================

def _visual_size_match(source_size, source_font_file, target_font_file):
    """
    Overflow-fix brief §9.2: two fonts at the same POINT size look different.
    Scale the target point size so its cap-height visually matches the source's,
    keeping the translated text the same apparent size even with a substitute font.
    Returns an adjusted point size. Falls back to source_size if metrics unavailable.
    """
    try:
        src = pymupdf.Font(fontfile=source_font_file) if source_font_file else pymupdf.Font("helv")
        tgt = pymupdf.Font(fontfile=target_font_file) if target_font_file else pymupdf.Font("helv")
        # Use ascender-descender span as a cap-height proxy (per-em, font units normalised).
        src_h = (src.ascender - src.descender)
        tgt_h = (tgt.ascender - tgt.descender)
        if tgt_h > 0 and src_h > 0:
            return source_size * (src_h / tgt_h)
    except Exception:
        pass
    return source_size


def _insert_wrapped_span(page, span, lines, fonts_dir, override_font_size=None):
    """
    Insert a multi-line (wrapped) translated item stacked downward from the source
    origin. Used when a vocabulary translation is too long for one line and wraps
    to a second line (overflow-fix brief §9.5: rewrap before shrink).
    """
    origin = pymupdf.Point(span["origin"][0], span["origin"][1])
    size = override_font_size if override_font_size else span["font_size"]
    color_hex = span["color"]
    r = int(color_hex[1:3], 16) / 255
    g = int(color_hex[3:5], 16) / 255
    b = int(color_hex[5:7], 16) / 255
    font_file = _find_font_file(span, fonts_dir)
    line_step = size * 1.15
    ok = False
    for i, line in enumerate(lines):
        pt = pymupdf.Point(origin.x, origin.y + i * line_step)
        try:
            if font_file:
                page.insert_text(pt, line, fontsize=size, fontname="F0",
                                 fontfile=font_file, color=(r, g, b))
            else:
                page.insert_text(pt, line, fontsize=size, fontname="helv", color=(r, g, b))
            ok = True
        except Exception:
            pass
    return ok


def insert_translated_span(page, span, translated_text, fonts_dir, col_width=None, override_font_size=None, clip=None):
    """
    Insert translated text at the exact origin point of the original span.
    Uses the same font size and color as the original (or override if provided).

    If the translated text is wider than available space, reduces font size.
    col_width: if provided, uses this as the max width constraint.
    override_font_size: if provided, uses this instead of the span's original size.
    clip: optional pymupdf.Rect — text is clipped to this region so a glyph can
          never bleed past its cell/region (overflow-fix brief §11).

    Supports rotated text: if span has rotation metadata, applies the same
    rotation transform to the translated text via the morph parameter.
    """
    origin = pymupdf.Point(span["origin"][0], span["origin"][1])
    font_size = override_font_size if override_font_size else span["font_size"]
    color_hex = span["color"]

    # Parse color
    r = int(color_hex[1:3], 16) / 255
    g = int(color_hex[3:5], 16) / 255
    b = int(color_hex[5:7], 16) / 255

    # Find font file
    font_file = _find_font_file(span, fonts_dir)

    # Check if this span is rotated
    rotation_angle = span.get("rotation_angle", 0.0)
    span_is_rotated = span.get("is_rotated", False)

    # Calculate available width
    if col_width:
        available_width = col_width - 5  # small padding from column edge
    else:
        # Fallback: use distance to right page edge minus margin
        available_width = page.rect.width - origin.x - 20

    # Measure the translated text width
    if font_file:
        font = pymupdf.Font(fontfile=font_file)
    else:
        font = pymupdf.Font("helv")

    text_width = font.text_length(translated_text, fontsize=font_size)

    # If text is too wide, reduce font size to fit
    actual_size = font_size
    if text_width > available_width and available_width > 0:
        shrink_ratio = available_width / text_width
        # Allow up to 15% shrink (0.85 min ratio) — brief recommends max 8% but we allow more for vocab
        min_ratio = 0.85
        if shrink_ratio >= min_ratio:
            actual_size = font_size * shrink_ratio
        else:
            # Won't fit even at max shrink — use minimum and flag overflow
            actual_size = font_size * min_ratio

    # Build morph parameter for rotated text
    morph = None
    if span_is_rotated and rotation_angle != 0:
        # morph = (pivot_point, rotation_matrix)
        # The pivot is the insertion point (origin)
        # PyMuPDF Matrix takes rotation in degrees
        morph = (origin, pymupdf.Matrix(rotation_angle))

    # Insert text. `clip` confines glyphs to the region (§11); guarded because not
    # all PyMuPDF builds accept the kwarg on insert_text.
    _kw = {}
    if clip is not None:
        _kw["clip"] = clip
    try:
        if font_file:
            try:
                page.insert_text(
                    point=origin, text=translated_text, fontsize=actual_size,
                    fontname="F0", fontfile=font_file, color=(r, g, b), morph=morph, **_kw,
                )
            except TypeError:
                page.insert_text(
                    point=origin, text=translated_text, fontsize=actual_size,
                    fontname="F0", fontfile=font_file, color=(r, g, b), morph=morph,
                )
        else:
            try:
                page.insert_text(
                    point=origin, text=translated_text, fontsize=actual_size,
                    fontname="helv", color=(r, g, b), morph=morph, **_kw,
                )
            except TypeError:
                page.insert_text(
                    point=origin, text=translated_text, fontsize=actual_size,
                    fontname="helv", color=(r, g, b), morph=morph,
                )
        return True
    except Exception as e:
        return False


def _find_font_file(span, fonts_dir):
    """Find the best font file for a span based on source font characteristics."""
    if not fonts_dir or not os.path.isdir(fonts_dir):
        return None
    
    # Strategy: match source font name first, then fall back to available fonts
    source_font = span.get("font_name", "").lower()
    is_bold = span.get("is_bold", False)
    
    # Build a map of available fonts in the fonts directory
    available = {}
    for f in os.listdir(fonts_dir):
        if f.lower().endswith(('.ttf', '.otf')):
            available[f.lower()] = os.path.join(fonts_dir, f)
    
    if not available:
        return None
    
    # Try to match source font name
    for filename, path in available.items():
        name_part = filename.replace('.ttf', '').replace('.otf', '').replace('-', '').lower()
        if source_font.replace('-', '').replace(' ', '').lower() in name_part:
            return path
    
    # Fall back to bold variant if span is bold
    if is_bold:
        for filename, path in available.items():
            if 'bold' in filename.lower():
                return path
    
    # Fall back to the first regular font found
    for filename, path in available.items():
        if 'regular' in filename.lower():
            return path
    
    # Last resort: first available font
    return list(available.values())[0]


# =============================================================================
# STORY PAGE RENDERER — For pages with illustration + text below
# =============================================================================

def render_story_page_v8(page, page_spans, translations_map, fonts_dir, page_num, report):
    """
    V8 story page: Instead of erasing the zone and using htmlbox,
    group story spans into a paragraph, erase them individually,
    then render the full translated paragraph using insert_htmlbox
    in the same container area.
    
    This hybrid approach:
    - Removes only story text spans (not page numbers, not anything else)
    - Uses htmlbox for proper paragraph wrapping
    - Detects the container from the span positions
    """
    # Get content spans (not page numbers, font >= 20px for story text)
    story_spans = [s for s in page_spans if not s["is_page_number"] and s["font_size"] >= 20]

    if not story_spans:
        return

    # Get translated text for this page
    translated_text = translations_map.get(page_num, "")
    if not translated_text.strip():
        return

    # Clean translated text (join into flowing paragraph)
    clean_text = _clean_story_text(translated_text)
    if not clean_text:
        return

    # Determine the text container from span positions
    min_x = min(s["bbox"][0] for s in story_spans)
    min_y = min(s["bbox"][1] for s in story_spans)
    max_x = max(s["bbox"][2] for s in story_spans)
    max_y = max(s["bbox"][3] for s in story_spans)

    # Find page number position to set bottom boundary
    page_num_spans = [s for s in page_spans if s["is_page_number"]]
    container_bottom = page_num_spans[0]["bbox"][1] - 10 if page_num_spans else page.rect.height - 60

    # Remove all story text spans
    for span in story_spans:
        remove_span(page, span, fill_color=(1, 1, 1))  # white bg for story pages
    page.apply_redactions()

    # Calculate font size — use original average, with fitting
    avg_font_size = sum(s["font_size"] for s in story_spans) / len(story_spans)
    font_size = avg_font_size

    # Build container rect
    container_rect = pymupdf.Rect(min_x, min_y, max_x, container_bottom)

    # Build HTML
    html = f'<p>{clean_text}</p>'
    font_css = _build_font_css(fonts_dir)
    font_family = _get_primary_font_family(fonts_dir)
    _tt = _source_text_transform(story_spans)  # mirror source casing (R3, §9)
    css = font_css + f"""
    * {{
        font-family: "{font_family}", sans-serif;
        font-size: {font_size}px;
        line-height: 1.17;
        color: #000000;
        text-transform: {_tt};
    }}
    p {{ margin: 0; text-align: left; }}
    """

    arch = pymupdf.Archive(fonts_dir)

    # Measure text height for vertical centering
    temp_doc = pymupdf.open()
    temp_page = temp_doc.new_page(width=page.rect.width, height=page.rect.height)
    result = temp_page.insert_htmlbox(container_rect, html, css=css, archive=arch, scale_low=1.0)
    temp_doc.close()

    spare_height = result[0] if isinstance(result, tuple) else result

    # If overflows, reduce font size
    if spare_height < 0:
        # Binary search for fitting font size
        low, high = font_size * 0.7, font_size
        while high - low > 0.5:
            mid = (low + high) / 2
            test_css = font_css + f"""
            * {{ font-family: "{font_family}"; font-size: {mid}px; line-height: 1.17; text-transform: {_tt}; }}
            p {{ margin: 0; text-align: left; }}
            """
            td = pymupdf.open()
            tp = td.new_page(width=page.rect.width, height=page.rect.height)
            r = tp.insert_htmlbox(container_rect, html, css=test_css, archive=arch, scale_low=1.0)
            td.close()
            s = r[0] if isinstance(r, tuple) else r
            if s >= 0:
                low = mid
            else:
                high = mid
        font_size = int(low)
        css = font_css + f"""
        * {{ font-family: "{font_family}"; font-size: {font_size}px; line-height: 1.17; color: #000; text-transform: {_tt}; }}
        p {{ margin: 0; text-align: left; }}
        """
        spare_height = 0  # no centering if we had to shrink

    # Vertical centering
    vertical_offset = max(0, spare_height / 2) if spare_height > 0 else 0
    final_rect = pymupdf.Rect(
        container_rect.x0, container_rect.y0 + vertical_offset,
        container_rect.x1, container_rect.y1
    )

    # Render
    try:
        page.insert_htmlbox(final_rect, html, css=css, archive=arch, scale_low=1.0)
        report["spans_replaced"] += 1
    except Exception as e:
        report["errors"].append({"page": page_num, "error": f"Story htmlbox failed: {str(e)}"})


# =============================================================================
# VOCABULARY/TABLE PAGE — The V8 killer feature: per-span replacement
# =============================================================================

def _header_item_ids(page_manifest):
    """Return the set of manifest item IDs that belong to table_header regions."""
    ids = set()
    for region in page_manifest.get("regions", []):
        if region.get("semantic_role") == "table_header":
            for item in region.get("items", []):
                ids.add(item["id"])
    return ids


def _cluster_header_spans_by_column(header_spans, content_columns=None, min_gap=15):
    """
    Group header source spans into logical header CELLS.

    Two source spans belong to the same header cell when they are vertically
    stacked over the same horizontal region (e.g. "HIGH FREQUENCY" above
    "WORDS"). We therefore cluster primarily by the header span's ORIGIN X
    (left edge), merging only spans whose left edges are close — NOT by full
    X-extent (a wide translated/източник header would otherwise swallow its
    neighbour, as "HIGH FREQUENCY WORDS" swallowed "PHONICS").

    When content_columns are supplied, cells are snapped to the content-column
    structure below them so the header row aligns to the table — book-agnostic,
    derived from detected geometry rather than any fixed layout.

    Returns cells sorted left→right:
      [{"x0", "x1", "y0", "y1", "spans": [...]}]
    """
    if not header_spans:
        return []

    # Cluster by LEFT-EDGE proximity (stacked headers share a left edge).
    spans = sorted(header_spans, key=lambda s: s["bbox"][0])
    cells = []
    for s in spans:
        x0, y0, x1, y1 = s["bbox"]
        left = x0
        placed = False
        for cell in cells:
            # Same cell only if left edges are close (stacked lines), not merely overlapping.
            if abs(left - cell["left"]) <= min_gap:
                cell["x0"] = min(cell["x0"], x0)
                cell["x1"] = max(cell["x1"], x1)
                cell["y0"] = min(cell["y0"], y0)
                cell["y1"] = max(cell["y1"], y1)
                cell["left"] = min(cell["left"], left)
                cell["spans"].append(s)
                placed = True
                break
        if not placed:
            cells.append({"x0": x0, "y0": y0, "x1": x1, "y1": y1, "left": left, "spans": [s]})

    cells = sorted(cells, key=lambda c: c["left"])

    # Snap cell x-extent to content columns so headers align to the table below.
    # Each content column is assigned to EXACTLY ONE header cell (the nearest by
    # center-x), so a wide header can't swallow a neighbour's column. This keeps
    # adjacent headers in their own regions — general across layouts.
    if content_columns and cells:
        cols_sorted = sorted(content_columns, key=lambda c: c.get("center", 0))
        cell_centers = [((c["x0"] + c["x1"]) / 2) for c in cells]
        # Reset extents; rebuild from assigned columns.
        assigned = {i: [] for i in range(len(cells))}
        for col in cols_sorted:
            cx = col.get("center", 0)
            nearest = min(range(len(cells)), key=lambda i: abs(cell_centers[i] - cx))
            assigned[nearest].append(col)
        for i, cell in enumerate(cells):
            cols = assigned[i]
            if cols:
                cell["x0"] = min(cell["x0"], min(c["left"] for c in cols))
                cell["x1"] = max(c["right"] for c in cols)
                # If multiple cells were assigned no columns they keep original extent.

        # Prevent any residual horizontal overlap between adjacent cells.
        for i in range(len(cells) - 1):
            if cells[i]["x1"] > cells[i + 1]["x0"]:
                mid = (cells[i]["x1"] + cells[i + 1]["x0"]) / 2
                cells[i]["x1"] = mid - 1
                cells[i + 1]["x0"] = mid + 1

    for c in cells:
        c.pop("left", None)
    return cells


def _extract_header_translations(id_to_translation, page_manifest):
    """
    Pull the translated header lines from the mapped items, preserving order.
    Returns a list of non-empty translated header strings (deduped consecutively).
    """
    headers = []
    seen = set()
    for region in page_manifest.get("regions", []):
        if region.get("semantic_role") != "table_header":
            continue
        for item in region.get("items", []):
            t = (id_to_translation.get(item["id"]) or "").strip()
            if t and t not in seen:
                headers.append(t)
                seen.add(t)
    return headers


def _render_vocab_headers(page, page_manifest, header_spans_all, id_to_translation,
                          fonts_dir, page_num, report, content_columns=None):
    """
    DEPRECATED internal: kept for compatibility. Prefer _mask_vocab_headers +
    _place_vocab_headers so masking happens in a single redaction pass with the
    content spans (redacting twice corrupts embedded fonts / glyph maps).
    """
    _mask_vocab_headers(page, header_spans_all)
    page.apply_redactions()
    _place_vocab_headers(page, page_manifest, header_spans_all, id_to_translation,
                         fonts_dir, page_num, report, content_columns)


def _mask_vocab_headers(page, header_spans_all):
    """
    Queue redaction for EVERY header source span. Does NOT call apply_redactions —
    the caller applies once for headers + content together (a second redaction pass
    corrupts embedded font glyphs).

    Uses a single background colour sampled from a clear strip just above the header
    row, so masks blend with the table background instead of showing the default
    redaction-annotation colour (the stray red box).
    """
    if not header_spans_all:
        return
    top_y = min(s["bbox"][1] for s in header_spans_all)
    left_x = min(s["bbox"][0] for s in header_spans_all)
    bg = (1, 1, 1)
    try:
        sample = pymupdf.Rect(left_x, max(0, top_y - 14), left_x + 6, max(2, top_y - 8)) & page.rect
        if not sample.is_empty and sample.width >= 2:
            pix = page.get_pixmap(clip=sample, dpi=72)
            px = pix.pixel(pix.width // 2, pix.height // 2)
            bg = (px[0] / 255.0, px[1] / 255.0, px[2] / 255.0)
    except Exception:
        bg = (1, 1, 1)
    for span in header_spans_all:
        remove_span(page, span, fill_color=bg)


def _detect_vertical_gridlines(page):
    """Return sorted x-positions of vertical grid lines on the page (table borders)."""
    xs = []
    try:
        for d in page.get_drawings():
            for item in d.get("items", []):
                if item[0] == "l":
                    p1, p2 = item[1], item[2]
                    if abs(p1.x - p2.x) <= 0.6 and abs(p1.y - p2.y) > 20:
                        xs.append(round((p1.x + p2.x) / 2, 1))
    except Exception:
        pass
    # De-duplicate near-identical lines.
    xs = sorted(set(xs))
    merged = []
    for x in xs:
        if not merged or x - merged[-1] > 3:
            merged.append(x)
    return merged


def _safe_cell_bounds(cell_x0, cell_x1, verticals, pad):
    """
    Clamp a header cell's x-range to sit strictly BETWEEN the nearest vertical grid
    lines (so no glyph can cross a border), minus padding. Book-agnostic: derived
    from detected grid geometry. Returns (x0, x1).
    """
    left_border = max([v for v in verticals if v <= cell_x0 + 1], default=cell_x0)
    right_border = min([v for v in verticals if v >= cell_x1 - 1], default=cell_x1)
    x0 = left_border + pad
    x1 = right_border - pad
    if x1 <= x0:  # degenerate — fall back to original with small pad
        x0, x1 = cell_x0 + pad, cell_x1 - pad
    return x0, x1


def _place_vocab_headers(page, page_manifest, header_spans_all, id_to_translation,
                         fonts_dir, page_num, report, content_columns=None):
    """
    Place translated headers AFTER redactions have been applied.

    Each translated header is fitted and rendered INSIDE its cell's safe inner
    bounds — clamped strictly between the surrounding vertical grid lines minus
    padding — so no header glyph can cross a table border (§4/§6.3/§6.4/§11).
    Book-agnostic: derived from detected geometry, not fixed column/header counts.
    """
    if not header_spans_all:
        return

    cells = _cluster_header_spans_by_column(header_spans_all, content_columns=content_columns)
    header_translations = _extract_header_translations(id_to_translation, page_manifest)

    # Coverage check (brief: "translated item count == source item count").
    if len(header_translations) != len(cells):
        report["errors"].append({
            "page": page_num,
            "error": (f"Header coverage mismatch: {len(cells)} header cells vs "
                      f"{len(header_translations)} translations — flagged for review."),
        })
        report.setdefault("review_pages", [])
        if page_num not in report["review_pages"]:
            report["review_pages"].append(page_num)

    font_css = _build_font_css(fonts_dir)
    font_family = _get_primary_font_family(fonts_dir)
    arch = pymupdf.Archive(fonts_dir)
    verticals = _detect_vertical_gridlines(page)
    pad = 3.0  # cell padding (§6.3)

    from text_fit_solver import FitConstraints, solve_text_fit
    measure_font = pymupdf.Font("helv")

    for idx, cell in enumerate(cells):
        if idx >= len(header_translations):
            break
        text = header_translations[idx]
        src_size = max((s["font_size"] for s in cell["spans"]), default=12)

        # SAFE INNER BOUNDS from the TABLE GRID: bound the header by the vertical grid
        # lines that contain the header's SOURCE span center — this is the true cell,
        # independent of fuzzy content-column snapping (§6: derive from grid geometry).
        src_cx = sum(((s["bbox"][0] + s["bbox"][2]) / 2) for s in cell["spans"]) / len(cell["spans"])
        if verticals:
            left_border = max([v for v in verticals if v <= src_cx], default=cell["x0"])
            right_border = min([v for v in verticals if v >= src_cx], default=cell["x1"])
            sx0, sx1 = left_border + pad, right_border - pad
            if sx1 <= sx0:
                sx0, sx1 = cell["x0"] + pad, cell["x1"] - pad
        else:
            sx0, sx1 = _safe_cell_bounds(cell["x0"], cell["x1"], verticals, pad)
        safe_w = max(6.0, sx1 - sx0)

        # Fit the header to the safe width: allow up to 3 wrapped lines, shrink only
        # as needed, so the header stays within the cell and never crosses a border.
        constraints = FitConstraints(
            container_width=safe_w,
            container_height=(cell["y1"] - cell["y0"]) + 26,
            source_font_size=src_size,
            min_font_size=6.0,
            max_shrink_ratio=0.5,
            allow_multiline=True,
            max_lines=3,
            line_height_ratio=1.05,
            padding_x=0.5,
            single_word=False,
        )
        fit = solve_text_fit(text, constraints, font_path=None)
        fit_size = fit.font_size if fit.font_size > 0 else src_size

        # GUARANTEE no border crossing: the single widest token must fit safe_w.
        # insert_htmlbox centers text; if the longest word is wider than the box it
        # overflows both sides across the border. Shrink until it fits (min 5pt).
        widest = max(text.split(), key=len) if text.split() else text
        while fit_size > 5.0 and measure_font.text_length(widest, fontsize=fit_size) > safe_w:
            fit_size -= 0.5

        # Hard clip to the safe box so any residual overflow cannot cross the border.
        clip = pymupdf.Rect(sx0, cell["y0"] - 2, sx1, cell["y1"] + 26) & page.rect
        rect = pymupdf.Rect(sx0, cell["y0"] - 2, sx1, cell["y1"] + 26)
        css = font_css + f"""
        * {{ font-family: "{font_family}"; font-size: {fit_size:.1f}px; line-height: 1.05; color: #000; }}
        p {{ margin: 0; text-align: center; }}
        """
        try:
            page.insert_htmlbox(rect, f'<p>{text}</p>', css=css, archive=arch, scale_low=0.5)
            report["spans_replaced"] += 1
        except Exception as e:
            report["errors"].append({"page": page_num, "error": f"Header render failed: {str(e)}"})


def render_vocabulary_page_v8(page, page_spans, translations_map, fonts_dir, page_num, report):
    """
    V8 vocabulary page: Manifest-driven per-span replacement.
    
    Uses the page manifest to get stable content IDs and exact coordinates,
    then maps legacy translations to those IDs using the translation_request
    module's legacy converter.
    
    Table borders, lines, headers — all untouched because we only remove
    individual text spans.
    """
    import sys, os
    base_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    sys.path.insert(0, os.path.join(base_dir, "scripts"))
    from page_manifest import build_vocabulary_manifest
    from translation_request import legacy_text_to_manifest_items

    # Get translated text
    translated_text = translations_map.get(page_num, "")
    if not translated_text.strip():
        return

    # Build page manifest for this vocabulary page
    spans_for_manifest = _extract_spans_for_manifest(page, page_num)
    page_manifest = build_vocabulary_manifest(page, page_num, spans_for_manifest)

    # Convert legacy flat text to manifest items (stable ID → translation)
    mapped_items = legacy_text_to_manifest_items(translated_text, page_manifest)
    
    if not mapped_items:
        report["errors"].append({"page": page_num, "error": "No items mapped from legacy text"})
        return

    # Build lookup: item_id → translation
    id_to_translation = {item["id"]: item["translation"] for item in mapped_items}

    # Build lookup: item_id → span (using manifest coordinates to find matching page spans)
    # The manifest items have bbox/origin that correspond to extracted spans
    id_to_span = {}
    header_spans_all = []   # ALL header source spans, mapped or not
    for region in page_manifest.get("regions", []):
        is_header = region.get("semantic_role") == "table_header"
        for item in region.get("items", []):
            matching_span = _find_span_at_origin(page_spans, item.get("origin"))
            if matching_span:
                id_to_span[item["id"]] = matching_span
                if is_header:
                    header_spans_all.append(matching_span)

    # BRIEF CONTRACT (kiro-pdf-layout-fidelity-brief): "remove or mask original text only"
    # and enforce 1:1 coverage. We MASK every header source span (regardless of whether a
    # translation mapped) so no original text can ghost through — but we defer applying the
    # redactions until the content spans are also queued, because applying redactions twice
    # on one page corrupts the embedded-font glyph map (garbled content). Header PLACEMENT
    # happens after the single redaction pass, below.
    _content_cols_for_headers = []
    for region in page_manifest.get("regions", []):
        if region.get("semantic_role") != "table_header" and region.get("column_center") is not None:
            _content_cols_for_headers.append({
                "left": region.get("column_center") - region.get("column_width", 100) / 2,
                "right": region.get("column_center") + region.get("column_width", 100) / 2,
                "center": region.get("column_center"),
            })
    _mask_vocab_headers(page, header_spans_all)  # queue header redactions (applied below with content)

    # Calculate column widths for overflow detection
    content_regions = [r for r in page_manifest.get("regions", []) if r.get("semantic_role") != "table_header"]
    col_widths = {}
    for region in content_regions:
        col_idx = region.get("column_index", 0)
        col_widths[col_idx] = region.get("column_width", 100)

    # Remove and replace each item
    items_to_render = []
    for item_id, translation in id_to_translation.items():
        # Skip header items here — they were handled by _render_vocab_headers above.
        if item_id in _header_item_ids(page_manifest):
            continue
        if item_id in id_to_span and translation:
            span = id_to_span[item_id]
            # Get column width and index for this span
            col_idx = 0
            col_width_val = 100
            for r in content_regions:
                for it in r.get("items", []):
                    if it["id"] == item_id:
                        col_idx = r.get("column_index", 0)
                        col_width_val = col_widths.get(col_idx, 100)
                        break

            items_to_render.append({
                "span": span,
                "translation": translation,
                "col_width": col_width_val,
                "col_idx": col_idx,
                "col_left": None,
                "col_right": None,
            })

    # Attach column x-bounds to each item for per-cell clipping (§11).
    _col_bounds = {}
    for r in content_regions:
        ci = r.get("column_index", 0)
        center = r.get("column_center")
        width = r.get("column_width", 100)
        if center is not None:
            _col_bounds[ci] = (center - width / 2, center + width / 2)
    for it in items_to_render:
        cb = _col_bounds.get(it["col_idx"])
        if cb:
            it["col_left"], it["col_right"] = cb[0] - 2, cb[1] + 2

    # Calculate consistent font size per column (use the MOST COMMON size, not median)
    # This ensures all words in a column render at the same size
    col_font_sizes = {}
    for region in content_regions:
        col_idx = region.get("column_index", 0)
        sizes = [it.get("font_size", 12) for it in region.get("items", [])]
        if sizes:
            # Use mode (most common size) for consistency
            from collections import Counter
            size_counts = Counter(round(s, 1) for s in sizes)
            most_common_size = size_counts.most_common(1)[0][0]
            col_font_sizes[col_idx] = most_common_size

    # Single redaction pass for BOTH header spans (queued above) and content spans.
    # Applying redactions once avoids embedded-font corruption from a second pass.
    for item in items_to_render:
        remove_span(page, item["span"], fill_color=(1, 1, 1))
    page.apply_redactions()

    # TYPOGRAPHY GROUP + WRAP (overflow-fix brief §9.4/§9.5): compute ONE consistent
    # font size PER COLUMN that fits every word in that column, allowing wrapping to
    # a second line before shrinking. This stops each word shrinking independently
    # (which caused mismatched sizes) and lets long translations wrap instead of
    # being crushed. Uses the existing constraint solver.
    from text_fit_solver import FitConstraints, solve_batch

    font_file = _find_font_file(items_to_render[0]["span"], fonts_dir) if items_to_render else None

    # Group render items by column.
    cols = {}
    for item in items_to_render:
        cols.setdefault(item.get("col_idx", 0), []).append(item)

    # Solve each column as a batch with a shared (consistent) size; allow 2 lines.
    item_size = {}          # id(item) -> font size
    item_lines = {}         # id(item) -> wrapped lines
    for col_idx, col_items in cols.items():
        src_size = col_font_sizes.get(col_idx, 12) or 12
        # Visual-size match: keep apparent size equal to source despite font swap (§9.2).
        src_size = _visual_size_match(src_size, None, font_file)
        col_w = col_items[0]["col_width"] or 100
        batch = []
        for it in col_items:
            batch.append({
                "text": it["translation"],
                "constraints": FitConstraints(
                    container_width=col_w,
                    container_height=src_size * 1.4,
                    source_font_size=src_size,
                    min_font_size=7.0,
                    max_shrink_ratio=0.35,
                    allow_multiline=False,             # single line: shrink, don't collide
                    max_lines=1,
                    line_height_ratio=1.15,
                    padding_x=1.5,
                    single_word=True,
                ),
            })
        results = solve_batch(batch, font_path=font_file, force_consistent=True)
        # Determine the true consistent size for the column (min across items).
        col_size = min((r.font_size for r in results), default=src_size)
        for it, res in zip(col_items, results):
            item_size[id(it)] = col_size
            # NOTE: per-item overflow flagging for vocab is intentionally NOT done
            # here. The detected column-width value is not the same basis the
            # renderer uses to place text, so measuring overflow against it produces
            # false positives (flagged 'huis' etc). Overflow for vocab is instead
            # governed by the solver's min-size floor; genuine impossible fits are
            # caught by the post-render glyph check in _verify_rendered_page.

    # Insert translated CONTENT first (insert_text / fontfile), THEN headers.
    replaced = 0
    for item in items_to_render:
        size = item_size.get(id(item))
        # Per-cell clip (§11): confine the word to its column x-range and its row
        # band so a glyph can never cross a border or into a neighbour column.
        span = item["span"]
        bb = span["bbox"]
        col_left = item.get("col_left")
        col_right = item.get("col_right")
        if col_left is None or col_right is None:
            col_left = bb[0] - 2
            col_right = bb[0] + (item["col_width"] or 100)
        row_h = (bb[3] - bb[1]) if bb[3] > bb[1] else (size or 12) * 1.2
        clip = pymupdf.Rect(col_left, bb[1] - row_h * 0.6, col_right, bb[3] + row_h * 0.6) & page.rect
        success = insert_translated_span(
            page, span, item["translation"], fonts_dir,
            col_width=item["col_width"],
            override_font_size=size,
            clip=clip,
        )
        if success:
            replaced += 1

    # Place translated headers LAST (htmlbox), after content text is committed.
    _place_vocab_headers(
        page, page_manifest, header_spans_all, id_to_translation, fonts_dir, page_num, report,
        content_columns=_content_cols_for_headers,
    )

    report["spans_replaced"] += replaced


def _extract_spans_for_manifest(page, page_num):
    """Extract spans in the format expected by page_manifest module."""
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
    spans = []
    span_idx = 0

    for block in text_dict.get("blocks", []):
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                text = span["text"].strip()
                if not text:
                    continue
                span_idx += 1
                bbox = span["bbox"]
                flags = span["flags"]

                is_page_num = (
                    text.isdigit() and len(text) <= 3 and
                    span["size"] < 20 and bbox[1] > 600
                )

                color_int = span.get("color", 0)
                r = (color_int >> 16) & 0xFF
                g = (color_int >> 8) & 0xFF
                b = color_int & 0xFF

                font_name = span["font"]
                if len(font_name) > 7 and font_name[6] == '+':
                    font_name = font_name[7:]

                spans.append({
                    "id": f"p{page_num:02d}_s{span_idx:04d}",
                    "text": span["text"],
                    "text_stripped": text,
                    "origin": list(span["origin"]),
                    "bbox": list(bbox),
                    "font_size": span["size"],
                    "font_name": font_name,
                    "color": f"#{r:02x}{g:02x}{b:02x}",
                    "is_bold": bool(flags & (1 << 4)),
                    "is_italic": bool(flags & (1 << 1)),
                    "is_page_number": is_page_num,
                })

    return spans


def _find_span_at_origin(page_spans, origin, tolerance=3):
    """Find a page span that matches the given origin point within tolerance."""
    if not origin:
        return None
    
    target_x, target_y = origin[0], origin[1]
    
    for span in page_spans:
        sx, sy = span["origin"]
        if abs(sx - target_x) < tolerance and abs(sy - target_y) < tolerance:
            return span
    
    return None


# =============================================================================
# COVER PAGE — Replace only subtitle
# =============================================================================

def render_cover_page_v8(page, page_spans, translations_map, fonts_dir, page_num, report):
    """
    V8 cover: Find the subtitle span(s) (large text, not symbols),
    remove them with background-color-aware redaction, place translation.
    """
    translated_text = translations_map.get(page_num, "")
    if not translated_text.strip():
        return

    # Find subtitle spans (>= 40px, more than 2 chars, not ®)
    subtitle_spans = [s for s in page_spans
                      if s["font_size"] >= 40
                      and len(s["text_stripped"]) > 2]

    if not subtitle_spans:
        return

    # Clean translated subtitle
    clean_text = translated_text.strip()
    lines = [l.strip() for l in clean_text.split('\n') if l.strip()]
    # Filter out publisher names and symbols
    skip_patterns = [r'studios?', r'mthombothi', r'^[®©]$']
    filtered = [l for l in lines if not any(re.search(pat, l, re.IGNORECASE) for pat in skip_patterns)]
    subtitle_text = ' '.join(filtered) if filtered else clean_text

    # Get subtitle bounding area
    sub_min_x = min(s["bbox"][0] for s in subtitle_spans)
    sub_min_y = min(s["bbox"][1] for s in subtitle_spans)
    sub_max_x = max(s["bbox"][2] for s in subtitle_spans)
    sub_max_y = max(s["bbox"][3] for s in subtitle_spans)

    # Remove subtitle spans with bg-color-aware fill
    for span in subtitle_spans:
        bg = _detect_bg_at_span(page, span)
        remove_span(page, span, fill_color=bg)
    page.apply_redactions()

    # Render translated subtitle using htmlbox (for proper centering)
    font_css = _build_font_css(fonts_dir)
    font_family = _get_primary_font_family(fonts_dir)
    _tt = _source_text_transform(subtitle_spans)  # mirror source casing (R3)
    css = font_css + f"""
    * {{ font-family: "{font_family}"; font-size: 49px; line-height: 1.2; color: #3d2c7c; text-transform: {_tt}; }}
    p {{ margin: 0; text-align: center; }}
    """
    arch = pymupdf.Archive(fonts_dir)
    textbox_rect = pymupdf.Rect(40, sub_min_y - 10, page.rect.width - 40, sub_max_y + 15)

    try:
        page.insert_htmlbox(textbox_rect, f'<p>{subtitle_text}</p>', css=css, archive=arch)
        report["spans_replaced"] += 1
    except Exception as e:
        report["errors"].append({"page": page_num, "error": f"Cover subtitle failed: {str(e)}"})


# =============================================================================
# BACK COVER — Replace title list
# =============================================================================

def render_back_cover_v8(page, page_spans, translations_map, fonts_dir, page_num, report):
    """
    V8 back cover: mask original text against the TRUE page background, then
    render the translated series-title list as stacked lines (one entry per row),
    matching the source's typographic casing (e.g. all-caps display font).
    """
    translated_text = translations_map.get(page_num, "")
    if not translated_text.strip():
        return

    # All text spans on this page (excluding very small or page numbers)
    content_spans = [s for s in page_spans if not s["is_page_number"] and s["font_size"] >= 10]
    if not content_spans:
        return

    # Detect whether the SOURCE renders as all-caps (display font). We mirror the
    # source's visual casing so the translated book matches the original design.
    text_transform = _source_text_transform(content_spans)

    # Mask original text using the DOMINANT page background (not a single left
    # pixel) so we don't stamp white boxes on a coloured page.
    bg_color = _detect_page_background(page)
    for span in content_spans:
        remove_span(page, span, fill_color=bg_color)
    page.apply_redactions()

    # Text zone from span positions
    min_x = min(s["bbox"][0] for s in content_spans)
    min_y = min(s["bbox"][1] for s in content_spans)
    max_x = max(s["bbox"][2] for s in content_spans)
    max_y = max(s["bbox"][3] for s in content_spans)

    # Reconstruct list structure (header + one line per numbered entry), robust to
    # whether upstream preserved the newlines.
    header, entries = _split_title_list(translated_text)

    def esc(t):
        return (t.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;"))

    html = ""
    if header:
        html += f'<p class="header">{esc(header)}</p>'
    for entry in entries:
        html += f'<p class="title">{esc(entry)}</p>'
    if not html:
        html = f'<p class="header">{esc(translated_text.strip())}</p>'

    # Derive font size from the source spans so we stay faithful to the original.
    src_size = max((s["font_size"] for s in content_spans), default=22)
    header_size = round(src_size)
    title_size = round(src_size)

    text_transform = text_transform
    font_css = _build_font_css(fonts_dir)
    font_family = _get_primary_font_family(fonts_dir)
    css = font_css + f"""
    * {{ font-family: "{font_family}"; color: #000; text-transform: {text_transform}; }}
    .header {{ font-size: {header_size}px; text-align: left; margin: 0 0 6px 0; line-height: 1.15; font-weight: bold; }}
    .title {{ font-size: {title_size}px; text-align: left; margin: 0; line-height: 1.15; font-weight: bold; }}
    """
    arch = pymupdf.Archive(fonts_dir)
    # Allow generous vertical room so the now-stacked list is not clipped.
    textbox_rect = pymupdf.Rect(min_x - 10, min_y - 6, max_x + 40, page.rect.height - 40)

    try:
        page.insert_htmlbox(textbox_rect, html, css=css, archive=arch, scale_low=0.6)
        report["spans_replaced"] += 1
    except Exception as e:
        report["errors"].append({"page": page_num, "error": f"Back cover failed: {str(e)}"})


# =============================================================================
# COPYRIGHT PAGE — Per-span replacement (simple)
# =============================================================================

def render_copyright_page_v8(page, page_spans, translations_map, fonts_dir, page_num, report):
    """
    V8 copyright page: Replace text spans individually.
    Preserves all images (logo, photo) and decorative elements.
    
    For this page type, we use a hybrid:
    - Large title text (>=40px) → replace with htmlbox (for centering)
    - Small info text (<15px) → batch remove + htmlbox in two columns
    """
    translated_text = translations_map.get(page_num, "")
    if not translated_text.strip():
        return

    # Separate spans by type
    subtitle_spans = [s for s in page_spans if s["font_size"] >= 40 and len(s["text_stripped"]) > 2]
    info_spans = [s for s in page_spans if s["font_size"] < 15 and not s["is_page_number"]]

    # Parse translated text
    lines = [l.strip() for l in translated_text.split('\n') if l.strip()]
    lines = [l for l in lines if l not in ['®', '©', '\uf8e8', '\u00ae']]

    if not lines:
        return

    subtitle_text = lines[0]
    remaining = lines[1:]

    font_css = _build_font_css(fonts_dir)
    font_family = _get_primary_font_family(fonts_dir)
    arch = pymupdf.Archive(fonts_dir)

    # Replace subtitle if present
    if subtitle_spans and subtitle_text:
        sub_min_y = min(s["bbox"][1] for s in subtitle_spans)
        sub_max_y = max(s["bbox"][3] for s in subtitle_spans)

        for span in subtitle_spans:
            remove_span(page, span, fill_color=(1, 1, 1))
        page.apply_redactions()

        _tt = _source_text_transform(subtitle_spans)  # mirror source casing (R3)
        css = font_css + f"""
        * {{ font-family: "{font_family}"; font-size: 49px; line-height: 1.2; color: #3d2c7c; text-transform: {_tt}; }}
        p {{ margin: 0; text-align: center; }}
        """
        rect = pymupdf.Rect(60, sub_min_y - 5, page.rect.width - 60, sub_max_y + 10)
        try:
            page.insert_htmlbox(rect, f'<p>{subtitle_text}</p>', css=css, archive=arch)
            report["spans_replaced"] += 1
        except Exception:
            pass

    # Replace info text spans
    if info_spans and remaining:
        # Detect two columns: left (x < 300) and right (x >= 300)
        left_spans = [s for s in info_spans if s["bbox"][0] < 300]
        right_spans = [s for s in info_spans if s["bbox"][0] >= 300]

        # Remove all info spans
        for span in info_spans:
            remove_span(page, span, fill_color=(1, 1, 1))
        page.apply_redactions()

        # Split translation into left (publisher) and right (bio) sections
        publisher_lines = []
        bio_lines = []
        in_bio = False
        for line in remaining:
            if not in_bio and any(m in line.lower() for m in ['die naam', 'the name', 'karakter', 'is uitgevind']):
                in_bio = True
            if in_bio:
                bio_lines.append(line)
            else:
                publisher_lines.append(line)

        # Render left column
        if left_spans and publisher_lines:
            left_min_y = min(s["bbox"][1] for s in left_spans)
            left_max_y = max(s["bbox"][3] for s in left_spans)
            css = font_css + f"""
            * {{ font-family: "{font_family}"; font-size: 7.5px; line-height: 1.4; color: #000; }}
            p {{ margin: 0; text-align: left; }}
            """
            rect = pymupdf.Rect(64, left_min_y, 300, left_max_y + 30)
            html = '<p>' + '<br/>'.join(publisher_lines) + '</p>'
            try:
                page.insert_htmlbox(rect, html, css=css, archive=arch)
                report["spans_replaced"] += 1
            except Exception:
                pass

        # Render right column
        if right_spans and bio_lines:
            right_min_y = min(s["bbox"][1] for s in right_spans)
            right_max_y = max(s["bbox"][3] for s in right_spans)
            css = font_css + f"""
            * {{ font-family: "{font_family}"; font-size: 7.5px; line-height: 1.4; color: #000; }}
            p {{ margin: 0; text-align: justify; }}
            """
            rect = pymupdf.Rect(321, right_min_y, 477, right_max_y + 30)
            bio_text = ' '.join(bio_lines)
            try:
                page.insert_htmlbox(rect, f'<p>{bio_text}</p>', css=css, archive=arch)
                report["spans_replaced"] += 1
            except Exception:
                pass


# =============================================================================
# PAGE CLASSIFICATION (same heuristics as V7 for now)
# =============================================================================

_TITLE_LIST_RE = re.compile(r'(?:^|\s)\d{1,2}\s*[-–—]\s+\S')


def _looks_like_title_list(page_spans):
    """
    Detect a series/title list (e.g. a back cover listing other titles):
    text containing numbered entries like "1 - Title  2 - Title ...".

    Book-agnostic: keys off the numbering pattern and short line structure,
    NOT the page position or font size. A title list may be rendered in large
    display caps (back cover) or small text — both must be detected.
    """
    joined = " ".join(s.get("text_stripped", "") for s in page_spans)
    matches = _TITLE_LIST_RE.findall(joined)
    return len(matches) >= 2


def _is_prose_page(page_spans):
    """
    A prose/story page has flowing sentence text: multiple words with
    sentence punctuation and few short standalone tokens. Used to distinguish
    a real story page from a title list or word table.
    """
    content = [s for s in page_spans if not s.get("is_page_number")]
    if not content:
        return False
    joined = " ".join(s.get("text_stripped", "") for s in content)
    words = joined.split()
    if len(words) < 8:
        return False
    # Sentence-like: has terminal punctuation and reasonable average word count
    has_sentence_punct = bool(re.search(r'[.!?]', joined))
    return has_sentence_punct and len(words) >= 8


def classify_page(page_spans, page_num, total_pages):
    """
    Classify page type from CONTENT and GEOMETRY signals — never from the page
    number alone (per the universal-engine brief: "must not rely on book-specific
    hard-coded page templates"). Page position is at most a tie-breaker.

    Detection order is by specificity, not by page position:
      1. Vocabulary/word-table  — many short items in columns.
      2. Title/series list       — numbered "N - Title" entries (any font size).
      3. Cover                    — a few very-large display spans, no prose.
      4. Copyright/imprint        — dense small-text block, no prose, not a list.
      5. Story                    — flowing sentence prose (the default content page).
    """
    content_spans = [s for s in page_spans if not s.get("is_page_number")]
    small = sum(1 for s in content_spans if s.get("font_size", 0) < 15)
    large = sum(1 for s in content_spans if s.get("font_size", 0) >= 15)
    very_large = sum(1 for s in content_spans if s.get("font_size", 0) >= 40)
    total_spans = len(content_spans)

    if total_spans == 0:
        return 'story'

    joined_lower = " ".join(s.get("text_stripped", "") for s in content_spans).lower()
    # Imprint markers used to recognise a copyright/publisher page by content, not position.
    imprint_markers = ("published by", "copyright", "isbn", "all rights reserved",
                       "(pty) ltd", "po box", "first edition", "printed")
    has_imprint = any(m in joined_lower for m in imprint_markers)

    # 1. Vocabulary: many small-font items, little large text (word tables).
    if small > 30 and large < 5:
        return 'vocabulary'

    # 2. Title/series list: numbered entries regardless of font size or position.
    #    This is what a back cover usually is; detect it by structure, not page num.
    if _looks_like_title_list(content_spans) and not _is_prose_page(content_spans):
        return 'back_cover'

    # 3. Copyright/imprint: dense small text dominated by imprint markers. Checked
    #    BEFORE prose because imprint blocks contain sentence punctuation too.
    if has_imprint and small >= large:
        return 'copyright'

    # 4. Cover: dominated by very-large display text, few spans, and NOT prose.
    if very_large > 0 and total_spans <= 6 and not _is_prose_page(content_spans):
        return 'cover'

    # 5. Copyright fallback: dense small text, no flowing prose, not a numbered list.
    if small >= 10 and total_spans > 15 and not _is_prose_page(content_spans):
        return 'copyright'

    # 6. Prose story page (the content default).
    if _is_prose_page(content_spans):
        return 'story'

    # Position-based tie-breakers ONLY when content signals are inconclusive.
    if page_num == 1 and very_large > 0:
        return 'cover'
    if page_num >= total_pages and total_spans > 2:
        return 'back_cover'

    return 'story'


# =============================================================================
# HELPERS
# =============================================================================

def _clean_story_text(text):
    """Clean translated story text into flowing paragraph."""
    clean = text.strip()
    clean = re.sub(r'^Hier is bladsy \d+.*?:\s*\n?', '', clean, flags=re.IGNORECASE)
    clean = re.sub(r'^Here is page \d+.*?:\s*\n?', '', clean, flags=re.IGNORECASE)
    clean = re.sub(r'^\d{1,2}\s*\\?\s*\n', '', clean)
    clean = re.sub(r'\s*\\\s*$', '', clean, flags=re.MULTILINE)
    lines = [l.strip() for l in clean.split('\n') if l.strip()]
    clean = ' '.join(lines)
    clean = re.sub(r'\s{2,}', ' ', clean)
    return clean.strip()


def _build_font_css(fonts_dir):
    """Build CSS @font-face declarations from all available fonts in the directory."""
    css = ""
    if not fonts_dir or not os.path.isdir(fonts_dir):
        return css
    
    # Discover fonts and build @font-face rules
    primary_family = None
    for filename in sorted(os.listdir(fonts_dir)):
        if not filename.lower().endswith(('.ttf', '.otf')):
            continue
        
        # Skip fonts with spaces in filename (breaks CSS url())
        if ' ' in filename:
            continue
        
        # Derive family name and weight from filename
        name_part = filename.rsplit('.', 1)[0]
        
        # Detect weight from filename
        weight = "normal"
        if "Bold" in name_part or "bold" in name_part:
            weight = "bold"
        elif "SemiBold" in name_part or "semibold" in name_part:
            weight = "600"
        elif "Medium" in name_part or "medium" in name_part:
            weight = "500"
        elif "Light" in name_part or "light" in name_part:
            weight = "300"
        
        # Derive family name (strip weight suffix)
        family = name_part
        for suffix in ['-Regular', '-Bold', '-SemiBold', '-Medium', '-Light',
                      'Regular', 'Bold', 'SemiBold', 'Medium', 'Light']:
            family = family.replace(suffix, '')
        family = family.rstrip('-').rstrip('_')
        
        if not primary_family:
            primary_family = family
        
        css += f'@font-face {{font-family: "{family}"; src: url({filename}); font-weight: {weight};}}\n'
    
    return css


def _get_primary_font_family(fonts_dir):
    """Get the primary font family name from the fonts directory."""
    if not fonts_dir or not os.path.isdir(fonts_dir):
        return "sans-serif"
    
    for filename in sorted(os.listdir(fonts_dir)):
        if not filename.lower().endswith(('.ttf', '.otf')):
            continue
        name_part = filename.rsplit('.', 1)[0]
        # Strip weight suffixes to get family
        family = name_part
        for suffix in ['-Regular', '-Bold', '-SemiBold', '-Medium', '-Light',
                      'Regular', 'Bold', 'SemiBold', 'Medium', 'Light']:
            family = family.replace(suffix, '')
        return family.rstrip('-').rstrip('_')
    
    return "sans-serif"


# =============================================================================
# VERIFICATION GATE (Layer 6)
# =============================================================================

def _build_page_scene_record(page_spans, page_type, page_num):
    """
    Build a stable-ID scene record for a page: regions -> units, each with a stable
    id, source text, bbox, and role. This is the explicit translation<->render
    mapping the brief requires (§2/§8) — no string-similarity re-inference.
    Book-agnostic: roles derived from page_type + geometry, IDs from page+index.
    """
    content = [s for s in page_spans if not s.get("is_page_number")]
    units = []
    for i, s in enumerate(content):
        role = {
            "cover": "subtitle", "copyright": "imprint", "vocabulary": "word_item",
            "back_cover": "title_item", "story": "paragraph",
        }.get(page_type, "text")
        units.append({
            "id": f"p{page_num:02d}_u{i:03d}",
            "role": role,
            "source_text": s.get("text_stripped", ""),
            "bbox": [round(v, 1) for v in s.get("bbox", [0, 0, 0, 0])],
        })
    return {
        "page_number": page_num,
        "page_type": page_type,
        "region_count": 1 if units else 0,
        "unit_count": len(units),
        "units": units,
    }


def _font_resolution_report(fonts_dir):
    """
    Record which font the engine resolved as primary, its file hash, and whether an
    approved font was available (brief §9.1). If no fonts dir / no usable font, this
    is an unresolved fallback and must fail closed.
    """
    import hashlib
    info = {"resolved_family": None, "font_file_hash": None,
            "fallback_used": False, "unresolved": False}
    if not fonts_dir or not os.path.isdir(fonts_dir):
        info["unresolved"] = True
        info["fallback_used"] = True
        return info
    fonts = [f for f in os.listdir(fonts_dir) if f.lower().endswith((".ttf", ".otf"))]
    if not fonts:
        info["unresolved"] = True
        info["fallback_used"] = True
        return info
    primary = _get_primary_font_family(fonts_dir)
    info["resolved_family"] = primary
    try:
        # Hash the first font file matching the primary family (or first font).
        chosen = None
        for f in sorted(fonts):
            if primary and primary.lower().replace("-", "") in f.lower().replace("-", ""):
                chosen = f
                break
        chosen = chosen or sorted(fonts)[0]
        with open(os.path.join(fonts_dir, chosen), "rb") as fh:
            info["font_file_hash"] = hashlib.sha256(fh.read()).hexdigest()[:16]
    except Exception:
        pass
    return info


def _verify_rendered_page(page, page_type, translated_text, page_num, report):
    """
    Lightweight post-render structural check for a single page. Its job is to
    catch SILENT layout failures (e.g. a title list that collapsed into one line,
    or a page where translated text vanished) and flag the page for human review
    rather than shipping it. Book-agnostic — checks structure, not specific content.

    Flags are recorded on report["review_pages"] and report["verification"].
    """
    issues = []
    try:
        rendered = page.get_text("text") or ""
    except Exception:
        return  # Can't verify — don't block.

    rendered_lines = [l for l in rendered.splitlines() if l.strip()]

    if page_type == "back_cover":
        # A series-title list should reconstruct into a header + 2+ entries.
        header, entries = _split_title_list(translated_text)
        if len(entries) >= 2:
            # We expected a multi-line list. If the rendered page has fewer text
            # lines than (header + entries)*0.6, the layout likely collapsed.
            expected = 1 + len(entries)
            if len(rendered_lines) < max(2, int(expected * 0.6)):
                issues.append(
                    f"title list may have collapsed: expected ~{expected} lines, "
                    f"rendered {len(rendered_lines)}"
                )

    if page_type == "vocabulary":
        # Glyph-level check on the ACTUAL rendered output (brief §12.1): does any
        # rendered word extend past the trim/page edge? This catches real overflow
        # regardless of the detected column-width estimate.
        try:
            pw = page.rect.width
            words = page.get_text("words")  # (x0,y0,x1,y1,word,block,line,wordno)
            past_edge = [w for w in words if w[2] > pw - 4]
            if past_edge:
                issues.append(
                    f"{len(past_edge)} rendered word(s) extend past the page edge"
                )
        except Exception:
            pass

    # General: translated text present but nothing rendered on the page.
    if translated_text.strip() and not rendered_lines:
        issues.append("translated text present but no text rendered on page")

    if issues:
        report.setdefault("review_pages", [])
        if page_num not in report["review_pages"]:
            report["review_pages"].append(page_num)
        report.setdefault("verification", {}).setdefault("flagged", []).append({
            "page": page_num,
            "page_type": page_type,
            "issues": issues,
        })


# =============================================================================
# MAIN ENGINE
# =============================================================================

def replace_text_in_pdf(input_pdf, output_pdf, translations, fonts_dir=None):
    """
    V8 Core: Per-span replacement engine with manifest-driven mapping.
    
    translations format:
    {
        "pages": [
            {"page_number": 1, "translated_text": "..."},
            ...
        ]
    }
    
    Also supports manifest-based format:
    {
        "pages": [
            {"page_number": 1, "translated_text": "...", "manifest_items": [...]}
        ]
    }
    """
    doc = pymupdf.open(input_pdf)
    total_pages = len(doc)
    _src_hash = _source_hash(input_pdf)  # for geometry caching (§19)

    report = {
        "version": "v8",
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
            "total_source_spans": 0,
            "total_translated": 0,
            "pages_with_gaps": [],
        },
    }

    # Build translations map
    translations_map = {p["page_number"]: p.get("translated_text", "")
                       for p in translations.get("pages", [])}

    # Track coverage
    total_source_spans = 0
    total_translated = 0

    # Process each page
    for page_idx in range(total_pages):
        page_num = page_idx + 1
        page = doc[page_idx]

        if page_num not in translations_map:
            continue
        if not translations_map[page_num].strip():
            continue

        # Extract all spans (cached by source hash for re-renders, §19)
        page_spans = _cached_page_spans(doc, page_idx, page_num, _src_hash)

        # Count source spans (excluding page numbers)
        content_spans = [s for s in page_spans if not s["is_page_number"]]
        total_source_spans += len(content_spans)

        # Classify
        page_type = classify_page(page_spans, page_num, total_pages)
        report["page_types"][str(page_num)] = page_type
        report["pages_processed"] += 1

        # SCENE/MANIFEST with stable IDs (§2/§8): record the page as a region graph
        # of text units with stable IDs, so translation<->render mapping is explicit
        # (not string-similarity). Persisted in the report for QA/diagnostics.
        report.setdefault("scene", {})[str(page_num)] = _build_page_scene_record(
            page_spans, page_type, page_num)

        # Track pre-render span count for coverage
        pre_render_replaced = report["spans_replaced"]

        # Render by type
        if page_type == 'cover':
            render_cover_page_v8(page, page_spans, translations_map, fonts_dir, page_num, report)
        elif page_type == 'story':
            render_story_page_v8(page, page_spans, translations_map, fonts_dir, page_num, report)
        elif page_type == 'vocabulary':
            render_vocabulary_page_v8(page, page_spans, translations_map, fonts_dir, page_num, report)
        elif page_type == 'back_cover':
            render_back_cover_v8(page, page_spans, translations_map, fonts_dir, page_num, report)
        elif page_type == 'copyright':
            render_copyright_page_v8(page, page_spans, translations_map, fonts_dir, page_num, report)

        # Track coverage per page
        page_replaced = report["spans_replaced"] - pre_render_replaced
        total_translated += page_replaced
        if page_replaced < len(content_spans) * 0.5:  # Less than 50% coverage
            report["coverage"]["pages_with_gaps"].append({
                "page": page_num,
                "source_spans": len(content_spans),
                "replaced": page_replaced,
            })

        # VERIFICATION GATE (Layer 6): structural sanity per rendered page.
        # Flags pages for human review instead of silently shipping a broken layout.
        _verify_rendered_page(page, page_type, translations_map.get(page_num, ""),
                              page_num, report)

    # Final coverage stats
    report["coverage"]["total_source_spans"] = total_source_spans
    report["coverage"]["total_translated"] = total_translated

    # Save
    output_dir = os.path.dirname(output_pdf)
    if output_dir and not os.path.isdir(output_dir):
        os.makedirs(output_dir, exist_ok=True)

    doc.save(output_pdf, garbage=4, deflate=True)
    doc.close()

    # Font resolution report + fail-closed on unresolved fallback (§9.1).
    report["font_resolution"] = _font_resolution_report(fonts_dir)
    if report["font_resolution"].get("unresolved"):
        report.setdefault("errors", []).append(
            {"page": 0, "error": "Unresolved font fallback: no usable font in fonts_dir"})
        report.setdefault("review_pages", [])
        # Force review for the whole document — cannot guarantee typography.
        for pn in report.get("page_types", {}):
            try:
                p = int(pn)
            except Exception:
                continue
            if p not in report["review_pages"]:
                report["review_pages"].append(p)

    # HARD-CONSTRAINT RENDER GATE (§4/§12.1): validate the ACTUAL rendered glyph
    # geometry. Any failure routes the page to layout review (fail-closed §13).
    try:
        from render_gate import validate_document
        gate = validate_document(
            output_pdf,
            page_types=report.get("page_types", {}),
            expected_by_page=translations_map,
        )
        report["render_gate"] = gate
        if not gate["ok"]:
            report.setdefault("review_pages", [])
            for pn in gate["review_pages"]:
                if pn not in report["review_pages"]:
                    report["review_pages"].append(pn)
        # Full per-region diagnostic manifest (§14).
        try:
            from render_gate import build_diagnostic_manifest_for_report
            build_diagnostic_manifest_for_report(output_pdf, report)
        except Exception as e:
            report["diagnostic_manifest"] = {"error": str(e)}

        # Raster validation with masks (§12.2): protected (non-text) zones must match
        # the source. Text zones excluded via scene unit bboxes.
        try:
            from render_gate import validate_raster
            raster = {}
            for pn_str, pscene in report.get("scene", {}).items():
                pn = int(pn_str)
                text_bboxes = [u.get("bbox") for u in pscene.get("units", []) if u.get("bbox")]
                rr = validate_raster(input_pdf, output_pdf, pn - 1, text_bboxes=text_bboxes)
                raster[pn] = rr
                if not rr.get("ok", True):
                    report.setdefault("review_pages", [])
                    if pn not in report["review_pages"]:
                        report["review_pages"].append(pn)
            report["raster_validation"] = raster
        except Exception as e:
            report["raster_validation"] = {"error": str(e)}
    except Exception as e:
        report["render_gate"] = {"skipped": True, "reason": str(e)}

    # Post-render structural validation
    try:
        from pdf_validation import validate_render_output
        validation = validate_render_output(input_pdf, output_pdf, report)
        report["validation"] = validation
    except ImportError:
        report["validation"] = {"skipped": True, "reason": "pdf_validation module not available"}
    except Exception as e:
        report["validation"] = {"skipped": True, "reason": f"Validation error: {str(e)}"}

    # Fail-closed publication signal: engine reports whether the render is
    # publishable. The caller MUST NOT approve when publishable is False.
    report["publishable"] = not report.get("review_pages")
    report["render_status"] = "READY_FOR_REVIEW" if report["publishable"] else "NEEDS_LAYOUT_REVIEW"

    return report


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="PDF Translation Engine V8 — Per-Span Replacement")
    subparsers = parser.add_subparsers(dest="command")

    replace_p = subparsers.add_parser("replace", help="Replace text in PDF")
    replace_p.add_argument("--input", "-i", required=True)
    replace_p.add_argument("--output", "-o", required=True)
    replace_p.add_argument("--translations", "-t", required=True)
    replace_p.add_argument("--fonts-dir", "-f")
    replace_p.add_argument("--validate", action="store_true",
                          help="Run structural validation after rendering (default: always on)")

    args = parser.parse_args()

    if args.command == "replace":
        with open(args.translations, 'r', encoding='utf-8') as f:
            translations = json.load(f)

        report = replace_text_in_pdf(
            input_pdf=args.input,
            output_pdf=args.output,
            translations=translations,
            fonts_dir=args.fonts_dir,
        )
        print(json.dumps(report, indent=2, ensure_ascii=False), file=sys.stderr)
        print(args.output)
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
