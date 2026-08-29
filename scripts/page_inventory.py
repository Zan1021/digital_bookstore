"""
Full Page Object Inventory — Digital Bookstore V8
===================================================
Inventory every visible object on a page: images, paths, fills,
strokes, clips, transparency groups.

Per the brief:
  - Full inventory of all visible objects (not just text)
  - This informs the renderer about what NOT to touch
  - Used for confidence scoring (more complex pages = lower confidence)
  - Used for overlap detection (text over images, text in bubbles)

Object types tracked:
  - Images (raster: JPEG, PNG, etc.)
  - Vector paths (lines, curves, fills, strokes)
  - Text spans (already handled by V8, included for completeness)
  - Transparency groups
  - Clipping paths
  - Form XObjects (embedded page fragments)
  - Annotations

Usage:
    python page_inventory.py inventory --input book.pdf --page N
    python page_inventory.py summary --input book.pdf
"""

import argparse
import json
import os
import sys
from typing import Optional

import pymupdf


# =============================================================================
# IMAGE INVENTORY
# =============================================================================

def inventory_images(page) -> list:
    """
    Get all images on a page with their bounding boxes, types, and sizes.
    
    Returns list of:
    {
        "type": "image",
        "xref": int,
        "bbox": [x0, y0, x1, y1],
        "width_px": int,
        "height_px": int,
        "colorspace": str,
        "bits_per_component": int,
        "coverage": float (0-1, fraction of page area),
        "format": str (jpeg, png, etc.)
    }
    """
    images = []
    page_area = page.rect.width * page.rect.height
    
    image_list = page.get_images(full=True)
    
    for img_info in image_list:
        xref = img_info[0]
        width = img_info[2]
        height = img_info[3]
        bpc = img_info[4]
        colorspace = img_info[5]
        
        # Get image bbox on page
        try:
            rects = page.get_image_rects(xref)
            for rect in rects:
                coverage = (rect.width * rect.height) / max(page_area, 1)
                images.append({
                    "type": "image",
                    "xref": xref,
                    "bbox": [round(rect.x0, 1), round(rect.y0, 1), 
                            round(rect.x1, 1), round(rect.y1, 1)],
                    "width_px": width,
                    "height_px": height,
                    "colorspace": colorspace,
                    "bits_per_component": bpc,
                    "coverage": round(coverage, 4),
                    "is_full_page": coverage > 0.8,
                })
        except Exception:
            # Image exists but we can't determine its position
            images.append({
                "type": "image",
                "xref": xref,
                "bbox": None,
                "width_px": width,
                "height_px": height,
                "colorspace": colorspace,
                "bits_per_component": bpc,
                "coverage": 0,
                "is_full_page": False,
            })
    
    return images


# =============================================================================
# VECTOR PATH INVENTORY (using drawings)
# =============================================================================

def inventory_drawings(page) -> dict:
    """
    Get all vector drawings (paths) on a page.
    
    PyMuPDF's get_drawings() returns all vector graphics:
    - Lines, rectangles, curves
    - Fills, strokes
    - Clipping paths
    
    We categorize them for the engine:
    - Decorative (borders, separators, background fills)
    - Structural (table lines, column separators)
    - Clips (clipping regions)
    
    Returns:
    {
        "total_paths": int,
        "fills": int,
        "strokes": int,
        "clips": int,
        "rects": int,
        "curves": int,
        "lines": int,
        "paths": [first N items for reference]
    }
    """
    try:
        drawings = page.get_drawings()
    except Exception:
        return {
            "total_paths": 0,
            "fills": 0, "strokes": 0, "clips": 0,
            "rects": 0, "curves": 0, "lines": 0,
            "paths": [],
        }
    
    stats = {
        "total_paths": len(drawings),
        "fills": 0,
        "strokes": 0,
        "clips": 0,
        "rects": 0,
        "curves": 0,
        "lines": 0,
        "paths": [],  # Sample of first 20
    }
    
    for i, drawing in enumerate(drawings):
        # Count by operation type
        if drawing.get("fill"):
            stats["fills"] += 1
        if (drawing.get("stroke_opacity") or 0) > 0 or drawing.get("color"):
            stats["strokes"] += 1
        if drawing.get("closePath") and drawing.get("fill") is None:
            stats["clips"] += 1
        
        # Classify path shape
        items = drawing.get("items", [])
        has_curve = any(item[0] == "c" for item in items)
        has_line = any(item[0] == "l" for item in items)
        is_rect = len(items) == 4 and all(item[0] == "l" for item in items)
        
        if is_rect:
            stats["rects"] += 1
        elif has_curve:
            stats["curves"] += 1
        elif has_line:
            stats["lines"] += 1
        
        # Store sample
        if i < 20:
            rect = drawing.get("rect")
            stats["paths"].append({
                "index": i,
                "bbox": [round(rect.x0, 1), round(rect.y0, 1),
                        round(rect.x1, 1), round(rect.y1, 1)] if rect else None,
                "fill_color": drawing.get("fill"),
                "stroke_color": drawing.get("color"),
                "width": drawing.get("width", 0),
                "item_count": len(items),
                "shape": "rect" if is_rect else ("curve" if has_curve else "line"),
            })
    
    return stats


