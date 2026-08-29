"""
Crop-Mark Detection & Digital Trimming — Digital Bookstore V8
===============================================================
Auto-detects whether a PDF has crop marks. If yes → trims digitally.
If no → passes the book through unchanged.

Detection priority (per brief):
  1. Valid TrimBox (smaller than MediaBox)
  2. Vector crop-mark detection
  3. Book-wide consensus
  4. Fallback: use as-is

This runs as a preprocessing step on upload, BEFORE the translation engine.

Usage:
    python cropmark_detection.py detect --input book.pdf
    python cropmark_detection.py trim --input book.pdf --output trimmed.pdf
    python cropmark_detection.py auto --input book.pdf --output output.pdf
"""

import argparse
import json
import math
import os
import sys
from typing import Optional

import pymupdf


# =============================================================================
# CONFIGURATION
# =============================================================================

CONFIG = {
    # Crop mark characteristics
    "max_stroke_width_pt": 1.5,
    "min_mark_length_pt": 4,
    "max_mark_length_pt": 40,
    "max_angle_deviation_deg": 1.5,
    # How far from page edge to search (ratio of page dimension)
    "edge_search_band_ratio": 0.15,
    # Gap between mark endpoint and inferred trim line
    "min_mark_gap_pt": 1,
    "max_mark_gap_pt": 30,
    # Trim box must be at least this many points smaller than MediaBox on each side
    "min_trim_inset_pt": 10,
    # Confidence thresholds
    "auto_accept_confidence": 0.85,
    "review_confidence": 0.5,
    # Alignment tolerance for opposite-side marks
    "alignment_tolerance_pt": 3.0,
}


# =============================================================================
# PAGE BOX INSPECTION
# =============================================================================

def inspect_page_boxes(page) -> dict:
    """
    Inspect all PDF page boxes and determine if metadata indicates trimming needed.
    """
    media_box = list(page.mediabox)
    crop_box = list(page.cropbox)
    # pymupdf exposes trimbox and bleedbox if set
    trim_box = list(page.trimbox) if page.trimbox else None
    bleed_box = list(page.bleedbox) if page.bleedbox else None
    
    # Check if TrimBox is meaningfully smaller than MediaBox
    has_valid_trim = False
    trim_inset = [0, 0, 0, 0]
    
    if trim_box:
        inset_left = trim_box[0] - media_box[0]
        inset_top = trim_box[1] - media_box[1]
        inset_right = media_box[2] - trim_box[2]
        inset_bottom = media_box[3] - trim_box[3]
        trim_inset = [inset_left, inset_top, inset_right, inset_bottom]
        
        # Valid if inset is meaningful (> min threshold on at least 2 sides)
        meaningful_insets = sum(1 for i in trim_inset if i >= CONFIG["min_trim_inset_pt"])
        has_valid_trim = meaningful_insets >= 2
        
        # BUT — only flag as "needs trimming" if CropBox == MediaBox
        # (meaning the PDF viewer is currently showing the bleed/marks)
        # If CropBox already matches TrimBox, the viewer is already clipped correctly
        crop_matches_trim = (
            abs(crop_box[0] - trim_box[0]) < 2 and
            abs(crop_box[1] - trim_box[1]) < 2 and
            abs(crop_box[2] - trim_box[2]) < 2 and
            abs(crop_box[3] - trim_box[3]) < 2
        )
        if crop_matches_trim:
            has_valid_trim = False  # Already displaying correctly
    
    # Check if CropBox is meaningfully smaller than MediaBox
    has_smaller_crop = False
    if crop_box != media_box:
        crop_inset_left = crop_box[0] - media_box[0]
        crop_inset_right = media_box[2] - crop_box[2]
        crop_inset_top = crop_box[1] - media_box[1]
        crop_inset_bottom = media_box[3] - crop_box[3]
        total_inset = crop_inset_left + crop_inset_right + crop_inset_top + crop_inset_bottom
        has_smaller_crop = total_inset > CONFIG["min_trim_inset_pt"] * 2
    
    return {
        "media_box": media_box,
        "crop_box": crop_box,
        "trim_box": trim_box,
        "bleed_box": bleed_box,
        "has_valid_trim_box": has_valid_trim,
        "has_smaller_crop_box": has_smaller_crop,
        "trim_inset": trim_inset,
        "media_width": media_box[2] - media_box[0],
        "media_height": media_box[3] - media_box[1],
    }


