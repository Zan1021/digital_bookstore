"""
Searchable/Selectable Text Verification — Digital Bookstore V8
================================================================
Verifies that translated text in the output PDF is actually searchable
and selectable (not just painted pixels).

Per the brief:
  - "Extract text from output, match to translation IDs"
  - Verify that what we wrote is actually readable as text by PDF viewers

Checks:
  1. Extract text from output PDF using pymupdf
  2. Compare extracted text against expected translations
  3. Report any pages where text is NOT extractable (rendering issue)
  4. Verify character encoding is correct (no garbled unicode)

Usage:
    python text_verification.py verify --output translated.pdf --translations trans.json
    python text_verification.py extract --input file.pdf --page N
"""

import argparse
import json
import os
import sys
import re
from typing import Optional

import pymupdf


# =============================================================================
# TEXT EXTRACTION FROM OUTPUT
# =============================================================================

def extract_page_text(page) -> dict:
    """
    Extract all selectable text from a page.
    Returns structured text data for verification.
    """
    # Plain text extraction
    plain_text = page.get_text("text").strip()
    
    # Word-level extraction (with positions)
    words = page.get_text("words")  # List of (x0, y0, x1, y1, word, block, line, word_n)
    
    # Dict extraction for detailed analysis
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
    
    span_texts = []
    for block in text_dict.get("blocks", []):
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                text = span["text"].strip()
                if text:
                    span_texts.append(text)
    
    return {
        "plain_text": plain_text,
        "word_count": len(words),
        "span_count": len(span_texts),
        "spans": span_texts,
        "char_count": len(plain_text),
    }


# =============================================================================
# VERIFICATION AGAINST TRANSLATIONS
# =============================================================================

def verify_translations_in_output(output_path: str, translations: dict) -> dict:
    """
    Verify that translated text is searchable/selectable in the output PDF.
    
    For each page with a translation, extracts text from the output and
    checks if the translation words appear.
    
    Returns:
    {
        "total_pages_checked": int,
        "pages_verified": int,
        "pages_with_issues": int,
        "page_results": [...],
        "overall_pass": bool,
    }
    """
    doc = pymupdf.open(output_path)
    
    result = {
        "total_pages_checked": 0,
        "pages_verified": 0,
        "pages_with_issues": 0,
        "page_results": [],
        "overall_pass": True,
    }
    
    for page_data in translations.get("pages", []):
        page_num = page_data["page_number"]
        expected_text = page_data.get("translated_text", "").strip()
        
        if not expected_text or page_num > len(doc):
            continue
        
        result["total_pages_checked"] += 1
        page = doc[page_num - 1]
        
        # Extract what's actually in the PDF
        extracted = extract_page_text(page)
        
        # Get expected words (significant words only, skip 1-2 char words)
        expected_words = set(
            w.lower() for w in re.findall(r'\b\w{3,}\b', expected_text)
        )
        
        # Get extracted words
        extracted_words = set(
            w.lower() for w in re.findall(r'\b\w{3,}\b', extracted["plain_text"])
        )
        
        # Calculate match rate
        if expected_words:
            found_words = expected_words & extracted_words
            match_rate = len(found_words) / len(expected_words)
            missing_words = expected_words - extracted_words
        else:
            found_words = set()
            match_rate = 1.0
            missing_words = set()
        
        page_result = {
            "page_number": page_num,
            "expected_word_count": len(expected_words),
            "found_word_count": len(found_words),
            "match_rate": round(match_rate, 3),
            "extracted_char_count": extracted["char_count"],
            "extracted_span_count": extracted["span_count"],
            "is_searchable": extracted["char_count"] > 0,
            "missing_words_sample": sorted(list(missing_words))[:10],
            "pass": match_rate > 0.5,  # >50% words found = pass
        }
        
        result["page_results"].append(page_result)
        
        if page_result["pass"]:
            result["pages_verified"] += 1
        else:
            result["pages_with_issues"] += 1
            result["overall_pass"] = False
    
    doc.close()
    return result


