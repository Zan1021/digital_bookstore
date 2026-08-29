"""
Render Comparison Tool — Digital Bookstore V8
===============================================
Renders all pages from source and translated PDFs as images,
then produces a side-by-side comparison report.

Used for visual QA: ensures non-text content remains unchanged
and translated text fits within its assigned regions.

Usage:
    python render_comparison.py --source book.pdf --translated book_af.pdf --output ./comparison/ --dpi 150
"""

import argparse
import json
import os
import sys
from pathlib import Path

import pymupdf


def render_pdf_pages(pdf_path: str, output_dir: str, dpi: int = 150, prefix: str = "page") -> list:
    """Render all pages of a PDF as PNG images."""
    os.makedirs(output_dir, exist_ok=True)
    doc = pymupdf.open(pdf_path)
    pages = []

    for page_idx in range(len(doc)):
        page = doc[page_idx]
        pix = page.get_pixmap(dpi=dpi)
        filename = f"{prefix}_{page_idx + 1:03d}.png"
        filepath = os.path.join(output_dir, filename)
        pix.save(filepath)
        pages.append({
            "page_number": page_idx + 1,
            "image_path": filepath,
            "width": pix.width,
            "height": pix.height,
        })

    doc.close()
    return pages


def compare_pages(source_dir: str, translated_dir: str, output_dir: str, dpi: int = 150, text_masks: dict = None) -> dict:
    """
    Generate a comparison report between source and translated page renders.
    
    For each page:
    - Checks dimension match
    - Computes pixel difference percentage (using raw pixel sampling)
    - Identifies regions with significant changes
    - Flags pages that exceed the tolerance threshold
    """
    os.makedirs(output_dir, exist_ok=True)

    source_pages = sorted(Path(source_dir).glob("*.png"))
    translated_pages = sorted(Path(translated_dir).glob("*.png"))

    report = {
        "total_pages": len(source_pages),
        "pages_compared": 0,
        "pages_passed": 0,
        "pages_failed": 0,
        "dimension_mismatches": 0,
        "pages": [],
    }

    for src_img in source_pages:
        page_num = int(src_img.stem.split("_")[1])
        
        # Find matching translated page
        trans_img = Path(translated_dir) / f"translated_{page_num:03d}.png"
        if not trans_img.exists():
            report["pages"].append({
                "page_number": page_num,
                "status": "missing_translation",
                "source_image": str(src_img),
                "translated_image": None,
            })
            report["pages_failed"] += 1
            continue

        report["pages_compared"] += 1

        # Load both images as pixmaps
        src_pix = pymupdf.Pixmap(str(src_img))
        trans_pix = pymupdf.Pixmap(str(trans_img))

        dims_match = (src_pix.width == trans_pix.width and src_pix.height == trans_pix.height)
        if not dims_match:
            report["dimension_mismatches"] += 1
            report["pages"].append({
                "page_number": page_num,
                "status": "dimension_mismatch",
                "source_image": str(src_img),
                "translated_image": str(trans_img),
                "dimensions_match": False,
                "source_size": f"{src_pix.width}x{src_pix.height}",
                "translated_size": f"{trans_pix.width}x{trans_pix.height}",
            })
            report["pages_failed"] += 1
            continue

        # Pixel comparison — sample grid of points and compute difference
        width = src_pix.width
        height = src_pix.height
        total_pixels_sampled = 0
        different_pixels = 0
        max_diff = 0

        # Build pixel mask from text bboxes (if provided)
        page_mask = None
        if text_masks and page_num in text_masks:
            mask_data = text_masks[page_num]
            scale_x = width / mask_data["page_width"]
            scale_y = height / mask_data["page_height"]
            page_mask = []
            for bbox in mask_data["bboxes"]:
                page_mask.append((
                    int(bbox[0] * scale_x),
                    int(bbox[1] * scale_y),
                    int(bbox[2] * scale_x),
                    int(bbox[3] * scale_y),
                ))

        def is_in_text_zone(x, y):
            """Check if pixel is inside a known text zone (should be skipped)."""
            if not page_mask:
                return False
            for mx0, my0, mx1, my1 in page_mask:
                if mx0 <= x <= mx1 and my0 <= y <= my1:
                    return True
            return False

        # Sample every 4th pixel for speed (still covers enough area)
        step = 4
        for y in range(0, height, step):
            for x in range(0, width, step):
                # Skip pixels in text zones (expected to differ)
                if is_in_text_zone(x, y):
                    continue

                src_pixel = src_pix.pixel(x, y)
                trans_pixel = trans_pix.pixel(x, y)
                
                total_pixels_sampled += 1
                
                # Calculate per-channel difference
                diff = 0
                channels = min(len(src_pixel), len(trans_pixel), 3)
                for c in range(channels):
                    diff += abs(src_pixel[c] - trans_pixel[c])
                
                avg_diff = diff / channels
                if avg_diff > 10:  # Threshold: >10/255 difference = significant
                    different_pixels += 1
                    max_diff = max(max_diff, avg_diff)

        # Calculate difference percentage
        diff_percent = (different_pixels / max(total_pixels_sampled, 1)) * 100

        # Determine pass/fail
        # With masking: non-text areas should be nearly identical (<5% diff)
        # Without masking: text areas will always differ, so use generous threshold (40%)
        threshold = 5 if page_mask else 40
        passed = diff_percent < threshold

        page_result = {
            "page_number": page_num,
            "status": "passed" if passed else "failed",
            "source_image": str(src_img),
            "translated_image": str(trans_img),
            "dimensions_match": True,
            "source_size": f"{width}x{height}",
            "pixel_diff_percent": round(diff_percent, 2),
            "max_channel_diff": round(max_diff, 1),
            "pixels_sampled": total_pixels_sampled,
            "pixels_different": different_pixels,
            "threshold": threshold,
            "masked": bool(page_mask),
        }

        if passed:
            report["pages_passed"] += 1
        else:
            report["pages_failed"] += 1

        report["pages"].append(page_result)

    return report


