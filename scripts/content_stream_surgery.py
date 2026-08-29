"""
Content-Stream Surgery — Digital Bookstore V8
===============================================
Removes text-showing operators directly from PDF content streams.
This is the cleanest possible text removal — it never damages
vector drawings, borders, images, or any non-text content.

PDF text-showing operators:
  Tj  — Show a text string
  TJ  — Show text with individual glyph positioning
  '   — Move to next line and show text
  "   — Set spacing, move to next line, show text

Strategy:
  1. Parse the page's content stream into tokens
  2. Track graphics state (to know text matrices, positions)
  3. Identify text-showing operators that correspond to target spans
  4. Neutralize those operators (replace with empty string or remove)
  5. Leave ALL other operators untouched (lines, fills, images, etc.)

Usage:
    python content_stream_surgery.py remove --input book.pdf --output cleaned.pdf --page 15 --spans spans.json
    python content_stream_surgery.py analyze --input book.pdf --page 15
"""

import argparse
import json
import os
import re
import sys
from typing import Optional

import pymupdf


# =============================================================================
# CONTENT STREAM ANALYSIS
# =============================================================================

def analyze_page_content_stream(page) -> dict:
    """
    Analyze a page's content stream to identify text-showing operators
    and their relationship to extracted text spans.
    
    Returns structured analysis of all text operations on the page.
    """
    # Get the raw content stream bytes
    xref = page.xref
    doc = page.parent
    
    # Get all content stream xrefs for the page
    contents = page.get_contents()
    
    analysis = {
        "page_number": page.number + 1,
        "content_streams": len(contents),
        "text_operations": [],
        "total_operators": 0,
        "text_operators_count": 0,
    }
    
    for stream_xref in contents:
        stream_bytes = doc.xref_stream(stream_xref)
        if not stream_bytes:
            continue
            
        stream_text = stream_bytes.decode('latin-1', errors='replace')
        
        # Find text-showing operators
        # Tj: (string) Tj
        # TJ: [array] TJ
        # ': (string) '
        # ": num num (string) "
        
        # Count all operators
        analysis["total_operators"] += len(re.findall(r'\b[A-Za-z\*\'\"]+\b', stream_text))
        
        # Find Tj operations
        tj_matches = re.finditer(r'\(([^)]*)\)\s*Tj', stream_text)
        for m in tj_matches:
            analysis["text_operations"].append({
                "operator": "Tj",
                "text": m.group(1),
                "position": m.start(),
                "length": len(m.group(0)),
                "stream_xref": stream_xref,
            })
            analysis["text_operators_count"] += 1
        
        # Find TJ operations (array form)
        tj_array_matches = re.finditer(r'\[(.*?)\]\s*TJ', stream_text, re.DOTALL)
        for m in tj_array_matches:
            # Extract text from array elements
            array_content = m.group(1)
            text_parts = re.findall(r'\(([^)]*)\)', array_content)
            combined_text = ''.join(text_parts)
            
            analysis["text_operations"].append({
                "operator": "TJ",
                "text": combined_text,
                "raw_array": array_content[:100],  # truncated for readability
                "position": m.start(),
                "length": len(m.group(0)),
                "stream_xref": stream_xref,
            })
            analysis["text_operators_count"] += 1
    
    return analysis


# =============================================================================
# TEXT REMOVAL BY CONTENT STREAM EDITING
# =============================================================================

def remove_text_from_stream(
    doc,
    page,
    target_texts: list,
    preserve_structure: bool = True,
) -> dict:
    """
    Remove specific text strings from a page's content stream.
    
    This edits the raw PDF content stream bytes, replacing text-showing
    operators that match target texts with empty operations.
    
    Args:
        doc: PyMuPDF document
        page: Page object
        target_texts: List of text strings to remove (stripped versions)
        preserve_structure: If True, replaces with empty Tj. If False, removes entirely.
    
    Returns:
        {"removed": int, "preserved": int, "errors": []}
    """
    result = {"removed": 0, "preserved": 0, "errors": [], "modified_streams": []}
    
    # Normalize target texts for matching
    targets = set(t.strip().lower() for t in target_texts if t.strip())
    
    if not targets:
        return result
    
    contents = page.get_contents()
    
    for stream_xref in contents:
        stream_bytes = doc.xref_stream(stream_xref)
        if not stream_bytes:
            continue
        
        stream_text = stream_bytes.decode('latin-1', errors='replace')
        modified = False
        new_stream = stream_text
        
        # Process TJ arrays (most common in complex PDFs)
        def replace_tj_array(match):
            nonlocal modified
            array_content = match.group(1)
            
            # Extract text from this TJ array
            text_parts = re.findall(r'\(([^)]*)\)', array_content)
            combined_text = ''.join(text_parts).strip().lower()
            
            # Check if this text matches any target
            if combined_text in targets or any(combined_text.startswith(t) or t.startswith(combined_text) for t in targets if len(t) > 2):
                modified = True
                result["removed"] += 1
                if preserve_structure:
                    # Replace with empty TJ to maintain stream structure
                    return '[() ] TJ'
                else:
                    return ''
            
            result["preserved"] += 1
            return match.group(0)
        
        new_stream = re.sub(r'\[(.*?)\]\s*TJ', replace_tj_array, new_stream, flags=re.DOTALL)
        
        # Process simple Tj operations
        def replace_tj(match):
            nonlocal modified
            text = match.group(1).strip().lower()
            
            if text in targets or any(text.startswith(t) or t.startswith(text) for t in targets if len(t) > 2):
                modified = True
                result["removed"] += 1
                if preserve_structure:
                    return '() Tj'
                else:
                    return ''
            
            result["preserved"] += 1
            return match.group(0)
        
        new_stream = re.sub(r'\(([^)]*)\)\s*Tj', replace_tj, new_stream)
        
        # If we modified this stream, write it back
        if modified:
            new_bytes = new_stream.encode('latin-1', errors='replace')
            doc.update_stream(stream_xref, new_bytes)
            result["modified_streams"].append(stream_xref)
    
    return result