# =============================================================================
# TEXT INVENTORY (summarized)
# =============================================================================

def inventory_text(page) -> dict:
    """
    Quick text summary for the inventory (detailed spans handled elsewhere).
    
    Returns:
    {
        "total_spans": int,
        "total_chars": int,
        "font_sizes": [unique sizes],
        "fonts_used": [unique fonts],
        "has_rotated_text": bool,
    }
    """
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
    
    spans_count = 0
    chars_count = 0
    font_sizes = set()
    fonts = set()
    has_rotated = False
    
    for block in text_dict.get("blocks", []):
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            direction = tuple(line.get("dir", (1, 0)))
            if abs(direction[0]) < 0.99 or abs(direction[1]) > 0.01:
                has_rotated = True
            
            for span in line.get("spans", []):
                text = span["text"].strip()
                if text:
                    spans_count += 1
                    chars_count += len(text)
                    font_sizes.add(round(span["size"], 1))
                    
                    font_name = span["font"]
                    if len(font_name) > 7 and font_name[6] == '+':
                        font_name = font_name[7:]
                    fonts.add(font_name)
    
    return {
        "total_spans": spans_count,
        "total_chars": chars_count,
        "font_sizes": sorted(font_sizes),
        "fonts_used": sorted(fonts),
        "has_rotated_text": has_rotated,
    }


# =============================================================================
# ANNOTATIONS INVENTORY
# =============================================================================

def inventory_annotations(page) -> list:
    """Get all annotations on a page."""
    annotations = []
    
    for annot in page.annots():
        annotations.append({
            "type": annot.type[1],  # Type name string
            "bbox": [round(annot.rect.x0, 1), round(annot.rect.y0, 1),
                    round(annot.rect.x1, 1), round(annot.rect.y1, 1)],
            "content": annot.info.get("content", "")[:100],
        })
    
    return annotations


# =============================================================================
# FULL PAGE INVENTORY
# =============================================================================

def build_page_inventory(page, page_num: int) -> dict:
    """
    Build a complete inventory of all visible objects on a page.
    
    Returns:
    {
        "page_number": int,
        "geometry": {"width": float, "height": float, "rotation": int},
        "images": [...],
        "drawings": {...},
        "text": {...},
        "annotations": [...],
        "complexity_score": float (0-1),
        "characteristics": [str]
    }
    """
    images = inventory_images(page)
    drawings = inventory_drawings(page)
    text = inventory_text(page)
    annotations = inventory_annotations(page)
    
    # Calculate complexity score (0 = simple, 1 = very complex)
    # Factors: number of objects, variety, overlaps
    image_score = min(len(images) / 5, 1.0) * 0.2
    drawing_score = min(drawings["total_paths"] / 100, 1.0) * 0.3
    text_score = min(text["total_spans"] / 50, 1.0) * 0.2
    variety_score = (
        (1 if images else 0) +
        (1 if drawings["total_paths"] > 0 else 0) +
        (1 if text["total_spans"] > 0 else 0) +
        (1 if drawings["curves"] > 0 else 0) +
        (1 if text["has_rotated_text"] else 0)
    ) / 5 * 0.3
    
    complexity = round(image_score + drawing_score + text_score + variety_score, 3)
    
    # Determine page characteristics
    characteristics = []
    if any(img.get("is_full_page") for img in images):
        characteristics.append("full_page_image")
    if drawings["total_paths"] > 50:
        characteristics.append("heavy_vector_graphics")
    if drawings["rects"] > 10:
        characteristics.append("table_structure")
    if text["has_rotated_text"]:
        characteristics.append("rotated_text")
    if text["total_spans"] > 30:
        characteristics.append("text_heavy")
    if len(images) > 3:
        characteristics.append("multi_image")
    if not text["total_spans"] and images:
        characteristics.append("image_only")
    if drawings["fills"] > 20:
        characteristics.append("colored_fills")
    
    return {
        "page_number": page_num,
        "geometry": {
            "width": round(page.rect.width, 1),
            "height": round(page.rect.height, 1),
            "rotation": page.rotation,
        },
        "images": images,
        "drawings": drawings,
        "text": text,
        "annotations": annotations,
        "complexity_score": complexity,
        "characteristics": characteristics,
        "object_counts": {
            "images": len(images),
            "vector_paths": drawings["total_paths"],
            "text_spans": text["total_spans"],
            "annotations": len(annotations),
            "total": len(images) + drawings["total_paths"] + text["total_spans"] + len(annotations),
        },
    }


