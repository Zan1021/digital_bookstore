"""
RTL/Complex Script Detection — Digital Bookstore V8
=====================================================
Detects Arabic, Hebrew, Indic, and other complex scripts in text content.

Per the brief:
  - "RTL/complex script detection — Arabic, Indic scripts, bidirectional text"

This matters for:
  - Text direction (RTL languages need right-to-left rendering)
  - Bidirectional text (mixed LTR/RTL in same paragraph)
  - Complex shaping (Indic scripts need proper conjunct formation)
  - Font selection (not all fonts support all scripts)
  - Column layout direction (RTL books read right-to-left page order)

Detection is based on Unicode script blocks:
  - Arabic: U+0600-U+06FF, U+0750-U+077F, U+08A0-U+08FF
  - Hebrew: U+0590-U+05FF
  - Devanagari: U+0900-U+097F
  - Bengali: U+0980-U+09FF
  - Tamil: U+0B80-U+0BFF
  - Thai: U+0E00-U+0E7F
  - CJK: U+4E00-U+9FFF, U+3000-U+303F

Usage:
    python script_detection.py detect --text "Some text with Arabic عربي"
    python script_detection.py analyze --input book.pdf
"""

import argparse
import json
import os
import sys
import unicodedata
from collections import Counter
from typing import Optional

import pymupdf


# =============================================================================
# SCRIPT RANGES
# =============================================================================

SCRIPT_RANGES = {
    "Arabic": [
        (0x0600, 0x06FF),   # Arabic
        (0x0750, 0x077F),   # Arabic Supplement
        (0x08A0, 0x08FF),   # Arabic Extended-A
        (0xFB50, 0xFDFF),   # Arabic Presentation Forms-A
        (0xFE70, 0xFEFF),   # Arabic Presentation Forms-B
    ],
    "Hebrew": [
        (0x0590, 0x05FF),   # Hebrew
        (0xFB1D, 0xFB4F),   # Hebrew Presentation Forms
    ],
    "Devanagari": [
        (0x0900, 0x097F),   # Devanagari
        (0xA8E0, 0xA8FF),   # Devanagari Extended
    ],
    "Bengali": [(0x0980, 0x09FF)],
    "Gurmukhi": [(0x0A00, 0x0A7F)],
    "Gujarati": [(0x0A80, 0x0AFF)],
    "Tamil": [(0x0B80, 0x0BFF)],
    "Telugu": [(0x0C00, 0x0C7F)],
    "Kannada": [(0x0C80, 0x0CFF)],
    "Malayalam": [(0x0D00, 0x0D7F)],
    "Thai": [(0x0E00, 0x0E7F)],
    "Lao": [(0x0E80, 0x0EFF)],
    "Tibetan": [(0x0F00, 0x0FFF)],
    "Myanmar": [(0x1000, 0x109F)],
    "Georgian": [(0x10A0, 0x10FF)],
    "Korean": [
        (0x1100, 0x11FF),   # Hangul Jamo
        (0xAC00, 0xD7AF),   # Hangul Syllables
    ],
    "CJK": [
        (0x4E00, 0x9FFF),   # CJK Unified Ideographs
        (0x3000, 0x303F),   # CJK Symbols
        (0x3040, 0x309F),   # Hiragana
        (0x30A0, 0x30FF),   # Katakana
    ],
    "Cyrillic": [(0x0400, 0x04FF), (0x0500, 0x052F)],
    "Greek": [(0x0370, 0x03FF)],
    "Latin": [(0x0000, 0x024F)],  # Basic + Extended
}

# Scripts that are RTL
RTL_SCRIPTS = {"Arabic", "Hebrew"}

# Scripts that need complex shaping
COMPLEX_SCRIPTS = {"Arabic", "Hebrew", "Devanagari", "Bengali", "Gurmukhi",
                   "Gujarati", "Tamil", "Telugu", "Kannada", "Malayalam",
                   "Thai", "Lao", "Tibetan", "Myanmar"}


# =============================================================================
# DETECTION FUNCTIONS
# =============================================================================

def detect_script_for_char(char: str) -> str:
    """Detect the script of a single character."""
    cp = ord(char)
    
    for script_name, ranges in SCRIPT_RANGES.items():
        for start, end in ranges:
            if start <= cp <= end:
                return script_name
    
    # Fallback to unicodedata
    try:
        script = unicodedata.category(char)
        if script.startswith('L'):
            return "Latin"  # Default for unrecognized letters
    except Exception:
        pass
    
    return "Common"  # Punctuation, digits, symbols


