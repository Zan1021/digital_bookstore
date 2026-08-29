"""
Rotated Text Support — Digital Bookstore V8
=============================================
Detects, extracts, and renders rotated/vertical text in PDFs.

Per the brief:
  - "Rotated label → Remove source text operators → Live text with original transform"
  - "Do not discard the original text matrix or coordinate transformations."
  - "Bounding boxes alone are insufficient for rotated, scaled, sheared, or vertical text."

PDF text can be rotated via:
  1. The text matrix (Tm operator) — per-text-object rotation
  2. The CTM (current transformation matrix) — inherited from graphics state
  3. Page rotation (/Rotate key) — rotates the entire page coordinate system

PyMuPDF exposes rotation through the line's "dir" property:
  dir = (cos θ, sin θ) where θ is the angle from horizontal.
  - Horizontal text: dir = (1, 0) → θ = 0°
  - Vertical (down): dir = (0, -1) → θ = -90° (or 270°)
  - Vertical (up): dir = (0, 1) → θ = 90°
  - 45° diagonal: dir ≈ (0.707, 0.707)

We extract this, store it per span, and use pymupdf's `morph` parameter
on `insert_text()` to re-apply the rotation to translated text.

Usage:
    python rotated_text.py detect --input book.pdf [--page N]
    python rotated_text.py render-test --input book.pdf --output test_rotated.pdf --page N
"""

import argparse
import json
import math
import os
import sys
from typing import Optional

import pymupdf


# =============================================================================
# CONSTANTS
# =============================================================================

# Rotation angle (degrees) below which text is considered horizontal
ROTATION_THRESHOLD = 2.0

# Common rotation angles for classification
ROTATION_CLASSES = {
    "horizontal": (0, ROTATION_THRESHOLD),
    "vertical_down": (265, 275),       # ~270° / -90° (most common vertical)
    "vertical_up": (85, 95),           # ~90°
    "diagonal_45": (40, 50),           # ~45°
    "diagonal_neg45": (310, 320),      # ~-45° / 315°
    "upside_down": (175, 185),         # ~180°
}


# =============================================================================
# DETECTION — Extract rotation metadata from text spans
# =============================================================================

def get_rotation_angle(direction: tuple) -> float:
    """
    Convert a PyMuPDF line direction vector (cos θ, sin θ) to degrees.
    
    PyMuPDF's dir uses standard math convention:
      - (1, 0) = 0° (horizontal, left to right)
      - (0, 1) = 90° (vertical, bottom to top — but in PDF coords)
      - (0, -1) = 270° / -90° (vertical, top to bottom in screen coords)
    
    Returns angle in degrees [0, 360).
    """
    cos_val, sin_val = direction
    angle_rad = math.atan2(sin_val, cos_val)
    angle_deg = math.degrees(angle_rad)
    # Normalize to [0, 360)
    if angle_deg < 0:
        angle_deg += 360
    return round(angle_deg, 2)


def classify_rotation(angle: float) -> str:
    """
    Classify a rotation angle into a named category.
    Returns one of: horizontal, vertical_down, vertical_up, diagonal_45,
    diagonal_neg45, upside_down, or 'rotated_N' for uncommon angles.
    """
    for name, (low, high) in ROTATION_CLASSES.items():
        if low <= angle <= high:
            return name
    # Check horizontal wrapping (358-360 and 0-2)
    if angle >= 360 - ROTATION_THRESHOLD or angle <= ROTATION_THRESHOLD:
        return "horizontal"
    return f"rotated_{int(angle)}"


def is_rotated(angle: float) -> bool:
    """Check if text is meaningfully rotated (not horizontal)."""
    return not (angle <= ROTATION_THRESHOLD or angle >= 360 - ROTATION_THRESHOLD)