# =============================================================================
# VECTOR CROP-MARK DETECTION
# =============================================================================

def extract_line_candidates(page) -> list:
    """
    Extract vector line segments that could be crop marks.
    
    Crop mark candidates are:
    - Near page edges (within edge_search_band)
    - Thin strokes (< max_stroke_width)
    - Short segments (min_mark_length to max_mark_length)
    - Horizontal or vertical (within angle tolerance)
    - Black or near-black color
    """
    candidates = []
    page_width = page.rect.width
    page_height = page.rect.height
    
    # Edge search bands
    band_x = page_width * CONFIG["edge_search_band_ratio"]
    band_y = page_height * CONFIG["edge_search_band_ratio"]
    
    try:
        drawings = page.get_drawings()
    except Exception:
        return []
    
    line_idx = 0
    for drawing in drawings:
        # Check stroke width
        stroke_width = drawing.get("width", 0) or 0
        if stroke_width > CONFIG["max_stroke_width_pt"]:
            continue
        
        # Check color (crop marks are typically black)
        color = drawing.get("color")
        fill = drawing.get("fill")
        if color and isinstance(color, (list, tuple)):
            # Skip obviously colored strokes (non-black)
            if len(color) >= 3:
                r, g, b = color[0], color[1], color[2]
                if r > 0.3 or g > 0.3 or b > 0.3:
                    continue  # Not dark enough
        
        # Extract line segments from drawing items
        items = drawing.get("items", [])
        for item in items:
            if item[0] != "l":  # Only line segments
                continue
            
            # item = ("l", Point(x0,y0), Point(x1,y1))
            p1 = item[1]
            p2 = item[2]
            x0, y0 = p1.x, p1.y
            x1, y1 = p2.x, p2.y
            
            # Calculate length
            length = math.sqrt((x1 - x0)**2 + (y1 - y0)**2)
            if length < CONFIG["min_mark_length_pt"] or length > CONFIG["max_mark_length_pt"]:
                continue
            
            # Determine orientation
            if abs(x1 - x0) < 0.5:
                orientation = "vertical"
                angle_deviation = 0
            elif abs(y1 - y0) < 0.5:
                orientation = "horizontal"
                angle_deviation = 0
            else:
                angle = math.degrees(math.atan2(abs(y1 - y0), abs(x1 - x0)))
                if angle < CONFIG["max_angle_deviation_deg"]:
                    orientation = "horizontal"
                    angle_deviation = angle
                elif abs(90 - angle) < CONFIG["max_angle_deviation_deg"]:
                    orientation = "vertical"
                    angle_deviation = abs(90 - angle)
                else:
                    continue  # Not horizontal or vertical
            
            # Check if near page edge
            mid_x = (x0 + x1) / 2
            mid_y = (y0 + y1) / 2
            
            near_left = mid_x < band_x
            near_right = mid_x > page_width - band_x
            near_top = mid_y < band_y
            near_bottom = mid_y > page_height - band_y
            
            if not (near_left or near_right or near_top or near_bottom):
                continue  # Not near any edge
            
            line_idx += 1
            
            # Determine which edge and corner this mark is near
            edge = []
            if near_left:
                edge.append("left")
            if near_right:
                edge.append("right")
            if near_top:
                edge.append("top")
            if near_bottom:
                edge.append("bottom")
            
            candidates.append({
                "id": f"line-{line_idx:04d}",
                "start": [round(x0, 2), round(y0, 2)],
                "end": [round(x1, 2), round(y1, 2)],
                "orientation": orientation,
                "length": round(length, 2),
                "stroke_width": stroke_width,
                "edge": edge,
                "midpoint": [round(mid_x, 2), round(mid_y, 2)],
            })
    
    return candidates


