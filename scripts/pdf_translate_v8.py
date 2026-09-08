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


def remove_spans_preserving_background(page, spans, padding=1):
    """
    Remove a set of text spans by ERASING their glyphs (true text redaction)
    while leaving whatever is underneath — flat fill, gradient, or artwork —
    completely intact.

    This is the book-agnostic replacement for fill-rectangle masking: instead of
    guessing a background colour and stamping a solid box (which never matches a
    gradient/textured/JPEG-compressed background exactly, leaving a visible
    block), we redact with text removal only. Art and vector line-work are
    preserved (PDF_REDACT_IMAGE_NONE / PDF_REDACT_LINE_ART_NONE), so no image or
    drawing under the text is disturbed.

    Mirrors the proven vocabulary/phonics render path. Returns True if every
    span's source text is verified gone from the page after redaction.
    """
    if not spans:
        return True

    for span in spans:
        bbox = span["bbox"]
        rect = pymupdf.Rect(
            bbox[0] - padding,
            bbox[1] - padding,
            bbox[2] + padding,
            bbox[3] + padding,
        )
        if rect.is_empty or rect.is_infinite:
            continue
        # fill=False => no rectangle is painted; only the glyphs are removed.
        page.add_redact_annot(rect, fill=False)

    try:
        page.apply_redactions(
            images=getattr(pymupdf, "PDF_REDACT_IMAGE_NONE", 0),
            graphics=getattr(pymupdf, "PDF_REDACT_LINE_ART_NONE", 0),
            text=getattr(pymupdf, "PDF_REDACT_TEXT_REMOVE", 0),
        )
    except TypeError:
        # Older PyMuPDF signature: text removal is the default; still preserve art.
        page.apply_redactions(images=0, graphics=0)

    # Verify removal with a fresh extraction (not cached).
    post = page.get_text()
    for span in spans:
        srcw = (span.get("text_stripped") or "").strip()
        if srcw and srcw in post:
            return False
    return True


def remove_outline_duplicate_vectors(page, band, color_thresh=0.72, max_stroke_width=1.0, pad=3):
    """
    Hide vector "outline duplicate" glyphs left behind after a text span is
    redacted.

    Some source pages draw a decorative OUTLINE/EMBOSS copy of a title as vector
    line-art *in addition to* the live text glyphs. Text redaction (which we use
    to preserve real artwork) intentionally leaves line-art alone, so that outline
    copy of the ORIGINAL-language title remains visible as a ghost behind the
    translated title.

    We CANNOT use apply_redactions(graphics=REMOVE) to drop these: redaction
    graphics-removal is page-global and deletes ANY vector overlapping ANY redact
    rect — including a page-sized background fill — which blanks the whole cover.

    Instead we cover ONLY each ghost glyph box with a small filled rectangle in the
    LOCAL background colour, and ONLY when that local background is effectively
    uniform (a flat field), so the cover-up is invisible. This never disturbs any
    other drawing. Book-agnostic and FAIL-SAFE: if the ghost sits on a non-uniform
    area (gradient/artwork), or no ghost matches, we do nothing and leave the page
    untouched rather than risk covering real art.

    A drawing is treated as a ghost only when ALL hold:
      * stroke-only (no fill) — an outline, not a solid shape;
      * light stroke colour (min channel >= color_thresh) — an outline highlight;
      * hairline (stroke width <= max_stroke_width);
      * bbox fully CONTAINED within `band` (the redacted subtitle bbox).

    Returns the number of ghost boxes covered (0 if none / not safe).
    """
    band = pymupdf.Rect(band)

    def _light(color):
        return color is not None and min(color) >= color_thresh

    def _contained(r):
        return (r.x0 >= band.x0 - pad and r.y0 >= band.y0 - pad
                and r.x1 <= band.x1 + pad and r.y1 <= band.y1 + pad)

    victims = []
    for dr in page.get_drawings():
        r = pymupdf.Rect(dr["rect"])
        if r.is_empty or r.is_infinite:
            continue
        stroke_only = dr.get("type") == "s" and dr.get("fill") is None
        hairline = (dr.get("width") or 0) <= max_stroke_width
        if stroke_only and hairline and _light(dr.get("color")) and _contained(r):
            victims.append(r)

    if not victims:
        return 0

    # Determine the LOCAL background colour + how uniform the band is. We sample
    # the band and take the dominant colour; if it doesn't dominate, the field is
    # not flat and a cover-up would be visible -> bail out (fail safe).
    bg_rgb, dom_frac = _dominant_color_in_rect(page, band)
    if bg_rgb is None or dom_frac < 0.55:
        return 0
    fill = (bg_rgb[0] / 255.0, bg_rgb[1] / 255.0, bg_rgb[2] / 255.0)

    # Paint each ghost box with the flat background colour. Small inset avoids
    # nibbling neighbouring art; a hair of overpaint on a uniform field is invisible.
    covered = 0
    shape = page.new_shape()
    for r in victims:
        rr = pymupdf.Rect(r.x0 - 0.5, r.y0 - 0.5, r.x1 + 0.5, r.y1 + 0.5)
        shape.draw_rect(rr)
        covered += 1
    shape.finish(fill=fill, color=fill, width=0)
    shape.commit()
    return covered


