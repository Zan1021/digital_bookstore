"""
Container Detection — Digital Bookstore V8
============================================
Detects non-rectangular containers (speech bubbles, callouts, curved shapes)
and provides polygon-based text regions for proper text fitting.

Supported container types:
- Speech bubbles (elliptical/rounded shapes with tail)
- Callout boxes (rounded rectangles with pointer)
- Circular labels
- Irregular shapes

Uses PyMuPDF drawing inspection to find closed paths that contain text.

Usage:
    python container_detection.py detect --input book.pdf --page 5 --output containers.json
"""

import argparse
import json
import math
import os
import sys
from typing import Optional

import pymupdf


# =============================================================================
# SPEECH BUBBLE DETECTION
# =============================================================================

def detect_speech_bubbles(page) -> list:
    """
    Detect speech bubbles and non-rectangular text containers on a page.
    
    Strategy:
    1. Get all drawings (paths) on the page
    2. Find closed paths that form enclosed shapes
    3. Check if any text spans fall within those shapes
    4. If text is inside a closed non-rectangular path → it's a speech bubble
    
    Returns list of bubble dicts:
    [
        {
            "id": "bubble-001",
            "type": "speech_bubble",
            "bbox": [x0, y0, x1, y1],
            "polygon": [[x,y], ...],
            "inner_rect": [x0, y0, x1, y1],  # largest inscribed rectangle
            "padding": 8,
            "contained_text": ["text span 1", ...],
            "center": [cx, cy],
        }
    ]
    """
    bubbles = []
    drawings = page.get_drawings()
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)

    # Get all text span positions
    text_spans = []
    for block in text_dict.get("blocks", []):
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                if span["text"].strip():
                    text_spans.append(span)

    # Analyze each drawing for bubble-like shapes
    bubble_idx = 0
    for drawing in drawings:
        items = drawing.get("items", [])
        rect = drawing.get("rect")
        
        if not rect or not items:
            continue

        # Skip very small drawings (decorations, dots) and very large (page borders)
        area = rect.width * rect.height
        page_area = page.rect.width * page.rect.height
        if area < 500 or area > page_area * 0.5:
            continue

        # Check if this is a closed path (potential bubble)
        is_closed = _is_closed_path(items)
        if not is_closed:
            continue

        # Check if it's roughly elliptical or rounded (not just a rectangle)
        is_bubble_shaped = _is_bubble_shaped(items, rect)
        if not is_bubble_shaped:
            continue

        # Check if any text spans fall inside this shape
        contained = []
        for span in text_spans:
            span_center_x = (span["bbox"][0] + span["bbox"][2]) / 2
            span_center_y = (span["bbox"][1] + span["bbox"][3]) / 2
            if rect.contains(pymupdf.Point(span_center_x, span_center_y)):
                contained.append(span["text"].strip())

        if not contained:
            continue  # No text inside = not a speech bubble

        bubble_idx += 1

        # Calculate inner rect (with padding from edges)
        padding = 8
        inner_rect = [
            rect.x0 + padding,
            rect.y0 + padding,
            rect.x1 - padding,
            rect.y1 - padding,
        ]

        # Extract polygon points from path
        polygon = _extract_polygon(items)

        bubbles.append({
            "id": f"bubble-{bubble_idx:03d}",
            "type": "speech_bubble",
            "bbox": [rect.x0, rect.y0, rect.x1, rect.y1],
            "polygon": polygon,
            "inner_rect": inner_rect,
            "padding": padding,
            "contained_text": contained,
            "center": [(rect.x0 + rect.x1) / 2, (rect.y0 + rect.y1) / 2],
            "width": rect.width,
            "height": rect.height,
        })

    return bubbles


def _is_closed_path(items) -> bool:
    """Check if a path is closed (forms an enclosed shape)."""
    if not items:
        return False
    
    # Look for 'c' (close) operation or check if start == end
    for item in items:
        if item[0] == "c":  # close path operator
            return True
    
    # Check if the path has enough curve/line segments to form a shape
    curve_count = sum(1 for item in items if item[0] in ("c", "l", "qu", "re"))
    return curve_count >= 3


def _is_bubble_shaped(items, rect) -> bool:
    """
    Determine if a path looks like a speech bubble (elliptical/rounded)
    rather than a simple rectangle.
    
    Bubbles have curves. Plain rectangles have only straight lines.
    """
    # Count curves vs straight lines
    curves = sum(1 for item in items if item[0] in ("c", "qu"))  # bezier curves
    lines = sum(1 for item in items if item[0] == "l")  # straight lines
    rects = sum(1 for item in items if item[0] == "re")  # rectangles

    # If it's just a rectangle, it's not a bubble
    if rects > 0 and curves == 0:
        return False

    # If it has curves, likely a bubble or rounded shape
    if curves >= 2:
        return True

    # Rounded rectangle (4 lines + 4 curves for corners)
    if curves >= 4 and lines >= 4:
        return True

    return False


