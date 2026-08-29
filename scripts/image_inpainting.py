"""
Local Image Inpainting — Digital Bookstore V8
===============================================
For text that's baked into raster images (not PDF text objects).
Uses pixel-level inpainting to erase text from artwork, then overlays
translated text as live PDF text.

Approach:
1. Identify image regions that contain text (via OCR or manual marking)
2. Create a text mask for the pixels to erase
3. Inpaint (fill with surrounding texture) — using simple interpolation
4. Replace the image in the PDF
5. Overlay live translated text on top

Note: This is a basic inpainting implementation using nearest-neighbor
interpolation. For production, consider integrating OpenCV's inpainting
algorithms (cv2.inpaint with TELEA or NS methods).

Usage:
    python image_inpainting.py inpaint --input book.pdf --page 1 --regions regions.json --output cleaned.pdf
"""

import argparse
import json
import os
import sys

import pymupdf


def create_text_mask(pix, text_bboxes: list, padding: int = 3) -> list:
    """
    Create a mask of pixel regions to inpaint.
    
    Args:
        pix: PyMuPDF Pixmap
        text_bboxes: List of [x0, y0, x1, y1] in pixel coordinates
        padding: Extra pixels around each bbox to cover antialiasing
    
    Returns:
        List of (x0, y0, x1, y1) tuples defining mask regions
    """
    mask_regions = []
    
    for bbox in text_bboxes:
        x0 = max(0, int(bbox[0]) - padding)
        y0 = max(0, int(bbox[1]) - padding)
        x1 = min(pix.width - 1, int(bbox[2]) + padding)
        y1 = min(pix.height - 1, int(bbox[3]) + padding)
        mask_regions.append((x0, y0, x1, y1))
    
    return mask_regions


def inpaint_region(pix, x0: int, y0: int, x1: int, y1: int, method: str = "edge_fill"):
    """
    Inpaint a rectangular region by filling with surrounding pixel colors.
    
    Methods:
    - "edge_fill": Sample colors from the edges and fill inward
    - "horizontal_extend": Extend left/right edge pixels horizontally
    - "solid_fill": Fill with the average edge color
    """
    if method == "edge_fill":
        _inpaint_edge_fill(pix, x0, y0, x1, y1)
    elif method == "horizontal_extend":
        _inpaint_horizontal(pix, x0, y0, x1, y1)
    else:
        _inpaint_solid(pix, x0, y0, x1, y1)


def _inpaint_solid(pix, x0, y0, x1, y1):
    """Fill region with average edge color."""
    samples = []
    
    # Sample top edge
    for x in range(x0, x1 + 1, 3):
        if 0 <= x < pix.width and y0 > 0:
            samples.append(pix.pixel(x, max(0, y0 - 1)))
    # Sample bottom edge
    for x in range(x0, x1 + 1, 3):
        if 0 <= x < pix.width and y1 < pix.height - 1:
            samples.append(pix.pixel(x, min(pix.height - 1, y1 + 1)))
    # Sample left edge
    for y in range(y0, y1 + 1, 3):
        if x0 > 0 and 0 <= y < pix.height:
            samples.append(pix.pixel(max(0, x0 - 1), y))
    # Sample right edge
    for y in range(y0, y1 + 1, 3):
        if x1 < pix.width - 1 and 0 <= y < pix.height:
            samples.append(pix.pixel(min(pix.width - 1, x1 + 1), y))
    
    if not samples:
        fill_color = (255, 255, 255)
    else:
        r = sum(s[0] for s in samples) // len(samples)
        g = sum(s[1] for s in samples) // len(samples)
        b = sum(s[2] for s in samples) // len(samples)
        fill_color = (r, g, b)
    
    # Fill the region
    for y in range(y0, y1 + 1):
        for x in range(x0, x1 + 1):
            if 0 <= x < pix.width and 0 <= y < pix.height:
                pix.set_pixel(x, y, fill_color)