def infer_trim_boundaries(candidates: list, page_width: float, page_height: float) -> dict:
    """
    From crop mark candidates, infer the four trim boundaries.
    
    Crop marks point toward the trim axis:
    - Horizontal marks near top/bottom → indicate top/bottom trim Y
    - Vertical marks near left/right → indicate left/right trim X
    
    The trim coordinate is the INNER endpoint of the mark (closest to page center).
    """
    # Collect boundary candidates
    left_candidates = []
    right_candidates = []
    top_candidates = []
    bottom_candidates = []
    
    center_x = page_width / 2
    center_y = page_height / 2
    
    for mark in candidates:
        x0, y0 = mark["start"]
        x1, y1 = mark["end"]
        
        if mark["orientation"] == "vertical":
            # Vertical marks → indicate left or right trim X
            mark_x = (x0 + x1) / 2
            
            if mark_x < center_x:
                # Left side → trim X is at this x position
                left_candidates.append(mark_x)
            else:
                # Right side
                right_candidates.append(mark_x)
        
        elif mark["orientation"] == "horizontal":
            # Horizontal marks → indicate top or bottom trim Y
            mark_y = (y0 + y1) / 2
            
            if mark_y < center_y:
                # Top side
                top_candidates.append(mark_y)
            else:
                # Bottom side
                bottom_candidates.append(mark_y)
    
    # Get consensus values (median for robustness)
    def median(values):
        if not values:
            return None
        s = sorted(values)
        n = len(s)
        return s[n // 2]
    
    left_trim = median(left_candidates)
    right_trim = median(right_candidates)
    top_trim = median(top_candidates)
    bottom_trim = median(bottom_candidates)
    
    # Calculate confidence based on how many boundaries we found
    boundaries_found = sum(1 for b in [left_trim, right_trim, top_trim, bottom_trim] if b is not None)
    
    # Check alignment (opposite sides should agree on width/height)
    alignment_score = 1.0
    if left_trim and right_trim:
        inferred_width = right_trim - left_trim
        if inferred_width < page_width * 0.5:
            alignment_score *= 0.5  # Suspicious — too narrow
    if top_trim and bottom_trim:
        inferred_height = bottom_trim - top_trim
        if inferred_height < page_height * 0.5:
            alignment_score *= 0.5  # Suspicious — too short
    
    confidence = (boundaries_found / 4) * alignment_score
    
    # Build trim box (fall back to page edge if boundary not detected)
    trim_box = [
        round(left_trim, 1) if left_trim else 0,
        round(top_trim, 1) if top_trim else 0,
        round(right_trim, 1) if right_trim else page_width,
        round(bottom_trim, 1) if bottom_trim else page_height,
    ]
    
    return {
        "trim_box": trim_box,
        "boundaries_found": boundaries_found,
        "confidence": round(confidence, 3),
        "left_candidates": len(left_candidates),
        "right_candidates": len(right_candidates),
        "top_candidates": len(top_candidates),
        "bottom_candidates": len(bottom_candidates),
    }


# =============================================================================
# FULL PAGE DETECTION
# =============================================================================

def detect_crop_marks_page(page, page_num: int) -> dict:
    """
    Full detection for a single page.
    Returns whether crop marks are present and the recommended trim box.
    """
    # Step 1: Inspect metadata boxes
    boxes = inspect_page_boxes(page)
    
    # If TrimBox is valid and meaningfully smaller → use it
    if boxes["has_valid_trim_box"]:
        return {
            "page_number": page_num,
            "has_crop_marks": True,
            "detection_source": "trim_box_metadata",
            "recommended_trim": boxes["trim_box"],
            "confidence": 0.95,
            "candidates_found": 0,
            "details": boxes,
        }
    
    # Step 2: Look for vector crop marks
    candidates = extract_line_candidates(page)
    
    if candidates:
        # Try to infer trim boundaries
        boundaries = infer_trim_boundaries(
            candidates, boxes["media_width"], boxes["media_height"]
        )
        
        if boundaries["boundaries_found"] >= 3 and boundaries["confidence"] >= CONFIG["auto_accept_confidence"]:
            return {
                "page_number": page_num,
                "has_crop_marks": True,
                "detection_source": "vector_crop_marks",
                "recommended_trim": boundaries["trim_box"],
                "confidence": boundaries["confidence"],
                "candidates_found": len(candidates),
                "details": boundaries,
            }
        elif boundaries["boundaries_found"] >= 2 and boundaries["confidence"] >= CONFIG["review_confidence"]:
            return {
                "page_number": page_num,
                "has_crop_marks": True,
                "detection_source": "vector_crop_marks_low_confidence",
                "recommended_trim": boundaries["trim_box"],
                "confidence": boundaries["confidence"],
                "candidates_found": len(candidates),
                "requires_review": True,
                "details": boundaries,
            }
    
    # Step 3: Check if CropBox is meaningfully smaller (implies trimming needed)
    if boxes["has_smaller_crop_box"]:
        return {
            "page_number": page_num,
            "has_crop_marks": True,
            "detection_source": "crop_box_metadata",
            "recommended_trim": boxes["crop_box"],
            "confidence": 0.80,
            "candidates_found": 0,
            "details": boxes,
        }
    
    # No crop marks detected — use as-is
    return {
        "page_number": page_num,
        "has_crop_marks": False,
        "detection_source": "none",
        "recommended_trim": None,
        "confidence": 1.0,  # Confident there are NO crop marks
        "candidates_found": len(candidates) if candidates else 0,
        "details": boxes,
    }


# =============================================================================
# DOCUMENT-LEVEL DETECTION
# =============================================================================

def detect_crop_marks_document(pdf_path: str) -> dict:
    """
    Scan entire document for crop marks.
    Uses book-wide consensus to validate.
    
    Returns:
    {
        "has_crop_marks": bool (document-level decision),
        "needs_trimming": bool,
        "consensus_trim_box": [x0, y0, x1, y1] or None,
        "confidence": float,
        "pages_with_marks": int,
        "pages_without_marks": int,
        "page_results": [...]
    }
    """
    doc = pymupdf.open(pdf_path)
    
    page_results = []
    trim_boxes = []
    pages_with_marks = 0
    pages_without_marks = 0
    
    for i in range(len(doc)):
        page = doc[i]
        result = detect_crop_marks_page(page, i + 1)
        page_results.append(result)
        
        if result["has_crop_marks"]:
            pages_with_marks += 1
            if result["recommended_trim"]:
                trim_boxes.append(result["recommended_trim"])
        else:
            pages_without_marks += 1
    
    doc.close()
    
    # Document-level decision
    total_pages = len(page_results)
    mark_ratio = pages_with_marks / max(total_pages, 1)
    
    # If majority of pages have crop marks → document needs trimming
    has_crop_marks = mark_ratio > 0.5
    
    # Book-wide consensus on trim box
    consensus_trim = None
    if trim_boxes:
        # Use median of each coordinate for robustness
        lefts = sorted([t[0] for t in trim_boxes])
        tops = sorted([t[1] for t in trim_boxes])
        rights = sorted([t[2] for t in trim_boxes])
        bottoms = sorted([t[3] for t in trim_boxes])
        
        n = len(trim_boxes)
        consensus_trim = [
            round(lefts[n // 2], 1),
            round(tops[n // 2], 1),
            round(rights[n // 2], 1),
            round(bottoms[n // 2], 1),
        ]
    
    # Overall confidence
    if has_crop_marks and consensus_trim:
        avg_confidence = sum(r["confidence"] for r in page_results if r["has_crop_marks"]) / max(pages_with_marks, 1)
    else:
        avg_confidence = 1.0  # Confident it's clean
    
    return {
        "file": pdf_path,
        "total_pages": total_pages,
        "has_crop_marks": has_crop_marks,
        "needs_trimming": has_crop_marks,
        "consensus_trim_box": consensus_trim,
        "confidence": round(avg_confidence, 3),
        "pages_with_marks": pages_with_marks,
        "pages_without_marks": pages_without_marks,
        "detection_summary": {
            "trim_box_metadata": sum(1 for r in page_results if r.get("detection_source") == "trim_box_metadata"),
            "vector_crop_marks": sum(1 for r in page_results if "vector" in r.get("detection_source", "")),
            "crop_box_metadata": sum(1 for r in page_results if r.get("detection_source") == "crop_box_metadata"),
            "none": sum(1 for r in page_results if r.get("detection_source") == "none"),
        },
        "page_results": page_results,
    }


# =============================================================================
# DIGITAL TRIMMING — Non-destructive clipping
# =============================================================================

def trim_pdf(input_path: str, output_path: str, trim_box: list = None) -> dict:
    """
    Apply digital trimming by setting CropBox/TrimBox.
    Non-destructive: original content preserved, just clipped for display.
    
    If trim_box is None, auto-detects and trims if needed.
    """
    # Auto-detect if no trim box provided
    if trim_box is None:
        detection = detect_crop_marks_document(input_path)
        
        if not detection["needs_trimming"]:
            # No trimming needed — copy as-is
            import shutil
            shutil.copy2(input_path, output_path)
            return {
                "trimmed": False,
                "reason": "No crop marks detected",
                "confidence": detection["confidence"],
            }
        
        trim_box = detection["consensus_trim_box"]
        if not trim_box:
            import shutil
            shutil.copy2(input_path, output_path)
            return {
                "trimmed": False,
                "reason": "Crop marks detected but could not determine trim box",
                "confidence": detection["confidence"],
            }
    
    # Apply trim
    doc = pymupdf.open(input_path)
    trim_rect = pymupdf.Rect(trim_box)
    
    pages_trimmed = 0
    for page in doc:
        # Non-destructive: set CropBox to trim area
        # This hides content outside the trim in compliant PDF viewers
        page.set_cropbox(trim_rect)
        pages_trimmed += 1
    
    doc.save(output_path, garbage=4, deflate=True)
    doc.close()
    
    return {
        "trimmed": True,
        "trim_box": trim_box,
        "pages_trimmed": pages_trimmed,
        "method": "cropbox_clipping",
        "non_destructive": True,
    }


# =============================================================================
# AUTO PIPELINE — The main entry point for the system
# =============================================================================

def auto_process(input_path: str, output_path: str = None) -> dict:
    """
    THE AUTO PIPELINE.
    
    1. Detect if PDF has crop marks
    2. If yes → trim digitally and output clean version
    3. If no → pass through unchanged
    
    This is what gets called on book upload.
    
    Returns:
    {
        "action": "passthrough" | "trimmed",
        "output_path": str,
        "detection": {...},
        "trim_result": {...} or None,
    }
    """
    if output_path is None:
        # Output alongside input with _digital suffix
        base, ext = os.path.splitext(input_path)
        output_path = f"{base}_digital{ext}"
    
    # Step 1: Detect
    detection = detect_crop_marks_document(input_path)
    
    # Step 2: Decision
    if not detection["needs_trimming"]:
        # No crop marks → pass through
        # Just copy (or in a real system, use the original path)
        import shutil
        if input_path != output_path:
            shutil.copy2(input_path, output_path)
        
        return {
            "action": "passthrough",
            "output_path": output_path,
            "detection": {
                "has_crop_marks": False,
                "confidence": detection["confidence"],
                "pages_checked": detection["total_pages"],
            },
            "trim_result": None,
        }
    
    # Step 3: Trim
    trim_result = trim_pdf(input_path, output_path, detection["consensus_trim_box"])
    
    return {
        "action": "trimmed",
        "output_path": output_path,
        "detection": {
            "has_crop_marks": True,
            "confidence": detection["confidence"],
            "consensus_trim_box": detection["consensus_trim_box"],
            "pages_checked": detection["total_pages"],
            "detection_methods": detection["detection_summary"],
        },
        "trim_result": trim_result,
    }


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="Crop-Mark Detection & Trimming — V8")
    subparsers = parser.add_subparsers(dest="command")
    
    # Detect
    detect_p = subparsers.add_parser("detect", help="Detect crop marks in a PDF")
    detect_p.add_argument("--input", "-i", required=True)
    detect_p.add_argument("--json", action="store_true")
    
    # Trim
    trim_p = subparsers.add_parser("trim", help="Trim a PDF (force trim even without marks)")
    trim_p.add_argument("--input", "-i", required=True)
    trim_p.add_argument("--output", "-o", required=True)
    trim_p.add_argument("--trim-box", help="Manual trim box: x0,y0,x1,y1")
    
    # Auto (the main one)
    auto_p = subparsers.add_parser("auto", help="Auto-detect and trim if needed")
    auto_p.add_argument("--input", "-i", required=True)
    auto_p.add_argument("--output", "-o")
    
    args = parser.parse_args()
    
    if args.command == "detect":
        result = detect_crop_marks_document(args.input)
        
        if args.json:
            # Remove page_results for brevity in JSON mode
            output = {k: v for k, v in result.items() if k != "page_results"}
            output["page_count_with_details"] = len(result["page_results"])
            print(json.dumps(output, indent=2))
        else:
            print(f"Crop-Mark Detection: {os.path.basename(args.input)}")
            print(f"  Pages: {result['total_pages']}")
            print(f"  Has crop marks: {'YES' if result['has_crop_marks'] else 'NO'}")
            print(f"  Needs trimming: {'YES' if result['needs_trimming'] else 'NO'}")
            print(f"  Confidence: {result['confidence']:.0%}")
            
            if result['consensus_trim_box']:
                tb = result['consensus_trim_box']
                print(f"  Consensus trim box: [{tb[0]}, {tb[1]}, {tb[2]}, {tb[3]}]")
                print(f"  Trimmed size: {tb[2]-tb[0]:.0f} x {tb[3]-tb[1]:.0f} pts")
            
            print(f"\n  Detection breakdown:")
            for method, count in result['detection_summary'].items():
                if count > 0:
                    print(f"    {method}: {count} pages")
    
    elif args.command == "trim":
        trim_box = None
        if args.trim_box:
            trim_box = [float(x) for x in args.trim_box.split(",")]
        
        result = trim_pdf(args.input, args.output, trim_box)
        
        if result["trimmed"]:
            print(f"Trimmed: {args.output}")
            print(f"  Trim box: {result['trim_box']}")
            print(f"  Pages: {result['pages_trimmed']}")
            print(f"  Method: {result['method']}")
        else:
            print(f"Not trimmed: {result['reason']}")
    
    elif args.command == "auto":
        result = auto_process(args.input, args.output)
        
        print(f"Auto-Process Result:")
        print(f"  Action: {result['action']}")
        print(f"  Output: {result['output_path']}")
        
        det = result['detection']
        print(f"  Crop marks: {'YES' if det['has_crop_marks'] else 'NO'}")
        print(f"  Confidence: {det['confidence']:.0%}")
        
        if result['trim_result']:
            tr = result['trim_result']
            print(f"  Trim box: {tr.get('trim_box')}")
            print(f"  Pages trimmed: {tr.get('pages_trimmed')}")
    
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
