"""
PDF Text Replacement Engine V7 — Digital Bookstore
=====================================================
Uses PyMuPDF's insert_htmlbox() for proper HTML/CSS text rendering.

V7 FEATURES:
  - Smart containers: detects image bottom → page number top per page
  - Auto font size: binary searches max font that fits tightest page
  - Vertical centering: text centered within container space
  - Container width matches image width (not fixed margins)
  - Consistent font across ALL story pages — auto-adapts to any language

CALIBRATED from the original PDF "Kolulu Engl Series 3 - 2 - A Fun Place":
  - Page size: 538 x 751 pts
  - Story pages: auto-calculated font (Playpen Sans), left-aligned, line-height ~1.17
  - Cover subtitle: 49px, centered, purple (#3d2c7c)
  - Back cover: 24px, centered, title list
  - Vocabulary: 9px, 4-column layout with manual line drawing

Usage:
    python pdf_translate.py extract --input book.pdf --output metadata.json
    python pdf_translate.py replace --input book.pdf --output book_af.pdf --translations translations.json --fonts-dir ./fonts
"""

import argparse
import json
import os
import re
import sys
from pathlib import Path

import pymupdf


# =============================================================================
# PAGE TYPE DETECTION
# =============================================================================

def classify_page(page, page_num, total_pages, text_blocks):
    """
    Classify a page into one of:
    - 'cover': Front cover (page 1)
    - 'copyright': Copyright/info page (page 2) - skip translation
    - 'story': Main story pages (illustration + text below)
    - 'vocabulary': Word list/table page (usually second-to-last)
    - 'back_cover': Back cover (last page, often colored background)
    """
    if page_num == 1:
        return 'cover'
    if page_num == 2:
        return 'copyright'
    if page_num == total_pages:
        return 'back_cover'

    # Vocabulary page detection: lots of small text spans
    small_text_count = 0
    large_text_count = 0
    for block in text_blocks:
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                if span["text"].strip():
                    if span["size"] < 15:
                        small_text_count += 1
                    else:
                        large_text_count += 1

    if small_text_count > 30 and large_text_count < 5:
        return 'vocabulary'

    return 'story'


# =============================================================================
# BACKGROUND COLOR DETECTION
# =============================================================================

