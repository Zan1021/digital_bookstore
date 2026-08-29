"""
Tagged PDF / Accessibility — Digital Bookstore V8
===================================================
Preserve and enhance PDF accessibility features.

Per the brief:
  - "Tagged PDF / accessibility — preserve reading order, language metadata, alt text"

Children's books NEED accessibility:
  - Screen readers for visually impaired children
  - Proper reading order so assistive tech reads in correct sequence
  - Language metadata so TTS uses correct pronunciation
  - Alt text for illustrations

This module:
  1. Detects existing structure tags in source PDFs
  2. Preserves reading order during translation
  3. Adds/updates language metadata for target language
  4. Generates alt text placeholders for review

Usage:
    python accessibility.py check --input book.pdf
    python accessibility.py set-language --input book.pdf --output book.pdf --language af
"""

import argparse
import json
import os
import sys
from typing import Optional

import pymupdf


# =============================================================================
# LANGUAGE CODES (ISO 639-1 to PDF language tags)
# =============================================================================

LANGUAGE_MAP = {
    "af": "af-ZA",      # Afrikaans
    "en": "en-ZA",      # English (South Africa)
    "zu": "zu-ZA",      # Zulu
    "xh": "xh-ZA",      # Xhosa
    "st": "st-ZA",      # Sesotho
    "tn": "tn-ZA",      # Setswana
    "ts": "ts-ZA",      # Tsonga
    "ss": "ss-ZA",      # Siswati
    "ve": "ve-ZA",      # Tshivenda
    "nr": "nr-ZA",      # isiNdebele
    "nso": "nso-ZA",    # Sepedi
    "fr": "fr-FR",      # French
    "pt": "pt-PT",      # Portuguese
    "es": "es-ES",      # Spanish
    "de": "de-DE",      # German
    "ar": "ar-SA",      # Arabic
}


# =============================================================================
# STRUCTURE TAG DETECTION
# =============================================================================

def check_pdf_accessibility(pdf_path: str) -> dict:
    """
    Check a PDF's accessibility features.
    
    Returns:
    {
        "has_structure_tree": bool,
        "has_language": bool,
        "language": str or None,
        "has_marked_content": bool,
        "tag_count": int,
        "reading_order_defined": bool,
        "accessibility_score": float (0-1),
        "recommendations": [str]
    }
    """
    doc = pymupdf.open(pdf_path)
    
    result = {
        "has_structure_tree": False,
        "has_language": False,
        "language": None,
        "has_marked_content": False,
        "tag_count": 0,
        "reading_order_defined": False,
        "accessibility_score": 0.0,
        "recommendations": [],
    }
    
    # Check for structure tree (tagged PDF)
    try:
        # Check catalog for MarkInfo and StructTreeRoot
        catalog = doc.pdf_catalog()
        
        # Try to read the catalog object
        cat_str = doc.xref_object(catalog)
        
        if '/StructTreeRoot' in cat_str:
            result["has_structure_tree"] = True
        
        if '/MarkInfo' in cat_str:
            result["has_marked_content"] = True
        
        if '/Lang' in cat_str:
            result["has_language"] = True
            # Extract language value
            import re
            lang_match = re.search(r'/Lang\s*\(([^)]+)\)', cat_str)
            if lang_match:
                result["language"] = lang_match.group(1)
    except Exception:
        pass
    
    # Check metadata for language
    metadata = doc.metadata
    if metadata:
        # PDF metadata doesn't always have language, but let's check
        if not result["language"]:
            # Sometimes stored in XMP
            pass
    
    # Calculate accessibility score
    score = 0.0
    if result["has_structure_tree"]:
        score += 0.4
    if result["has_language"]:
        score += 0.2
    if result["has_marked_content"]:
        score += 0.2
    
    # Check if text is selectable (basic accessibility)
    page = doc[0]
    text = page.get_text("text").strip()
    if text:
        score += 0.2  # Text is at least selectable
    
    result["accessibility_score"] = round(score, 2)
    
    # Recommendations
    if not result["has_structure_tree"]:
        result["recommendations"].append("Add structure tags (headings, paragraphs, figures)")
    if not result["has_language"]:
        result["recommendations"].append("Set document language metadata")
    if not result["has_marked_content"]:
        result["recommendations"].append("Add marked content sequences for reading order")
    if score >= 0.8:
        result["recommendations"].append("Good accessibility! Consider adding alt text for images.")
    
    doc.close()
    return result


# =============================================================================
# LANGUAGE METADATA
# =============================================================================

def set_document_language(pdf_path: str, output_path: str, language_code: str) -> dict:
    """
    Set or update the document language in a PDF.
    
    The language tag is set in the document catalog's /Lang entry.
    This tells screen readers which language to use for pronunciation.
    """
    # Map short code to full BCP-47 tag
    lang_tag = LANGUAGE_MAP.get(language_code, language_code)
    
    try:
        doc = pymupdf.open(pdf_path)
        
        # Set language in catalog
        catalog_xref = doc.pdf_catalog()
        
        # Update the catalog with language
        doc.xref_set_key(catalog_xref, "Lang", f"({lang_tag})")
        
        # Save
        doc.save(output_path, garbage=4, deflate=True)
        doc.close()
        
        return {
            "success": True,
            "language_set": lang_tag,
            "output": output_path,
        }
    except Exception as e:
        return {
            "success": False,
            "error": str(e),
        }