# =============================================================================
# HIGH-LEVEL: REMOVE SPANS BY COORDINATES
# =============================================================================

def remove_spans_by_surgery(
    doc,
    page,
    spans_to_remove: list,
) -> dict:
    """
    Remove text spans from a page using content-stream surgery.
    
    Falls back to redaction if surgery doesn't find the text in the stream
    (can happen with complex encoding or Form XObjects).
    
    Args:
        doc: PyMuPDF document
        page: Page object
        spans_to_remove: List of span dicts with 'text_stripped' key
    
    Returns:
        {"surgery_removed": int, "redaction_fallback": int, "errors": []}
    """
    result = {
        "surgery_removed": 0,
        "redaction_fallback": 0,
        "total_spans": len(spans_to_remove),
        "errors": [],
    }
    
    # Extract texts to target
    target_texts = [s["text_stripped"] for s in spans_to_remove if s.get("text_stripped")]
    
    if not target_texts:
        return result
    
    # Attempt content-stream surgery
    surgery_result = remove_text_from_stream(doc, page, target_texts)
    result["surgery_removed"] = surgery_result["removed"]
    
    # Check which texts were NOT removed by surgery (need redaction fallback)
    # We can't easily verify per-span, so if surgery removed fewer than expected,
    # fall back to redaction for the remaining
    if surgery_result["removed"] < len(target_texts):
        # Some texts weren't found in the stream — use redaction as fallback
        remaining_count = len(target_texts) - surgery_result["removed"]
        
        # Apply redaction to all spans (safe to do even if some were already removed)
        # PyMuPDF redaction is idempotent for already-removed text
        for span in spans_to_remove:
            bbox = span.get("bbox")
            if bbox:
                rect = pymupdf.Rect(bbox[0] - 1, bbox[1] - 1, bbox[2] + 1, bbox[3] + 1)
                page.add_redact_annot(rect, text="", fill=(1, 1, 1))
        
        page.apply_redactions()
        result["redaction_fallback"] = remaining_count
    
    return result


# =============================================================================
# FULL PAGE SURGERY (remove all text, keep everything else)
# =============================================================================

def clean_page_text(doc, page, keep_page_numbers: bool = True) -> dict:
    """
    Remove ALL text from a page via content-stream surgery.
    Optionally keeps page numbers (small digits near bottom).
    
    This is the nuclear option — removes every text-showing operator.
    Use for pages where you want to completely re-render all text.
    """
    # Get all text on the page
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
    
    all_texts = []
    for block in text_dict.get("blocks", []):
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                text = span["text"].strip()
                if not text:
                    continue
                
                # Skip page numbers if requested
                if keep_page_numbers:
                    if text.isdigit() and len(text) <= 3 and span["size"] < 20 and span["bbox"][1] > 600:
                        continue
                
                all_texts.append(text)
    
    if not all_texts:
        return {"removed": 0, "total": 0}
    
    # Remove all text via surgery
    surgery_result = remove_text_from_stream(doc, page, all_texts)
    
    return {
        "removed": surgery_result["removed"],
        "total": len(all_texts),
        "preserved_non_text": surgery_result["preserved"],
    }


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="Content-Stream Surgery for PDF Text Removal")
    subparsers = parser.add_subparsers(dest="command")

    # Analyze command
    analyze_p = subparsers.add_parser("analyze", help="Analyze content stream")
    analyze_p.add_argument("--input", "-i", required=True)
    analyze_p.add_argument("--page", "-p", type=int, required=True)

    # Remove command
    remove_p = subparsers.add_parser("remove", help="Remove text via surgery")
    remove_p.add_argument("--input", "-i", required=True)
    remove_p.add_argument("--output", "-o", required=True)
    remove_p.add_argument("--page", "-p", type=int, required=True)
    remove_p.add_argument("--texts", "-t", required=True, help="JSON array of texts to remove")

    args = parser.parse_args()

    if args.command == "analyze":
        doc = pymupdf.open(args.input)
        page = doc[args.page - 1]
        analysis = analyze_page_content_stream(page)
        doc.close()
        print(json.dumps(analysis, indent=2, ensure_ascii=False))

    elif args.command == "remove":
        doc = pymupdf.open(args.input)
        page = doc[args.page - 1]

        with open(args.texts, 'r', encoding='utf-8') as f:
            texts = json.load(f)

        result = remove_text_from_stream(doc, page, texts)
        doc.save(args.output, garbage=4, deflate=True)
        doc.close()

        print(json.dumps(result, indent=2))
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