def _inpaint_edge_fill(pix, x0, y0, x1, y1):
    """
    Fill region using bilinear interpolation from edges.
    Each pixel gets a weighted average based on distance to nearest edge.
    """
    width = x1 - x0 + 1
    height = y1 - y0 + 1
    
    if width < 2 or height < 2:
        _inpaint_solid(pix, x0, y0, x1, y1)
        return
    
    # Sample edge colors
    top_colors = []
    bottom_colors = []
    
    for x in range(x0, x1 + 1):
        if 0 <= x < pix.width:
            if y0 > 0:
                top_colors.append(pix.pixel(x, y0 - 1))
            else:
                top_colors.append((255, 255, 255))
            if y1 < pix.height - 1:
                bottom_colors.append(pix.pixel(x, y1 + 1))
            else:
                bottom_colors.append((255, 255, 255))
    
    # Fill using vertical interpolation between top and bottom edges
    for yi, y in enumerate(range(y0, y1 + 1)):
        t = yi / max(height - 1, 1)  # 0 at top, 1 at bottom
        for xi, x in enumerate(range(x0, x1 + 1)):
            if 0 <= x < pix.width and 0 <= y < pix.height and xi < len(top_colors):
                top = top_colors[xi]
                bot = bottom_colors[xi] if xi < len(bottom_colors) else top
                
                r = int(top[0] * (1 - t) + bot[0] * t)
                g = int(top[1] * (1 - t) + bot[1] * t)
                b = int(top[2] * (1 - t) + bot[2] * t)
                
                pix.set_pixel(x, y, (r, g, b))


def _inpaint_horizontal(pix, x0, y0, x1, y1):
    """Extend left and right edge pixels horizontally into the region."""
    for y in range(y0, y1 + 1):
        if 0 <= y < pix.height:
            # Get left edge color
            left_color = pix.pixel(max(0, x0 - 1), y) if x0 > 0 else (255, 255, 255)
            # Get right edge color
            right_color = pix.pixel(min(pix.width - 1, x1 + 1), y) if x1 < pix.width - 1 else (255, 255, 255)
            
            width = x1 - x0 + 1
            for xi, x in enumerate(range(x0, x1 + 1)):
                if 0 <= x < pix.width:
                    t = xi / max(width - 1, 1)
                    r = int(left_color[0] * (1 - t) + right_color[0] * t)
                    g = int(left_color[1] * (1 - t) + right_color[1] * t)
                    b = int(left_color[2] * (1 - t) + right_color[2] * t)
                    pix.set_pixel(x, y, (r, g, b))


def inpaint_page_image(
    page,
    text_bboxes_pdf: list,
    method: str = "edge_fill",
    dpi: int = 300,
) -> pymupdf.Pixmap:
    """
    Render page and inpaint text regions.
    
    Args:
        page: PyMuPDF page
        text_bboxes_pdf: List of [x0, y0, x1, y1] in PDF coordinates
        method: Inpainting method
        dpi: Render DPI
    
    Returns:
        Inpainted pixmap
    """
    # Render at high DPI
    pix = page.get_pixmap(dpi=dpi)
    
    # Convert PDF coords to pixel coords
    scale_x = pix.width / page.rect.width
    scale_y = pix.height / page.rect.height
    
    pixel_bboxes = []
    for bbox in text_bboxes_pdf:
        pixel_bboxes.append([
            bbox[0] * scale_x,
            bbox[1] * scale_y,
            bbox[2] * scale_x,
            bbox[3] * scale_y,
        ])
    
    # Create mask and inpaint each region
    mask = create_text_mask(pix, pixel_bboxes, padding=4)
    
    for region in mask:
        inpaint_region(pix, *region, method=method)
    
    return pix


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="Local Image Inpainting for Text Removal")
    parser.add_argument("--input", "-i", required=True, help="Input PDF")
    parser.add_argument("--page", "-p", type=int, required=True)
    parser.add_argument("--regions", "-r", required=True, help="JSON file with text bboxes")
    parser.add_argument("--output", "-o", required=True, help="Output image path")
    parser.add_argument("--method", "-m", default="edge_fill", choices=["edge_fill", "horizontal_extend", "solid_fill"])
    parser.add_argument("--dpi", type=int, default=300)

    args = parser.parse_args()

    doc = pymupdf.open(args.input)
    page = doc[args.page - 1]

    with open(args.regions, 'r', encoding='utf-8') as f:
        bboxes = json.load(f)

    pix = inpaint_page_image(page, bboxes, method=args.method, dpi=args.dpi)
    pix.save(args.output)
    
    doc.close()
    print(f"Inpainted image saved: {args.output} ({pix.width}x{pix.height})")


if __name__ == "__main__":
    main()