# =============================================================================
# READING ORDER
# =============================================================================

def extract_reading_order(page) -> list:
    """
    Extract the reading order of text elements on a page.
    
    Returns text elements in the order a screen reader should read them:
    - Top to bottom
    - Left to right (or right to left for RTL)
    - Grouped by logical regions (heading first, then body)
    """
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
    
    elements = []
    for block in text_dict.get("blocks", []):
        if block.get("type") != 0:
            continue
        
        block_text = ""
        block_bbox = list(block.get("bbox", [0, 0, 0, 0]))
        
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                text = span["text"].strip()
                if text:
                    block_text += text + " "
        
        if block_text.strip():
            elements.append({
                "text": block_text.strip(),
                "bbox": block_bbox,
                "type": "heading" if any(s["size"] > 20 for line in block.get("lines", []) for s in line.get("spans", [])) else "body",
            })
    
    # Sort: headings first within their Y position, then body text
    elements.sort(key=lambda e: (
        0 if e["type"] == "heading" else 1,
        e["bbox"][1],  # Y position (top to bottom)
        e["bbox"][0],  # X position (left to right)
    ))
    
    return elements


# =============================================================================
# ALT TEXT GENERATION (placeholder)
# =============================================================================

def generate_alt_text_placeholders(pdf_path: str) -> dict:
    """
    Generate alt text placeholders for images in a PDF.
    
    In a real implementation, this would use AI vision to describe images.
    For now, creates descriptive placeholders that a human reviewer can edit.
    """
    doc = pymupdf.open(pdf_path)
    
    placeholders = []
    
    for page_idx in range(len(doc)):
        page = doc[page_idx]
        images = page.get_images()
        
        for img_info in images:
            xref = img_info[0]
            width = img_info[2]
            height = img_info[3]
            
            # Determine image role based on size and position
            page_area = page.rect.width * page.rect.height
            try:
                rects = page.get_image_rects(xref)
                if rects:
                    rect = rects[0]
                    img_area = rect.width * rect.height
                    coverage = img_area / max(page_area, 1)
                    
                    if coverage > 0.5:
                        role = "illustration"
                        placeholder = f"[Illustration on page {page_idx + 1}: Description needed]"
                    elif coverage > 0.1:
                        role = "figure"
                        placeholder = f"[Figure on page {page_idx + 1}: Description needed]"
                    else:
                        role = "icon"
                        placeholder = f"[Icon/logo on page {page_idx + 1}]"
                else:
                    role = "unknown"
                    placeholder = f"[Image on page {page_idx + 1}]"
            except Exception:
                role = "unknown"
                placeholder = f"[Image on page {page_idx + 1}]"
            
            placeholders.append({
                "page": page_idx + 1,
                "xref": xref,
                "dimensions": f"{width}x{height}",
                "role": role,
                "alt_text": placeholder,
                "needs_review": True,
            })
    
    doc.close()
    
    return {
        "total_images": len(placeholders),
        "needs_alt_text": sum(1 for p in placeholders if p["needs_review"]),
        "placeholders": placeholders,
    }


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="Accessibility — V8")
    subparsers = parser.add_subparsers(dest="command")
    
    # Check
    check_p = subparsers.add_parser("check", help="Check PDF accessibility")
    check_p.add_argument("--input", "-i", required=True)
    
    # Set language
    lang_p = subparsers.add_parser("set-language", help="Set document language")
    lang_p.add_argument("--input", "-i", required=True)
    lang_p.add_argument("--output", "-o", required=True)
    lang_p.add_argument("--language", "-l", required=True, help="Language code (af, en, zu, etc.)")
    
    # Alt text
    alt_p = subparsers.add_parser("alt-text", help="Generate alt text placeholders")
    alt_p.add_argument("--input", "-i", required=True)
    
    args = parser.parse_args()
    
    if args.command == "check":
        result = check_pdf_accessibility(args.input)
        
        print(f"Accessibility Check:")
        print(f"  Structure tree: {'Yes' if result['has_structure_tree'] else 'No'}")
        print(f"  Language set: {'Yes' if result['has_language'] else 'No'} ({result['language'] or 'none'})")
        print(f"  Marked content: {'Yes' if result['has_marked_content'] else 'No'}")
        print(f"  Score: {result['accessibility_score']:.0%}")
        
        if result['recommendations']:
            print(f"\n  Recommendations:")
            for r in result['recommendations']:
                print(f"    - {r}")
    
    elif args.command == "set-language":
        result = set_document_language(args.input, args.output, args.language)
        if result['success']:
            print(f"Language set to: {result['language_set']}")
            print(f"Output: {result['output']}")
        else:
            print(f"Failed: {result['error']}")
    
    elif args.command == "alt-text":
        result = generate_alt_text_placeholders(args.input)
        print(f"Alt Text Placeholders:")
        print(f"  Total images: {result['total_images']}")
        print(f"  Needs alt text: {result['needs_alt_text']}")
        for p in result['placeholders'][:10]:
            print(f"    Page {p['page']}: [{p['role']}] {p['dimensions']} — {p['alt_text']}")
    
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