def build_document_inventory(pdf_path: str) -> dict:
    """
    Build inventory for an entire document.
    
    Returns:
    {
        "file": str,
        "total_pages": int,
        "pages": [...],
        "summary": {...}
    }
    """
    doc = pymupdf.open(pdf_path)
    
    result = {
        "file": pdf_path,
        "total_pages": len(doc),
        "pages": [],
        "summary": {
            "total_images": 0,
            "total_vector_paths": 0,
            "total_text_spans": 0,
            "avg_complexity": 0,
            "complex_pages": [],
            "simple_pages": [],
        },
    }
    
    complexities = []
    
    for i in range(len(doc)):
        page = doc[i]
        inventory = build_page_inventory(page, i + 1)
        result["pages"].append(inventory)
        
        # Accumulate summary
        result["summary"]["total_images"] += inventory["object_counts"]["images"]
        result["summary"]["total_vector_paths"] += inventory["object_counts"]["vector_paths"]
        result["summary"]["total_text_spans"] += inventory["object_counts"]["text_spans"]
        complexities.append(inventory["complexity_score"])
        
        if inventory["complexity_score"] > 0.6:
            result["summary"]["complex_pages"].append(i + 1)
        elif inventory["complexity_score"] < 0.2:
            result["summary"]["simple_pages"].append(i + 1)
    
    result["summary"]["avg_complexity"] = round(
        sum(complexities) / max(len(complexities), 1), 3
    )
    
    doc.close()
    return result


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="Full Page Object Inventory — V8")
    subparsers = parser.add_subparsers(dest="command")
    
    # Single page inventory
    inv_p = subparsers.add_parser("inventory", help="Inventory a single page")
    inv_p.add_argument("--input", "-i", required=True)
    inv_p.add_argument("--page", "-p", type=int, required=True)
    inv_p.add_argument("--json", action="store_true")
    
    # Document summary
    sum_p = subparsers.add_parser("summary", help="Summarize entire document")
    sum_p.add_argument("--input", "-i", required=True)
    sum_p.add_argument("--json", action="store_true")
    
    args = parser.parse_args()
    
    if args.command == "inventory":
        doc = pymupdf.open(args.input)
        page = doc[args.page - 1]
        inventory = build_page_inventory(page, args.page)
        doc.close()
        
        if args.json:
            print(json.dumps(inventory, indent=2, ensure_ascii=False))
        else:
            print(f"Page {args.page} Inventory:")
            print(f"  Geometry: {inventory['geometry']['width']}x{inventory['geometry']['height']} "
                  f"(rotation: {inventory['geometry']['rotation']})")
            print(f"  Complexity: {inventory['complexity_score']:.1%}")
            print(f"  Characteristics: {', '.join(inventory['characteristics']) or 'none'}")
            print(f"\n  Objects:")
            oc = inventory['object_counts']
            print(f"    Images: {oc['images']}")
            print(f"    Vector paths: {oc['vector_paths']}")
            print(f"    Text spans: {oc['text_spans']}")
            print(f"    Annotations: {oc['annotations']}")
            print(f"    Total: {oc['total']}")
            
            if inventory['images']:
                print(f"\n  Images:")
                for img in inventory['images']:
                    print(f"    {img['width_px']}x{img['height_px']} "
                          f"({img['colorspace']}) "
                          f"coverage={img['coverage']:.0%}")
            
            print(f"\n  Drawings:")
            d = inventory['drawings']
            print(f"    Fills: {d['fills']}, Strokes: {d['strokes']}, "
                  f"Rects: {d['rects']}, Curves: {d['curves']}, Lines: {d['lines']}")
            
            print(f"\n  Text:")
            t = inventory['text']
            print(f"    Spans: {t['total_spans']}, Chars: {t['total_chars']}")
            print(f"    Fonts: {', '.join(t['fonts_used'])}")
            print(f"    Sizes: {t['font_sizes']}")
            print(f"    Rotated: {t['has_rotated_text']}")
    
    elif args.command == "summary":
        result = build_document_inventory(args.input)
        
        if args.json:
            print(json.dumps(result, indent=2, ensure_ascii=False))
        else:
            print(f"Document Inventory: {result['file']}")
            print(f"  Pages: {result['total_pages']}")
            s = result['summary']
            print(f"  Total images: {s['total_images']}")
            print(f"  Total vector paths: {s['total_vector_paths']}")
            print(f"  Total text spans: {s['total_text_spans']}")
            print(f"  Avg complexity: {s['avg_complexity']:.1%}")
            
            if s['complex_pages']:
                print(f"  Complex pages (>60%): {s['complex_pages']}")
            if s['simple_pages']:
                print(f"  Simple pages (<20%): {s['simple_pages']}")
            
            print(f"\n  Per-page breakdown:")
            for pg in result['pages']:
                chars = ', '.join(pg['characteristics'][:3]) if pg['characteristics'] else 'basic'
                oc = pg['object_counts']
                print(f"    Page {pg['page_number']:2d}: "
                      f"complexity={pg['complexity_score']:.0%} "
                      f"| img={oc['images']} path={oc['vector_paths']} "
                      f"txt={oc['text_spans']} "
                      f"| {chars}")
    
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