def build_text_matrix(angle_deg: float, font_size: float, origin: tuple) -> list:
    """
    Build a 6-element text matrix [a, b, c, d, e, f] for the given rotation.
    
    The PDF text matrix encodes: scale, rotation, and translation.
    For rotation by θ with font size s at position (x, y):
      Tm = [s*cos(θ), s*sin(θ), -s*sin(θ), s*cos(θ), x, y]
    
    This is what we'd use in content-stream surgery to write the Tm operator.
    """
    angle_rad = math.radians(angle_deg)
    cos_val = math.cos(angle_rad)
    sin_val = math.sin(angle_rad)
    
    a = font_size * cos_val
    b = font_size * sin_val
    c = -font_size * sin_val
    d = font_size * cos_val
    e = origin[0]
    f = origin[1]
    
    return [round(v, 4) for v in [a, b, c, d, e, f]]


def extract_rotation_from_line(line: dict) -> dict:
    """
    Extract rotation metadata from a PyMuPDF text dict line object.
    
    Returns:
    {
        "direction": (cos, sin),
        "angle_degrees": float,
        "classification": str,
        "is_rotated": bool,
        "wmode": int (0=horizontal, 1=vertical writing mode)
    }
    """
    direction = tuple(line.get("dir", (1, 0)))
    wmode = line.get("wmode", 0)
    angle = get_rotation_angle(direction)
    
    return {
        "direction": direction,
        "angle_degrees": angle,
        "classification": classify_rotation(angle),
        "is_rotated": is_rotated(angle),
        "wmode": wmode,
    }


# =============================================================================
# FULL PAGE SCAN — Find all rotated text on a page
# =============================================================================

def scan_page_for_rotated_text(page, page_num: int) -> dict:
    """
    Scan a page and return all text grouped by rotation status.
    
    Returns:
    {
        "page_number": int,
        "page_rotation": int (from /Rotate key),
        "total_spans": int,
        "rotated_spans": [...],
        "horizontal_spans": [...],
        "rotation_summary": {"horizontal": N, "vertical_down": N, ...}
    }
    """
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
    
    result = {
        "page_number": page_num,
        "page_rotation": page.rotation,  # /Rotate key (0, 90, 180, 270)
        "total_spans": 0,
        "rotated_spans": [],
        "horizontal_spans": [],
        "rotation_summary": {},
    }
    
    span_idx = 0
    
    for block in text_dict.get("blocks", []):
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            rotation_meta = extract_rotation_from_line(line)
            
            for span in line.get("spans", []):
                text = span["text"].strip()
                if not text:
                    continue
                
                span_idx += 1
                bbox = span["bbox"]
                
                span_data = {
                    "id": f"p{page_num:02d}_s{span_idx:04d}",
                    "text": span["text"],
                    "text_stripped": text,
                    "origin": list(span["origin"]),
                    "bbox": list(bbox),
                    "font_size": span["size"],
                    "font_name": span["font"],
                    "rotation": rotation_meta,
                }
                
                result["total_spans"] += 1
                
                if rotation_meta["is_rotated"]:
                    result["rotated_spans"].append(span_data)
                else:
                    result["horizontal_spans"].append(span_data)
                
                # Summary counting
                classification = rotation_meta["classification"]
                result["rotation_summary"][classification] = (
                    result["rotation_summary"].get(classification, 0) + 1
                )
    
    return result


def scan_document_for_rotated_text(pdf_path: str) -> dict:
    """
    Scan an entire PDF for rotated text spans.
    
    Returns:
    {
        "file": str,
        "total_pages": int,
        "pages_with_rotated_text": int,
        "total_rotated_spans": int,
        "pages": [...]
    }
    """
    doc = pymupdf.open(pdf_path)
    
    result = {
        "file": pdf_path,
        "total_pages": len(doc),
        "pages_with_rotated_text": 0,
        "total_rotated_spans": 0,
        "pages": [],
    }
    
    for page_idx in range(len(doc)):
        page = doc[page_idx]
        page_result = scan_page_for_rotated_text(page, page_idx + 1)
        
        if page_result["rotated_spans"]:
            result["pages_with_rotated_text"] += 1
            result["total_rotated_spans"] += len(page_result["rotated_spans"])
        
        result["pages"].append(page_result)
    
    doc.close()
    return result


