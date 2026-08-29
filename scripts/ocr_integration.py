"""
OCR Integration — Digital Bookstore V8
========================================
Handles scanned/image-based PDF pages where text is not extractable as spans.

Per the brief:
  - "Scanned prose — OCR and local inpainting — Live PDF text"
  - Detect text in images, extract with confidence scores
  - Fall back gracefully if OCR libraries are not available

Detection Strategy:
  A page is considered "scanned" if:
  1. It has zero or very few text spans (< 3 meaningful spans)
  2. But it has at least one large image covering most of the page area
  3. OR it was explicitly flagged by the manifest

OCR Backends (in priority order):
  1. PyMuPDF built-in OCR (uses Tesseract if tessdata is available)
  2. pytesseract (if installed) — via image extraction
  3. Fallback: flag page for manual handling

Usage:
    python ocr_integration.py detect --input book.pdf [--page N]
    python ocr_integration.py extract --input book.pdf --page N --output text.json
    python ocr_integration.py check-deps
"""

import argparse
import json
import os
import sys
from typing import Optional

import pymupdf


# =============================================================================
# DEPENDENCY CHECKING
# =============================================================================

def check_ocr_dependencies() -> dict:
    """
    Check which OCR backends are available.
    Returns availability status for each backend.
    """
    status = {
        "pymupdf_ocr": False,
        "pytesseract": False,
        "tesseract_path": None,
        "tessdata_path": None,
        "available_backend": None,
    }
    
    # Check PyMuPDF OCR capability (requires tessdata)
    try:
        tessdata = pymupdf.get_tessdata()
        if tessdata and os.path.isdir(tessdata):
            status["pymupdf_ocr"] = True
            status["tessdata_path"] = tessdata
    except Exception:
        pass
    
    # Check pytesseract
    try:
        import pytesseract
        # Try to get version to confirm it works
        pytesseract.get_tesseract_version()
        status["pytesseract"] = True
        status["tesseract_path"] = pytesseract.pytesseract.tesseract_cmd
    except (ImportError, Exception):
        pass
    
    # Determine best available backend
    if status["pymupdf_ocr"]:
        status["available_backend"] = "pymupdf"
    elif status["pytesseract"]:
        status["available_backend"] = "pytesseract"
    else:
        status["available_backend"] = None
    
    return status


# =============================================================================
# SCANNED PAGE DETECTION
# =============================================================================

def is_scanned_page(page, min_text_spans: int = 3) -> dict:
    """
    Determine if a page is scanned (image-based) vs born-digital.
    
    A page is considered scanned if:
    - Has very few extractable text spans (< min_text_spans)
    - Has at least one image covering > 50% of page area
    
    Returns:
    {
        "is_scanned": bool,
        "confidence": float (0-1),
        "reason": str,
        "text_span_count": int,
        "image_count": int,
        "largest_image_coverage": float (0-1),
    }
    """
    # Extract text spans
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
    span_count = 0
    for block in text_dict.get("blocks", []):
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                if span["text"].strip():
                    span_count += 1
    
    # Get images
    images = page.get_images()
    page_area = page.rect.width * page.rect.height
    
    # Calculate largest image coverage
    largest_coverage = 0.0
    for img_info in images:
        xref = img_info[0]
        try:
            # Get image dimensions from the page's image list
            img_rect = page.get_image_rects(xref)
            if img_rect:
                for rect in img_rect:
                    img_area = rect.width * rect.height
                    coverage = img_area / max(page_area, 1)
                    largest_coverage = max(largest_coverage, coverage)
        except Exception:
            pass
    
    # Decision logic
    has_few_spans = span_count < min_text_spans
    has_large_image = largest_coverage > 0.5
    
    if has_few_spans and has_large_image:
        is_scanned = True
        confidence = min(1.0, largest_coverage + (1 - span_count / max(min_text_spans, 1)) * 0.3)
        reason = f"Few text spans ({span_count}) with large image coverage ({largest_coverage:.0%})"
    elif has_few_spans and len(images) > 0:
        is_scanned = True
        confidence = 0.6
        reason = f"Few text spans ({span_count}) with images present"
    elif span_count == 0 and len(images) > 0:
        is_scanned = True
        confidence = 0.9
        reason = "Zero text spans with images"
    else:
        is_scanned = False
        confidence = 1.0 - (largest_coverage * 0.3)
        reason = f"Sufficient text spans ({span_count})"
    
    return {
        "is_scanned": is_scanned,
        "confidence": round(confidence, 3),
        "reason": reason,
        "text_span_count": span_count,
        "image_count": len(images),
        "largest_image_coverage": round(largest_coverage, 3),
    }


