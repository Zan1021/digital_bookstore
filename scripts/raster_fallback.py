"""
Raster Fallback Mode — Digital Bookstore V8
=============================================
For pages where vector text editing fails or damages content.
Renders the page at high DPI, paints over text areas with background color,
then overlays translated text as live PDF text.

The result is visually identical to the original (non-text areas) with
translated text rendered on top.

Usage:
    python raster_fallback.py render --input book.pdf --output page_15.pdf --page 15 --translations trans.json --fonts-dir ./fonts
"""

import argparse
import json
import os
import sys

import pymupdf


def render_page_raster_fallback(
    doc,
    page,
    translated_spans: list,
    fonts_dir: str,
    dpi: int = 300,
    bg_detection: bool = True,
) -> dict:
    """
    Raster fallback rendering:
    1. Render page at high DPI as a pixmap
    2. Paint over each text span's bbox with detected background color
    3. Save as the page background image
    4. Create a new page with the image as background
    5. Overlay translated text at exact original coordinates
    
    Args:
        doc: PyMuPDF document
        page: Source page object
        translated_spans: List of dicts with 'origin', 'bbox', 'translation', 'font_size', 'color'
        fonts_dir: Path to font files
        dpi: Render resolution (300 for print quality, 150 for screen)
    
    Returns:
        {"status": "success", "spans_placed": int, "image_size": "WxH"}
    """
    result = {"status": "success", "spans_placed": 0, "errors": []}

    # Step 1: Render the full page as a high-DPI pixmap
    pix = page.get_pixmap(dpi=dpi)
    
    # Calculate scale factor (PDF points to pixels)
    scale_x = pix.width / page.rect.width
    scale_y = pix.height / page.rect.height

    # Step 2: Paint over text areas with background color
    for span in translated_spans:
        bbox = span.get("bbox")
        if not bbox:
            continue
        
        # Convert PDF coordinates to pixel coordinates
        px0 = int(bbox[0] * scale_x)
        py0 = int(bbox[1] * scale_y)
        px1 = int(bbox[2] * scale_x)
        py1 = int(bbox[3] * scale_y)
        
        # Add small padding for antialiasing
        px0 = max(0, px0 - 2)
        py0 = max(0, py0 - 2)
        px1 = min(pix.width - 1, px1 + 2)
        py1 = min(pix.height - 1, py1 + 2)
        
        # Detect background color by sampling around the bbox edges
        bg_color = _sample_background(pix, px0, py0, px1, py1)
        
        # Paint over the text area
        for y in range(py0, py1 + 1):
            for x in range(px0, px1 + 1):
                if 0 <= x < pix.width and 0 <= y < pix.height:
                    pix.set_pixel(x, y, bg_color)

    result["image_size"] = f"{pix.width}x{pix.height}"

    # Step 3: Replace the page content with the rasterized image
    # Clear the page
    page.clean_contents()
    
    # Remove existing content
    # Insert the painted pixmap as the full page background
    img_rect = page.rect
    page.insert_image(img_rect, pixmap=pix)

    # Step 4: Overlay translated text at exact original coordinates
    font_file = os.path.join(fonts_dir, "PlaypenSans-Regular.ttf") if fonts_dir else None
    
    for span in translated_spans:
        translation = span.get("translation", "")
        if not translation:
            continue
        
        origin = span.get("origin")
        if not origin:
            continue
        
        font_size = span.get("font_size", 12)
        color_hex = span.get("color", "#000000")
        
        # Parse color
        r = int(color_hex[1:3], 16) / 255
        g = int(color_hex[3:5], 16) / 255
        b = int(color_hex[5:7], 16) / 255
        
        point = pymupdf.Point(origin[0], origin[1])
        
        try:
            if font_file and os.path.isfile(font_file):
                page.insert_text(
                    point=point,
                    text=translation,
                    fontsize=font_size,
                    fontname="F0",
                    fontfile=font_file,
                    color=(r, g, b),
                )
            else:
                page.insert_text(
                    point=point,
                    text=translation,
                    fontsize=font_size,
                    fontname="helv",
                    color=(r, g, b),
                )
            result["spans_placed"] += 1
        except Exception as e:
            result["errors"].append(f"Span at {origin}: {str(e)}")

    return result


def _sample_background(pix, px0, py0, px1, py1) -> tuple:
    """
    Sample background color around a bounding box.
    Samples from edges just outside the bbox.
    Returns (r, g, b) tuple.
    """
    samples = []
    
    # Sample points around the bbox edges
    sample_points = [
        (max(0, px0 - 5), (py0 + py1) // 2),      # left of bbox
        (min(pix.width - 1, px1 + 5), (py0 + py1) // 2),  # right of bbox
        ((px0 + px1) // 2, max(0, py0 - 5)),        # above bbox
        ((px0 + px1) // 2, min(pix.height - 1, py1 + 5)),  # below bbox
    ]
    
    for x, y in sample_points:
        if 0 <= x < pix.width and 0 <= y < pix.height:
            pixel = pix.pixel(x, y)
            samples.append(pixel[:3])  # RGB only
    
    if not samples:
        return (255, 255, 255)  # default white
    
    # Average the samples
    avg_r = sum(s[0] for s in samples) // len(samples)
    avg_g = sum(s[1] for s in samples) // len(samples)
    avg_b = sum(s[2] for s in samples) // len(samples)
    
    return (avg_r, avg_g, avg_b)


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="Raster Fallback Renderer")
    parser.add_argument("--input", "-i", required=True, help="Input PDF")
    parser.add_argument("--output", "-o", required=True, help="Output PDF")
    parser.add_argument("--page", "-p", type=int, required=True, help="Page number to render")
    parser.add_argument("--translations", "-t", required=True, help="JSON file with span translations")
    parser.add_argument("--fonts-dir", "-f", help="Fonts directory")
    parser.add_argument("--dpi", type=int, default=300, help="Render DPI")

    args = parser.parse_args()

    doc = pymupdf.open(args.input)
    page = doc[args.page - 1]

    with open(args.translations, 'r', encoding='utf-8') as f:
        spans = json.load(f)

    result = render_page_raster_fallback(doc, page, spans, args.fonts_dir, args.dpi)

    # Save
    doc.save(args.output, garbage=4, deflate=True)
    doc.close()

    print(json.dumps(result, indent=2))


if __name__ == "__main__":
    main()