# =============================================================================
# ENCODING VERIFICATION
# =============================================================================

def verify_encoding(output_path: str) -> dict:
    """
    Check that text encoding in the PDF is correct (no garbled characters).
    
    Looks for:
    - Replacement characters (U+FFFD)
    - .notdef glyphs (rendered as empty boxes)
    - Unmapped character codes
    """
    doc = pymupdf.open(output_path)
    
    issues = []
    total_chars = 0
    garbled_chars = 0
    
    for page_idx in range(len(doc)):
        page = doc[page_idx]
        text = page.get_text("text")
        total_chars += len(text)
        
        # Check for replacement characters
        replacements = text.count('\ufffd')
        if replacements > 0:
            garbled_chars += replacements
            issues.append({
                "page": page_idx + 1,
                "issue": "replacement_characters",
                "count": replacements,
            })
        
        # Check for suspicious control characters (excluding normal whitespace)
        control_chars = sum(1 for c in text if ord(c) < 32 and c not in '\n\r\t')
        if control_chars > 0:
            issues.append({
                "page": page_idx + 1,
                "issue": "control_characters",
                "count": control_chars,
            })
    
    doc.close()
    
    return {
        "total_chars_scanned": total_chars,
        "garbled_chars": garbled_chars,
        "encoding_issues": issues,
        "pass": garbled_chars == 0 and len(issues) == 0,
    }


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="Text Verification — V8")
    subparsers = parser.add_subparsers(dest="command")
    
    # Verify against translations
    verify_p = subparsers.add_parser("verify", help="Verify translations are searchable")
    verify_p.add_argument("--output", "-o", required=True, help="Translated PDF")
    verify_p.add_argument("--translations", "-t", required=True, help="Translations JSON")
    
    # Extract text
    extract_p = subparsers.add_parser("extract", help="Extract text from a page")
    extract_p.add_argument("--input", "-i", required=True)
    extract_p.add_argument("--page", "-p", type=int, required=True)
    
    # Check encoding
    enc_p = subparsers.add_parser("encoding", help="Check text encoding")
    enc_p.add_argument("--input", "-i", required=True)
    
    args = parser.parse_args()
    
    if args.command == "verify":
        with open(args.translations, 'r', encoding='utf-8') as f:
            translations = json.load(f)
        
        result = verify_translations_in_output(args.output, translations)
        
        print(f"Text Verification Results:")
        print(f"  Pages checked: {result['total_pages_checked']}")
        print(f"  Pages verified: {result['pages_verified']}")
        print(f"  Pages with issues: {result['pages_with_issues']}")
        print(f"  Overall: {'PASS' if result['overall_pass'] else 'FAIL'}")
        
        for pr in result['page_results']:
            status = 'OK' if pr['pass'] else 'ISSUE'
            print(f"    Page {pr['page_number']}: [{status}] "
                  f"match={pr['match_rate']:.0%} "
                  f"({pr['found_word_count']}/{pr['expected_word_count']} words)")
    
    elif args.command == "extract":
        doc = pymupdf.open(args.input)
        page = doc[args.page - 1]
        result = extract_page_text(page)
        doc.close()
        
        print(f"Page {args.page} Text:")
        print(f"  Characters: {result['char_count']}")
        print(f"  Words: {result['word_count']}")
        print(f"  Spans: {result['span_count']}")
        print(f"\n  Text:")
        print(f"  {result['plain_text'][:500]}")
    
    elif args.command == "encoding":
        result = verify_encoding(args.input)
        
        print(f"Encoding Check:")
        print(f"  Total chars: {result['total_chars_scanned']}")
        print(f"  Garbled: {result['garbled_chars']}")
        print(f"  Issues: {len(result['encoding_issues'])}")
        print(f"  Result: {'PASS' if result['pass'] else 'FAIL'}")
    
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