# =============================================================================
# RENDERING — Insert translated text with rotation applied
# =============================================================================

def insert_rotated_text(page, origin: tuple, text: str, font_size: float,
                        angle_degrees: float, font_path: Optional[str] = None,
                        color: tuple = (0, 0, 0)) -> bool:
    """
    Insert text at a given position with rotation applied.
    
    Uses PyMuPDF's `morph` parameter on `insert_text()` which applies an
    affine transformation: morph = (pivot_point, matrix).
    
    The pivot point is the origin (insertion point), and the matrix encodes
    the rotation.
    
    Parameters:
        page: PyMuPDF page object
        origin: (x, y) insertion point
        text: translated text string
        font_size: size in points
        angle_degrees: rotation angle (0 = horizontal)
        font_path: path to font file (optional, falls back to helv)
        color: (r, g, b) as floats 0-1
    
    Returns: True on success
    """
    if not is_rotated(angle_degrees):
        # No rotation needed — use standard insertion
        try:
            if font_path and os.path.isfile(font_path):
                page.insert_text(
                    point=pymupdf.Point(*origin),
                    text=text,
                    fontsize=font_size,
                    fontname="F0",
                    fontfile=font_path,
                    color=color,
                )
            else:
                page.insert_text(
                    point=pymupdf.Point(*origin),
                    text=text,
                    fontsize=font_size,
                    fontname="helv",
                    color=color,
                )
            return True
        except Exception:
            return False
    
    # Build rotation matrix for morph parameter
    pivot = pymupdf.Point(*origin)
    rotation_matrix = pymupdf.Matrix(angle_degrees)
    
    try:
        if font_path and os.path.isfile(font_path):
            page.insert_text(
                point=pivot,
                text=text,
                fontsize=font_size,
                fontname="F0",
                fontfile=font_path,
                color=color,
                morph=(pivot, rotation_matrix),
            )
        else:
            page.insert_text(
                point=pivot,
                text=text,
                fontsize=font_size,
                fontname="helv",
                color=color,
                morph=(pivot, rotation_matrix),
            )
        return True
    except Exception as e:
        print(f"[rotated_text] Failed to insert rotated text: {e}", file=sys.stderr)
        return False


def calculate_rotated_bbox(origin: tuple, text: str, font_size: float,
                           angle_degrees: float, font_path: Optional[str] = None) -> list:
    """
    Calculate the bounding box of rotated text for redaction purposes.
    
    When text is rotated, the bbox is no longer axis-aligned. We need to
    compute the rotated corners and then get the axis-aligned bounding rect.
    
    Returns [x0, y0, x1, y1] of the axis-aligned bounding box.
    """
    # Measure text width in the standard orientation
    if font_path and os.path.isfile(font_path):
        font = pymupdf.Font(fontfile=font_path)
    else:
        font = pymupdf.Font("helv")
    
    text_width = font.text_length(text, fontsize=font_size)
    text_height = font_size * 1.2  # approximate ascent + descent
    
    # The four corners of the unrotated text bbox relative to origin
    # Origin is at the baseline start, so text extends right and up
    corners = [
        (0, -text_height * 0.8),           # top-left (above baseline)
        (text_width, -text_height * 0.8),   # top-right
        (text_width, text_height * 0.2),    # bottom-right (below baseline)
        (0, text_height * 0.2),             # bottom-left
    ]
    
    # Rotate each corner around origin
    angle_rad = math.radians(angle_degrees)
    cos_a = math.cos(angle_rad)
    sin_a = math.sin(angle_rad)
    
    rotated_corners = []
    for dx, dy in corners:
        rx = origin[0] + dx * cos_a - dy * sin_a
        ry = origin[1] + dx * sin_a + dy * cos_a
        rotated_corners.append((rx, ry))
    
    # Get axis-aligned bounding box
    xs = [c[0] for c in rotated_corners]
    ys = [c[1] for c in rotated_corners]
    
    return [min(xs), min(ys), max(xs), max(ys)]