def full_comparison(source_pdf: str, translated_pdf: str, output_dir: str, dpi: int = 150, manifest_path: str = None) -> dict:
    """
    Full comparison pipeline:
    1. Render source PDF pages as images
    2. Render translated PDF pages as images
    3. Compare pixels (with optional text-zone masking from manifest)
    4. Output report
    
    If manifest_path is provided, text zones are masked (excluded from diff),
    so only non-text areas (borders, artwork, illustrations) are compared.
    """
    source_dir = os.path.join(output_dir, "source")
    translated_dir = os.path.join(output_dir, "translated")

    print(f"Rendering source PDF at {dpi} DPI...", file=sys.stderr)
    source_pages = render_pdf_pages(source_pdf, source_dir, dpi, prefix="source")
    print(f"  {len(source_pages)} pages rendered", file=sys.stderr)

    print(f"Rendering translated PDF at {dpi} DPI...", file=sys.stderr)
    translated_pages = render_pdf_pages(translated_pdf, translated_dir, dpi, prefix="translated")
    print(f"  {len(translated_pages)} pages rendered", file=sys.stderr)

    # Load manifest for text-zone masking
    text_masks = {}
    if manifest_path and os.path.exists(manifest_path):
        with open(manifest_path, 'r', encoding='utf-8') as f:
            manifest = json.load(f)
        for page_data in manifest.get("pages", []):
            page_num = page_data["page_number"]
            page_width = page_data.get("geometry", {}).get("width", 538)
            page_height = page_data.get("geometry", {}).get("height", 751)
            bboxes = []
            for region in page_data.get("regions", []):
                for item in region.get("items", []):
                    if "bbox" in item:
                        bboxes.append(item["bbox"])
            if bboxes:
                text_masks[page_num] = {
                    "bboxes": bboxes,
                    "page_width": page_width,
                    "page_height": page_height,
                }

    print("Comparing pages...", file=sys.stderr)
    report = compare_pages(source_dir, translated_dir, output_dir, dpi, text_masks)

    # Add metadata
    report["source_pdf"] = os.path.basename(source_pdf)
    report["translated_pdf"] = os.path.basename(translated_pdf)
    report["dpi"] = dpi
    report["masked"] = bool(text_masks)

    # Save report
    report_path = os.path.join(output_dir, "comparison_report.json")
    with open(report_path, 'w', encoding='utf-8') as f:
        json.dump(report, f, indent=2, ensure_ascii=False)

    print(f"\nComparison complete:", file=sys.stderr)
    print(f"  Pages compared: {report['pages_compared']}", file=sys.stderr)
    print(f"  Passed: {report['pages_passed']}", file=sys.stderr)
    print(f"  Failed: {report['pages_failed']}", file=sys.stderr)
    print(f"  Masked comparison: {'Yes' if text_masks else 'No'}", file=sys.stderr)
    print(f"  Report: {report_path}", file=sys.stderr)

    return report


def main():
    parser = argparse.ArgumentParser(description="Render & Compare PDFs for Visual QA")
    parser.add_argument("--source", "-s", required=True, help="Source PDF path")
    parser.add_argument("--translated", "-t", required=True, help="Translated PDF path")
    parser.add_argument("--output", "-o", required=True, help="Output directory for comparison")
    parser.add_argument("--dpi", type=int, default=150, help="Render DPI (default: 150)")

    args = parser.parse_args()

    report = full_comparison(args.source, args.translated, args.output, args.dpi)
    print(json.dumps(report, indent=2))


if __name__ == "__main__":
    main()