def _dominant_color_in_rect(page, rect, dpi=150):
    """Return ((r,g,b) 0-255, dominant_fraction) for the pixels inside `rect`.
    Used to (a) get the flat background colour and (b) measure how uniform it is.
    Book-agnostic: no colour is assumed."""
    from collections import Counter
    rect = pymupdf.Rect(rect) & page.rect
    if rect.is_empty:
        return None, 0.0
    try:
        pix = page.get_pixmap(clip=rect, dpi=dpi)
    except Exception:
        return None, 0.0
    if pix.width < 2 or pix.height < 2:
        return None, 0.0
    c = Counter()
    step_x = max(1, pix.width // 120)
    step_y = max(1, pix.height // 120)
    total = 0
    for x in range(0, pix.width, step_x):
        for y in range(0, pix.height, step_y):
            p = pix.pixel(x, y)
            c[(p[0], p[1], p[2])] += 1
            total += 1
    if not total:
        return None, 0.0
    col, n = c.most_common(1)[0]
    return col, n / total


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


def _source_text_transform_apply(text, spans):
    """Apply the source-derived casing transform to a whole STRING (for the
    insert_text render path, which has no CSS text-transform). Book-agnostic:
    uppercases only when the source is all-caps; otherwise leaves text unchanged."""
    tt = _source_text_transform(spans)
    if tt == "uppercase":
        return (text or "").upper()
    return text


def _source_weight(spans):
    """
    Return the CSS font-weight ('bold'/'normal') matching the SOURCE spans' detected
    weight (brief §9: preserve original typography). Book-agnostic: uses the per-span
    is_bold flag the extractor reads from the PDF font descriptor. Bold when the
    majority of the (non-page-number) spans are bold.
    """
    spans = [s for s in (spans or []) if not s.get("is_page_number")]
    if not spans:
        return "normal"
    bold = sum(1 for s in spans if s.get("is_bold"))
    return "bold" if bold > len(spans) / 2 else "normal"


def _apply_source_casing(text, source_span):
    """
    Mirror a SINGLE source span's casing onto the translated string (R3, §9) for
    renderers that place text with insert_text (no CSS text-transform available,
    e.g. vocabulary word items). If the source token is all-caps, uppercase the
    translation; if it is Title Case, title-case the translation; otherwise leave
    it unchanged. Book-agnostic: derived from the source glyphs, not hardcoded.
    """
    src = (source_span or {}).get("text_stripped", "") if isinstance(source_span, dict) else ""
    letters = [c for c in src if c.isalpha()]
    if not letters or not text:
        return text
    if all(c.isupper() for c in letters):
        return text.upper()
    # Title Case: every alphabetic run in the source starts uppercase, rest lower,
    # and it is not a single all-lower token.
    words = [w for w in src.split() if any(c.isalpha() for c in w)]
    if words and all(w[:1].isupper() for w in words) and not all(c.isupper() for c in letters):
        return text.title()
    return text


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

def _cap_height_ratio(font):
    """
    Return a font's cap-height as a fraction of its point size, measured from the
    actual glyph geometry (brief §9.2: match APPARENT size, not point size).

    Cap height is the height of a capital letter (we probe 'H'); if unavailable we
    fall back to x-height ('x'), then to the ascender-descender span. Everything is
    normalised to a 1pt em so the value is a pure ratio. Book-agnostic — measured
    from the font, not hardcoded per family.
    """
    try:
        for probe in ("H", "E", "I"):  # cap-height probes
            try:
                bbox = font.glyph_bbox(ord(probe))
                h = abs(bbox.y1 - bbox.y0)
                if h > 0:
                    return h
            except Exception:
                continue
        for probe in ("x", "o", "n"):  # x-height fallback
            try:
                bbox = font.glyph_bbox(ord(probe))
                h = abs(bbox.y1 - bbox.y0)
                if h > 0:
                    return h
            except Exception:
                continue
    except Exception:
        pass
    # Last-resort proxy: ascender - descender (per em).
    try:
        span = font.ascender - font.descender
        if span > 0:
            return span
    except Exception:
        pass
    return 0.7  # conservative default cap-height ratio


def _visual_size_match(source_size, source_font_file, target_font_file):
    """
    Overflow-fix brief §9.2: two fonts at the same POINT size look different.
    Scale the target point size so its CAP-HEIGHT visually matches the source's,
    keeping the translated text the same apparent size even with a substitute font.

    Uses true cap-height (x-height / asc-desc fallback) measured from the glyph
    geometry — NOT the ascender-descender proxy. Falls back to source_size if the
    metrics are unavailable. Book-agnostic.
    """
    try:
        src = pymupdf.Font(fontfile=source_font_file) if source_font_file else pymupdf.Font("helv")
        tgt = pymupdf.Font(fontfile=target_font_file) if target_font_file else pymupdf.Font("helv")
        src_cap = _cap_height_ratio(src)
        tgt_cap = _cap_height_ratio(tgt)
        if tgt_cap > 0 and src_cap > 0:
            return source_size * (src_cap / tgt_cap)
    except Exception:
        pass
    return source_size


def _insert_wrapped_span(page, span, lines, fonts_dir, override_font_size=None, font_file=None):
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
    if font_file is None:
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


def insert_translated_span(page, span, translated_text, fonts_dir, col_width=None, override_font_size=None, clip=None, font_file=None, no_shrink=False):
    """
    Insert translated text at the exact origin point of the original span.
    Uses the same font size and color as the original (or override if provided).

    If the translated text is wider than available space, reduces font size.
    col_width: if provided, uses this as the max width constraint.
    override_font_size: if provided, uses this instead of the span's original size.
    clip: optional pymupdf.Rect — text is clipped to this region so a glyph can
          never bleed past its cell/region (overflow-fix brief §11).
    font_file: explicit font file to render with (e.g. the publisher house font);
          when None, falls back to source-matched font resolution.

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

    # Find font file (honor an explicitly passed house font; else source-matched).
    if font_file is None:
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

    # Measure the translated text width. Prefer HarfBuzz shaping (accurate: applies
    # kerning/ligatures/GPOS) over the pymupdf advance-sum approximation, so overflow
    # detection matches what the renderer actually produces (brief §9.3).
    if font_file:
        try:
            from text_shaping import accurate_text_width
            text_width = accurate_text_width(translated_text, font_file, font_size)
        except Exception:
            text_width = font.text_length(translated_text, fontsize=font_size)
    else:
        text_width = font.text_length(translated_text, fontsize=font_size)

    # If text is too wide, reduce font size to fit
    actual_size = font_size
    if not no_shrink and text_width > available_width and available_width > 0:
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

def render_story_page_v8(page, page_spans, translations_map, fonts_dir, page_num, report,
                         forced_size=None, measure_only=False):
    """
    V8 story page: Instead of erasing the zone and using htmlbox,
    group story spans into a paragraph, erase them individually,
    then render the full translated paragraph using insert_htmlbox
    in the same container area.

    forced_size: if provided, render at EXACTLY this font size (book-wide typography
      group consistency, §9.4 — every story page uses one size instead of each page
      shrinking independently). When None, the page fits its own size.
    measure_only: if True, do NOT modify the page; just return the largest font size
      that fits this page's container (used by the consistency pre-pass). Returns a
      float (or None if the page has no story content).
    """
    # Get content spans (not page numbers, font >= 20px for story text)
    story_spans = [s for s in page_spans if not s["is_page_number"] and s["font_size"] >= 20]

    if not story_spans:
        return None if measure_only else None

    # END-MARKER SEPARATION (spec Req 1.5/6.x): a document end-marker ("The End" /
    # "Die Einde") is its OWN element — not part of the prose paragraph. Detect it on
    # the SOURCE spans (book-agnostic _mark_end_markers) and exclude it from the prose
    # block; it is placed separately (centered at its source location) below.
    try:
        from document_model import _mark_end_markers as _mem
        _mem(story_spans, "story")
    except Exception:
        pass
    end_spans = [s for s in story_spans if s.get("is_end_marker")]
    prose_spans = [s for s in story_spans if not s.get("is_end_marker")]
    if prose_spans:
        story_spans = prose_spans

    # Get translated text for this page
    translated_text = translations_map.get(page_num, "")
    if not translated_text.strip():
        return None if measure_only else None

    # If an end-marker exists, peel its translation off the END of the flat text so it
    # is not glued to the prose. Book-agnostic: take the last short line (<=4 words)
    # when the preceding text ends a sentence — mirrors the source structure.
    end_marker_text = None
    if end_spans and not measure_only:
        _lines = [l.strip() for l in translated_text.strip().split("\n") if l.strip()]
        if len(_lines) >= 2 and len(_lines[-1].split()) <= 4:
            end_marker_text = _lines[-1]
            translated_text = "\n".join(_lines[:-1])
        else:
            # Single blob: split on the last sentence terminator, take a short tail.
            import re as _re
            m = list(_re.finditer(r"[.!?]\s+", translated_text.strip()))
            if m:
                tail = translated_text.strip()[m[-1].end():].strip()
                if tail and len(tail.split()) <= 4:
                    end_marker_text = tail
                    translated_text = translated_text.strip()[:m[-1].end()].strip()

    # Clean translated text (join into flowing paragraph)
    clean_text = _clean_story_text(translated_text)
    if not clean_text:
        return None if measure_only else None

    # Determine the text container from span positions
    min_x = min(s["bbox"][0] for s in story_spans)
    min_y = min(s["bbox"][1] for s in story_spans)
    max_x = max(s["bbox"][2] for s in story_spans)
    max_y = max(s["bbox"][3] for s in story_spans)

    # Find page number position to set bottom boundary
    page_num_spans = [s for s in page_spans if s["is_page_number"]]
    container_bottom = page_num_spans[0]["bbox"][1] - 10 if page_num_spans else page.rect.height - 60

    # Calculate font size — use original average as the ceiling.
    avg_font_size = sum(s["font_size"] for s in story_spans) / len(story_spans)
    font_size = avg_font_size

    # ASCENDER HEADROOM: insert_htmlbox aligns the first line's cap/ascender to the
    # box TOP, so with a tall-ascender (cursive) font the top of the first line gets
    # clipped when the box top sits on the source glyph top. Add headroom above the
    # text equal to a fraction of the font size (bounded so we don't overlap the
    # illustration above). Book-agnostic: derived from font size + available gap.
    top_gap_available = min_y  # space between page top and the text block
    headroom = min(max(6.0, avg_font_size * 0.45), max(0.0, top_gap_available - 2))
    box_top = max(0.0, min_y - headroom)

    # Build container rect (with top headroom for ascenders).
    container_rect = pymupdf.Rect(min_x, box_top, max_x, container_bottom)

    # Font resolution: use the house font FILE selected by detected source weight.
    # insert_text with an explicit fontfile is the reliable path (htmlbox falls back
    # to CharisSIL and/or corrupts the text layer on this PyMuPDF).
    clean_text = _source_text_transform_apply(clean_text, story_spans)
    font_file = _weight_aware_house_font(story_spans, fonts_dir)
    font_probe = pymupdf.Font(fontfile=font_file) if font_file else pymupdf.Font("helv")

    def _fits_at(size):
        """Return spare height (>=0 fits) for the given size, WITHOUT touching page.
        Measures a real wrap with the actual font file."""
        words = clean_text.split()
        lines = _wrap_paragraph(words, font_file, font_probe, size, container_rect.width)
        block_h = size * 1.17 * len(lines)
        return container_rect.height - block_h

    def _largest_fitting_size():
        """Binary-search the largest size (<= source avg) that fits this container."""
        if _fits_at(font_size) >= 0:
            return font_size
        low, high = font_size * 0.6, font_size
        while high - low > 0.5:
            mid = (low + high) / 2
            if _fits_at(mid) >= 0:
                low = mid
            else:
                high = mid
        return low

    # MEASURE-ONLY: return the largest size that fits, no rendering.
    if measure_only:
        return _largest_fitting_size()

    # Choose the render size: a book-wide forced size (consistency) if given and it
    # fits; otherwise this page's own largest fitting size.
    if forced_size is not None:
        font_size = forced_size if _fits_at(forced_size) >= 0 else _largest_fitting_size()
    else:
        font_size = _largest_fitting_size()

    # Remove all story text spans (only now that we're actually rendering).
    for span in story_spans:
        remove_span(page, span, fill_color=(1, 1, 1))  # white bg for story pages
    for span in end_spans:
        remove_span(page, span, fill_color=(1, 1, 1))  # also mask the source end-marker
    page.apply_redactions()

    # Vertical centering based on spare height at the chosen size.
    spare_height = _fits_at(font_size)
    vertical_offset = max(0, spare_height / 2) if spare_height and spare_height > 0 else 0
    final_rect = pymupdf.Rect(
        container_rect.x0, container_rect.y0 + vertical_offset,
        container_rect.x1, container_rect.y1
    )

    # Render with the reliable insert_text path (correct font + correct text layer).
    used = draw_paragraph_text(
        page, final_rect, clean_text, font_file, font_size,
        color=(0, 0, 0), line_height=1.17, align="left",
    )
    if used is not None:
        report["spans_replaced"] += 1
    else:
        report["errors"].append({"page": page_num, "error": "Story render produced no text"})

    # Place the END-MARKER as its OWN element (spec Req 1.5): a separate line at the
    # source end-marker's location, centered on that source box, in the same font.
    # It is NOT concatenated into the prose paragraph.
    if end_spans and end_marker_text:
        eb = [min(s["bbox"][0] for s in end_spans), min(s["bbox"][1] for s in end_spans),
              max(s["bbox"][2] for s in end_spans), max(s["bbox"][3] for s in end_spans)]
        em_size = max((s["font_size"] for s in end_spans), default=font_size)
        # Center horizontally on the source end-marker box; keep its source baseline row.
        em_rect = pymupdf.Rect(min(eb[0], container_rect.x0), eb[1] - 2,
                               max(eb[2], container_rect.x1), eb[3] + em_size)
        _emf = _weight_aware_house_font(end_spans, fonts_dir)
        draw_paragraph_text(
            page, em_rect, _apply_source_casing(end_marker_text, end_spans[0]) if end_spans else end_marker_text,
            _emf, round(em_size), color=(0, 0, 0), line_height=1.1,
            align=_infer_source_alignment(end_spans, em_rect.x0, em_rect.x1),
            min_size=round(em_size) * 0.7, clip=em_rect & page.rect,
        )
    return font_size


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
        # Accept both the scene-graph role ("heading") and the legacy role
        # ("table_header") so header extraction works across manifest builders.
        if region.get("semantic_role") not in ("table_header", "heading"):
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


def _infer_source_alignment(source_spans, cell_x0, cell_x1):
    """
    Infer the SOURCE header's horizontal alignment within its cell from glyph
    geometry (§9: mirror the original, don't impose our own).

    MULTI-LINE AWARE: for a stacked header, alignment is best read from the LEFT/RIGHT
    edges of each source LINE, not the combined bbox — a left-aligned block whose
    widest line nearly fills the cell would otherwise look "centered" by the combined
    left/right gap (the "HIGH FREQUENCY WORDS" bug). We therefore:
      - group spans into lines by y,
      - if every line shares the SAME left edge (within tol) -> "left",
      - else if every line shares the SAME right edge (within tol) -> "right",
      - else fall back to the combined left-gap vs right-gap comparison (center/…).
    Book-agnostic: pure geometry, no per-book constants.
    """
    if not source_spans:
        return "center"

    # Group into source lines by y-center proximity.
    heights = sorted((s["bbox"][3] - s["bbox"][1]) for s in source_spans)
    med_h = heights[len(heights) // 2] if heights else 12.0
    med_h = med_h if med_h > 1 else 12.0
    by_line = {}
    for s in source_spans:
        yc = (s["bbox"][1] + s["bbox"][3]) / 2
        key = round(yc / max(1.0, med_h * 0.6))
        by_line.setdefault(key, []).append(s)

    line_lefts = [min(s["bbox"][0] for s in ln) for ln in by_line.values()]
    line_rights = [max(s["bbox"][2] for s in ln) for ln in by_line.values()]
    tol = max(2.0, med_h * 0.35)

    if len(by_line) >= 2:
        # Consistent left edges across lines => left-aligned (regardless of widths).
        if (max(line_lefts) - min(line_lefts)) <= tol:
            return "left"
        # Consistent right edges across lines => right-aligned.
        if (max(line_rights) - min(line_rights)) <= tol:
            return "right"
        # Otherwise fall through to gap comparison (likely centered).

    s_left = min(line_lefts)
    s_right = max(line_rights)
    left_gap = max(0.0, s_left - cell_x0)
    right_gap = max(0.0, cell_x1 - s_right)
    total = left_gap + right_gap
    if total <= 1.0:
        return "center"
    diff = abs(left_gap - right_gap) / max(1.0, cell_x1 - cell_x0)
    if diff < 0.12:
        return "center"
    return "left" if left_gap < right_gap else "right"


def _manifest_source_to_translation(page_manifest, id_to_translation):
    """
    Build {normalized_source_text: [translation, ...]} from the page manifest joined
    with a plain id->translation map. Used when the caller supplies a flat id->str map
    (no rich contract), so per-span header assembly can still resolve each source
    span's translation. Buckets are ordered by manifest reading order to support
    duplicate source words (e.g. two "WORDS" headers). Book-agnostic.
    """
    out = {}
    if not id_to_translation:
        return out
    for region in page_manifest.get("regions", []):
        for item in region.get("items", []):
            key = _norm_lookup_key(item.get("text", ""))
            tr = id_to_translation.get(item.get("id"))
            if key and tr:
                out.setdefault(key, []).append(tr)
    return out


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

    # STRUCTURE-AWARE header cells (spec Task 1/5): detect the TRUE header cells,
    # including MERGED (column-spanning) cells and the full header-row box, from the
    # grid geometry — not fuzzy per-column clustering. This is what lets a merged
    # header ("WORDS" over 3 columns) be centered across its FULL span, and a multi-
    # line header be vertically centered in the header row without overflowing.
    from universal_containers import detect_table_grid, detect_header_cells
    grid = detect_table_grid(page)
    hdr = detect_header_cells(grid, header_spans_all) if grid is not None else None
    struct_cells = hdr.get("cells") if hdr else None
    header_row_box = hdr.get("header_row_box") if hdr else None

    # Translations for header cells, matched GEOMETRICALLY (per semantic review):
    # each header source span is assigned to exactly one cell by half-open center-x
    # containment, then a cell's translation is ASSEMBLED from its constituent spans'
    # own contract translations — ordered top->bottom, left->right — with hard line
    # breaks between source lines. This fixes the merged-cell swap (grouped cell text
    # like "HIGH FREQUENCY WORDS" has no single contract key) and preserves the
    # source's own line structure. Book-agnostic: pure geometry + the contract.
    header_translations = _extract_header_translations(id_to_translation, page_manifest)
    # Private, freshly-built bucket copy so header consumption never corrupts other
    # consumers of the contract (e.g. the manifest bridge).
    src_to_trans = _contract_source_to_translation(id_to_translation, page_num)
    # FALLBACK: render_vocabulary_page_v8 passes a PLAIN id->str map (not the rich
    # contract dict), so _contract_source_to_translation returns {}. Rebuild the
    # source_text -> [translation] buckets from the manifest itself: each manifest
    # item carries its source `text` and `id`; id_to_translation gives id->translation.
    # This is what lets per-span header assembly find each span's translation.
    if not src_to_trans:
        src_to_trans = _manifest_source_to_translation(page_manifest, id_to_translation)

    if not struct_cells:
        # No grid structure detected — nothing reliable to place. Flag for review.
        report.setdefault("review_pages", [])
        if page_num not in report["review_pages"]:
            report["review_pages"].append(page_num)
        return

    TOL = 6.0  # single tolerance, matching detect_header_cells

    def _spans_in_cell(cell):
        """Assign header spans to this cell by half-open center-x containment
        [cx0, cx1) with a single tolerance, so every span lands in exactly one cell
        (no double-claim on shared edges). Ordered top->bottom, left->right — the
        same key detect_header_cells/_cluster_header_spans use to group labels."""
        cx0, cx1 = cell["cell_box"][0], cell["cell_box"][2]
        inside = []
        for s in header_spans_all:
            cxc = (s["bbox"][0] + s["bbox"][2]) / 2
            # Cells are contiguous (shared edges), so a half-open interval partitions
            # every span into exactly one cell. Widen only the outermost edges by TOL
            # so a span hugging the table's left/right border isn't dropped.
            lo = cx0 - TOL if cell is struct_cells[0] else cx0
            hi = cx1 + TOL if cell is struct_cells[-1] else cx1
            if lo <= cxc < hi:
                inside.append(s)
        return sorted(inside, key=lambda z: (round(z["bbox"][1] / 4.0), z["bbox"][0]))

    def _assemble_cell_translation(cell_spans):
        """Assemble the translated header text from the cell's source spans, joining
        spans on DIFFERENT source lines with '\\n' (hard break, preserves the source's
        stacking) and spans on the SAME line with a space. Returns (text, matched_all).
        matched_all is False if any span had no contract translation (=> fail-closed)."""
        if not cell_spans:
            return "", False
        # Group spans into source lines by y proximity.
        med_h = sorted((s["bbox"][3] - s["bbox"][1]) for s in cell_spans)[len(cell_spans) // 2]
        med_h = med_h if med_h > 1 else 12.0
        lines_of_spans, cur_line, last_yc = [], [], None
        for s in cell_spans:
            yc = (s["bbox"][1] + s["bbox"][3]) / 2
            if last_yc is not None and (yc - last_yc) > med_h * 0.6:
                lines_of_spans.append(cur_line)
                cur_line = []
            cur_line.append(s)
            last_yc = yc
        if cur_line:
            lines_of_spans.append(cur_line)

        matched_all = True
        line_strs = []
        for ln in lines_of_spans:
            parts = []
            for s in ln:
                key = _norm_lookup_key(s.get("text_stripped", s.get("text", "")))
                if key and src_to_trans.get(key):
                    parts.append(src_to_trans[key].pop(0))
                else:
                    matched_all = False
            if parts:
                line_strs.append(" ".join(parts))
        return "\n".join(line_strs), matched_all

    pad = 3.0  # cell padding (§6.3)
    from text_fit_solver import FitConstraints, solve_text_fit

    for idx, cell in enumerate(struct_cells):
        cb = cell["cell_box"]           # TRUE cell box (spans merged columns)
        sb = cell.get("source_box", cb)  # tight source glyph box (multi-line aware)
        ry0 = header_row_box[1] if header_row_box else cb[1]
        ry1 = header_row_box[3] if header_row_box else cb[3]

        # Geometric span->cell assignment (exactly one cell per span).
        cell_spans = _spans_in_cell(cell)

        # Assemble this cell's translation from its own spans (hard-break aware).
        text, matched_all = _assemble_cell_translation(cell_spans)
        if not text:
            # Last-resort: reading-order translation, else the source text itself.
            text = header_translations[idx] if idx < len(header_translations) else cell.get("text", "")
        if not matched_all:
            # Fail-closed: a partially-assembled header must not ship silently.
            report.setdefault("review_pages", [])
            if page_num not in report["review_pages"]:
                report["review_pages"].append(page_num)
        if not text:
            continue

        # Safe inner box = the full cell minus padding. Merged header uses the FULL
        # spanned width, so it centers across all its columns (fixes off-center bug).
        sx0, sx1 = cb[0] + pad, cb[2] - pad
        safe_w = max(6.0, sx1 - sx0)

        # ALIGNMENT: mirror the SOURCE header's alignment inside its cell (§9), do NOT
        # force-center. Inferred from source glyph geometry vs the cell bounds.
        align = _infer_source_alignment(cell_spans, cb[0], cb[2]) if cell_spans else "center"

        # SOURCE LINE COUNT: how many visual lines did this header occupy in the
        # source? Preserved via the hard breaks in `text`; also used to seed sizing.
        src_lines = _count_source_lines(cell_spans)

        # Per-line size from the source glyph height (fall back to 12pt).
        src_size = (sb[3] - sb[1]) / max(1, src_lines) if (sb[3] - sb[1]) > 4 else 12
        if src_size < 5:
            src_size = 12

        constraints = FitConstraints(
            container_width=safe_w,
            container_height=(ry1 - ry0),
            source_font_size=src_size,
            min_font_size=6.0,
            max_shrink_ratio=0.5,
            allow_multiline=True,
            max_lines=max(3, src_lines + 1),
            line_height_ratio=1.05,
            padding_x=0.5,
            single_word=False,
        )
        fit = solve_text_fit(text, constraints, font_path=None)
        fit_size = fit.font_size if fit.font_size > 0 else src_size

        rect = pymupdf.Rect(sx0, ry0, sx1, ry1)
        clip = rect & page.rect
        # Header placed with the SOURCE alignment, vertically centered in the header
        # row. Font = house font by source weight (correct font + real text layer).
        font_file = _weight_aware_house_font(header_spans_all, fonts_dir)
        used = draw_paragraph_text(
            page, rect, text, font_file, round(fit_size),
            color=(0, 0, 0), line_height=1.05, align=align, min_size=6.0,
            valign="middle", clip=clip,
        )
        if used is not None:
            report["spans_replaced"] += 1
        else:
            report["errors"].append({"page": page_num, "error": "Header render produced no text"})


def _count_source_lines(cell_spans):
    """
    Count the distinct visual text lines a header cell's source spans occupy, by
    clustering their y-centers. Book-agnostic: a line break is a y-gap larger than
    ~60% of the median span height. Returns at least 1.
    """
    if not cell_spans:
        return 1
    heights = sorted((s["bbox"][3] - s["bbox"][1]) for s in cell_spans)
    med_h = heights[len(heights) // 2] if heights else 12.0
    centers = sorted((s["bbox"][1] + s["bbox"][3]) / 2 for s in cell_spans)
    lines = 1
    for prev, cur in zip(centers, centers[1:]):
        if cur - prev > med_h * 0.6:
            lines += 1
    return max(1, lines)


def _norm_lookup_key(s):
    """Normalize a source string for contract<->manifest matching: collapse
    whitespace, strip, lowercase. Book-agnostic."""
    import re as _re
    return _re.sub(r"\s+", " ", (s or "")).strip().lower()


def _contract_source_to_translation(id_to_translation, page_num):
    """
    Build {normalized_source_text: [translation, ...]} for the given page from the
    stable-ID contract. `id_to_translation` here is expected to be a dict of
    id -> {"translation":..., "source_text":..., "page_number":...} when the caller
    supplies rich items; if it is a plain id->str map we cannot recover source text,
    so return {} (caller falls back). Ordered buckets support duplicate source words.
    """
    out = {}
    if not id_to_translation:
        return out
    for iid, val in id_to_translation.items():
        if not isinstance(val, dict):
            return {}  # plain id->str map: no source text available for bridging
        if val.get("page_number") not in (None, page_num):
            continue
        src = _norm_lookup_key(val.get("source_text", ""))
        if not src:
            continue
        out.setdefault(src, []).append(val.get("translation", ""))
    return out


def _lookup_id_translation(id_to_translation, unit_id):
    """Return the translation string for a stable id from either a rich
    ({id:{translation,...}}) or plain ({id:str}) contract map. None if absent."""
    if not id_to_translation:
        return None
    val = id_to_translation.get(unit_id)
    if val is None:
        return None
    if isinstance(val, dict):
        return val.get("translation")
    return val


def _vocab_manifest_from_scene(page_scene, page_num):
    """Build the vocabulary page_manifest structure the renderer expects DIRECTLY from
    the scene graph, so item ids are the STABLE contract ids (p{page}_s{index}) and each
    item carries its true cell_box + geometry.

    Regions:
      - one 'table_header' region grouping all heading units;
      - one 'word_list_column' region per column_index for the body/phonics units.
    Each item: {id, text, origin, bbox, cell_box, semantic_role, align_h, align_v}.
    Book-agnostic: every value comes from the scene units, no per-book constants.
    """
    if page_scene is None:
        return None
    units = [u for u in getattr(page_scene, "text_units", [])
             if u.translation_policy in ("translate", "educational_adaptation")]
    if not units:
        return None

    def _item(u):
        bbox = list(u.bbox)
        it = {
            "id": u.id,
            "text": u.source_text,
            "origin": [bbox[0], bbox[1]],
            "bbox": bbox,
            "semantic_role": u.semantic_role,
        }
        if getattr(u, "cell_box", None) is not None:
            it["cell_box"] = list(u.cell_box)
        if getattr(u, "align_h", None):
            it["align_h"] = u.align_h
        if getattr(u, "align_v", None):
            it["align_v"] = u.align_v
        if getattr(u, "column_span", 1) and u.column_span != 1:
            it["column_span"] = u.column_span
        if getattr(u, "is_merged", False):
            it["is_merged"] = True
        return it

    header_units = [u for u in units if u.semantic_role in ("heading", "table_header")]
    body_units = [u for u in units if u not in header_units]

    regions = []
    if header_units:
        regions.append({
            "id": f"p{page_num:02d}-headers",
            "semantic_role": "table_header",
            "translation_policy": "translate_items",
            "items": [_item(u) for u in sorted(header_units, key=lambda z: (z.bbox[0], z.bbox[1]))],
        })

    # Group body units by column_index (fallback: cluster by x origin).
    cols = {}
    for u in body_units:
        ci = getattr(u, "column_index", None)
        if ci is None:
            ci = int(round(u.bbox[0] / 24.0))
        cols.setdefault(ci, []).append(u)

    for ci in sorted(cols.keys()):
        col_units = sorted(cols[ci], key=lambda z: (z.bbox[1], z.bbox[0]))  # top->bottom
        lefts = [u.bbox[0] for u in col_units]
        rights = [u.bbox[2] for u in col_units]
        # Prefer the cell_box x-extent when present (border-accurate).
        cbs = [u.cell_box for u in col_units if getattr(u, "cell_box", None)]
        if cbs:
            col_left = min(cb[0] for cb in cbs)
            col_right = max(cb[2] for cb in cbs)
        else:
            col_left, col_right = min(lefts), max(rights)
        regions.append({
            "id": f"p{page_num:02d}-col-{ci}",
            "semantic_role": "word_list_column",
            "translation_policy": "translate_items",
            "column_index": ci,
            "column_center": (col_left + col_right) / 2,
            "column_width": max(6.0, col_right - col_left),
            "items": [_item(u) for u in col_units],
        })

    return {
        "page_number": page_num,
        "page_type": "vocabulary",
        "builder": "scene_graph",
        "regions": regions,
    }


def render_vocabulary_page_from_scene(page, page_scene, id_to_translation, fonts_dir,
                                      page_num, report):
    """Book-agnostic vocabulary/phonics render driven ENTIRELY by the scene graph
    (ChatGPT-reviewed Option B). Identity + geometry come from scene units; text comes
    from the contract keyed by stable id. Does NOT use page_spans / _find_span_at_origin
    (whose baseline-vs-top origin convention differs from the scene bbox — the bug that
    left English on the page).

    Pipeline per the review:
      1. Resolve each renderable unit's translation by id (fail closed on missing).
      2. Redact every renderable unit's source bbox with TEXT REMOVAL, preserving
         images + line art (table grid lines survive). Apply once.
      3. Verify removal by fresh extraction.
      4. Group units sharing a cell into ordered slots; place each unit's translation
         in its slot within the cell_box (alignment, shrink-to-fit, explicit clip).
         insert failures / overflow => blank + flag (never English fallback).
      5. Record staged counters for verification.
    Returns True on a fully-placed page, False when the page was flagged for review.
    """
    units = page_scene.get_renderable_units() if hasattr(page_scene, "get_renderable_units") \
        else [u for u in page_scene.text_units
              if u.translation_policy in ("translate", "educational_adaptation")]
    if not units:
        return None  # not handled → caller may fall back to legacy path

    stages = {"resolved": 0, "redaction_verified": False, "inserted": 0,
              "units_total": len(units), "unresolved": [], "overflow": [], "mode": "scene_id"}

    # 1) Resolve translations by id (fail closed — never source text).
    resolved = {}   # unit.id -> translated string
    for u in units:
        tr = _lookup_id_translation(id_to_translation, u.id)
        if tr is not None and str(tr).strip() != "":
            resolved[u.id] = str(tr)
            stages["resolved"] += 1
        else:
            stages["unresolved"].append(u.id)

    # 2) Redact every renderable unit's source bbox: REMOVE text, PRESERVE art/lines.
    for u in units:
        rect = pymupdf.Rect(u.bbox)
        if rect.is_empty or rect.is_infinite:
            continue
        page.add_redact_annot(rect, fill=False)
    try:
        page.apply_redactions(images=getattr(pymupdf, "PDF_REDACT_IMAGE_NONE", 0),
                              graphics=getattr(pymupdf, "PDF_REDACT_LINE_ART_NONE", 0),
                              text=getattr(pymupdf, "PDF_REDACT_TEXT_REMOVE", 0))
    except TypeError:
        # Older signature: text removal is the default (text=0); still preserve art.
        page.apply_redactions(images=0, graphics=0)

    # 3) Verify source removal (fresh extraction, not cached).
    post = page.get_text()
    # Any renderable source word still present indicates removal failure for that unit.
    still_present = []
    for u in units:
        srcw = (u.source_text or "").strip()
        if srcw and srcw in post:
            still_present.append(u.id)
    stages["redaction_verified"] = (len(still_present) == 0)
    if still_present:
        stages["source_survived"] = still_present[:10]

    # 4) Place each unit inside its cell_box, book-agnostically, honouring the
    #    structure the weekend module produced: PADDING, PEER-GROUP UNIFORM SIZING,
    #    and ROW-ALIGNED vertical placement (not evenly-divided bands).
    font_file = _house_font_file(fonts_dir) or None
    from collections import defaultdict

    # Cell padding: a fraction of the smaller cell dimension, clamped to a sane
    # pt range. Keeps text off the grid lines on any book/scale. (container_detection
    # uses ~8px; we derive it so it scales with the table.)
    def _cell_pad(x0, y0, x1, y1):
        return max(2.0, min(6.0, 0.06 * min(x1 - x0, y1 - y0)))

    # --- PEER-GROUP UNIFORM SIZE (render_gate Req: peers render at one size) ---
    # For each peer_group_id, choose ONE font size = the largest size that lets the
    # WIDEST member fit its padded cell width, capped by the source glyph height.
    # Every member of the group then renders at that single size.
    def _text_width(s, size):
        try:
            from text_shaping import accurate_text_width
            return accurate_text_width(s, font_file, size)
        except Exception:
            return len(s) * size * 0.5  # rough fallback

    peer_members = defaultdict(list)   # pg -> [(unit, translated)]
    standalone = []                    # units with no peer group
    for u in units:
        tr = resolved.get(u.id)
        if tr is None:
            continue
        pg = getattr(u, "peer_group_id", None)
        (peer_members[pg].append((u, tr)) if pg else standalone.append((u, tr)))

    peer_size = {}   # pg -> chosen pt size
    for pg, members in peer_members.items():
        # Source cap: median source glyph height in the group (apparent size).
        src_heights = sorted((u.bbox[3] - u.bbox[1]) for u, _ in members)
        cap = src_heights[len(src_heights) // 2] if src_heights else 12.0
        cap = max(8.0, min(cap, 40.0))
        size = cap

        # HEIGHT CAP: CONTENT items sharing ONE column cell are distributed into n
        # rows; the per-row pitch limits how tall the text can be or rows overlap
        # (the 'g' descenders collided). Cap size by the smallest row pitch — but ONLY
        # for content cells, never header cells (headers are not row-distributed and
        # capping them regressed merged-header centering/fit). Book-agnostic.
        member_roles = {getattr(u, "semantic_role", "") for u, _ in members}
        is_content_group = not (member_roles & {"heading", "table_header", "merged_header"})
        if is_content_group:
            by_cell = {}
            for u, _tr in members:
                cbk = tuple(round(c, 1) for c in (getattr(u, "cell_box", None) or u.bbox))
                by_cell.setdefault(cbk, 0)
                by_cell[cbk] += 1
            for cbk, count in by_cell.items():
                if count <= 0:
                    continue
                pad = _cell_pad(*cbk)
                inner_h = (cbk[3] - pad) - (cbk[1] + pad)
                pitch = inner_h / count if count else inner_h
                # Leave headroom: text box ~= size*1.3 tall, so size <= pitch/1.3.
                size = min(size, max(7.0, pitch / 1.3))

        # WIDTH CAP: shrink until the widest member's longest line fits its padded width.
        for u, tr in members:
            cb = getattr(u, "cell_box", None) or u.bbox
            pad = _cell_pad(*cb)
            avail_w = max(4.0, (cb[2] - cb[0]) - 2 * pad)
            longest = max((ln for ln in str(tr).split("\n")), key=len, default=str(tr))
            while size > 7.0 and _text_width(longest, size) > avail_w:
                size -= 0.5
        peer_size[pg] = round(size, 1)

    # CROSS-COLUMN HARMONISATION: the body content columns (word_list_item / phonics)
    # are peers of EACH OTHER, not just within a column. Sized independently, a column
    # with fewer rows gets a taller row pitch and renders BIGGER than its neighbours
    # (the middle column looked oversized). Clamp all content columns to their SHARED
    # minimum so the table body is one consistent size, like the source. Headers are
    # excluded (they legitimately differ from body text). Book-agnostic.
    content_pgs = []
    for pg, members in peer_members.items():
        roles = {getattr(u, "semantic_role", "") for u, _ in members}
        if not (roles & {"heading", "table_header", "merged_header"}):
            content_pgs.append(pg)
    if len(content_pgs) >= 2:
        shared = min(peer_size[pg] for pg in content_pgs)
        for pg in content_pgs:
            peer_size[pg] = shared

    def _place(u, tr, size_override=None):
        cb = tuple(getattr(u, "cell_box", None) or u.bbox)
        x0, y0, x1, y1 = cb
        pad = _cell_pad(x0, y0, x1, y1)
        role = getattr(u, "semantic_role", "") or ""
        is_header = role in ("heading", "table_header", "merged_header")
        tr_cased = _source_text_transform_apply(tr, [{"text": u.source_text}]) if is_header else tr
        align_h = {"center": "center", "right": "right"}.get(getattr(u, "align_h", None), "left")
        # size: peer size if in a group, else source glyph height (clamped).
        if size_override is not None:
            size = size_override
        else:
            size = max(8.0, min(u.bbox[3] - u.bbox[1], 40.0))

        # TASK 3 (uniform size): LOCK the peer size ONLY for non-header CONTENT items
        # (word-list/phonics), so long words don't auto-shrink smaller than their peers.
        # Headers must keep their natural fit/centering (locking them regressed the
        # merged-header centering), so headers are never size-locked here.
        draw_min = round(size, 1) if (size_override is not None and not is_header) else 7.0

        if is_header:
            # Header fills its (padded) cell; honour align_v.
            valign = {"top": "top", "bottom": "bottom"}.get(getattr(u, "align_v", None), "middle")
            box = pymupdf.Rect(x0 + pad, y0 + pad, x1 - pad, y1 - pad)
        else:
            # CONTENT item: place at its SOURCE ROW position within the column so
            # rows line up across columns (don't redistribute into even bands).
            # Row height must comfortably fit the LOCKED size so it isn't clipped.
            row_h = max(size * 1.3, (u.bbox[3] - u.bbox[1]) * 1.15)
            top = max(y0 + pad, min(u.bbox[1], y1 - pad - row_h))
            box = pymupdf.Rect(x0 + pad, top, x1 - pad, min(top + row_h, y1 - pad))
            valign = "middle"

        clip = box & page.rect
        used = draw_paragraph_text(
            page, box, tr_cased, font_file, round(size, 1),
            color=(0, 0, 0), align=align_h, min_size=draw_min, valign=valign, clip=clip,
        )
        return used

    for u, tr in standalone:
        used = _place(u, tr)
        if used is None or (isinstance(used, (int, float)) and used < 0):
            stages["overflow"].append(u.id)
        else:
            stages["inserted"] += 1

    for pg, members in peer_members.items():
        # Order top->bottom, left->right so reading order is preserved.
        members.sort(key=lambda z: (round(z[0].bbox[1], 1), z[0].bbox[0]))

        # Coalesce units that SHARE a cell_box (e.g. a multi-word/multi-line header
        # "HIGH FREQUENCY WORDS" arrives as 3 heading units on ONE cell). Drawing them
        # separately stacks them on top of each other (garbled). Instead join them in
        # reading order and render the cell ONCE. Book-agnostic: keyed purely on a
        # shared cell_box, not on any specific header text.
        from collections import OrderedDict
        groups = OrderedDict()
        for u, tr in members:
            cb = tuple(round(c, 1) for c in (getattr(u, "cell_box", None) or u.bbox))
            groups.setdefault(cb, []).append((u, tr))

        for cb, cell_members in groups.items():
            if len(cell_members) == 1:
                u, tr = cell_members[0]
                used = _place(u, tr, size_override=peer_size.get(pg))
                if used is None or (isinstance(used, (int, float)) and used < 0):
                    stages["overflow"].append(u.id)
                else:
                    stages["inserted"] += 1
                continue

            # Multiple units share this cell.
            roles = {getattr(u, "semantic_role", "") for u, _ in cell_members}
            is_header_cell = roles & {"heading", "table_header", "merged_header"}
            if is_header_cell:
                rep_u = cell_members[0][0]
                joined = " ".join(str(tr).strip() for _, tr in cell_members if str(tr).strip())
                used = _place(rep_u, joined, size_override=peer_size.get(pg))
                # Count each source unit as placed (the cell is rendered).
                if used is None or (isinstance(used, (int, float)) and used < 0):
                    stages["overflow"].extend(u.id for u, _ in cell_members)
                else:
                    stages["inserted"] += len(cell_members)
            else:
                # TASK 5 (vertical distribution): content items share ONE full-column
                # cell_box. Distribute them EVENLY down the column (uniform row pitch)
                # instead of at raw source-y, so columns with different item counts each
                # fill their height with consistent rhythm and read cleanly. Book-agnostic.
                cell_members.sort(key=lambda z: (round(z[0].bbox[1], 1), z[0].bbox[0]))
                x0, y0, x1, y1 = cb
                pad = _cell_pad(x0, y0, x1, y1)
                n = len(cell_members)
                size = peer_size.get(pg) or max(8.0, min(cell_members[0][0].bbox[3] - cell_members[0][0].bbox[1], 40.0))
                # Start clearly below the cell top so the first row never rides up into
                # the header band above it (content cell_box top can abut the header,
                # and a colspan header covers several content columns).
                top_inset = max(pad, size * 0.9, 8.0)
                inner_top = y0 + top_inset
                inner_h = (y1 - pad) - inner_top
                pitch = inner_h / n if n else inner_h
                align_h = {"center": "center", "right": "right"}.get(
                    getattr(cell_members[0][0], "align_h", None), "left")
                for i, (u, tr) in enumerate(cell_members):
                    slot = pymupdf.Rect(x0 + pad, inner_top + i * pitch,
                                        x1 - pad, inner_top + (i + 1) * pitch)
                    clip = slot & page.rect
                    used = draw_paragraph_text(
                        page, slot, tr, font_file, round(size, 1),
                        color=(0, 0, 0), align=align_h, min_size=round(size, 1),
                        valign="middle", clip=clip,
                    )
                    if used is None or (isinstance(used, (int, float)) and used < 0):
                        stages["overflow"].append(u.id)
                    else:
                        stages["inserted"] += 1

    # 5) Record + fail-closed policy.
    report.setdefault("vocabulary", {})[str(page_num)] = stages
    flagged = bool(stages["unresolved"] or stages["overflow"] or not stages["redaction_verified"])
    if flagged:
        report.setdefault("unresolved_span_ids", []).extend(stages["unresolved"])
        report.setdefault("review_pages", [])
        if page_num not in report["review_pages"]:
            report["review_pages"].append(page_num)
    return not flagged


def render_vocabulary_page_v8(page, page_spans, translations_map, fonts_dir, page_num, report,
                              id_to_translation=None, allow_legacy_flat=False, page_scene=None):
    """
    V8 vocabulary page: Manifest-driven per-span replacement.

    FALLBACK renderer: the production dispatch prefers
    render_vocabulary_page_from_scene() and only reaches this when there is no scene
    graph / no stable-ID contract for the page. It fails closed (CONTRACT_BRIDGE_MISS
    + route to review) unless allow_legacy_flat is explicitly set (Task 11). Retained
    deliberately as the tested no-contract fallback — do not delete without updating
    test_table_structure.py's Task-11 cases.

    Uses the page manifest to get stable content IDs and exact coordinates.

    Translation source (§2.2):
      - PREFERRED: when `id_to_translation` (the stable-ID contract) is supplied,
        each manifest item is filled DIRECTLY from its own stable ID. This preserves
        the SOURCE unit boundaries exactly — a multi-line phonics entry stays ONE
        unit and is never re-split by line gaps.
      - FALLBACK: when no contract is available, reconstruct id->translation from the
        flat per-page text via the legacy line-position converter (lossy: can mis-
        group wrapped entries). Kept only for pre-contract data.

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

    # Build page manifest for this vocabulary page (legacy fallback path only —
    # the scene-driven render_vocabulary_page_from_scene is preferred when a scene is
    # available). Book-agnostic column clustering.
    spans_for_manifest = _extract_spans_for_manifest(page, page_num)
    page_manifest = build_vocabulary_manifest(page, page_num, spans_for_manifest)

    manifest_items_flat = []
    for region in page_manifest.get("regions", []):
        for item in region.get("items", []):
            manifest_items_flat.append(item)

    contract_map = _contract_source_to_translation(id_to_translation, page_num) \
        if id_to_translation else {}

    if contract_map:
        mapped_items = []
        used = {}
        for item in manifest_items_flat:
            src = _norm_lookup_key(item.get("text", ""))
            # Support duplicate source words: consume translations in order.
            bucket = contract_map.get(src)
            if bucket:
                k = used.get(src, 0)
                if k < len(bucket):
                    mapped_items.append({"id": item["id"], "translation": bucket[k]})
                    used[src] = k + 1
        if not mapped_items:
            # Source-text bridge found nothing (e.g. contract lacks this page).
            # TASK 11: the legacy line-position mapper is NO LONGER a silent default.
            # By default we FAIL CLOSED (route the page to review) so lossy mapping
            # never happens behind the operator's back. Legacy is used ONLY when the
            # caller explicitly opts in via allow_legacy_flat.
            if allow_legacy_flat:
                if not translated_text.strip():
                    return
                mapped_items = legacy_text_to_manifest_items(translated_text, page_manifest)
                report.setdefault("flags", {}).setdefault("LEGACY_FLAT_MAPPING", []).append(page_num)
            else:
                report.setdefault("review_pages", [])
                if page_num not in report["review_pages"]:
                    report["review_pages"].append(page_num)
                report.setdefault("flags", {}).setdefault("CONTRACT_BRIDGE_MISS", []).append(page_num)
                report["errors"].append({"page": page_num,
                    "error": "contract bridge matched no items and legacy flat mapping is "
                             "disabled (pass allow_legacy_flat to opt in)"})
                return
    else:
        # No stable-ID contract at all for this render.
        # TASK 11: default is fail-closed; legacy flat mapping is opt-in only.
        if not allow_legacy_flat:
            report.setdefault("review_pages", [])
            if page_num not in report["review_pages"]:
                report["review_pages"].append(page_num)
            report.setdefault("flags", {}).setdefault("CONTRACT_BRIDGE_MISS", []).append(page_num)
            report["errors"].append({"page": page_num,
                "error": "no stable-ID contract for this page and legacy flat mapping is "
                         "disabled (pass allow_legacy_flat to opt in)"})
            return
        if not translated_text.strip():
            return
        # Legacy flat-text -> manifest items (lossy line-position mapping). Opt-in.
        mapped_items = legacy_text_to_manifest_items(translated_text, page_manifest)
        report.setdefault("flags", {}).setdefault("LEGACY_FLAT_MAPPING", []).append(page_num)

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
    #
    # CLAMP TO REAL GRID BORDERS: the manifest's column_center ± width/2 can be wider
    # than the true cell (e.g. the phonics column measured width=120 but its real
    # border-to-border cell is ~98pt), which let long wrapped lines overflow past the
    # right table line. We clamp every content column to the nearest detected vertical
    # gridlines minus padding — the same border-accurate bounds the headers use — so
    # a translated line always wraps INSIDE its cell. Book-agnostic: pure geometry.
    _verticals = _detect_vertical_gridlines(page)
    _CPAD = 3.0
    # True left edge of each column from its items' origins (robust when the manifest's
    # column_center/width is a poor estimate — e.g. the phonics column).
    _col_text_left = {}
    for r in content_regions:
        ci = r.get("column_index", 0)
        lefts = [it.get("origin", it.get("bbox", [0])[0:1])[0]
                 for it in r.get("items", []) if it.get("origin") or it.get("bbox")]
        if lefts:
            _col_text_left[ci] = min(lefts)
    _col_bounds = {}
    for r in content_regions:
        ci = r.get("column_index", 0)
        center = r.get("column_center")
        width = r.get("column_width", 100)
        if center is None:
            continue
        # Anchor on the ACTUAL text left, not the (sometimes wrong) center.
        text_left = _col_text_left.get(ci, center - width / 2)
        est_l, est_r = text_left, text_left + width
        if _verticals:
            # Left border = nearest gridline at/left of the text; right border =
            # nearest gridline right of it. Clamp inside them minus padding so lines
            # wrap within the true cell and never cross the table line.
            left_border = max([v for v in _verticals if v <= text_left + 1], default=text_left - 2)
            right_border = min([v for v in _verticals if v >= text_left + 1], default=est_r)
            safe_l = left_border + _CPAD
            safe_r = right_border - _CPAD
            if safe_r <= safe_l:
                safe_l, safe_r = text_left, text_left + width
            _col_bounds[ci] = (safe_l, safe_r)
        else:
            _col_bounds[ci] = (est_l, est_r)
    for it in items_to_render:
        cb = _col_bounds.get(it["col_idx"])
        if cb:
            it["col_left"], it["col_right"] = cb[0], cb[1]
            # Keep col_width in sync so the wrap/fit width matches the clamped cell.
            it["col_width"] = max(6.0, cb[1] - cb[0])

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
    #
    # COVERAGE GUARANTEE (fixes English leaking through): mask EVERY non-page-number
    # content source span on the page — not just the ones a translation mapped to —
    # so untranslated source text (e.g. a phonics cell like "wh-en, wh-ere" with no
    # matching translation) can never ghost through. Spans that DID map get their
    # translation placed after; spans that did NOT map are recorded as a coverage
    # gap and the page is flagged for review (fail-closed).
    rendered_span_ids = {id(item["span"]) for item in items_to_render}
    header_span_ids = {id(s) for s in header_spans_all}
    unmatched_content = [
        s for s in page_spans
        if not s.get("is_page_number")
        and id(s) not in rendered_span_ids
        and id(s) not in header_span_ids
    ]
    for item in items_to_render:
        remove_span(page, item["span"], fill_color=(1, 1, 1))
    for s in unmatched_content:
        # Mask untranslated source so English never shows; sample local bg.
        remove_span(page, s, fill_color=(1, 1, 1))
    page.apply_redactions()

    if unmatched_content:
        report.setdefault("coverage", {}).setdefault("untranslated_source", []).append({
            "page": page_num,
            "count": len(unmatched_content),
            "samples": [s.get("text_stripped", "")[:20] for s in unmatched_content[:5]],
        })
        report.setdefault("review_pages", [])
        if page_num not in report["review_pages"]:
            report["review_pages"].append(page_num)

    # TYPOGRAPHY GROUP + WRAP (overflow-fix brief §9.4/§9.5): compute ONE consistent
    # font size PER COLUMN that fits every word in that column, allowing wrapping to
    # a second line before shrinking. This stops each word shrinking independently
    # (which caused mismatched sizes) and lets long translations wrap instead of
    # being crushed. Uses the existing constraint solver.
    from text_fit_solver import FitConstraints, solve_batch

    font_file = _house_font_file(fonts_dir)  # publisher house font (PlaypenSans)
    if not font_file and items_to_render:
        font_file = _find_font_file(items_to_render[0]["span"], fonts_dir)

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
        # FULL FITTING LADDER (§9.5): for any item that still does not fit at the
        # column size, run the controlled ladder (tracking -> line-spacing ->
        # shrink -> alternate font -> request-shorter). Items that exhaust the
        # ladder are flagged request_shorter and routed to review (fail-closed).
        from text_fit_solver import solve_fitting_ladder, FitConstraints as _FC
        _alt_fonts = []
        try:
            if fonts_dir and os.path.isdir(fonts_dir):
                _alt_fonts = [os.path.join(fonts_dir, f) for f in sorted(os.listdir(fonts_dir))
                              if f.lower().endswith((".ttf", ".otf"))
                              and os.path.join(fonts_dir, f) != font_file][:3]
        except Exception:
            _alt_fonts = []
        for it, res in zip(col_items, results):
            item_size[id(it)] = col_size
            # Only invoke the ladder for GENUINE overflow. The force_consistent
            # re-solve above reports fits=False for any item whose natural size
            # exceeds the shared column size — that is a sizing artifact, NOT real
            # overflow (the item still fits the cell at col_size). Re-measure the
            # item at the chosen column size on a single line; only if it truly
            # exceeds the cell width do we run the controlled ladder (§9.5).
            # Use the item's ACTUAL rendered cell width (the clip x-range the
            # renderer will use), not the narrower manifest column-width estimate.
            _cl = it.get("col_left")
            _cr = it.get("col_right")
            if _cl is not None and _cr is not None and _cr > _cl:
                _cell_w = (_cr - _cl) - 3.0
            else:
                _cell_w = (col_w or 100) - 3.0
            from text_shaping import accurate_text_width as _acc
            try:
                _w = _acc(it["translation"], font_file, col_size) if font_file \
                    else pymupdf.Font("helv").text_length(it["translation"], fontsize=col_size)
            except Exception:
                _w = 0
            if _w <= _cell_w:
                continue  # fits at column size — no ladder needed
            # Genuine pre-render overflow at the column size: run the controlled
            # ladder (§9.5) to try tracking -> line-spacing -> shrink -> alternate
            # font. Apply the improved size/step when it fits. We DO NOT force
            # review from this pre-render estimate — the AUTHORITATIVE overflow
            # decision is the post-render glyph gate (_verify_rendered_page +
            # render_gate), which measures actual rendered geometry. The ladder's
            # request_shorter is recorded as a diagnostic hint only, avoiding the
            # historical false positives from an unreliable pre-render width basis.
            ladder = solve_fitting_ladder(
                it["translation"],
                _FC(container_width=_cell_w + 3.0, container_height=col_size * 1.4,
                    source_font_size=col_size, min_font_size=7.0,
                    max_shrink_ratio=0.35, allow_multiline=False, max_lines=1,
                    line_height_ratio=1.15, padding_x=1.5, single_word=True),
                font_path=font_file, alternate_font_paths=_alt_fonts,
            )
            it["_ladder"] = {
                "step": ladder.step, "strategy": ladder.strategy,
                "tracking_em": ladder.tracking_em,
                "alternate_font": os.path.basename(ladder.alternate_font) if ladder.alternate_font else None,
                "request_shorter": ladder.request_shorter,
            }
            # PEER-SIZE CONSISTENCY (spec Req 3.5, Captain Zan): every word in a column
            # peer group renders at the SAME col_size. A word that overflows at col_size
            # must WRAP to a second line at that size — NOT shrink below its peers. If
            # it still cannot fit even wrapped, flag the page for review (fail closed).
            # (We keep item_size[it] == col_size; wrapping is handled at insert time.)
            it["_needs_wrap"] = True
            if not ladder.fits and ladder.request_shorter:
                report.setdefault("fit_ladder", {}).setdefault("request_shorter_hints", []).append({
                    "page": page_num, "text": it["translation"][:40], "step": ladder.step,
                })

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
        # Mirror the SOURCE word's casing (R3, §9). Vocab items render via
        # insert_text (no CSS text-transform), so apply casing to the string itself.
        cased_text = _apply_source_casing(item["translation"], span)
        if item.get("_needs_wrap"):
            # Word too wide for one line at the shared column size: WRAP to keep the
            # SAME size as its peers (never shrink below peers). Split on the widest
            # break; if a single token is itself too wide, it will be caught by the
            # post-render gate and flagged.
            _fnt = pymupdf.Font(fontfile=font_file) if font_file else pymupdf.Font("helv")
            avail = (col_right - col_left) - 3.0
            words = cased_text.split()
            lines, cur = [], ""
            for w in words:
                trial = w if not cur else f"{cur} {w}"
                tw = _measure_text_width(trial, font_file, _fnt, size or 12)
                if tw <= avail or not cur:
                    cur = trial
                else:
                    lines.append(cur); cur = w
            if cur:
                lines.append(cur)
            success = _insert_wrapped_span(page, span, lines, fonts_dir,
                                           override_font_size=size, font_file=font_file)
        else:
            success = insert_translated_span(
                page, span, cased_text, fonts_dir,
                col_width=item["col_width"],
                override_font_size=size,
                clip=clip,
                font_file=font_file,   # render vocab words in the house font (PlaypenSans)
                no_shrink=True,        # keep the shared column size (peer consistency)
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

    # Apply the same logical-unit merge used by the document scene so the manifest's
    # items match the contract's units (a wrapped multi-line entry is ONE item).
    try:
        from document_model import _merge_continuation_spans
        spans = _merge_continuation_spans(spans, "vocabulary")
    except Exception:
        pass
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

def _dominant_span_color(spans, default=(0, 0, 0)):
    """
    Return the source subtitle colour as an (r,g,b) 0-1 tuple, taken from the
    source spans so the translated subtitle matches the ORIGINAL text colour
    (book-agnostic — no hardcoded palette). Picks the most common span colour,
    weighted by text length so the dominant title colour wins over any stray
    symbol span. Spans store colour as '#rrggbb' (see span extraction).
    """
    from collections import Counter
    tally = Counter()
    for s in spans or []:
        hexcol = s.get("color")
        if not isinstance(hexcol, str) or not hexcol.startswith("#") or len(hexcol) != 7:
            continue
        weight = max(1, len((s.get("text_stripped") or "").strip()))
        tally[hexcol.lower()] += weight
    if not tally:
        return default
    hexcol = tally.most_common(1)[0][0]
    try:
        r = int(hexcol[1:3], 16) / 255.0
        g = int(hexcol[3:5], 16) / 255.0
        b = int(hexcol[5:7], 16) / 255.0
        return (r, g, b)
    except ValueError:
        return default


def render_cover_page_v8(page, page_spans, translations_map, fonts_dir, page_num, report):
    """
    V8 cover: Find the subtitle span(s) (large text, not symbols),
    remove them with background-color-aware redaction, place translation.
    """
    translated_text = translations_map.get(page_num, "")
    if not translated_text.strip():
        return

    # Find subtitle spans: large font (>= 40px) that carry real letters/digits.
    # We DON'T require >2 chars per span, because some covers set the subtitle as
    # one span PER LETTER (e.g. 'C','o','l','o','u','r','s'); requiring >2 chars
    # dropped the whole title on those books. Instead we keep any large-font span
    # with at least one alphanumeric char, which still excludes the ® symbol span
    # (it extracts as a non-alphanumeric replacement char). Book-agnostic.
    subtitle_spans = [s for s in page_spans
                      if s["font_size"] >= 40
                      and any(ch.isalnum() for ch in s["text_stripped"])]

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

    # Remove subtitle spans by ERASING glyphs against the true background
    # (no fill rectangle) so we never stamp an off-colour block behind the
    # translated subtitle. Book-agnostic: works on any cover background.
    remove_spans_preserving_background(page, subtitle_spans)

    # Some covers draw a decorative OUTLINE/EMBOSS duplicate of the original
    # title as vector line-art behind the live text. Text redaction preserves
    # line-art, so that outline copy of the SOURCE-language title survives as a
    # ghost behind the translated title. Remove only those outline duplicates
    # (stroke-only, light, hairline vectors fully inside the subtitle band).
    # Book-agnostic: matches nothing when no such ghost exists.
    band = pymupdf.Rect(sub_min_x, sub_min_y, sub_max_x, sub_max_y)
    ghosts = remove_outline_duplicate_vectors(page, band)
    if ghosts:
        report.setdefault("cover_outline_ghosts_removed", 0)
        report["cover_outline_ghosts_removed"] += ghosts

    # Reliable font path (insert_text + explicit fontfile): htmlbox falls back to
    # CharisSIL and corrupts the text layer on this PyMuPDF. Match the SOURCE
    # subtitle font/weight so the cover subtitle keeps the original typeface, and
    # mirror source casing. Book-agnostic: font chosen from the source spans.
    subtitle_text = _source_text_transform_apply(subtitle_text, subtitle_spans)
    font_file = _weight_aware_house_font(subtitle_spans, fonts_dir)
    src_size = max((s["font_size"] for s in subtitle_spans), default=44)
    # Use the SOURCE subtitle's own colour so the translation matches the original
    # design exactly, instead of a hardcoded purple. Book-agnostic: read from the
    # source spans (the extractor stores each span's colour as #rrggbb).
    text_color = _dominant_span_color(subtitle_spans, default=(0x3d / 255, 0x2c / 255, 0x7c / 255))
    box = pymupdf.Rect(40, sub_min_y - 10, page.rect.width - 40, sub_max_y + 15)
    used = draw_paragraph_text(
        page, box, subtitle_text, font_file, round(src_size),
        color=text_color,
        line_height=1.2, align="center", min_size=round(src_size) * 0.6,
    )
    if used is not None:
        report["spans_replaced"] += 1
    else:
        report["errors"].append({"page": page_num, "error": "Cover subtitle produced no text"})


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

    # Erase original text glyphs against the TRUE background (no fill rectangle),
    # so no coloured box is stamped behind each translated title line. This
    # preserves gradients/artwork under the text and is book-agnostic.
    remove_spans_preserving_background(page, content_spans)

    # Text zone from span positions
    min_x = min(s["bbox"][0] for s in content_spans)
    min_y = min(s["bbox"][1] for s in content_spans)
    max_x = max(s["bbox"][2] for s in content_spans)
    max_y = max(s["bbox"][3] for s in content_spans)

    # Reconstruct list structure (header + one line per numbered entry), robust to
    # whether upstream preserved the newlines.
    header, entries = _split_title_list(translated_text)

    # Assemble the ordered list of lines to stack (header first, then entries).
    lines = []
    if header:
        lines.append(header)
    lines.extend(entries)
    if not lines:
        lines = [translated_text.strip()]

    # Mirror source casing (e.g. all-caps display font) on each line.
    lines = [_source_text_transform_apply(l, content_spans) for l in lines]

    # SOURCE-FAITHFUL PLACEMENT (Captain Zan): mirror the ORIGINAL back cover's
    # geometry — its horizontal ALIGNMENT and its per-line VERTICAL positions
    # (which include the gap between the heading and the list). We therefore:
    #   1. reconstruct the source's visual lines (top y of each) in reading order,
    #   2. infer the source's horizontal alignment (center/left/right) from the
    #      line centers vs the page,
    #   3. place each translated line at its matching source line's y, aligned the
    #      same way — so the heading gap and line rhythm are reproduced exactly.
    # Book-agnostic: pure geometry from the source spans, no per-title constants.
    src_line_tops, src_line_centers, src_line_lefts, src_line_rights = \
        _source_line_geometry(content_spans)
    page_w = page.rect.width

    # Infer source horizontal alignment from the line boxes vs the page.
    if src_line_centers:
        avg_ctr = sum(src_line_centers) / len(src_line_centers)
        centered = all(abs(c - page_w / 2) < page_w * 0.06 for c in src_line_centers)
        left_consistent = (max(src_line_lefts) - min(src_line_lefts)) < 12
        if centered:
            back_align = "center"
        elif left_consistent:
            back_align = "left"
        else:
            back_align = "center" if abs(avg_ctr - page_w / 2) < page_w * 0.1 else "left"
    else:
        back_align = "center"

    # Derive font size from the source spans so we stay faithful to the original.
    src_size = max((s["font_size"] for s in content_spans), default=22)
    line_size = round(src_size)
    font_file = _weight_aware_house_font(content_spans, fonts_dir)

    # Horizontal band: for centered text, use a page-centered band so lines center on
    # the PAGE midline (not a skewed min_x..max_x box). For left/right, anchor on the
    # source's own left/right edge to preserve the original indent.
    if back_align == "center":
        half = min(page_w / 2 - 20, max((max_x - min_x) / 2 + 40, 120))
        band_left = page_w / 2 - half
        band_right = page_w / 2 + half
    else:
        band_left = min_x - 6
        band_right = max_x + 40
    line_box_w = band_right - band_left

    # SIZE CONSISTENCY (§9.4): one shared size across all lines (series-title group).
    _probe = pymupdf.Font(fontfile=font_file) if font_file else pymupdf.Font("helv")
    shared_size = line_size
    for _ln in lines:
        while shared_size > line_size * 0.5 and \
                _measure_text_width(_ln, font_file, _probe, shared_size) > line_box_w:
            shared_size -= 0.5
    shared_size = max(shared_size, line_size * 0.5)

    # Map each output line to a SOURCE line top (preserving the heading gap + rhythm).
    # If counts differ, fall back to an even stack from the first source top.
    if len(src_line_tops) == len(lines) and src_line_tops:
        row_tops = src_line_tops
    else:
        top0 = src_line_tops[0] if src_line_tops else (min_y - 6)
        step = (src_line_tops[1] - src_line_tops[0]) if len(src_line_tops) >= 2 else shared_size * 1.3
        row_tops = [top0 + i * step for i in range(len(lines))]

    row_h = shared_size * 1.3
    drew_any = False
    for i, line in enumerate(lines):
        row_top = row_tops[i]
        row_rect = pymupdf.Rect(band_left, row_top - 2, band_right, row_top + row_h)
        used = draw_paragraph_text(
            page, row_rect, line, font_file, shared_size,
            color=(0, 0, 0), line_height=1.0, align=back_align, min_size=shared_size,
            valign="top",
        )
        drew_any = drew_any or (used is not None)

    if drew_any:
        report["spans_replaced"] += 1
    else:
        report["errors"].append({"page": page_num, "error": "Back cover produced no text"})


def _source_line_geometry(content_spans):
    """
    Reconstruct the source's visual lines from its spans. Returns four parallel
    lists (top-to-bottom): line_tops, line_centers, line_lefts, line_rights.
    Book-agnostic: groups spans by y proximity, no per-title constants.
    """
    if not content_spans:
        return [], [], [], []
    heights = sorted((s["bbox"][3] - s["bbox"][1]) for s in content_spans)
    med_h = heights[len(heights) // 2] if heights else 12.0
    med_h = med_h if med_h > 1 else 12.0
    rows = []
    for s in sorted(content_spans, key=lambda z: z["bbox"][1]):
        yc = (s["bbox"][1] + s["bbox"][3]) / 2
        placed = False
        for r in rows:
            if abs(yc - r["yc"]) <= med_h * 0.6:
                r["spans"].append(s)
                r["yc"] = sum((x["bbox"][1] + x["bbox"][3]) / 2 for x in r["spans"]) / len(r["spans"])
                placed = True
                break
        if not placed:
            rows.append({"yc": yc, "spans": [s]})
    tops, centers, lefts, rights = [], [], [], []
    for r in sorted(rows, key=lambda z: z["yc"]):
        x0 = min(s["bbox"][0] for s in r["spans"])
        x1 = max(s["bbox"][2] for s in r["spans"])
        y0 = min(s["bbox"][1] for s in r["spans"])
        tops.append(y0)
        centers.append((x0 + x1) / 2)
        lefts.append(x0)
        rights.append(x1)
    return tops, centers, lefts, rights


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

    # Reliable font path for ALL copyright text (insert_text + house font), so no
    # part of the page falls back to CharisSIL. Book-agnostic.
    # --- Subtitle ---
    if subtitle_spans and subtitle_text:
        sub_min_x = min(s["bbox"][0] for s in subtitle_spans)
        sub_max_x = max(s["bbox"][2] for s in subtitle_spans)
        sub_min_y = min(s["bbox"][1] for s in subtitle_spans)
        sub_max_y = max(s["bbox"][3] for s in subtitle_spans)

        for span in subtitle_spans:
            remove_span(page, span, fill_color=(1, 1, 1))
        page.apply_redactions()

        sub_size = max((s["font_size"] for s in subtitle_spans), default=44)
        sub_font = _weight_aware_house_font(subtitle_spans, fonts_dir)
        sub_rect = pymupdf.Rect(60, sub_min_y - 5, page.rect.width - 60, sub_max_y + 10)
        sub_align = _infer_source_alignment(subtitle_spans, 60, page.rect.width - 60)
        used = draw_paragraph_text(
            page, sub_rect, _source_text_transform_apply(subtitle_text, subtitle_spans),
            sub_font, round(sub_size), color=(0x3d/255, 0x2c/255, 0x7c/255),
            line_height=1.2, align=sub_align, min_size=round(sub_size) * 0.6,
            valign="middle", clip=sub_rect & page.rect,
        )
        if used is not None:
            report["spans_replaced"] += 1

    # --- Info text (two columns) ---
    if info_spans and remaining:
        # Detect two columns: left (x < 300) and right (x >= 300)
        left_spans = [s for s in info_spans if s["bbox"][0] < 300]
        right_spans = [s for s in info_spans if s["bbox"][0] >= 300]

        for span in info_spans:
            remove_span(page, span, fill_color=(1, 1, 1))
        page.apply_redactions()

        # Split translation into left (publisher) and right (bio) sections.
        publisher_lines = []
        bio_lines = []
        in_bio = False
        for line in remaining:
            if not in_bio and any(m in line.lower() for m in ['die naam', 'the name', 'karakter', 'is uitgevind']):
                in_bio = True
            (bio_lines if in_bio else publisher_lines).append(line)

        info_size = max((s["font_size"] for s in info_spans), default=8)
        info_font = _weight_aware_house_font(info_spans, fonts_dir)

        if left_spans and publisher_lines:
            left_min_y = min(s["bbox"][1] for s in left_spans)
            left_max_y = max(s["bbox"][3] for s in left_spans)
            rect = pymupdf.Rect(64, left_min_y, 300, left_max_y + 30)
            used = draw_paragraph_text(
                page, rect, " ".join(publisher_lines), info_font, round(info_size) or 8,
                color=(0, 0, 0), line_height=1.4, align="left",
                min_size=6.0, clip=rect & page.rect,
            )
            if used is not None:
                report["spans_replaced"] += 1

        if right_spans and bio_lines:
            right_min_y = min(s["bbox"][1] for s in right_spans)
            right_max_y = max(s["bbox"][3] for s in right_spans)
            rect = pymupdf.Rect(321, right_min_y, 477, right_max_y + 30)
            used = draw_paragraph_text(
                page, rect, " ".join(bio_lines), info_font, round(info_size) or 8,
                color=(0, 0, 0), line_height=1.4, align="left",
                min_size=6.0, clip=rect & page.rect,
            )
            if used is not None:
                report["spans_replaced"] += 1


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
    A prose/story page has flowing sentence text. Detects narrative sentences even
    when SHORT and set in large display type (common in early-reader books, e.g.
    "Five monkeys are screaming."). Book-agnostic: keys off sentence STRUCTURE
    (terminal punctuation and/or lowercase function words), not font size or
    position — so a large-type story line is not mistaken for a cover title.

    A genuine cover/title (title-cased or all-caps display words with no sentence
    structure) will NOT match.
    """
    content = [s for s in page_spans if not s.get("is_page_number")]
    if not content:
        return False
    joined = " ".join(s.get("text_stripped", "") for s in content)
    words = joined.split()
    if len(words) < 3:
        return False

    # A numbered title/series list is NOT prose, even though its header line may
    # contain function words (e.g. "Titles in the ... series:"). Title-list
    # structure takes precedence so back covers classify correctly.
    if _looks_like_title_list(content):
        return False

    has_sentence_punct = bool(re.search(r'[.!?]', joined))
    # Lowercase function words are a strong signal of running prose (vs a title,
    # which is typically title-cased/all-caps display text). Language-agnostic set
    # covering common English + Afrikaans function words.
    function_words = {
        "the", "a", "an", "is", "are", "was", "were", "in", "on", "at", "and",
        "of", "to", "with", "under", "over", "near", "nearby", "they", "he", "she",
        "it", "while", "when", "who", "that", "this", "these", "those", "there",
        "here", "for", "from", "by", "as", "so", "but", "or", "up", "out",
        "die", "n", "en", "op", "onder", "oor", "naby", "hulle",
        "met", "van", "'n", "sy", "hy", "dit", "terwyl", "wat", "daar",
    }
    lower_tokens = [w.strip(".,!?;:").lower() for w in words]
    has_function_word = any(t in function_words for t in lower_tokens)

    # Long flowing block: the original ≥8-word heuristic (kept).
    if len(words) >= 8 and has_sentence_punct:
        return True
    # Short narrative line in large type: sentence punctuation AND a function word,
    # with at least a few words (a real sentence, not a title).
    if len(words) >= 3 and has_sentence_punct and has_function_word:
        return True
    # Sentence-like even without terminal punctuation IF it has multiple lowercase
    # function words (mid-sentence continuation across pages, e.g. "while four cubs
    # play nearby" — no period but clearly prose).
    if len(words) >= 4 and sum(1 for t in lower_tokens if t in function_words) >= 2:
        return True
    # Large-type narrative line: contains lowercase non-initial words (running
    # text), e.g. "Five monkeys are screaming" / "I have never seen nine rhinos".
    # A genuine title is title-cased/all-caps, so lowercase-dominant lines are
    # prose. Book-agnostic: keys off casing, not vocabulary.
    if len(words) >= 3:
        non_initial = words[1:]
        # Real words only: ignore single characters (letter-spaced display titles
        # like "C o l o u r s" must NOT read as lowercase prose words).
        clean_non_initial = [w.strip(".,!?;:'\"") for w in non_initial]
        clean_non_initial = [w for w in clean_non_initial if len(w) >= 2]
        lower_non_initial = sum(1 for w in clean_non_initial if w[:1].islower())
        if clean_non_initial and lower_non_initial >= max(2, int(0.5 * len(clean_non_initial))):
            return True
    return False


def _looks_like_vocabulary_page(page_spans):
    """
    Detect a vocabulary / word-table page (e.g. WORDS / HIGH FREQUENCY WORDS /
    PHONICS grids) STRUCTURALLY, independent of font size.

    The old detector keyed on `small_font_count > 30`, which silently missed books
    whose word tables are set in large type (e.g. 23pt) — those misclassified as
    'story' because a high-frequency-word list is full of lowercase function words.

    Signals (book-agnostic, geometry + shape only):
      - Many short items: most content spans are 1–2 tokens (word-list cells), not
        sentences.
      - Multiple columns: content spans cluster into >= 2 distinct left-edge (x)
        bands — the hallmark of a table/word grid rather than a single prose column.
      - Little to no sentence prose: few spans carry terminal sentence punctuation.
    A section-header cue (a short ALL-CAPS heading span) strengthens the signal but
    is not required, so it works across languages and titles.
    """
    content = [s for s in page_spans if not s.get("is_page_number")]
    n = len(content)
    if n < 12:
        return False

    def _tok_count(s):
        return len((s.get("text_stripped", "") or "").split())

    short_items = sum(1 for s in content if 1 <= _tok_count(s) <= 2)
    sentence_spans = sum(
        1 for s in content if re.search(r'[.!?]', s.get("text_stripped", "") or "")
    )

    # Column bands from span left edges (bbox[0]), quantised to ~24pt buckets so
    # minor jitter within a column collapses to one band.
    lefts = [round((s.get("bbox", [0])[0]) / 24.0) for s in content if s.get("bbox")]
    distinct_columns = len(set(lefts))

    short_ratio = short_items / n if n else 0.0
    prose_ratio = sentence_spans / n if n else 0.0

    # An all-caps short heading span (WORDS / PHONICS / HOË FREKWENSIE WOORDE) — a
    # supportive cue, not mandatory.
    def _is_heading_span(s):
        t = (s.get("text_stripped", "") or "").strip()
        letters = [c for c in t if c.isalpha()]
        return len(t) >= 3 and _tok_count(s) <= 3 and bool(letters) \
            and all((not c.isalpha()) or c.isupper() for c in t)

    has_caps_heading = any(_is_heading_span(s) for s in content)

    # Decide: dominated by short items, arranged in multiple columns, and not prose.
    if short_ratio >= 0.6 and distinct_columns >= 2 and prose_ratio <= 0.25:
        return True
    # Header-anchored fallback: a caps section heading + mostly short items in a grid.
    if has_caps_heading and short_ratio >= 0.5 and distinct_columns >= 3 and prose_ratio <= 0.3:
        return True
    return False


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

    # 1a. Vocabulary (font-size-independent): structural detection of a word/table
    #     grid (many short items across multiple columns, little prose). Catches word
    #     tables set in LARGE type that the small-font rule above misses. Runs before
    #     prose so a high-frequency-word list (full of lowercase function words) is
    #     not mistaken for running prose.
    if _looks_like_vocabulary_page(content_spans):
        return 'vocabulary'

    # 1b. Front cover signal (book-agnostic, geometry-based): the FIRST page with
    #     very-large display title text and only a few spans is a cover, even if the
    #     title happens to read like a short lowercase phrase ("Play with me"). A
    #     small publisher/studio credit line above/below the big title is typical.
    #     This runs before prose so a lowercase display title is not read as a story
    #     line. Restricted to page 1 + few spans so interior story pages are unaffected.
    if page_num == 1 and very_large > 0 and total_spans <= 6:
        return 'cover'

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


def _measure_text_width(text, font_file, font, size):
    """Width of `text` at `size`. Prefers HarfBuzz shaping (kerning/ligatures/GPOS)
    so wrapping matches what insert_text actually paints; falls back to pymupdf's
    advance-sum. Book-agnostic."""
    if font_file:
        try:
            from text_shaping import accurate_text_width
            return accurate_text_width(text, font_file, size)
        except Exception:
            pass
    return font.text_length(text, fontsize=size)


def _wrap_paragraph(words, font_file, font, size, max_width):
    """Greedy word-wrap `words` into lines no wider than `max_width` at `size`.
    A single word longer than max_width is kept on its own line (caller may shrink).
    Returns a list of line strings."""
    lines, cur = [], ""
    for w in words:
        trial = w if not cur else f"{cur} {w}"
        if _measure_text_width(trial, font_file, font, size) <= max_width or not cur:
            cur = trial
        else:
            lines.append(cur)
            cur = w
    if cur:
        lines.append(cur)
    return lines


def _wrap_text_with_hard_breaks(text, font_file, font, size, max_width):
    """
    Wrap `text` into display lines, honoring HARD line breaks (newline chars) as
    FORCED breaks, then width-wrapping each resulting segment. This lets a caller
    preserve the source's own line structure (e.g. a two-line header
    "HIGH FREQUENCY\nWORDS") — each hard segment starts on its own line and only
    wraps further if it is too wide for `max_width`.

    Book-agnostic: no header strings, just newline-splitting + greedy width wrap.
    Returns a flat list of line strings in top-to-bottom order.
    """
    out = []
    for segment in (text or "").split("\n"):
        seg = segment.strip()
        if not seg:
            # Preserve an intentional blank line within the block.
            out.append("")
            continue
        out.extend(_wrap_paragraph(seg.split(), font_file, font, size, max_width))
    return out or [""]


def draw_paragraph_text(page, rect, text, font_file, size, color=(0, 0, 0),
                        line_height=1.17, align="left", min_size=None,
                        text_transform="none", valign="top", clip=None):
    """
    Render a wrapped paragraph inside `rect` using insert_text with an EXPLICIT
    font file. This is the reliable path on PyMuPDF 1.28.2: insert_htmlbox does NOT
    resolve archive/registered fonts (it silently falls back to CharisSIL) AND its
    @font-face url() path corrupts the ToUnicode text layer. insert_text with a real
    fontfile gives BOTH correct glyphs (incl. real bold via a Bold font file) AND a
    correct, searchable text layer.

    Auto-shrinks the font size until all wrapped lines fit the rect height (down to
    min_size, default 60% of size). Book-agnostic: font_file is chosen by the caller
    from detected source weight / house font; nothing here is title-specific.

    HARD LINE BREAKS: newline characters in `text` are honored as forced breaks
    (each starts a new display line, then width-wraps if needed), so a caller can
    reproduce the source's own line structure. Width-only wrapping still applies
    within each hard segment.

    align:  horizontal alignment within rect ("left" | "center" | "right").
    valign: vertical alignment of the wrapped block within rect ("top" | "middle").
    clip:   optional pymupdf.Rect — glyphs are confined to it (never cross a border).

    Returns the size actually used, or None if it could not render.
    """
    # Preserve newlines through transform/strip (do NOT collapse them): only trim
    # outer whitespace and normalize case per segment-agnostic transform.
    text = (text or "").strip()
    if not text:
        return None
    if text_transform == "uppercase":
        text = text.upper()
    elif text_transform == "lowercase":
        text = text.lower()

    font = pymupdf.Font(fontfile=font_file) if font_file else pymupdf.Font("helv")
    max_width = rect.width
    max_height = rect.height
    floor = min_size if min_size else size * 0.6

    # Font metrics used for BOTH the fit test and centering, so the shrink guarantees
    # the true visual block (ascender→descender) fits the cell and can center.
    try:
        _asc = font.ascender if (0 < getattr(font, "ascender", 0) <= 1.5) else 0.8
    except Exception:
        _asc = 0.8
    try:
        _desc = abs(font.descender) if (0 < abs(getattr(font, "descender", 0)) <= 1.0) else 0.2
    except Exception:
        _desc = 0.2

    # Fit loop: shrink until the wrapped block fits the rect height. Uses hard-break-
    # aware wrapping (forced breaks counted) and the TRUE visual block height
    # (ascender→descender), so a multi-line header never overflows onto the border.
    cur = size
    while cur >= floor:
        lines = _wrap_text_with_hard_breaks(text, font_file, font, cur, max_width)
        step = cur * line_height
        visual_h = (len(lines) - 1) * step + cur * (_asc + _desc)
        widest = max((_measure_text_width(l, font_file, font, cur) for l in lines),
                     default=0)
        if visual_h <= max_height and widest <= max_width:
            break
        cur -= 0.5
    cur = max(cur, floor)

    lines = _wrap_text_with_hard_breaks(text, font_file, font, cur, max_width)
    step = cur * line_height
    n = len(lines)

    # Font metrics for TRUE visual centering (equal padding above and below the block).
    try:
        asc = font.ascender if hasattr(font, "ascender") else 0.8
    except Exception:
        asc = 0.8
    asc = asc if 0 < asc <= 1.5 else 0.8
    try:
        desc = abs(font.descender) if hasattr(font, "descender") else 0.2
    except Exception:
        desc = 0.2
    desc = desc if 0 < desc <= 1.0 else 0.2

    # Visual block height = distance from the TOP of the first line's glyphs to the
    # BOTTOM of the last line's descender. Baseline math alone biased the block
    # downward onto the bottom border; this measures the real ink extent so the block
    # centers with equal space above and below (Captain Zan: same padding top/bottom).
    visual_block_h = (n - 1) * step + cur * (asc + desc)
    if valign == "middle":
        top_pad = max(0.0, (max_height - visual_block_h) / 2.0)
    else:
        top_pad = 0.0
    # First baseline sits one ascender below the block's visual top.
    baseline_y = rect.y0 + top_pad + cur * asc

    fontname = "F0" if font_file else "helv"
    _kw = {}
    if clip is not None:
        _kw["clip"] = clip
    drew = False
    for i, line in enumerate(lines):
        y = baseline_y + i * step
        if y > rect.y1 + step:
            break
        # Use the RENDER advance width (what insert_text actually paints — base
        # advances, no HarfBuzz GPOS kerning) for horizontal placement, so centered
        # and right-aligned lines land exactly where painted. _measure_text_width
        # (HarfBuzz-shaped) is narrower and biased centered text off-center.
        try:
            lw = font.text_length(line, fontsize=cur)
        except Exception:
            lw = _measure_text_width(line, font_file, font, cur)
        if align == "center":
            x = rect.x0 + max(0, (max_width - lw) / 2)
        elif align == "right":
            x = rect.x0 + max(0, max_width - lw)
        else:
            x = rect.x0
        pt = pymupdf.Point(x, y)
        try:
            if font_file:
                try:
                    page.insert_text(pt, line, fontsize=cur, fontname=fontname,
                                     fontfile=font_file, color=color, **_kw)
                except TypeError:
                    page.insert_text(pt, line, fontsize=cur, fontname=fontname,
                                     fontfile=font_file, color=color)
            else:
                try:
                    page.insert_text(pt, line, fontsize=cur, fontname="helv",
                                     color=color, **_kw)
                except TypeError:
                    page.insert_text(pt, line, fontsize=cur, fontname="helv", color=color)
            drew = True
        except Exception:
            pass
    return cur if drew else None


def _weight_aware_house_font(spans, fonts_dir):
    """
    Return the house font FILE PATH matching the detected source weight of `spans`.
    Bold source -> PlaypenSans-Bold, else Regular. Falls back to the source-matched
    font file, then None. This is what makes bold ACTUALLY render (real Bold glyphs),
    unlike the htmlbox `font-weight: bold` path which fell back to CharisSIL.
    """
    prefer_bold = _source_weight(spans) == "bold"
    hf = _house_font_file(fonts_dir, prefer_bold=prefer_bold)
    if hf:
        return hf
    # Fall back to source-matched font file via a synthetic span.
    dom = None
    names = [s.get("font_name", "") for s in spans if s.get("font_name")]
    if names:
        from collections import Counter
        dom = Counter(names).most_common(1)[0][0]
    return _find_font_file({"font_name": dom or "", "is_bold": prefer_bold}, fonts_dir)


def _register_html_fonts(page, fonts_dir):
    """
    Register every available font ON THE PAGE (page.insert_font) under its family
    name and return CSS (font-family rules only, NO @font-face url()).

    Why: `insert_htmlbox` with a CSS `@font-face { src: url(file) }` (via Archive)
    embeds the font but produces a BROKEN ToUnicode cmap — the page LOOKS right but
    its text layer extracts as garbage (not selectable/searchable/accessible).
    Registering the font on the page with page.insert_font() first, then referencing
    it by a bare `font-family`, yields a correct ToUnicode map (real, searchable
    text). Book-agnostic.

    Returns "" (no CSS needed): the family names are live on the page, so renderers
    reference them directly via `font-family: "<Family>"`.
    """
    if not fonts_dir or not os.path.isdir(fonts_dir):
        return ""
    for filename in sorted(os.listdir(fonts_dir)):
        if not filename.lower().endswith(('.ttf', '.otf')):
            continue
        if ' ' in filename:
            continue
        name_part = filename.rsplit('.', 1)[0]
        family = name_part
        for suffix in ['-Regular', '-Bold', '-SemiBold', '-Medium', '-Light',
                      'Regular', 'Bold', 'SemiBold', 'Medium', 'Light']:
            family = family.replace(suffix, '')
        family = family.rstrip('-').rstrip('_')
        path = os.path.join(fonts_dir, filename)
        is_bold = "bold" in name_part.lower()
        is_regular = not any(w in name_part.lower()
                             for w in ("bold", "semibold", "medium", "light"))
        try:
            # Register the base family from the Regular file so `font-family: X` works
            # with a correct text layer.
            if is_regular:
                page.insert_font(fontname=family.replace(" ", ""), fontfile=path)
            # Register a bold alias so `font-weight: bold` resolves to real bold glyphs.
            if is_bold:
                page.insert_font(fontname=(family + "Bold").replace(" ", ""), fontfile=path)
        except Exception:
            continue
    return ""


def _build_font_css(fonts_dir):
    """DEPRECATED: previous @font-face url() approach produced a corrupt text layer.
    Kept only for non-page callers; returns empty so nothing references url() fonts.
    Renderers must use _register_html_fonts(page, fonts_dir) instead."""
    return ""


def _build_font_css_LEGACY(fonts_dir):
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


def _family_of_file(filename):
    """Derive the CSS @font-face family name from a font filename (same rule as
    _build_font_css so lookups match)."""
    family = filename.rsplit('.', 1)[0]
    for suffix in ['-Regular', '-Bold', '-SemiBold', '-Medium', '-Light',
                   'Regular', 'Bold', 'SemiBold', 'Medium', 'Light']:
        family = family.replace(suffix, '')
    return family.rstrip('-').rstrip('_')


def _source_font_family(spans, fonts_dir):
    """
    Resolve the CSS font-family that MATCHES the source spans' font (brief §9.1/§9.3
    "preserve the original typography"). The source stores a font name (e.g. 'Edu-Aid');
    we pick the fonts-dir file whose family matches it so the translation renders in
    the SAME typeface — critically, this avoids falling back to an unrelated ALL-CAPS
    display font (e.g. 'AdLibBT') that would make mixed-case text look all-uppercase.

    Only returns a family whose font file is CSS-registrable (no spaces in filename,
    matching _build_font_css). Falls back to the primary family, then sans-serif.
    Book-agnostic: driven by the source font name, not any book constant.
    """
    if not fonts_dir or not os.path.isdir(fonts_dir):
        return "sans-serif"
    files = [f for f in sorted(os.listdir(fonts_dir))
             if f.lower().endswith((".ttf", ".otf")) and " " not in f]
    if not files:
        return "sans-serif"

    # Dominant source font name across the spans.
    from collections import Counter
    names = [s.get("font_name", "") for s in spans if s.get("font_name")]
    if names:
        src_name = Counter(names).most_common(1)[0][0]
        norm = src_name.lower().replace("-", "").replace(" ", "")
        # Exact/substring family match against available registrable fonts.
        for f in files:
            fam = _family_of_file(f)
            fnorm = fam.lower().replace("-", "").replace(" ", "")
            if norm and (norm in fnorm or fnorm in norm):
                return fam
    # Fall back to a registrable primary family.
    return _family_of_file(files[0])


# Publisher house font (Johan's books all use PlaypenSans). Overridable via the
# env var STORY_BODY_FONT if a different house font is ever adopted — book-agnostic,
# no per-title hardcoding.
_HOUSE_STORY_FONT = os.environ.get("STORY_BODY_FONT", "PlaypenSans")


def _preferred_story_family(spans, fonts_dir):
    """
    Resolve the story body font family. Prefers the publisher house font
    (PlaypenSans by default) when it is present in the fonts dir; otherwise falls
    back to the source-matched font, then the primary family. Only returns a
    CSS-registrable family (no spaces in filename, matching _build_font_css).
    """
    if fonts_dir and os.path.isdir(fonts_dir):
        want = _HOUSE_STORY_FONT.lower().replace("-", "").replace(" ", "")
        for f in sorted(os.listdir(fonts_dir)):
            if not f.lower().endswith((".ttf", ".otf")) or " " in f:
                continue
            fam = _family_of_file(f)
            if want and want in fam.lower().replace("-", "").replace(" ", ""):
                return fam
    # House font not available — preserve the source typeface instead.
    return _source_font_family(spans, fonts_dir)


def _house_font_file(fonts_dir, prefer_bold=False):
    """
    Return the FILE PATH of the publisher house font (PlaypenSans by default,
    overridable via STORY_BODY_FONT). Prefers Regular (or Bold if requested).
    Falls back to None so callers use their own source-matched resolution.
    """
    if not fonts_dir or not os.path.isdir(fonts_dir):
        return None
    want = _HOUSE_STORY_FONT.lower().replace("-", "").replace(" ", "")
    cands = []
    for f in sorted(os.listdir(fonts_dir)):
        if not f.lower().endswith((".ttf", ".otf")):
            continue
        if want and want in f.lower().replace("-", "").replace(" ", ""):
            cands.append(os.path.join(fonts_dir, f))
    if not cands:
        return None
    # Prefer the requested weight, else Regular, else first.
    key = "bold" if prefer_bold else "regular"
    for c in cands:
        if key in os.path.basename(c).lower():
            return c
    for c in cands:
        if "regular" in os.path.basename(c).lower():
            return c
    return cands[0]


# =============================================================================
# VERIFICATION GATE (Layer 6)
# =============================================================================

def _build_page_scene_record(page_spans, page_type, page_num):
    """
    Build a stable-ID scene record for a page: regions -> units, each with a stable
    id, source text, bbox, and role. This is the explicit translation<->render
    mapping the brief requires (§2/§8) — no string-similarity re-inference.
    Book-agnostic: roles derived from page_type + geometry, IDs from page+index.

    NOTE (§2.1): This flat per-span record is the FALLBACK. When the region-graph
    document scene is available, `_scene_record_from_page_scene` is used instead so
    the record is derived from the SAME region graph that drives rendering — one
    source of truth, no divergent second model.
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


def _scene_record_from_page_scene(page_scene):
    """
    Build the report's per-page scene record from the canonical region-graph
    PageScene (document_model). This is the §2.1 single-source-of-truth path:
    the diagnostic record and the render both read the SAME region graph, so
    there is no second, divergent scene model.

    Emits regions -> units with stable IDs, semantic role, per-region safe inner
    bounds (design container), and source bounds — the structural fields §14 needs.
    """
    regions_out = []
    units_out = []
    for region in sorted(page_scene.regions, key=lambda r: r.reading_order):
        container = region.get_container()
        child_units = []
        for uid in region.child_ids:
            unit = page_scene.unit_by_id(uid)
            if not unit or unit.translation_policy == "preserve":
                continue
            style = page_scene.styles.get(unit.style_id)
            u = {
                "id": unit.id,
                "region_id": region.id,
                "role": unit.semantic_role,
                "semantic_type": region.region_type,
                "source_text": unit.source_text,
                "bbox": [round(v, 1) for v in unit.bbox],
                "source_bounds": [round(v, 1) for v in unit.bbox],
                "reading_order": unit.reading_order,
                "nominal_size_pt": round(style.nominal_size_pt, 2) if style else None,
                "font_name": style.font.family if style and style.font else None,
                "column_index": unit.column_index,
            }
            child_units.append(u)
            units_out.append(u)
        if not child_units:
            continue
        regions_out.append({
            "region_id": region.id,
            "region_type": region.region_type,
            "semantic_type": region.region_type,
            "reading_order": region.reading_order,
            "source_bounds": [round(v, 1) for v in region.bbox],
            "safe_inner_bounds": [round(v, 1) for v in container],
            "container_source": region.container_source,
            "unit_ids": [u["id"] for u in child_units],
        })
    return {
        "page_number": page_scene.page_number,
        "page_type": page_scene.page_type,
        "page_family_id": page_scene.page_family_id,
        "region_count": len(regions_out),
        "unit_count": len(units_out),
        "regions": regions_out,
        "units": units_out,
    }


def _font_resolution_report(fonts_dir, requested_fonts=None):
    """
    Record which font the engine resolved as primary, its file hash, and whether an
    APPROVED font was available (brief §9.1/§9.3). If no fonts dir / no usable font,
    this is an unresolved fallback and must fail closed. If resolution lands on an
    UNAPPROVED font, that must also fail closed.

    Records the §9.1 provenance fields: fontRequested, resolvedFamily, fontFileHash,
    fallbackUsed — plus an `approved` flag and an `unapproved` document-level flag.
    """
    import hashlib
    info = {"resolved_family": None, "font_file_hash": None,
            "fallback_used": False, "unresolved": False,
            "approved": True, "unapproved": False, "policy": None}
    if not fonts_dir or not os.path.isdir(fonts_dir):
        info["unresolved"] = True
        info["fallback_used"] = True
        info["approved"] = False
        return info
    fonts = [f for f in os.listdir(fonts_dir) if f.lower().endswith((".ttf", ".otf"))]
    if not fonts:
        info["unresolved"] = True
        info["fallback_used"] = True
        info["approved"] = False
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

    # APPROVED-FONT ENFORCEMENT (§9.1/§9.3): resolve every requested source font
    # through the font policy and fail closed on any unapproved/unresolved result.
    try:
        from font_policy import document_font_policy_report
        policy = document_font_policy_report(fonts_dir, requested_fonts)
        info["policy"] = policy
        if policy.get("any_unresolved"):
            info["unresolved"] = True
            info["approved"] = False
        if policy.get("any_unapproved"):
            info["unapproved"] = True
            info["approved"] = False
    except Exception as e:
        info["policy"] = {"error": str(e)}
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

def replace_text_in_pdf(input_pdf, output_pdf, translations, fonts_dir=None, only_item_ids=None,
                        allow_legacy_flat=False):
    """
    V8 Core: Per-span replacement engine with manifest-driven mapping.
    
    translations format (either is accepted):

    1. Flat per-page (legacy, still supported):
    {
        "pages": [
            {"page_number": 1, "translated_text": "..."},
            ...
        ]
    }

    2. Stable-ID contract (§2.2 — preferred):
    {
        "items": [
            {"id": "p03_s0001", "page_number": 3, "reading_order": 1,
             "translation": "..."},
            ...
        ]
    }

    only_item_ids: optional iterable of stable unit IDs. When provided, only the
    pages that contain those items are re-rendered (per-item / single-page
    re-render, §2.2 / §15). Other pages are copied through unchanged.

    allow_legacy_flat: opt-in escape hatch (default False). The legacy line-position
    flat-text mapper (legacy_text_to_manifest_items) is a LOSSY path retired as the
    default in Task 11. When a vocabulary page has no stable-ID contract (or the
    source-text bridge matches nothing), the default behaviour is to FAIL CLOSED —
    the page is routed to review (CONTRACT_BRIDGE_MISS) rather than silently mapped
    by line position. Set allow_legacy_flat=True only to deliberately fall back.
    """
    doc = pymupdf.open(input_pdf)
    total_pages = len(doc)
    _src_hash = _source_hash(input_pdf)  # for geometry caching (§19)

    # =====================================================================
    # REGION-GRAPH MODEL (§2.1) — build the canonical DocumentScene ONCE and
    # use it as the single driving data model. The diagnostic scene record and
    # the render both read from THIS graph, so there is no second, divergent
    # scene renderer. `scene_renderer.py` has been removed; the region graph now
    # lives inside the one production engine. Book-agnostic: derived from the PDF.
    # =====================================================================
    document_scene = None
    try:
        from document_model import build_document_scene
        document_scene = build_document_scene(input_pdf)
    except Exception:
        document_scene = None  # Fall back to flat per-span record if unavailable.

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

    # STABLE-ID CONTRACT (§2.2): if the translations payload is ID-mapped
    # (a top-level "items" list of {id, translation, page_number}), consume the
    # IDs DIRECTLY — no string reconstruction. We map each stable unit id to its
    # translation and, for renderers that work per-page, synthesise the per-page
    # flat text from the ID-mapped items in reading order so both paths converge
    # on one code path. `only_item_ids`, when provided, restricts rendering to
    # those items (per-item / single-page re-render).
    id_to_translation = {}
    contract_items = translations.get("items")
    if contract_items:
        for it in contract_items:
            iid = it.get("id")
            if not iid:
                continue
            # Preserve source_text + page_number when present so per-unit renderers
            # can bridge to the manifest by SOURCE TEXT (boundary-preserving, §2.2).
            # When only a translation string is present, store the bare string.
            if it.get("source_text") is not None or it.get("page_number") is not None:
                _entry = {
                    "translation": it.get("translation", ""),
                    "source_text": it.get("source_text", ""),
                    "page_number": it.get("page_number"),
                }
                # Structure-aware placement fields (spec Req 2/3), when present.
                for _k in ("semantic_role", "cell_box", "align_h", "align_v",
                           "peer_group_id", "column_span", "is_merged"):
                    if _k in it:
                        _entry[_k] = it[_k]
                id_to_translation[iid] = _entry
            else:
                id_to_translation[iid] = it.get("translation", "")
        # Derive per-page flat text (reading order) so the existing per-page
        # renderers keep working while being driven by the ID contract.
        by_page = {}
        for it in sorted(contract_items, key=lambda x: (x.get("page_number", 0),
                                                        x.get("reading_order", 0))):
            pn = it.get("page_number")
            if pn is None:
                continue
            by_page.setdefault(pn, []).append(it.get("translation", ""))
        for pn, parts in by_page.items():
            # Only fill pages the flat map does not already cover.
            translations_map.setdefault(pn, "\n".join(p for p in parts if p is not None))
    report["translation_contract"] = {
        "id_mapped": bool(contract_items),
        "item_count": len(id_to_translation),
        "only_item_ids": sorted(only_item_ids) if only_item_ids else None,
    }

    # PRE-RENDER TRANSLATION COMPARE: before rendering, compare the SOURCE content
    # against the TARGET translation for completeness / consistency / no-English-leak.
    # This is the language-level gate (distinct from the geometry gates). A failure
    # marks the affected pages for review so the edition can't be silently approved
    # with a wrong/incomplete translation. Skipped for scoped single-page re-renders.
    if not only_item_ids:
        try:
            from translation_compare import compare as _compare_translation
            pre = _compare_translation(input_pdf, translations, target_language="af")
            report["translation_compare"] = pre
            if not pre.get("ok"):
                report.setdefault("review_pages", [])
                for pn in pre.get("review_pages", []):
                    if pn not in report["review_pages"]:
                        report["review_pages"].append(pn)
        except Exception as e:
            report["translation_compare"] = {"skipped": True, "reason": str(e)}

    # Track coverage
    total_source_spans = 0
    total_translated = 0

    # PER-ITEM / SINGLE-PAGE RE-RENDER SCOPE (§2.2/§15): when only_item_ids is
    # given, only re-render the pages that own those items. Stable IDs encode the
    # page as the "pNN" prefix, so we derive the in-scope page set from the IDs.
    scoped_pages = None
    if only_item_ids:
        scoped_pages = set()
        import re as _re
        for iid in only_item_ids:
            m = _re.match(r"p(\d+)", str(iid))
            if m:
                scoped_pages.add(int(m.group(1)))
        report["translation_contract"]["scoped_pages"] = sorted(scoped_pages)

    # TYPOGRAPHY GROUP CONSISTENCY (§9.4): story pages share a style family, so they
    # must render at ONE consistent font size — not each page shrinking independently
    # (which produced 32/33/36/39pt across pages). Pre-pass: measure the largest size
    # that fits EACH story page, then use the smallest of those as the book-wide story
    # size so every story page matches. Book-agnostic: derived from the pages, no
    # hardcoded size. Skipped for scoped single-page re-renders (keeps existing size).
    forced_story_size = None
    if scoped_pages is None:
        story_fit_sizes = []
        for _pi in range(total_pages):
            _pn = _pi + 1
            if _pn not in translations_map or not translations_map[_pn].strip():
                continue
            _spans = _cached_page_spans(doc, _pi, _pn, _src_hash)
            if classify_page(_spans, _pn, total_pages) != "story":
                continue
            try:
                _fs = render_story_page_v8(doc[_pi], _spans, translations_map, fonts_dir,
                                           _pn, report, measure_only=True)
            except Exception:
                _fs = None
            if _fs:
                story_fit_sizes.append(_fs)
        if story_fit_sizes:
            forced_story_size = min(story_fit_sizes)
            report["story_typography_size"] = round(forced_story_size, 2)

    # Process each page
    for page_idx in range(total_pages):
        page_num = page_idx + 1
        page = doc[page_idx]

        if page_num not in translations_map:
            continue
        if not translations_map[page_num].strip():
            continue
        # Per-item re-render: skip pages not in scope (they are copied through
        # unchanged in the saved output — only the targeted page(s) change).
        if scoped_pages is not None and page_num not in scoped_pages:
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
        # §2.1: prefer the canonical region-graph PageScene so the diagnostic record
        # and the render read the SAME model. Fall back to the flat record only when
        # the scene graph could not be built.
        page_scene_obj = document_scene.get_page(page_num) if document_scene else None
        if page_scene_obj is not None:
            report.setdefault("scene", {})[str(page_num)] = \
                _scene_record_from_page_scene(page_scene_obj)
        else:
            report.setdefault("scene", {})[str(page_num)] = _build_page_scene_record(
                page_spans, page_type, page_num)

        # Track pre-render span count for coverage
        pre_render_replaced = report["spans_replaced"]

        # Render by type
        if page_type == 'cover':
            render_cover_page_v8(page, page_spans, translations_map, fonts_dir, page_num, report)
        elif page_type == 'story':
            render_story_page_v8(page, page_spans, translations_map, fonts_dir, page_num, report,
                                 forced_size=forced_story_size)
        elif page_type == 'vocabulary':
            # Book-agnostic scene-driven path (place by stable id + cell_box) when the
            # scene graph AND a stable-id contract are available; otherwise fall back to
            # the legacy page_spans path (which fails closed on no-contract per Task 11,
            # and only uses the lossy legacy flat mapper when allow_legacy_flat is set).
            _handled = None
            if page_scene_obj is not None and id_to_translation:
                _handled = render_vocabulary_page_from_scene(
                    page, page_scene_obj, id_to_translation, fonts_dir, page_num, report)
            # _handled is True/False when the scene path did the work (flagged or not);
            # None means it declined (no units / no contract) → legacy path, which
            # itself fails closed (CONTRACT_BRIDGE_MISS + review) unless allow_legacy_flat.
            if _handled is None:
                render_vocabulary_page_v8(page, page_spans, translations_map, fonts_dir, page_num, report,
                                          id_to_translation=id_to_translation or None,
                                          allow_legacy_flat=allow_legacy_flat,
                                          page_scene=page_scene_obj)
        elif page_type == 'back_cover':
            render_back_cover_v8(page, page_spans, translations_map, fonts_dir, page_num, report)
        elif page_type == 'copyright':
            render_copyright_page_v8(page, page_spans, translations_map, fonts_dir, page_num, report)

        # Track coverage per page (DIAGNOSTIC ONLY). The raw source-span vs replace-
        # call ratio is NOT a reliable defect signal — a structure-aware renderer
        # places one multi-line/merged element with a single call covering many source
        # spans, so a low ratio is expected and must NOT flag the page. The
        # AUTHORITATIVE per-element check is validate_structure (spec Req 4). We record
        # the ratio for diagnostics but do not route to review from it.
        page_replaced = report["spans_replaced"] - pre_render_replaced
        total_translated += page_replaced
        if page_replaced < len(content_spans) * 0.5:
            report["coverage"]["pages_with_gaps"].append({
                "page": page_num,
                "source_spans": len(content_spans),
                "replaced": page_replaced,
                "note": "diagnostic only; authoritative check is structure_gate",
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

    # Font resolution report + fail-closed on unresolved/unapproved fallback (§9.1/§9.3).
    # Collect the distinct SOURCE font families seen across all rendered pages so the
    # policy can verify each resolves to an APPROVED font.
    _requested_fonts = set()
    try:
        for pn_str, pscene in report.get("scene", {}).items():
            for u in pscene.get("units", []):
                fam = u.get("font_name")
                if fam:
                    _requested_fonts.add(fam)
    except Exception:
        pass
    # Also sample directly from the source doc's spans if scene lacked font names.
    if not _requested_fonts:
        try:
            _sdoc = pymupdf.open(input_pdf)
            for _pi in range(len(_sdoc)):
                for _sp in extract_page_spans(_sdoc[_pi], _pi + 1):
                    if _sp.get("font_name"):
                        _requested_fonts.add(_sp["font_name"])
            _sdoc.close()
        except Exception:
            pass
    report["font_resolution"] = _font_resolution_report(fonts_dir, _requested_fonts)
    if report["font_resolution"].get("unresolved") or report["font_resolution"].get("unapproved"):
        _reason = ("Unresolved font fallback: no usable font in fonts_dir"
                   if report["font_resolution"].get("unresolved")
                   else "Unapproved font used: resolution landed outside the approved font set")
        report.setdefault("errors", []).append({"page": 0, "error": _reason})
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
            fonts_dir=fonts_dir,
        )
        report["render_gate"] = gate
        if not gate["ok"]:
            report.setdefault("review_pages", [])
            for pn in gate["review_pages"]:
                if pn not in report["review_pages"]:
                    report["review_pages"].append(pn)

        # STRUCTURAL COMPARISON GATE (spec Req 4): compare rendered output against the
        # source structure element-by-element (present / in-box / peer-size / font).
        # Expected elements come from the source-derived contract (structure-populated
        # units). Book-agnostic. Any deviation flags its page (fail closed).
        try:
            from render_gate import validate_structure
            _expected = None
            if contract_items:
                _expected = contract_items
            elif document_scene is not None:
                _expected = document_scene.to_translation_request("af").get("items")
            if _expected:
                struct = validate_structure(_expected, output_pdf, fonts_dir=fonts_dir)
                report["structure_gate"] = {"ok": struct["ok"], "pages": struct["pages"]}
                if not struct["ok"]:
                    report.setdefault("review_pages", [])
                    for pn in struct["review_pages"]:
                        if pn not in report["review_pages"]:
                            report["review_pages"].append(pn)
        except Exception as e:
            report["structure_gate"] = {"skipped": True, "reason": str(e)}

        # TYPOGRAPHY HIERARCHY + MIN READABILITY (§10.1/§10.2): validate against
        # the region-graph scene sizes. Any violation routes its page to review.
        try:
            from render_gate import validate_typography
            typo = validate_typography(report)
            report["typography"] = typo
            if not typo["ok"]:
                report.setdefault("review_pages", [])
                for pn in typo["review_pages"]:
                    if pn not in report["review_pages"]:
                        report["review_pages"].append(pn)
        except Exception as e:
            report["typography"] = {"skipped": True, "reason": str(e)}
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

    # TRANSLATION CONSISTENCY (cross-page): the same SOURCE string must translate the
    # same way everywhere (e.g. the book title "A Fun Place" must not be "'n Plek Vol
    # Pret" on the cover but "'n Plek van Pret" on the imprint page). The layout gates
    # only check geometry, so this catches a SEMANTIC discrepancy the render engine
    # otherwise renders faithfully-but-inconsistently. Book-agnostic: derived from
    # repeated source strings, no per-title constants.
    try:
        _check_translation_consistency(input_pdf, translations, report)
    except Exception as e:
        report.setdefault("consistency", {})["error"] = str(e)

    # Fail-closed publication signal: engine reports whether the render is
    # publishable. The caller MUST NOT approve when publishable is False.
    report["publishable"] = not report.get("review_pages")
    report["render_status"] = "READY_FOR_REVIEW" if report["publishable"] else "NEEDS_LAYOUT_REVIEW"

    return report


# =============================================================================
# CLI
# =============================================================================

# =============================================================================
# INTERACTIVE OVERLAY DATA (brief §15) — page image + region overlay boxes
# =============================================================================

def build_overlay_data(rendered_pdf, page_number, manifest_path, image_out, dpi=110):
    """
    Render the given page of the translated PDF to a PNG and return overlay data:
    per-region boxes (source bounds, safe inner bounds, rendered glyph bounds) in
    IMAGE PIXEL coordinates, plus status/type/failure reasons, so the admin UI can
    draw toggleable overlays (source boxes, safe boxes, glyph bounds, reading order,
    collisions, clipped areas, font info) with invalid regions highlighted in red.

    Book-agnostic: everything comes from the rendered geometry + the manifest.
    """
    import json as _json
    with open(manifest_path, "r", encoding="utf-8") as f:
        manifest = _json.load(f)

    page_entry = None
    for p in manifest.get("pages", []):
        if p.get("page") == page_number:
            page_entry = p
            break

    doc = pymupdf.open(rendered_pdf)
    if page_number - 1 >= len(doc):
        doc.close()
        raise ValueError(f"page {page_number} out of range")
    page = doc[page_number - 1]
    scale = dpi / 72.0
    pix = page.get_pixmap(dpi=dpi)
    os.makedirs(os.path.dirname(image_out), exist_ok=True)
    pix.save(image_out)
    doc.close()

    def _to_px(b):
        if not b:
            return None
        return [round(b[0] * scale, 1), round(b[1] * scale, 1),
                round(b[2] * scale, 1), round(b[3] * scale, 1)]

    regions_out = []
    if page_entry:
        for r in page_entry.get("regions", []):
            reasons = []
            # A region is 'invalid' when the page failed and its type is implicated,
            # or it reports clipped glyphs / bad visual scale.
            if r.get("clippedGlyphCount"):
                reasons.append(f"{r['clippedGlyphCount']} clipped glyph(s)")
            vsr = r.get("visualScaleRatio")
            if vsr is not None and (vsr < 0.6 or vsr > 1.6):
                reasons.append(f"visual scale {vsr}")
            regions_out.append({
                "regionId": r.get("regionId"),
                "semanticType": r.get("semanticType"),
                "sourceBounds": _to_px(r.get("sourceBounds")),
                "safeInnerBounds": _to_px(r.get("safeInnerBounds")),
                "renderedGlyphBounds": _to_px(r.get("renderedGlyphBounds")),
                "readingOrderIndex": len(regions_out),
                "visualScaleRatio": vsr,
                "lineHeight": r.get("lineHeight"),
                "tracking": r.get("tracking"),
                "clippedGlyphCount": r.get("clippedGlyphCount", 0),
                "invalid": bool(reasons),
                "reasons": reasons,
            })

    # TASK 12: per-element structural comparison verdict — which logical element
    # deviated from the source structure and why. Boxes converted to image pixels so
    # the UI can highlight the exact element that failed.
    structure_deviations = []
    if page_entry:
        for d in page_entry.get("structureDeviations", []):
            structure_deviations.append({
                "elementId": d.get("elementId"),
                "role": d.get("role"),
                "constraint": d.get("constraint"),
                "detail": d.get("detail"),
                "box": _to_px(d.get("box")),
            })

    return {
        "page": page_number,
        "page_type": page_entry.get("page_type") if page_entry else None,
        "status": page_entry.get("status") if page_entry else "OK",
        "image": os.path.basename(image_out),
        "image_width": pix.width,
        "image_height": pix.height,
        "dpi": dpi,
        "regions": regions_out,
        "structureDeviations": structure_deviations,
        "structureOk": page_entry.get("structureOk", True) if page_entry else True,
        "failureReasons": page_entry.get("failureReasons", []) if page_entry else [],
        "typographyFailures": page_entry.get("typographyFailures", []) if page_entry else [],
    }


def _check_translation_consistency(input_pdf, translations, report):
    """
    Cross-page translation-consistency check. The SAME source string translated
    differently on different pages is a real defect the layout gates cannot see
    (e.g. the book title rendered three different ways). We detect it by:

      1. extracting, per source page, the prominent SHORT strings (title-like:
         the largest-font line and other short lines), keyed by normalised source;
      2. pairing each source page with its translated text (from `translations`);
      3. finding a source string that appears on multiple pages but whose page
         translations diverge — the clearest, book-agnostic signal being the BOOK
         TITLE (the cover's dominant display line), which recurs on the imprint and
         back-cover pages.

    Flags a `consistency` report section and routes affected pages to review
    (fail-closed) so a human reconciles the wording. Book-agnostic: no title text
    is hardcoded; the title is discovered as the cover's largest display string.
    """
    import difflib

    # Build per-page translated text.
    tmap = {p["page_number"]: (p.get("translated_text") or "")
            for p in translations.get("pages", [])}
    if not tmap:
        # ID-mapped contract: reconstruct per-page target from items.
        for it in translations.get("items", []):
            pn = it.get("page_number")
            if pn is not None:
                tmap.setdefault(pn, "")
                tmap[pn] = (tmap[pn] + "\n" + (it.get("translation") or "")).strip()
    if not tmap:
        return

    doc = pymupdf.open(input_pdf)
    total = len(doc)

    # 1. Discover the book title from the cover (page 1): the largest-font line.
    def _page_lines_by_size(page, page_num):
        spans = [s for s in extract_page_spans(page, page_num) if not s.get("is_page_number")]
        return spans

    cover_spans = _page_lines_by_size(doc[0], 1) if total >= 1 else []
    title_src = None
    if cover_spans:
        # Largest-font display span with >2 letters is the title.
        big = sorted(cover_spans, key=lambda s: s.get("font_size", 0), reverse=True)
        for s in big:
            t = s.get("text_stripped", "")
            if len(re.sub(r"[^A-Za-z]", "", t)) > 2 and "studio" not in t.lower():
                title_src = t
                break

    findings = []
    if title_src:
        norm_title = re.sub(r"\s+", " ", title_src).strip().lower()
        # Find which source pages contain the title string.
        title_pages = []
        for pi in range(total):
            spans = _page_lines_by_size(doc[pi], pi + 1)
            joined = " ".join(s.get("text_stripped", "") for s in spans).lower()
            if norm_title and norm_title in re.sub(r"\s+", " ", joined):
                title_pages.append(pi + 1)

        # For each such page, extract the candidate translated title = the first
        # prominent (non-publisher) line of that page's translation.
        def _candidate_title(text):
            for ln in [l.strip() for l in text.split("\n") if l.strip()]:
                low = ln.lower()
                if "studio" in low or "mthombothi" in low:
                    continue
                if re.sub(r"[^A-Za-z]", "", ln):
                    return ln
            return ""

        title_translations = {}
        for pn in title_pages:
            cand = _candidate_title(tmap.get(pn, ""))
            if cand:
                title_translations[pn] = cand

        # Divergence: more than one DISTINCT translation (fuzzy) => inconsistent.
        distinct = []
        for pn, t in title_translations.items():
            tl = t.strip().lower()
            if not any(difflib.SequenceMatcher(None, tl, d).ratio() >= 0.85 for d in distinct):
                distinct.append(tl)
        if len(distinct) > 1:
            findings.append({
                "type": "title_inconsistent",
                "source_title": title_src,
                "translations_by_page": title_translations,
                "distinct_count": len(distinct),
            })
            report.setdefault("review_pages", [])
            for pn in title_translations:
                if pn not in report["review_pages"]:
                    report["review_pages"].append(pn)

    doc.close()
    report["consistency"] = {
        "checked": True,
        "title_source": title_src,
        "findings": findings,
        "ok": len(findings) == 0,
    }


def main():
    parser = argparse.ArgumentParser(description="PDF Translation Engine V8 — Per-Span Replacement")
    subparsers = parser.add_subparsers(dest="command")

    replace_p = subparsers.add_parser("replace", help="Replace text in PDF")
    replace_p.add_argument("--input", "-i", required=True)
    replace_p.add_argument("--output", "-o", required=True)
    replace_p.add_argument("--translations", "-t", required=True)
    replace_p.add_argument("--fonts-dir", "-f")
    replace_p.add_argument("--only-items",
                          help="Comma-separated stable item IDs to re-render (per-item / single-page)")
    replace_p.add_argument("--allow-legacy-flat", action="store_true",
                          help="Opt in to the retired lossy legacy flat-text mapper for pages "
                               "with no contract (default: fail closed to review — Task 11)")
    replace_p.add_argument("--validate", action="store_true",
                          help="Run structural validation after rendering (default: always on)")

    # STABLE-ID CONTRACT (§2.2): emit the page->region->item translation request
    # with stable IDs so the translation layer (PHP) can store and return IDs.
    contract_p = subparsers.add_parser("contract",
                                       help="Emit the stable-ID translation contract for a PDF")
    contract_p.add_argument("--input", "-i", required=True)
    contract_p.add_argument("--target-language", "-l", default="af")
    contract_p.add_argument("--output", "-o", help="Write contract JSON here (default: stdout)")

    # INTERACTIVE OVERLAY DATA (§15): render a page image + region overlay boxes
    # (source bounds, safe inner bounds, rendered glyph bounds, status) in IMAGE
    # pixel coordinates, so the admin UI can draw toggleable overlays over the PNG.
    overlay_p = subparsers.add_parser("overlay-data",
                                      help="Emit page image + region overlay boxes for the admin debug overlay")
    overlay_p.add_argument("--rendered", "-r", required=True, help="Rendered (translated) PDF path")
    overlay_p.add_argument("--page", "-p", type=int, required=True, help="1-based page number")
    overlay_p.add_argument("--manifest", "-m", required=True, help="Diagnostic manifest JSON path")
    overlay_p.add_argument("--image-out", required=True, help="Where to write the page PNG")
    overlay_p.add_argument("--dpi", type=int, default=110)
    overlay_p.add_argument("--output", "-o", help="Write overlay JSON here (default: stdout)")

    args = parser.parse_args()

    if args.command == "replace":
        with open(args.translations, 'r', encoding='utf-8') as f:
            translations = json.load(f)

        only_items = None
        if getattr(args, "only_items", None):
            only_items = [s.strip() for s in args.only_items.split(",") if s.strip()]

        report = replace_text_in_pdf(
            input_pdf=args.input,
            output_pdf=args.output,
            translations=translations,
            fonts_dir=args.fonts_dir,
            only_item_ids=only_items,
            allow_legacy_flat=getattr(args, "allow_legacy_flat", False),
        )
        print(json.dumps(report, indent=2, ensure_ascii=False), file=sys.stderr)
        print(args.output)
    elif args.command == "contract":
        from document_model import build_document_scene
        scene = build_document_scene(args.input)
        contract = scene.to_translation_request(args.target_language)
        payload = json.dumps(contract, indent=2, ensure_ascii=False)
        if args.output:
            with open(args.output, "w", encoding="utf-8") as f:
                f.write(payload)
            print(args.output)
        else:
            print(payload)
    elif args.command == "overlay-data":
        payload = build_overlay_data(
            rendered_pdf=args.rendered,
            page_number=args.page,
            manifest_path=args.manifest,
            image_out=args.image_out,
            dpi=args.dpi,
        )
        out_json = json.dumps(payload, indent=2, ensure_ascii=False)
        if args.output:
            with open(args.output, "w", encoding="utf-8") as f:
                f.write(out_json)
            print(args.output)
        else:
            print(out_json)
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