def detect_scanned_pages(pdf_path: str) -> dict:
    """
    Scan entire document and identify which pages are scanned.
    
    Returns:
    {
        "file": str,
        "total_pages": int,
        "scanned_pages": [page_numbers],
        "born_digital_pages": [page_numbers],
        "page_details": [...]
    }
    """
    doc = pymupdf.open(pdf_path)
    
    result = {
        "file": pdf_path,
        "total_pages": len(doc),
        "scanned_pages": [],
        "born_digital_pages": [],
        "page_details": [],
    }
    
    for i in range(len(doc)):
        page = doc[i]
        detection = is_scanned_page(page)
        detection["page_number"] = i + 1
        result["page_details"].append(detection)
        
        if detection["is_scanned"]:
            result["scanned_pages"].append(i + 1)
        else:
            result["born_digital_pages"].append(i + 1)
    
    doc.close()
    return result


# =============================================================================
# OCR EXTRACTION
# =============================================================================

def ocr_page_pymupdf(page, language: str = "eng") -> dict:
    """
    Extract text from a scanned page using PyMuPDF's built-in OCR.
    
    PyMuPDF can perform OCR on the page's pixmap and return structured text.
    Requires tessdata to be available.
    
    Returns:
    {
        "success": bool,
        "backend": "pymupdf",
        "text": str (full page text),
        "blocks": [...] (structured text blocks with positions),
        "confidence": float (average confidence if available),
        "language": str,
    }
    """
    try:
        # PyMuPDF's get_text with "ocr" flag performs OCR
        # This creates a TextPage using OCR
        tp = page.get_textpage_ocr(flags=pymupdf.TEXT_PRESERVE_WHITESPACE, language=language)
        
        # Extract text from the OCR'd textpage
        text = page.get_text("text", textpage=tp)
        
        # Get structured blocks with positions
        blocks_dict = page.get_text("dict", textpage=tp, flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
        
        # Parse into our format
        ocr_spans = []
        for block in blocks_dict.get("blocks", []):
            if block.get("type") != 0:
                continue
            for line in block.get("lines", []):
                for span in line.get("spans", []):
                    span_text = span["text"].strip()
                    if span_text:
                        ocr_spans.append({
                            "text": span_text,
                            "bbox": list(span["bbox"]),
                            "origin": list(span["origin"]),
                            "font_size": span["size"],
                            "confidence": 0.8,  # PyMuPDF doesn't give per-word confidence
                        })
        
        return {
            "success": True,
            "backend": "pymupdf",
            "text": text.strip(),
            "spans": ocr_spans,
            "span_count": len(ocr_spans),
            "confidence": 0.8,  # Default estimate
            "language": language,
        }
    
    except Exception as e:
        return {
            "success": False,
            "backend": "pymupdf",
            "error": str(e),
            "text": "",
            "spans": [],
            "span_count": 0,
            "confidence": 0,
            "language": language,
        }


def ocr_page_pytesseract(page, language: str = "eng", dpi: int = 300) -> dict:
    """
    Extract text from a scanned page using pytesseract.
    
    Renders the page to an image at high DPI, then runs Tesseract OCR.
    Returns structured output with bounding boxes and confidence scores.
    """
    try:
        import pytesseract
        from PIL import Image
        import io
    except ImportError:
        return {
            "success": False,
            "backend": "pytesseract",
            "error": "pytesseract or Pillow not installed",
            "text": "",
            "spans": [],
            "span_count": 0,
            "confidence": 0,
            "language": language,
        }
    
    try:
        # Render page to high-DPI image
        pix = page.get_pixmap(dpi=dpi)
        img_bytes = pix.tobytes("png")
        img = Image.open(io.BytesIO(img_bytes))
        
        # Run OCR with detailed output
        ocr_data = pytesseract.image_to_data(
            img, lang=language, output_type=pytesseract.Output.DICT
        )
        
        # Parse results
        ocr_spans = []
        confidences = []
        full_text_parts = []
        
        # Scale factors (OCR coordinates are at render DPI, need to map back to PDF points)
        scale_x = page.rect.width / pix.width
        scale_y = page.rect.height / pix.height
        
        n_boxes = len(ocr_data['text'])
        for i in range(n_boxes):
            text = ocr_data['text'][i].strip()
            conf = int(ocr_data['conf'][i])
            
            if not text or conf < 0:
                continue
            
            # Convert pixel coordinates back to PDF points
            x = ocr_data['left'][i] * scale_x
            y = ocr_data['top'][i] * scale_y
            w = ocr_data['width'][i] * scale_x
            h = ocr_data['height'][i] * scale_y
            
            ocr_spans.append({
                "text": text,
                "bbox": [x, y, x + w, y + h],
                "origin": [x, y + h * 0.8],  # Approximate baseline
                "font_size": h * 0.8,  # Approximate font size from height
                "confidence": conf / 100.0,
            })
            
            confidences.append(conf / 100.0)
            full_text_parts.append(text)
        
        avg_confidence = sum(confidences) / max(len(confidences), 1)
        
        return {
            "success": True,
            "backend": "pytesseract",
            "text": ' '.join(full_text_parts),
            "spans": ocr_spans,
            "span_count": len(ocr_spans),
            "confidence": round(avg_confidence, 3),
            "language": language,
        }
    
    except Exception as e:
        return {
            "success": False,
            "backend": "pytesseract",
            "error": str(e),
            "text": "",
            "spans": [],
            "span_count": 0,
            "confidence": 0,
            "language": language,
        }


def ocr_page(page, language: str = "eng") -> dict:
    """
    OCR a page using the best available backend.
    Tries backends in priority order: pymupdf → pytesseract → fail gracefully.
    """
    deps = check_ocr_dependencies()
    
    if deps["available_backend"] == "pymupdf":
        return ocr_page_pymupdf(page, language)
    elif deps["available_backend"] == "pytesseract":
        return ocr_page_pytesseract(page, language)
    else:
        return {
            "success": False,
            "backend": None,
            "error": "No OCR backend available. Install Tesseract or pytesseract.",
            "text": "",
            "spans": [],
            "span_count": 0,
            "confidence": 0,
            "language": language,
        }


# =============================================================================
# INTEGRATION WITH V8 ENGINE
# =============================================================================

def ocr_for_manifest(page, page_num: int, language: str = "eng") -> list:
    """
    Run OCR on a page and return spans in the same format as
    extract_page_spans() from pdf_translate_v8.py.
    
    This allows seamless integration: if a page is scanned, we OCR it
    and feed the result into the same manifest/rendering pipeline.
    """
    ocr_result = ocr_page(page, language)
    
    if not ocr_result["success"]:
        return []
    
    # Convert OCR spans to V8 span format
    v8_spans = []
    for i, span in enumerate(ocr_result["spans"]):
        v8_spans.append({
            "id": f"p{page_num:02d}_ocr_{i+1:04d}",
            "text": span["text"],
            "text_stripped": span["text"].strip(),
            "origin": span["origin"],
            "bbox": span["bbox"],
            "font_size": span.get("font_size", 12),
            "font_name": "OCR-detected",
            "color": "#000000",
            "is_bold": False,
            "is_italic": False,
            "is_page_number": False,
            "page_number": page_num,
            "rotation_angle": 0.0,
            "rotation_class": "horizontal",
            "is_rotated": False,
            "text_direction": (1.0, 0.0),
            "ocr_confidence": span.get("confidence", 0),
            "ocr_backend": ocr_result["backend"],
        })
    
    return v8_spans


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="OCR Integration — V8 Engine")
    subparsers = parser.add_subparsers(dest="command")
    
    # Check dependencies
    subparsers.add_parser("check-deps", help="Check OCR backend availability")
    
    # Detect scanned pages
    detect_p = subparsers.add_parser("detect", help="Detect scanned pages in a PDF")
    detect_p.add_argument("--input", "-i", required=True)
    detect_p.add_argument("--page", "-p", type=int, help="Check specific page")
    
    # Extract text via OCR
    extract_p = subparsers.add_parser("extract", help="Extract text via OCR")
    extract_p.add_argument("--input", "-i", required=True)
    extract_p.add_argument("--page", "-p", type=int, required=True)
    extract_p.add_argument("--language", "-l", default="eng")
    extract_p.add_argument("--output", "-o", help="Save result to JSON file")
    
    args = parser.parse_args()
    
    if args.command == "check-deps":
        deps = check_ocr_dependencies()
        print("OCR Backend Status:")
        print(f"  PyMuPDF OCR: {'Available' if deps['pymupdf_ocr'] else 'Not available'}")
        if deps['tessdata_path']:
            print(f"    Tessdata: {deps['tessdata_path']}")
        print(f"  pytesseract: {'Available' if deps['pytesseract'] else 'Not available'}")
        if deps['tesseract_path']:
            print(f"    Binary: {deps['tesseract_path']}")
        print(f"\n  Active backend: {deps['available_backend'] or 'NONE'}")
        if not deps['available_backend']:
            print("\n  To enable OCR:")
            print("    Option 1: Install Tesseract and set TESSDATA_PREFIX")
            print("    Option 2: pip install pytesseract Pillow")
    
    elif args.command == "detect":
        if args.page:
            doc = pymupdf.open(args.input)
            page = doc[args.page - 1]
            result = is_scanned_page(page)
            result["page_number"] = args.page
            doc.close()
            
            print(f"Page {args.page}:")
            print(f"  Scanned: {result['is_scanned']}")
            print(f"  Confidence: {result['confidence']:.0%}")
            print(f"  Reason: {result['reason']}")
            print(f"  Text spans: {result['text_span_count']}")
            print(f"  Images: {result['image_count']}")
            print(f"  Image coverage: {result['largest_image_coverage']:.0%}")
        else:
            result = detect_scanned_pages(args.input)
            print(f"File: {result['file']}")
            print(f"Total pages: {result['total_pages']}")
            print(f"Scanned pages: {result['scanned_pages'] or 'None'}")
            print(f"Born-digital pages: {len(result['born_digital_pages'])}")
            
            if result['scanned_pages']:
                print("\nScanned page details:")
                for pg in result['page_details']:
                    if pg['is_scanned']:
                        print(f"  Page {pg['page_number']}: "
                              f"{pg['confidence']:.0%} confidence — {pg['reason']}")
    
    elif args.command == "extract":
        doc = pymupdf.open(args.input)
        page = doc[args.page - 1]
        
        result = ocr_page(page, args.language)
        doc.close()
        
        if result["success"]:
            print(f"OCR successful (backend: {result['backend']})")
            print(f"  Spans extracted: {result['span_count']}")
            print(f"  Confidence: {result['confidence']:.0%}")
            print(f"  Language: {result['language']}")
            print(f"\nExtracted text:")
            print(f"  {result['text'][:500]}{'...' if len(result['text']) > 500 else ''}")
            
            if args.output:
                with open(args.output, 'w', encoding='utf-8') as f:
                    json.dump(result, f, indent=2, ensure_ascii=False)
                print(f"\nSaved to: {args.output}")
        else:
            print(f"OCR failed: {result['error']}")
            print(f"  Backend attempted: {result['backend'] or 'none available'}")
    
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