def _extract_polygon(items) -> list:
    """Extract polygon vertices from path items."""
    points = []
    for item in items:
        op = item[0]
        if op == "l" and len(item) >= 2:  # line to
            pt = item[1]
            if hasattr(pt, 'x'):
                points.append([round(pt.x, 1), round(pt.y, 1)])
        elif op == "m" and len(item) >= 2:  # move to
            pt = item[1]
            if hasattr(pt, 'x'):
                points.append([round(pt.x, 1), round(pt.y, 1)])
        elif op == "c" and len(item) >= 4:  # curve (use endpoint)
            pt = item[3] if len(item) > 3 else item[-1]
            if hasattr(pt, 'x'):
                points.append([round(pt.x, 1), round(pt.y, 1)])

    return points


# =============================================================================
# CONTAINER-AWARE TEXT FITTING
# =============================================================================

def fit_text_in_bubble(
    text: str,
    bubble: dict,
    font_size: float,
    font_path: str,
    max_shrink: float = 0.7,
) -> dict:
    """
    Fit text within a speech bubble's inner rectangle.
    
    Returns fit result:
    {
        "fits": bool,
        "font_size": float,
        "lines": int,
        "rect": [x0, y0, x1, y1],
        "alignment": "center",
    }
    """
    inner = bubble["inner_rect"]
    width = inner[2] - inner[0]
    height = inner[3] - inner[1]

    font = pymupdf.Font(fontfile=font_path) if font_path else pymupdf.Font("helv")

    # Try fitting at current size
    current_size = font_size
    min_size = font_size * max_shrink

    while current_size >= min_size:
        # Estimate lines needed
        text_width = font.text_length(text, fontsize=current_size)
        chars_per_line = max(1, int(len(text) * (width / max(text_width, 1))))
        
        # Simple word-wrap estimation
        words = text.split()
        lines = []
        current_line = ""
        for word in words:
            test_line = f"{current_line} {word}".strip()
            if font.text_length(test_line, fontsize=current_size) <= width:
                current_line = test_line
            else:
                if current_line:
                    lines.append(current_line)
                current_line = word
        if current_line:
            lines.append(current_line)

        # Check height
        line_height = current_size * 1.3
        total_height = len(lines) * line_height

        if total_height <= height:
            return {
                "fits": True,
                "font_size": current_size,
                "lines": len(lines),
                "rect": inner,
                "alignment": "center",
                "text_lines": lines,
            }

        current_size -= 0.5

    return {
        "fits": False,
        "font_size": min_size,
        "lines": 0,
        "rect": inner,
        "alignment": "center",
        "overflow": True,
    }


# =============================================================================
# DETECT ALL CONTAINERS ON A PAGE
# =============================================================================

def detect_all_containers(page, page_num: int) -> dict:
    """
    Detect all non-rectangular text containers on a page.
    
    Returns:
    {
        "page_number": page_num,
        "bubbles": [...],
        "callouts": [...],
        "has_non_rectangular": bool,
    }
    """
    bubbles = detect_speech_bubbles(page)

    return {
        "page_number": page_num,
        "bubbles": bubbles,
        "callouts": [],  # Future: detect callout boxes
        "has_non_rectangular": len(bubbles) > 0,
        "container_count": len(bubbles),
    }


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="Container Detection (speech bubbles, callouts)")
    parser.add_argument("--input", "-i", required=True, help="Input PDF")
    parser.add_argument("--page", "-p", type=int, help="Single page to analyze")
    parser.add_argument("--output", "-o", help="Output JSON file")

    args = parser.parse_args()

    doc = pymupdf.open(args.input)
    results = []

    if args.page:
        page = doc[args.page - 1]
        result = detect_all_containers(page, args.page)
        results.append(result)
    else:
        for page_idx in range(len(doc)):
            page = doc[page_idx]
            result = detect_all_containers(page, page_idx + 1)
            if result["has_non_rectangular"]:
                results.append(result)

    doc.close()

    output = json.dumps(results, indent=2, ensure_ascii=False)
    if args.output:
        with open(args.output, 'w', encoding='utf-8') as f:
            f.write(output)
        print(f"Saved to: {args.output}", file=sys.stderr)
    else:
        print(output)

    # Summary
    total_bubbles = sum(r["container_count"] for r in results)
    pages_with = sum(1 for r in results if r["has_non_rectangular"])
    print(f"\nDetected {total_bubbles} containers on {pages_with} pages", file=sys.stderr)


if __name__ == "__main__":
    main()