def detect_page_background(page, sample_y=None):
    """
    Detect the page background color by sampling from multiple areas.
    Uses majority voting across sample points for robustness.
    Returns (r, g, b) as floats 0-1.
    """
    pw = page.rect.width
    ph = page.rect.height

    # Sample from edges and corners — areas unlikely to have content
    sample_rects = [
        pymupdf.Rect(5, ph - 25, 40, ph - 5),       # bottom-left corner
        pymupdf.Rect(pw - 40, ph - 25, pw - 5, ph - 5),  # bottom-right corner
        pymupdf.Rect(5, 5, 40, 25),                  # top-left corner
        pymupdf.Rect(pw - 40, 5, pw - 5, 25),       # top-right corner
        pymupdf.Rect(pw // 2 - 10, ph - 20, pw // 2 + 10, ph - 5),  # bottom-center
        pymupdf.Rect(5, ph // 2 - 10, 20, ph // 2 + 10),  # left-center
        pymupdf.Rect(pw - 20, ph // 2 - 10, pw - 5, ph // 2 + 10),  # right-center
    ]

    if sample_y:
        # Sample at the left edge at the target Y position
        sample_rects.append(pymupdf.Rect(5, sample_y, 25, sample_y + 15))
        sample_rects.append(pymupdf.Rect(pw - 25, sample_y, pw - 5, sample_y + 15))

    colors = []
    for rect in sample_rects:
        rect = rect & page.rect
        if rect.is_empty or rect.width < 2 or rect.height < 2:
            continue
        try:
            pix = page.get_pixmap(clip=rect, dpi=72)
            if pix.width > 0 and pix.height > 0:
                # Sample multiple pixels for better accuracy
                for px, py in [(pix.width // 2, pix.height // 2),
                               (1, 1), (pix.width - 2, pix.height - 2)]:
                    if 0 <= px < pix.width and 0 <= py < pix.height:
                        pixel = pix.pixel(px, py)
                        colors.append((pixel[0], pixel[1], pixel[2]))
        except Exception:
            continue

    if not colors:
        return (1.0, 1.0, 1.0)

    # Group colors by similarity and find the dominant non-white color
    non_white = [(c[0] / 255.0, c[1] / 255.0, c[2] / 255.0)
                 for c in colors
                 if not (c[0] > 245 and c[1] > 245 and c[2] > 245)]

    if non_white:
        # Average the non-white colors for a smooth result
        avg_r = sum(c[0] for c in non_white) / len(non_white)
        avg_g = sum(c[1] for c in non_white) / len(non_white)
        avg_b = sum(c[2] for c in non_white) / len(non_white)
        return (avg_r, avg_g, avg_b)

    return (1.0, 1.0, 1.0)


# =============================================================================
# TEXT ZONE DETECTION
# =============================================================================

def find_text_zone(page, text_blocks):
    """
    Find the bounding rectangle of ALL text content on the page.
    """
    min_x, min_y = page.rect.width, page.rect.height
    max_x, max_y = 0, 0

    for block in text_blocks:
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                if not span["text"].strip():
                    continue
                bbox = span["bbox"]
                min_x = min(min_x, bbox[0])
                min_y = min(min_y, bbox[1])
                max_x = max(max_x, bbox[2])
                max_y = max(max_y, bbox[3])

    if max_x == 0:
        return None

    return pymupdf.Rect(min_x, min_y, max_x, max_y)


def find_story_text_zone(page, text_blocks):
    """
    For story pages: find the text zone (excluding page numbers).
    Returns the bounding rect of the main story text spans only.
    
    Original PDF characteristics:
    - Story text is 39px (English); translated at 33px (longer words)
    - Page numbers are 17px at y=667-687
    - Text x ranges from ~63-475
    - Text y varies: starts between 338-517, ends around 607-653
    """
    story_spans = []
    page_number_spans = []

    for block in text_blocks:
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                text = span["text"].strip()
                if not text:
                    continue
                # Page numbers: small font, 1-2 digits, near bottom
                if span["size"] < 20 and re.match(r'^\d{1,2}$', text):
                    page_number_spans.append(span)
                else:
                    story_spans.append(span)

    if not story_spans:
        return None

    # Get bounding box of story text only
    first_y = min(s["bbox"][1] for s in story_spans)
    last_y = max(s["bbox"][3] for s in story_spans)
    min_x = min(s["bbox"][0] for s in story_spans)
    max_x = max(s["bbox"][2] for s in story_spans)

    return pymupdf.Rect(min_x, first_y, max_x, last_y)


# =============================================================================
# REDACTION (ERASE TEXT)
# =============================================================================

def erase_text_in_rect(page, zone_rect, fill_color=(1, 1, 1), keep_page_numbers=True):
    """
    Erase ALL text within the specified zone using redactions.
    Optionally keeps standalone page numbers (1-2 digit numbers at small font size).
    """
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)

    has_redaction = False
    for block in text_dict.get("blocks", []):
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                text = span["text"].strip()
                if not text:
                    continue
                span_rect = pymupdf.Rect(span["bbox"])
                if not span_rect.intersects(zone_rect):
                    continue
                # Keep page numbers if requested
                if keep_page_numbers and span["size"] < 20 and re.match(r'^\d{1,2}$', text):
                    continue
                page.add_redact_annot(span_rect, text="", fill=fill_color)
                has_redaction = True

    if has_redaction:
        page.apply_redactions()

    return has_redaction


# =============================================================================
# TEXT CLEANING
# =============================================================================

def clean_translated_text(text, page_type='story'):
    """
    Clean up GPT-translated text artifacts:
    - Remove leading page numbers
    - Remove trailing backslashes
    - Remove GPT preamble
    - Normalize whitespace (join lines into flowing text for story pages)
    """
    if not text:
        return ""

    clean = text.strip()

    # Remove GPT preamble like "Hier is bladsy 15 om aan te pas:"
    clean = re.sub(r'^Hier is bladsy \d+.*?:\s*\n?', '', clean, flags=re.IGNORECASE)
    # Remove "Here is page X..." preamble
    clean = re.sub(r'^Here is page \d+.*?:\s*\n?', '', clean, flags=re.IGNORECASE)

    # Remove leading page numbers (e.g., "1\n" or "6  \n")
    clean = re.sub(r'^\d{1,2}\s*\\?\s*\n', '', clean)
    # Also handle "1  " at start without newline
    clean = re.sub(r'^\d{1,2}\s{2,}', '', clean)

    # Remove trailing backslashes (GPT artifact from line-by-line translation)
    clean = re.sub(r'\s*\\\s*$', '', clean, flags=re.MULTILINE)
    clean = re.sub(r'\s*\\$', '', clean, flags=re.MULTILINE)

    if page_type == 'story':
        # For story pages: join all lines into flowing paragraph
        # The GPT output mimics original line breaks, but we want free-flowing text
        # that insert_htmlbox will wrap correctly
        lines = [l.strip() for l in clean.split('\n') if l.strip()]
        clean = ' '.join(lines)
        # Clean up double spaces
        clean = re.sub(r'\s{2,}', ' ', clean)
    elif page_type == 'back_cover':
        # Keep line breaks for title lists
        lines = [l.strip() for l in clean.split('\n') if l.strip()]
        clean = '\n'.join(lines)
    elif page_type == 'cover':
        # For cover: filter out publisher name, symbols
        lines = [l.strip() for l in clean.split('\n') if l.strip()]
        filtered = []
        skip_patterns = [r'studios?', r'mthombothi', r'^[®©]$', r'^MTHOMBOTHI']
        for line in lines:
            skip = False
            for pat in skip_patterns:
                if re.search(pat, line, re.IGNORECASE):
                    skip = True
                    break
            if not skip and line not in ['®', '©']:
                filtered.append(line)
        clean = ' '.join(filtered) if filtered else clean
    elif page_type == 'vocabulary':
        # Keep structure but remove preamble
        pass

    return clean.strip()


# =============================================================================
# TEXT RENDERING WITH insert_htmlbox
# =============================================================================

def build_font_css(fonts_dir):
    """Build CSS @font-face declarations for Playpen Sans."""
    css = ""
    font_files = {
        "Regular": "PlaypenSans-Regular.ttf",
        "Bold": "PlaypenSans-Bold.ttf",
        "Medium": "PlaypenSans-Medium.ttf",
        "SemiBold": "PlaypenSans-SemiBold.ttf",
    }

    for weight_name, filename in font_files.items():
        filepath = os.path.join(fonts_dir, filename)
        if os.path.isfile(filepath):
            weight = {"Regular": "normal", "Bold": "bold", "Medium": "500", "SemiBold": "600"}[weight_name]
            css += f'@font-face {{font-family: "PlaypenSans"; src: url({filename}); font-weight: {weight};}}\n'

    return css


def render_story_page(page, translated_text, fonts_dir, page_num, report, font_size=26, container=None):
    """
    V7 Engine: Render a story page with smart containers.
    
    Container is detected externally (image bottom → page number top, image width).
    Font size is pre-calculated to fit the tightest page across all story pages.
    Text is vertically centered within the container.
    
    Args:
        page: PyMuPDF page object
        translated_text: translated text string
        fonts_dir: path to fonts directory
        page_num: 1-based page number
        report: report dict
        font_size: pre-calculated optimal font size (from calculate_optimal_font_size)
        container: dict with keys {top, bottom, left, right} or None for auto-detect
    """
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
    blocks = text_dict.get("blocks", [])

    # Find the story text zone (used for erasing original text)
    text_zone = find_story_text_zone(page, blocks)
    if not text_zone:
        report["errors"].append({"page": page_num, "error": "No text zone found"})
        return

    # Clean translated text — join into single flowing paragraph
    clean_text = clean_translated_text(translated_text, page_type='story')
    if not clean_text:
        return

    # Detect container if not provided
    if container is None:
        container = detect_story_container(page, blocks)

    # Erase original text in zone (keep page numbers)
    erase_rect = pymupdf.Rect(
        text_zone.x0 - 10,
        text_zone.y0 - 5,
        text_zone.x1 + 10,
        text_zone.y1 + 5
    )
    erase_text_in_rect(page, erase_rect, fill_color=(1, 1, 1), keep_page_numbers=True)

    # Build HTML
    html = f'<p>{clean_text}</p>'

    font_css = build_font_css(fonts_dir)
    css = font_css + f"""
    * {{
        font-family: "PlaypenSans", sans-serif;
        font-size: {font_size}px;
        line-height: 1.17;
        color: #000000;
    }}
    p {{
        margin: 0;
        text-align: left;
    }}
    """

    arch = pymupdf.Archive(fonts_dir)

    # Full container rect (for measuring text height)
    full_rect = pymupdf.Rect(
        container['left'], container['top'],
        container['right'], container['bottom']
    )
    container_height = container['bottom'] - container['top']

    # Pass 1: Measure text height using a dry run on a temp page
    temp_doc = pymupdf.open()
    temp_page = temp_doc.new_page(width=page.rect.width, height=page.rect.height)
    result = temp_page.insert_htmlbox(full_rect, html, css=css, archive=arch, scale_low=1.0)
    temp_doc.close()

    if isinstance(result, tuple):
        spare_height, scale = result
    else:
        spare_height = result
        scale = 1.0

    # Calculate vertical centering offset
    if spare_height > 0:
        text_height = container_height - spare_height
        vertical_offset = spare_height / 2  # center vertically
    else:
        # Text overflows — no centering, just place at top
        vertical_offset = 0

    # Final textbox rect — vertically centered
    textbox_rect = pymupdf.Rect(
        container['left'],
        container['top'] + vertical_offset,
        container['right'],
        container['bottom']
    )

    try:
        result = page.insert_htmlbox(textbox_rect, html, css=css, archive=arch, scale_low=1.0)
        if isinstance(result, tuple):
            spare_height, scale = result
        else:
            spare_height = result
            scale = 1.0
        if spare_height < 0:
            report["overflow_warnings"].append({
                "page": page_num,
                "note": f"Text overflow at {font_size}px! spare={spare_height:.1f}"
            })
        report["spans_replaced"] += 1
    except Exception as e:
        report["errors"].append({
            "page": page_num,
            "error": f"insert_htmlbox failed: {str(e)}"
        })


def detect_story_container(page, blocks=None):
    """
    V7: Detect the text container for a story page.
    
    Container boundaries:
    - Top: bottom of illustration/image
    - Bottom: top of page number (or page height - 60)
    - Left/Right: match the image x-bounds (or fall back to margins)
    
    Returns dict: {top, bottom, left, right}
    """
    if blocks is None:
        text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
        blocks = text_dict.get("blocks", [])

    # Detect illustration bounds
    img_bottom = 0
    img_left = page.rect.width
    img_right = 0

    for img in page.get_image_info():
        bbox = img.get('bbox', (0, 0, 0, 0))
        if bbox[3] > img_bottom:
            img_bottom = bbox[3]
        if bbox[0] < img_left:
            img_left = bbox[0]
        if bbox[2] > img_right:
            img_right = bbox[2]

    # Also check vector drawings (but exclude page border lines)
    for d in page.get_drawings():
        drect = d.get('rect', pymupdf.Rect(0, 0, 0, 0))
        if drect.y1 > img_bottom and drect.y1 < 650 and drect.x0 > 30:
            img_bottom = drect.y1

    # Detect page number position (small text, 1-2 digits, near bottom)
    page_num_top = page.rect.height - 60  # default if not found
    for block in blocks:
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                import re
                text = span["text"].strip()
                if span["size"] < 20 and re.match(r'^\d{1,2}$', text) and span["bbox"][1] > 600:
                    page_num_top = span["bbox"][1] - 10  # 10px above page number
                    break

    # Container boundaries
    container_top = img_bottom + 5 if img_bottom > 0 else 300  # small gap below image
    container_bottom = page_num_top

    # Width: match image width, or fall back to page margins
    if img_right > img_left:
        container_left = img_left
        container_right = img_right
    else:
        container_left = 65
        container_right = page.rect.width - 65

    return {
        'top': container_top,
        'bottom': container_bottom,
        'left': container_left,
        'right': container_right
    }


def calculate_optimal_font_size(doc, story_pages, translations, fonts_dir):
    """
    V7: Calculate the maximum font size that fits ALL story pages without overflow.
    
    Binary searches between 15px and 40px to find the largest font that works
    for the tightest page (most text in smallest container).
    
    Args:
        doc: PyMuPDF document (original PDF)
        story_pages: list of (page_idx, page_num) tuples for story pages
        translations: dict mapping page_num → translated_text
        fonts_dir: path to fonts directory
    
    Returns:
        (optimal_font_size, containers) — font size as float, and dict of page_num → container
    """
    font_css = build_font_css(fonts_dir)
    
    # First, detect containers for all story pages
    containers = {}
    page_texts = {}
    
    for page_idx, page_num in story_pages:
        page = doc[page_idx]
        text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
        blocks = text_dict.get("blocks", [])
        
        container = detect_story_container(page, blocks)
        containers[page_num] = container
        
        # Clean the translated text
        translated_text = translations.get(page_num, '')
        clean_text = clean_translated_text(translated_text, page_type='story')
        page_texts[page_num] = clean_text or ''
    
    def test_font_size(font_size):
        """Test if all story pages fit at given font size. Returns True if all fit."""
        css = font_css + f"""
        * {{
            font-family: "PlaypenSans", sans-serif;
            font-size: {font_size}px;
            line-height: 1.17;
            color: #000000;
        }}
        p {{ margin: 0; text-align: left; }}
        """
        
        for page_idx, page_num in story_pages:
            clean_text = page_texts.get(page_num, '')
            if not clean_text:
                continue
            
            container = containers[page_num]
            rect = pymupdf.Rect(
                container['left'], container['top'],
                container['right'], container['bottom']
            )
            
            html = f'<p>{clean_text}</p>'
            
            temp_doc = pymupdf.open()
            temp_page = temp_doc.new_page(width=doc[0].rect.width, height=doc[0].rect.height)
            arch = pymupdf.Archive(fonts_dir)
            
            result = temp_page.insert_htmlbox(rect, html, css=css, archive=arch, scale_low=1.0)
            temp_doc.close()
            
            if isinstance(result, tuple):
                spare = result[0]
            else:
                spare = result
            
            if spare < 0:
                return False
        
        return True
    
    # Binary search for optimal font size
    low, high = 15.0, 40.0
    
    while high - low > 0.5:
        mid = (low + high) / 2
        if test_font_size(mid):
            low = mid
        else:
            high = mid
    
    # Use the floor value to be safe
    optimal_size = int(low)
    
    return optimal_size, containers


def detect_color_at_point(page, x, y, radius=5):
    """
    Sample the actual pixel color at a specific point on the page.
    Uses a small area around the point for robustness.
    Returns (r, g, b) as floats 0-1.
    """
    rect = pymupdf.Rect(x - radius, y - radius, x + radius, y + radius)
    rect = rect & page.rect
    if rect.is_empty:
        return (1.0, 1.0, 1.0)
    try:
        pix = page.get_pixmap(clip=rect, dpi=72)
        if pix.width > 0 and pix.height > 0:
            pixel = pix.pixel(pix.width // 2, pix.height // 2)
            return (pixel[0] / 255.0, pixel[1] / 255.0, pixel[2] / 255.0)
    except Exception:
        pass
    return (1.0, 1.0, 1.0)


def render_cover_page(page, translated_text, fonts_dir, page_num, report):
    """
    Render the front cover subtitle only.
    
    Original characteristics:
    - Subtitle "A Fun Place": 49px, at y=601-663, x=117-421
    - Publisher "MTHOMBOTHI STUDIOS": 16.8px at y=677 — DO NOT TOUCH
    - Registration mark "®": 49.1px — DO NOT TOUCH
    - Centered text, purple color
    
    Strategy: Instead of redacting (which leaves visible fill rectangles on
    artwork backgrounds), we draw a filled rectangle over the subtitle area
    using the page background color, then render the new text on top.
    """
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
    blocks = text_dict.get("blocks", [])

    # Find the subtitle span(s) — large text (>= 40px) that is NOT a symbol
    subtitle_spans = []
    for block in blocks:
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                text = span["text"].strip()
                if not text or len(text) <= 2:
                    continue
                if span["size"] >= 40:
                    subtitle_spans.append(span)

    if not subtitle_spans:
        report["errors"].append({"page": page_num, "error": "No subtitle found on cover"})
        return

    # Get subtitle bounding box
    sub_min_x = min(s["bbox"][0] for s in subtitle_spans)
    sub_min_y = min(s["bbox"][1] for s in subtitle_spans)
    sub_max_x = max(s["bbox"][2] for s in subtitle_spans)
    sub_max_y = max(s["bbox"][3] for s in subtitle_spans)

    # Detect background color by sampling at left and right edges at subtitle height
    # Note: page has a white border frame ~50px wide, so sample INSIDE at x=80
    bg_color = detect_color_at_point(page, 80, (sub_min_y + sub_max_y) / 2)

    # Draw a filled rectangle over the subtitle to cover old text
    # This is better than redaction because it doesn't leave visible edges
    cover_rect = pymupdf.Rect(
        sub_min_x - 10,
        sub_min_y - 3,
        sub_max_x + 10,
        sub_max_y + 3
    )
    shape = page.new_shape()
    shape.draw_rect(cover_rect)
    shape.finish(color=bg_color, fill=bg_color)
    shape.commit()

    # Clean translated subtitle
    clean_text = clean_translated_text(translated_text, page_type='cover')
    if not clean_text:
        return

    # Build HTML
    html = f'<p>{clean_text}</p>'

    font_css = build_font_css(fonts_dir)
    css = font_css + """
    * {
        font-family: "PlaypenSans", sans-serif;
        font-size: 49px;
        line-height: 1.2;
        color: #3d2c7c;
    }
    p {
        margin: 0;
        text-align: center;
    }
    """

    arch = pymupdf.Archive(fonts_dir)

    # Textbox at the subtitle position — expanded vertically for longer translations
    textbox_rect = pymupdf.Rect(
        40,
        sub_min_y - 10,
        page.rect.width - 40,
        sub_max_y + 15
    )

    try:
        result = page.insert_htmlbox(textbox_rect, html, css=css, archive=arch)
        if isinstance(result, tuple):
            unused = result[0]
        else:
            unused = result
        if unused < 0:
            report["overflow_warnings"].append({
                "page": page_num,
                "note": f"Cover subtitle overflowed, auto-scaled"
            })
        report["spans_replaced"] += 1
    except Exception as e:
        report["errors"].append({
            "page": page_num,
            "error": f"Cover insert_htmlbox failed: {str(e)}"
        })


def render_copyright_page(page, translated_text, fonts_dir, page_num, report):
    """
    Render the copyright/title page (typically page 2).
    
    Original layout (Kolulu Taktaki):
    ┌─────────────────────────────────┐
    │   KOLULU TAKTAKI artwork        │  y=84-262  DO NOT TOUCH
    │   "A Fun Place" subtitle        │  y=271-332 (49px, centered)
    │─────────────────────────────────│  y~370 divider
    │ LOGO    │         PHOTO         │  y=392-531
    │ Publisher│                       │
    │ info    │   Character bio text  │  y=537-629
    │         │                       │
    │ Copyright paragraph             │  y=618-655 (full width left)
    └─────────────────────────────────┘
    
    Strategy:
    1. Only touch TEXT below y=370 (leave title artwork + subtitle alone)
    2. Only erase text spans in the lower zone (not the subtitle "A Fun Place")
    3. Render left column: publisher info + copyright paragraph
    4. Render right column: character bio (below photo)
    5. Preserve all images (MTHOMBOTHI logo + child photo) untouched
    """
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
    blocks = text_dict.get("blocks", [])

    # Detect the subtitle position — large text (>= 40px) that is the book subtitle
    # We need to replace THIS with the translated subtitle, and leave everything else
    subtitle_spans = []
    lower_text_spans = []
    
    for block in blocks:
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                text = span["text"].strip()
                if not text:
                    continue
                # Subtitle: large font (>=40px), not a symbol
                if span["size"] >= 40 and len(text) > 2:
                    subtitle_spans.append(span)
                # Lower zone text: small font, below y=370
                elif span["bbox"][1] > 370 and span["size"] < 20:
                    lower_text_spans.append(span)

    # Parse the translated text into sections
    clean_text = translated_text.strip()
    lines = [l.strip() for l in clean_text.split('\n') if l.strip()]
    
    # Remove ® symbol line
    lines = [l for l in lines if l not in ['®', '©', '\uf8e8', '\u00ae']]
    
    if not lines:
        return

    # First line is the subtitle translation
    subtitle_text = lines[0]
    remaining_lines = lines[1:]
    
    # Split remaining into publisher info vs character bio
    # The character bio starts with "Die naam en karakter" or similar
    # It's typically the paragraph about the character/author
    # Detection: find the line that starts the character description
    # (it's after a blank section and talks about "naam" or "karakter" or "name")
    publisher_lines = []
    bio_lines = []
    in_bio = False
    
    for line in remaining_lines:
        # Detect start of character bio section
        if not in_bio and any(marker in line.lower() for marker in 
                             ['die naam', 'the name', 'karakter', 'character',
                              'is uitgevind', 'were invented', 'was invented']):
            in_bio = True
        
        if in_bio:
            bio_lines.append(line)
        else:
            publisher_lines.append(line)
    
    # If bio detection failed, split by copyright paragraph
    # (copyright is last ~4 lines of publisher section)
    if not bio_lines and len(publisher_lines) > 14:
        # Assume last chunk starting with "Die naam" or similar is bio
        for i, line in enumerate(publisher_lines):
            if any(marker in line.lower() for marker in ['die naam', 'the name', 'naam en karakter']):
                bio_lines = publisher_lines[i:]
                publisher_lines = publisher_lines[:i]
                break
    
    # Further split publisher_lines into info and copyright paragraph
    # Copyright starts with "Kopiereg" / "Copyright"
    info_lines = []
    copyright_lines = []
    in_copyright = False
    
    for line in publisher_lines:
        if not in_copyright and any(marker in line.lower() for marker in 
                                   ['kopiereg', 'copyright']):
            in_copyright = True
        
        if in_copyright:
            copyright_lines.append(line)
        else:
            info_lines.append(line)

    font_css = build_font_css(fonts_dir)
    arch = pymupdf.Archive(fonts_dir)

    # =========================================================================
    # STEP 1: Replace the subtitle ("A Fun Place" → translated)
    # =========================================================================
    if subtitle_spans and subtitle_text:
        # Get subtitle bounding box
        sub_min_x = min(s["bbox"][0] for s in subtitle_spans)
        sub_min_y = min(s["bbox"][1] for s in subtitle_spans)
        sub_max_x = max(s["bbox"][2] for s in subtitle_spans)
        sub_max_y = max(s["bbox"][3] for s in subtitle_spans)
        
        # Erase original subtitle only
        subtitle_rect = pymupdf.Rect(sub_min_x - 5, sub_min_y - 5, sub_max_x + 5, sub_max_y + 5)
        page.add_redact_annot(subtitle_rect, text="", fill=(1, 1, 1))
        page.apply_redactions()
        
        # Render translated subtitle — same style as original (large, centered)
        subtitle_html = f'<p>{subtitle_text}</p>'
        subtitle_css = font_css + """
        * {
            font-family: "PlaypenSans", sans-serif;
            font-size: 49px;
            line-height: 1.2;
            color: #3d2c7c;
        }
        p {
            margin: 0;
            text-align: center;
        }
        """
        
        # Use same rect area as original subtitle
        subtitle_render_rect = pymupdf.Rect(
            60, sub_min_y - 5,
            page.rect.width - 60, sub_max_y + 10
        )
        
        try:
            page.insert_htmlbox(subtitle_render_rect, subtitle_html, css=subtitle_css, archive=arch)
            report["spans_replaced"] += 1
        except Exception as e:
            report["errors"].append({"page": page_num, "error": f"Subtitle failed: {str(e)}"})

    # =========================================================================
    # STEP 2: Erase ONLY the lower-zone text spans (y > 370, small font)
    # This preserves the MTHOMBOTHI logo and child photo
    # =========================================================================
    for span in lower_text_spans:
        span_rect = pymupdf.Rect(span["bbox"])
        page.add_redact_annot(span_rect, text="", fill=(1, 1, 1))
    
    if lower_text_spans:
        page.apply_redactions()

    # =========================================================================
    # STEP 3: Render LEFT column — publisher info + copyright
    # Original: x=64-280, y=477-655
    # =========================================================================
    left_col_css = font_css + """
    * {
        font-family: "PlaypenSans", sans-serif;
        font-size: 7.5px;
        line-height: 1.4;
        color: #000000;
    }
    p { margin: 0; text-align: left; }
    .copyright { margin-top: 24px; }
    """
    
    # Publisher info (below where the logo sits)
    left_html = '<p>' + '<br/>'.join(info_lines) + '</p>'
    if copyright_lines:
        left_html += '<p class="copyright">' + '<br/>'.join(copyright_lines) + '</p>'
    
    # Left column rect: below logo area, left side
    left_rect = pymupdf.Rect(64, 477, 300, 670)
    
    try:
        page.insert_htmlbox(left_rect, left_html, css=left_col_css, archive=arch)
        report["spans_replaced"] += 1
    except Exception as e:
        report["errors"].append({"page": page_num, "error": f"Left column failed: {str(e)}"})

    # =========================================================================
    # STEP 4: Render RIGHT column — character bio (below photo)
    # Original: x=321-477, y=537-629, justified text
    # =========================================================================
    if bio_lines:
        right_col_css = font_css + """
        * {
            font-family: "PlaypenSans", sans-serif;
            font-size: 7.5px;
            line-height: 1.4;
            color: #000000;
        }
        p { margin: 0; text-align: justify; }
        """
        
        # Join bio lines into a flowing paragraph
        bio_text = ' '.join(bio_lines)
        right_html = f'<p>{bio_text}</p>'
        
        # Right column rect: below the photo, right side
        right_rect = pymupdf.Rect(321, 537, 477, 660)
        
        try:
            page.insert_htmlbox(right_rect, right_html, css=right_col_css, archive=arch)
            report["spans_replaced"] += 1
        except Exception as e:
            report["errors"].append({"page": page_num, "error": f"Right column failed: {str(e)}"})


def render_back_cover(page, translated_text, fonts_dir, page_num, report):
    """
    Render the back cover title list.
    
    Original characteristics:
    - Font: 24px
    - Centered text
    - Header line + numbered title list
    - Text zone: y=108-338, centered on page
    - Often has colored background (orange, etc.)
    """
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
    blocks = text_dict.get("blocks", [])

    # Find text zone
    text_zone = find_text_zone(page, blocks)
    if not text_zone:
        report["errors"].append({"page": page_num, "error": "No text zone on back cover"})
        return

    # Detect background color by sampling inside the page (avoiding white border at edges)
    bg_color = detect_color_at_point(page, 50, text_zone.y0 + 10)

    # Erase text using a shape overlay (better than redaction for colored backgrounds)
    # Draw a filled rectangle that covers all existing text
    cover_rect = pymupdf.Rect(
        text_zone.x0 - 15,
        text_zone.y0 - 10,
        text_zone.x1 + 15,
        text_zone.y1 + 10
    )
    shape = page.new_shape()
    shape.draw_rect(cover_rect)
    shape.finish(color=bg_color, fill=bg_color)
    shape.commit()

    # Clean text — preserve line structure for title list
    clean_text = clean_translated_text(translated_text, page_type='back_cover')
    if not clean_text:
        return

    lines = [l.strip() for l in clean_text.split('\n') if l.strip()]

    # Build HTML — first line is header, rest are numbered titles
    if lines:
        header = lines[0]
        titles = lines[1:] if len(lines) > 1 else []

        html = f'<p class="header">{header}</p>'
        if titles:
            for title in titles:
                html += f'<p class="title">{title}</p>'
    else:
        html = f'<p class="header">{clean_text}</p>'

    font_css = build_font_css(fonts_dir)
    css = font_css + """
    * {
        font-family: "PlaypenSans", sans-serif;
        color: #000000;
    }
    .header {
        font-size: 24px;
        text-align: center;
        margin-bottom: 20px;
        line-height: 1.2;
    }
    .title {
        font-size: 24px;
        text-align: center;
        margin: 0;
        line-height: 1.2;
    }
    """

    arch = pymupdf.Archive(fonts_dir)

    # Textbox in the original text zone area
    textbox_rect = pymupdf.Rect(
        text_zone.x0 - 20,
        text_zone.y0 - 5,
        text_zone.x1 + 20,
        text_zone.y1 + 30
    )

    try:
        result = page.insert_htmlbox(textbox_rect, html, css=css, archive=arch)
        report["spans_replaced"] += 1
    except Exception as e:
        report["errors"].append({
            "page": page_num,
            "error": f"Back cover insert_htmlbox failed: {str(e)}"
        })


def render_vocabulary_page(page, translated_text, fonts_dir, page_num, report):
    """
    Render the vocabulary/word list page.
    
    Original characteristics:
    - Font: 11-12px
    - 4 columns: WORDS (2 sub-cols), HIGH FREQUENCY WORDS, PHONICS
    - Headers in bold with border below
    - Text zone: y=71-651 (nearly full page)
    - Single words per line, ~40-45 words per column
    """
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
    blocks = text_dict.get("blocks", [])

    # Find text zone
    text_zone = find_text_zone(page, blocks)
    if not text_zone:
        report["errors"].append({"page": page_num, "error": "No text zone on vocabulary page"})
        return

    # Erase all text
    erase_rect = pymupdf.Rect(
        text_zone.x0 - 5,
        text_zone.y0 - 5,
        text_zone.x1 + 5,
        text_zone.y1 + 5
    )
    erase_text_in_rect(page, erase_rect, fill_color=(1, 1, 1), keep_page_numbers=False)

    # Also paint a white rectangle over the entire zone to cover original table borders/lines
    # (erase_text_in_rect only removes text spans, not drawn lines from the original PDF)
    # Use full page width minus small margins to ensure all original lines are covered
    cover_shape = page.new_shape()
    cover_rect = pymupdf.Rect(25, text_zone.y0 - 10, page.rect.width - 25, text_zone.y1 + 10)
    cover_shape.draw_rect(cover_rect)
    cover_shape.finish(color=None, fill=(1, 1, 1))
    cover_shape.commit()

    # Clean translated text
    clean_text = clean_translated_text(translated_text, page_type='vocabulary')
    if not clean_text:
        return

    # Parse the vocabulary text into sections
    lines = [l.strip() for l in clean_text.split('\n') if l.strip()]

    # Remove preamble like "Hier is bladsy 15 om aan te pas:"
    while lines and not re.match(r'^[A-Z\sËÖÜ]+$', lines[0]):
        lines.pop(0)

    # Collect all header lines at the top (ALL CAPS lines)
    headers = []
    while lines and re.match(r'^[A-Z\sËÖÜ\-]+$', lines[0]) and len(lines[0]) < 30:
        headers.append(lines.pop(0))

    # Remaining lines are all the words/items in a flat list
    all_words = lines

    # The original layout has specific word counts per section:
    # - WORDS column: ~45 vocabulary words
    # - HIGH FREQUENCY WORDS: ~20 common words (short, simple words)
    # - PHONICS: ~10 items (phonics rules with dashes)
    # 
    # Detect phonics items: they contain " - " pattern or multi-word descriptions
    phonics_start = len(all_words)
    for i, word in enumerate(all_words):
        # Phonics items typically have " - " or are multi-word descriptions
        if re.search(r'\s+-\s+', word) or re.match(r'^\d-letter', word):
            phonics_start = i
            break

    phonics_words = all_words[phonics_start:]
    remaining_words = all_words[:phonics_start]

    # The original layout has: WORDS (~44 in 2 cols of ~22), HF WORDS (~15), PHONICS (~10)
    # Total original: ~69 items. GPT may over-generate, so cap to match original proportions.
    VOCAB_TARGET = 44   # 2 columns × 22
    HF_TARGET = 15
    
    # Cap words to fit original layout
    if len(remaining_words) > VOCAB_TARGET + HF_TARGET:
        vocab_words = remaining_words[:VOCAB_TARGET]
        hf_words = remaining_words[VOCAB_TARGET:VOCAB_TARGET + HF_TARGET]
    elif len(remaining_words) > VOCAB_TARGET:
        vocab_words = remaining_words[:VOCAB_TARGET]
        hf_words = remaining_words[VOCAB_TARGET:]
    else:
        vocab_words = remaining_words
        hf_words = []
    
    # Cap phonics too
    if len(phonics_words) > 10:
        phonics_words = phonics_words[:10]

    # Split vocab words into 2 columns
    mid = (len(vocab_words) + 1) // 2
    col1_words = vocab_words[:mid]
    col2_words = vocab_words[mid:]

    # =========================================================================
    # APPROACH: Draw table lines manually, then insert text into each column rect
    # This avoids PyMuPDF's table rendering issues where borders clip text
    # =========================================================================
    
    font_css = build_font_css(fonts_dir)
    arch = pymupdf.Archive(fonts_dir)
    
    # Define column layout — use full page width with small margins
    # (wider than original text zone for better readability)
    table_left = 30
    table_right = page.rect.width - 30
    table_top = text_zone.y0
    table_width = table_right - table_left
    
    # Calculate actual table height based on content
    # Longest column determines height (col1 or col2 with vocab words)
    max_rows = max(len(col1_words), len(col2_words), len(hf_words), len(phonics_words))
    LINE_HEIGHT = 12.6  # 9px font × 1.4 line-height
    HEADER_HEIGHT = 25
    content_height = max_rows * LINE_HEIGHT + 10  # content + small padding
    table_bottom = table_top + HEADER_HEIGHT + content_height
    
    # Column x positions (proportional: 22%, 22%, 26%, 30% — FONIES gets more space for longer entries)
    col_widths = [0.22, 0.22, 0.26, 0.30]
    col_x = [table_left]
    for w in col_widths[:-1]:
        col_x.append(col_x[-1] + table_width * w)
    col_x.append(table_right)  # right edge
    
    # Header height
    HEADER_HEIGHT = 25
    header_bottom = table_top + HEADER_HEIGHT
    
    # Draw table internal lines only (no outer border)
    shape = page.new_shape()
    # Header separator line
    shape.draw_line(pymupdf.Point(table_left, header_bottom), pymupdf.Point(table_right, header_bottom))
    # Vertical line between WOORDE and HF WOORDE (col2 → col3)
    x = col_x[2] - 3
    shape.draw_line(pymupdf.Point(x, table_top), pymupdf.Point(x, table_bottom))
    # Vertical line between HF WOORDE and FONIES (col3 → col4)
    x = col_x[3] - 3
    shape.draw_line(pymupdf.Point(x, table_top), pymupdf.Point(x, table_bottom))
    shape.finish(color=(0, 0, 0), width=0.5)
    shape.commit()
    
    # CSS for column text (no borders, just text)
    col_css = font_css + """
    * {
        font-family: "PlaypenSans", sans-serif;
        font-size: 9px;
        line-height: 1.4;
        color: #000000;
    }
    p { margin: 0; }
    .bold { font-weight: bold; }
    """
    
    # Insert headers
    # "WOORDE" spanning cols 1-2
    header_rect = pymupdf.Rect(col_x[0] + 4, table_top + 3, col_x[2] - 4, header_bottom - 2)
    page.insert_htmlbox(header_rect, '<p class="bold">WOORDE</p>', css=col_css, archive=arch)
    
    # "HOË FREKWENSIE WOORDE" in col 3
    header_rect = pymupdf.Rect(col_x[2] + 4, table_top + 3, col_x[3] - 4, header_bottom - 2)
    page.insert_htmlbox(header_rect, '<p class="bold">HOË FREKWENSIE WOORDE</p>', css=col_css, archive=arch)
    
    # "FONIES" in col 4
    header_rect = pymupdf.Rect(col_x[3] + 4, table_top + 3, col_x[4] - 4, header_bottom - 2)
    page.insert_htmlbox(header_rect, '<p class="bold">FONIES</p>', css=col_css, archive=arch)
    
    # Insert word columns (below header)
    content_top = header_bottom + 3
    padding_left = 8   # padding from left border of column
    padding_right = 8  # padding from right border of column (clear of separator lines)
    
    # Column 1: vocab first half
    col_rect = pymupdf.Rect(col_x[0] + padding_left, content_top, col_x[1] - padding_right, table_bottom - 3)
    col_html = '<p>' + '<br/>'.join(col1_words) + '</p>'
    page.insert_htmlbox(col_rect, col_html, css=col_css, archive=arch, scale_low=0)
    
    # Column 2: vocab second half
    col_rect = pymupdf.Rect(col_x[1] + padding_left, content_top, col_x[2] - padding_right, table_bottom - 3)
    col_html = '<p>' + '<br/>'.join(col2_words) + '</p>'
    page.insert_htmlbox(col_rect, col_html, css=col_css, archive=arch, scale_low=0)
    
    # Column 3: high frequency words
    col_rect = pymupdf.Rect(col_x[2] + padding_left, content_top, col_x[3] - padding_right, table_bottom - 3)
    col_html = '<p>' + '<br/>'.join(hf_words) + '</p>'
    page.insert_htmlbox(col_rect, col_html, css=col_css, archive=arch, scale_low=0)
    
    # Column 4: phonics
    col_rect = pymupdf.Rect(col_x[3] + padding_left, content_top, col_x[4] - padding_right, table_bottom - 3)
    col_html = '<p>' + '<br/>'.join(phonics_words) + '</p>'
    page.insert_htmlbox(col_rect, col_html, css=col_css, archive=arch, scale_low=0)
    
    report["spans_replaced"] += 1


# =============================================================================
# METADATA EXTRACTION
# =============================================================================

def extract_text_metadata(input_pdf: str) -> dict:
    """Extract all text spans from each page with exact positioning info."""
    doc = pymupdf.open(input_pdf)
    result = {
        "file": os.path.basename(input_pdf),
        "page_count": len(doc),
        "pages": []
    }

    for page_idx in range(len(doc)):
        page = doc[page_idx]
        page_data = {
            "page_number": page_idx + 1,
            "width": page.rect.width,
            "height": page.rect.height,
            "text_blocks": []
        }

        text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)

        for block in text_dict.get("blocks", []):
            if block.get("type") != 0:
                continue

            block_data = {
                "bbox": list(block["bbox"]),
                "lines": []
            }

            for line in block.get("lines", []):
                line_data = {
                    "bbox": list(line["bbox"]),
                    "spans": []
                }

                for span in line.get("spans", []):
                    text = span.get("text", "").strip()
                    if not text:
                        continue

                    span_data = {
                        "text": span["text"],
                        "origin": list(span["origin"]),
                        "bbox": list(span["bbox"]),
                        "size": span["size"],
                        "font": span["font"],
                        "flags": span["flags"],
                        "color": span["color"],
                    }
                    line_data["spans"].append(span_data)

                if line_data["spans"]:
                    block_data["lines"].append(line_data)

            if block_data["lines"]:
                page_data["text_blocks"].append(block_data)

        result["pages"].append(page_data)

    doc.close()
    return result


# =============================================================================
# MAIN REPLACEMENT ENGINE
# =============================================================================

def replace_text_in_pdf(
    input_pdf: str,
    output_pdf: str,
    translations: dict,
    fonts_dir: str | None = None,
) -> dict:
    """
    V7 Core replacement engine.

    Two-pass approach for story pages:
    Pass 1: Classify pages, detect containers, calculate optimal font size
    Pass 2: Render all pages with consistent font and vertical centering
    """
    doc = pymupdf.open(input_pdf)
    total_pages = len(doc)

    report = {
        "version": "v7",
        "pages_processed": 0,
        "spans_replaced": 0,
        "page_types": {},
        "overflow_warnings": [],
        "errors": [],
        "auto_font_size": None
    }

    translation_pages = {p["page_number"]: p for p in translations.get("pages", [])}

    # =========================================================================
    # PASS 1: Classify all pages and calculate optimal font size for story pages
    # =========================================================================
    story_pages = []  # list of (page_idx, page_num)
    page_classifications = {}

    for page_idx in range(total_pages):
        page_num = page_idx + 1
        page = doc[page_idx]

        if page_num not in translation_pages:
            continue

        trans_page = translation_pages[page_num]
        translated_text = trans_page.get("translated_text", "")
        if not translated_text.strip():
            continue

        text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
        blocks = text_dict.get("blocks", [])
        page_type = classify_page(page, page_num, total_pages, blocks)
        page_classifications[page_num] = page_type
        report["page_types"][str(page_num)] = page_type

        if page_type == 'story':
            story_pages.append((page_idx, page_num))

    # Calculate optimal font size for all story pages
    story_translations = {}
    for _, page_num in story_pages:
        trans_page = translation_pages[page_num]
        story_translations[page_num] = trans_page.get("translated_text", "")

    optimal_font_size = 26  # default fallback
    containers = {}
    
    if story_pages:
        optimal_font_size, containers = calculate_optimal_font_size(
            doc, story_pages, story_translations, fonts_dir
        )
    
    report["auto_font_size"] = optimal_font_size

    # =========================================================================
    # PASS 2: Render all pages
    # =========================================================================
    for page_idx in range(total_pages):
        page_num = page_idx + 1
        page = doc[page_idx]

        if page_num not in translation_pages:
            continue

        trans_page = translation_pages[page_num]
        translated_text = trans_page.get("translated_text", "")
        if not translated_text.strip():
            continue

        report["pages_processed"] += 1
        page_type = page_classifications.get(page_num, 'story')

        # Render based on page type
        if page_type == 'cover':
            render_cover_page(page, translated_text, fonts_dir, page_num, report)
        elif page_type == 'story':
            container = containers.get(page_num)
            render_story_page(page, translated_text, fonts_dir, page_num, report,
                            font_size=optimal_font_size, container=container)
        elif page_type == 'vocabulary':
            render_vocabulary_page(page, translated_text, fonts_dir, page_num, report)
        elif page_type == 'back_cover':
            render_back_cover(page, translated_text, fonts_dir, page_num, report)
        elif page_type == 'copyright':
            render_copyright_page(page, translated_text, fonts_dir, page_num, report)

    # Save output
    output_dir = os.path.dirname(output_pdf)
    if output_dir and not os.path.isdir(output_dir):
        os.makedirs(output_dir, exist_ok=True)

    doc.save(output_pdf, garbage=4, deflate=True)
    doc.close()

    return report


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(
        description="PDF Text Replacement Engine V6.1 — HTML/CSS-based rendering"
    )
    subparsers = parser.add_subparsers(dest="command", help="Command to run")

    # Extract command
    extract_parser = subparsers.add_parser("extract", help="Extract text metadata from PDF")
    extract_parser.add_argument("--input", "-i", required=True, help="Input PDF file")
    extract_parser.add_argument("--output", "-o", help="Output JSON file (default: stdout)")
    extract_parser.add_argument("--pretty", action="store_true", help="Pretty-print JSON output")

    # Replace command
    replace_parser = subparsers.add_parser("replace", help="Replace text in PDF using translations")
    replace_parser.add_argument("--input", "-i", required=True, help="Input PDF file")
    replace_parser.add_argument("--output", "-o", required=True, help="Output PDF file")
    replace_parser.add_argument("--translations", "-t", required=True, help="Translations JSON file")
    replace_parser.add_argument("--fonts-dir", "-f", help="Directory containing TTF/OTF font files")

    args = parser.parse_args()

    if args.command == "extract":
        metadata = extract_text_metadata(args.input)
        json_output = json.dumps(metadata, indent=2 if args.pretty else None, ensure_ascii=False)

        if args.output:
            with open(args.output, 'w', encoding='utf-8') as f:
                f.write(json_output)
            print(f"Metadata extracted to: {args.output}", file=sys.stderr)
        else:
            print(json_output)

    elif args.command == "replace":
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
        sys.exit(1)


if __name__ == "__main__":
    main()