def detect_scripts_in_text(text: str) -> dict:
    """
    Analyze text and detect all scripts present.
    
    Returns:
    {
        "scripts_found": {"Latin": count, "Arabic": count, ...},
        "dominant_script": str,
        "is_rtl": bool,
        "is_bidi": bool (mixed RTL+LTR),
        "is_complex": bool (needs complex shaping),
        "direction": "ltr" | "rtl" | "mixed",
        "script_percentages": {"Latin": 80.5, ...},
    }
    """
    if not text:
        return {
            "scripts_found": {},
            "dominant_script": "Latin",
            "is_rtl": False,
            "is_bidi": False,
            "is_complex": False,
            "direction": "ltr",
            "script_percentages": {},
        }
    
    # Count characters per script (skip whitespace/punctuation)
    script_counts = Counter()
    total_letters = 0
    
    for char in text:
        if char.isspace() or unicodedata.category(char).startswith('P'):
            continue
        script = detect_script_for_char(char)
        if script != "Common":
            script_counts[script] += 1
            total_letters += 1
    
    if total_letters == 0:
        return {
            "scripts_found": {},
            "dominant_script": "Latin",
            "is_rtl": False,
            "is_bidi": False,
            "is_complex": False,
            "direction": "ltr",
            "script_percentages": {},
        }
    
    # Determine dominant script
    dominant = script_counts.most_common(1)[0][0] if script_counts else "Latin"
    
    # Check RTL
    rtl_count = sum(script_counts.get(s, 0) for s in RTL_SCRIPTS)
    ltr_count = total_letters - rtl_count
    
    is_rtl = rtl_count > ltr_count
    is_bidi = rtl_count > 0 and ltr_count > 0 and min(rtl_count, ltr_count) > total_letters * 0.1
    
    # Check complex shaping needs
    complex_count = sum(script_counts.get(s, 0) for s in COMPLEX_SCRIPTS)
    is_complex = complex_count > 0
    
    # Direction
    if is_bidi:
        direction = "mixed"
    elif is_rtl:
        direction = "rtl"
    else:
        direction = "ltr"
    
    # Percentages
    percentages = {
        script: round(count / total_letters * 100, 1)
        for script, count in script_counts.most_common()
    }
    
    return {
        "scripts_found": dict(script_counts),
        "dominant_script": dominant,
        "is_rtl": is_rtl,
        "is_bidi": is_bidi,
        "is_complex": is_complex,
        "direction": direction,
        "script_percentages": percentages,
        "total_letters": total_letters,
    }


def detect_scripts_in_pdf(pdf_path: str) -> dict:
    """
    Analyze an entire PDF for script usage.
    
    Returns document-level script analysis plus per-page breakdown.
    """
    doc = pymupdf.open(pdf_path)
    
    all_text = ""
    page_results = []
    
    for i in range(len(doc)):
        page = doc[i]
        page_text = page.get_text("text")
        all_text += page_text
        
        page_analysis = detect_scripts_in_text(page_text)
        page_analysis["page_number"] = i + 1
        page_results.append(page_analysis)
    
    doc.close()
    
    # Document-level analysis
    doc_analysis = detect_scripts_in_text(all_text)
    
    return {
        "file": pdf_path,
        "document_level": doc_analysis,
        "pages": page_results,
        "rendering_requirements": {
            "needs_rtl_support": doc_analysis["is_rtl"],
            "needs_bidi_support": doc_analysis["is_bidi"],
            "needs_complex_shaping": doc_analysis["is_complex"],
            "text_direction": doc_analysis["direction"],
            "dominant_script": doc_analysis["dominant_script"],
        },
    }


def get_rendering_requirements(text: str) -> dict:
    """
    Quick check: what special rendering does this text need?
    Used by V8 engine before rendering a translation.
    """
    analysis = detect_scripts_in_text(text)
    
    return {
        "direction": analysis["direction"],
        "needs_harfbuzz": analysis["is_complex"],
        "needs_bidi_reorder": analysis["is_bidi"],
        "script": analysis["dominant_script"],
        "font_requirements": _get_font_requirements(analysis["dominant_script"]),
    }


def _get_font_requirements(script: str) -> dict:
    """Get font requirements for a script."""
    if script in ("Arabic", "Hebrew"):
        return {
            "needs_rtl_glyphs": True,
            "suggested_fonts": ["Noto Sans Arabic", "Noto Sans Hebrew", "Arial"],
            "opentype_features": ["liga", "calt", "kern", "mark", "mkmk"],
        }
    elif script in COMPLEX_SCRIPTS:
        return {
            "needs_rtl_glyphs": False,
            "suggested_fonts": [f"Noto Sans {script}", "Arial Unicode MS"],
            "opentype_features": ["liga", "calt", "kern", "mark", "mkmk", "half", "pres", "blws"],
        }
    elif script == "CJK":
        return {
            "needs_rtl_glyphs": False,
            "suggested_fonts": ["Noto Sans CJK", "Source Han Sans"],
            "opentype_features": ["kern", "vert"],
        }
    else:
        return {
            "needs_rtl_glyphs": False,
            "suggested_fonts": ["PlaypenSans-Regular"],
            "opentype_features": ["kern", "liga"],
        }


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="Script Detection — V8")
    subparsers = parser.add_subparsers(dest="command")
    
    # Detect in text
    detect_p = subparsers.add_parser("detect", help="Detect scripts in text")
    detect_p.add_argument("--text", "-t", required=True)
    
    # Analyze PDF
    analyze_p = subparsers.add_parser("analyze", help="Analyze PDF scripts")
    analyze_p.add_argument("--input", "-i", required=True)
    
    args = parser.parse_args()
    
    if args.command == "detect":
        result = detect_scripts_in_text(args.text)
        
        print(f"Text: \"{args.text[:60]}{'...' if len(args.text) > 60 else ''}\"")
        print(f"Dominant script: {result['dominant_script']}")
        print(f"Direction: {result['direction']}")
        print(f"RTL: {result['is_rtl']}")
        print(f"Bidirectional: {result['is_bidi']}")
        print(f"Complex shaping: {result['is_complex']}")
        if result['script_percentages']:
            print(f"Scripts: {result['script_percentages']}")
    
    elif args.command == "analyze":
        result = detect_scripts_in_pdf(args.input)
        
        doc = result['document_level']
        print(f"Document: {result['file']}")
        print(f"Dominant script: {doc['dominant_script']}")
        print(f"Direction: {doc['direction']}")
        print(f"Scripts: {doc['script_percentages']}")
        
        req = result['rendering_requirements']
        print(f"\nRendering requirements:")
        print(f"  RTL support: {req['needs_rtl_support']}")
        print(f"  BiDi support: {req['needs_bidi_support']}")
        print(f"  Complex shaping: {req['needs_complex_shaping']}")
    
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