def remove_rotated_span(page, span_data: dict, fill_color: tuple = None):
    """
    Remove a rotated text span using its calculated rotated bounding box.
    
    For rotated text, the standard bbox from pymupdf is already the
    axis-aligned envelope of the rotated text, so we can use it directly
    for redaction. However, for very tight removal, we recalculate.
    """
    bbox = span_data["bbox"]
    padding = 2  # slightly more padding for rotated text (antialiasing at angles)
    
    rect = pymupdf.Rect(
        bbox[0] - padding,
        bbox[1] - padding,
        bbox[2] + padding,
        bbox[3] + padding
    )
    
    if fill_color is None:
        fill_color = (1, 1, 1)
    
    page.add_redact_annot(rect, text="", fill=fill_color)


# =============================================================================
# SPAN ENRICHMENT — Add rotation metadata to existing span extraction
# =============================================================================

def enrich_spans_with_rotation(page, page_num: int, existing_spans: list) -> list:
    """
    Given a list of extracted spans (from extract_page_spans), add rotation
    metadata by re-reading the page dict and matching by position.
    
    This is used to upgrade existing V8 span data with rotation awareness
    without rewriting the entire extraction pipeline.
    
    Adds to each span:
    - rotation_angle: float (degrees)
    - rotation_class: str 
    - is_rotated: bool
    - text_direction: (cos, sin) tuple
    """
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
    
    # Build a lookup from origin → rotation info
    origin_to_rotation = {}
    
    for block in text_dict.get("blocks", []):
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            direction = tuple(line.get("dir", (1, 0)))
            angle = get_rotation_angle(direction)
            classification = classify_rotation(angle)
            rotated = is_rotated(angle)
            wmode = line.get("wmode", 0)
            
            for span in line.get("spans", []):
                origin_key = (round(span["origin"][0], 1), round(span["origin"][1], 1))
                origin_to_rotation[origin_key] = {
                    "rotation_angle": angle,
                    "rotation_class": classification,
                    "is_rotated": rotated,
                    "text_direction": direction,
                    "wmode": wmode,
                }
    
    # Enrich existing spans
    for span in existing_spans:
        origin_key = (round(span["origin"][0], 1), round(span["origin"][1], 1))
        rot_info = origin_to_rotation.get(origin_key, {
            "rotation_angle": 0.0,
            "rotation_class": "horizontal",
            "is_rotated": False,
            "text_direction": (1.0, 0.0),
            "wmode": 0,
        })
        span.update(rot_info)
    
    return existing_spans


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="Rotated Text Detection & Rendering — V8")
    subparsers = parser.add_subparsers(dest="command")
    
    # Detect command
    detect_p = subparsers.add_parser("detect", help="Detect rotated text in a PDF")
    detect_p.add_argument("--input", "-i", required=True, help="Input PDF path")
    detect_p.add_argument("--page", "-p", type=int, help="Specific page number (optional)")
    detect_p.add_argument("--json", action="store_true", help="Output as JSON")
    
    # Render test command
    render_p = subparsers.add_parser("render-test", help="Test rotated text rendering")
    render_p.add_argument("--input", "-i", required=True, help="Input PDF path")
    render_p.add_argument("--output", "-o", required=True, help="Output PDF path")
    render_p.add_argument("--page", "-p", type=int, required=True, help="Page number")
    render_p.add_argument("--fonts-dir", "-f", help="Fonts directory")
    
    args = parser.parse_args()
    
    if args.command == "detect":
        if args.page:
            doc = pymupdf.open(args.input)
            page = doc[args.page - 1]
            result = scan_page_for_rotated_text(page, args.page)
            doc.close()
        else:
            result = scan_document_for_rotated_text(args.input)
        
        if args.json:
            # Convert tuples to lists for JSON
            print(json.dumps(result, indent=2, ensure_ascii=False, default=str))
        else:
            # Human-readable output
            if "pages" in result:
                print(f"File: {result['file']}")
                print(f"Total pages: {result['total_pages']}")
                print(f"Pages with rotated text: {result['pages_with_rotated_text']}")
                print(f"Total rotated spans: {result['total_rotated_spans']}")
                print()
                for pg in result["pages"]:
                    if pg["rotated_spans"]:
                        print(f"  Page {pg['page_number']}:")
                        for span in pg["rotated_spans"]:
                            rot = span["rotation"]
                            print(f"    [{rot['classification']}] "
                                  f"{rot['angle_degrees']}° — \"{span['text_stripped']}\"")
            else:
                print(f"Page {result['page_number']} "
                      f"(page rotation: {result['page_rotation']}°)")
                print(f"Total spans: {result['total_spans']}")
                print(f"Rotated spans: {len(result['rotated_spans'])}")
                if result["rotated_spans"]:
                    print("\nRotated text found:")
                    for span in result["rotated_spans"]:
                        rot = span["rotation"]
                        print(f"  [{rot['classification']}] "
                              f"{rot['angle_degrees']}° — \"{span['text_stripped']}\"")
                else:
                    print("No rotated text found on this page.")
                print(f"\nRotation summary: {result['rotation_summary']}")
    
    elif args.command == "render-test":
        doc = pymupdf.open(args.input)
        page = doc[args.page - 1]
        
        # Scan for rotated text
        scan = scan_page_for_rotated_text(page, args.page)
        
        if not scan["rotated_spans"]:
            print(f"No rotated text found on page {args.page}. Creating test with synthetic rotation.")
            # Create a test: insert some rotated text to prove the rendering works
            font_path = None
            if args.fonts_dir:
                candidate = os.path.join(args.fonts_dir, "PlaypenSans-Regular.ttf")
                if os.path.isfile(candidate):
                    font_path = candidate
            
            # Insert test text at various angles
            test_cases = [
                (pymupdf.Point(200, 300), "Test 45°", 16, 45),
                (pymupdf.Point(350, 300), "Test 90°", 16, 90),
                (pymupdf.Point(200, 500), "Test -45°", 16, -45),
                (pymupdf.Point(350, 500), "Test 270°", 16, 270),
            ]
            
            for origin, text, size, angle in test_cases:
                success = insert_rotated_text(
                    page, tuple(origin), text, size, angle, font_path
                )
                status = "✓" if success else "✗"
                print(f"  {status} Inserted '{text}' at {angle}°")
        else:
            # Replace rotated spans with "[TRANSLATED]" prefix to show it works
            print(f"Found {len(scan['rotated_spans'])} rotated spans. Replacing...")
            
            font_path = None
            if args.fonts_dir:
                candidate = os.path.join(args.fonts_dir, "PlaypenSans-Regular.ttf")
                if os.path.isfile(candidate):
                    font_path = candidate
            
            for span_data in scan["rotated_spans"]:
                rot = span_data["rotation"]
                # Remove original
                remove_rotated_span(page, span_data)
                page.apply_redactions()
                
                # Insert translated version at same angle
                translated = f"[TR] {span_data['text_stripped']}"
                success = insert_rotated_text(
                    page,
                    tuple(span_data["origin"]),
                    translated,
                    span_data["font_size"],
                    rot["angle_degrees"],
                    font_path,
                )
                status = "✓" if success else "✗"
                print(f"  {status} Replaced '{span_data['text_stripped']}' "
                      f"({rot['classification']}, {rot['angle_degrees']}°)")
        
        doc.save(args.output, garbage=4, deflate=True)
        doc.close()
        print(f"\nSaved: {args.output}")
    
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
