"""
HarfBuzz Text Shaping — Digital Bookstore V8
==============================================
Proper glyph shaping for accurate text measurement and rendering.

Per the brief:
  - "HarfBuzz text shaping — proper glyph shaping for measurement/rendering agreement"

Why this matters:
  - pymupdf.Font.text_length() gives approximate widths
  - HarfBuzz performs ACTUAL shaping (ligatures, kerning, GPOS/GSUB)
  - The measured width matches what the PDF renderer will produce
  - Critical for: overflow detection, column fitting, vertical centering

This module provides:
  1. Accurate text width measurement via HarfBuzz
  2. Per-glyph advance widths (for character-level positioning)
  3. Font feature detection (ligatures, kerning support)
  4. Comparison between HarfBuzz and pymupdf measurements

Usage:
    python text_shaping.py measure --text "Hello world" --font ./fonts/PlaypenSans-Regular.ttf --size 24
    python text_shaping.py compare --text "Hello world" --font ./fonts/PlaypenSans-Regular.ttf --size 24
"""

import argparse
import json
import os
import sys
from typing import Optional

try:
    import uharfbuzz as hb
    HAS_HARFBUZZ = True
except ImportError:
    HAS_HARFBUZZ = False

import pymupdf


# =============================================================================
# HARFBUZZ SHAPING
# =============================================================================

class TextShaper:
    """
    HarfBuzz-based text shaper for accurate glyph measurement.
    
    Caches font blobs and faces for performance across multiple measurements.
    """
    
    def __init__(self, font_path: str):
        """Initialize shaper with a font file."""
        if not HAS_HARFBUZZ:
            raise RuntimeError("uharfbuzz not installed. Run: pip install uharfbuzz")
        
        if not os.path.isfile(font_path):
            raise FileNotFoundError(f"Font not found: {font_path}")
        
        self.font_path = font_path
        
        # Load font blob
        with open(font_path, 'rb') as f:
            font_data = f.read()
        
        self._blob = hb.Blob(font_data)
        self._face = hb.Face(self._blob)
        self._font = hb.Font(self._face)
        
        # Get units per EM for scaling
        self._upem = self._face.upem
    
    def shape_text(self, text: str, font_size: float, 
                  language: str = "en", script: str = "Latn",
                  direction: str = "ltr") -> dict:
        """
        Shape text and return detailed glyph information.
        
        Returns:
        {
            "text": str,
            "font_size": float,
            "total_width": float (in points),
            "total_height": float (in points),
            "glyph_count": int,
            "glyphs": [
                {"glyph_id": int, "cluster": int, 
                 "x_advance": float, "y_advance": float,
                 "x_offset": float, "y_offset": float}
            ],
            "features_applied": list
        }
        """
        # Create buffer
        buf = hb.Buffer()
        buf.add_str(text)
        buf.guess_segment_properties()
        
        # Override direction if specified
        if direction == "rtl":
            buf.direction = "RTL"
        elif direction == "ltr":
            buf.direction = "LTR"
        
        # Shape with standard features
        features = {"kern": True, "liga": True}
        hb.shape(self._font, buf, features)
        
        # Extract glyph info
        infos = buf.glyph_infos
        positions = buf.glyph_positions
        
        # Scale factor: HarfBuzz works in font units, we need points
        scale = font_size / self._upem
        
        glyphs = []
        total_width = 0.0
        max_height = font_size  # Approximate
        
        for info, pos in zip(infos, positions):
            x_advance = pos.x_advance * scale
            y_advance = pos.y_advance * scale
            x_offset = pos.x_offset * scale
            y_offset = pos.y_offset * scale
            
            glyphs.append({
                "glyph_id": info.codepoint,
                "cluster": info.cluster,
                "x_advance": round(x_advance, 3),
                "y_advance": round(y_advance, 3),
                "x_offset": round(x_offset, 3),
                "y_offset": round(y_offset, 3),
            })
            
            total_width += x_advance
        
        return {
            "text": text,
            "font_size": font_size,
            "total_width": round(total_width, 3),
            "total_height": round(max_height, 3),
            "glyph_count": len(glyphs),
            "char_count": len(text),
            "glyphs": glyphs,
        }
    
    def measure_width(self, text: str, font_size: float) -> float:
        """
        Quick measurement: get just the width in points.
        This is the primary function for overflow detection.
        """
        result = self.shape_text(text, font_size)
        return result["total_width"]
    
    def will_overflow(self, text: str, font_size: float, 
                     available_width: float) -> dict:
        """
        Check if text will overflow a given width.
        
        Returns:
        {
            "overflows": bool,
            "text_width": float,
            "available_width": float,
            "overflow_amount": float (positive = overflows by this much),
            "suggested_font_size": float (to fit within available_width)
        }
        """
        text_width = self.measure_width(text, font_size)
        overflow_amount = text_width - available_width
        
        suggested_size = font_size
        if overflow_amount > 0:
            # Calculate the font size that would fit
            ratio = available_width / text_width
            suggested_size = font_size * ratio
        
        return {
            "overflows": overflow_amount > 0,
            "text_width": round(text_width, 2),
            "available_width": round(available_width, 2),
            "overflow_amount": round(overflow_amount, 2),
            "suggested_font_size": round(suggested_size, 2),
            "shrink_ratio": round(available_width / max(text_width, 0.1), 3),
        }
    
    def get_font_info(self) -> dict:
        """Get font metadata."""
        return {
            "path": self.font_path,
            "units_per_em": self._upem,
            "glyph_count": self._face.glyph_count,
        }


# =============================================================================
# COMPARISON WITH PYMUPDF
# =============================================================================

def compare_measurements(text: str, font_path: str, font_size: float) -> dict:
    """
    Compare HarfBuzz measurement with pymupdf's text_length().
    
    Shows the difference between approximate (pymupdf) and accurate (HarfBuzz).
    """
    # PyMuPDF measurement
    pymupdf_font = pymupdf.Font(fontfile=font_path)
    pymupdf_width = pymupdf_font.text_length(text, fontsize=font_size)
    
    # HarfBuzz measurement
    if HAS_HARFBUZZ:
        shaper = TextShaper(font_path)
        hb_width = shaper.measure_width(text, font_size)
    else:
        hb_width = None
    
    difference = abs(hb_width - pymupdf_width) if hb_width else None
    
    return {
        "text": text,
        "font_size": font_size,
        "pymupdf_width": round(pymupdf_width, 3),
        "harfbuzz_width": round(hb_width, 3) if hb_width else None,
        "difference_pts": round(difference, 3) if difference else None,
        "difference_pct": round(difference / max(pymupdf_width, 0.1) * 100, 2) if difference else None,
        "harfbuzz_available": HAS_HARFBUZZ,
    }


# =============================================================================
# INTEGRATION WITH V8 ENGINE
# =============================================================================

# Module-level shaper cache (one per font file)
_shaper_cache = {}


def get_shaper(font_path: str) -> Optional['TextShaper']:
    """Get or create a cached TextShaper for a font file."""
    if not HAS_HARFBUZZ:
        return None
    
    if font_path not in _shaper_cache:
        try:
            _shaper_cache[font_path] = TextShaper(font_path)
        except Exception:
            _shaper_cache[font_path] = None
    
    return _shaper_cache[font_path]


def accurate_text_width(text: str, font_path: str, font_size: float) -> float:
    """
    Get accurate text width. Uses HarfBuzz if available, falls back to pymupdf.
    
    This is the function V8 should call instead of pymupdf.Font.text_length().
    """
    shaper = get_shaper(font_path)
    
    if shaper:
        return shaper.measure_width(text, font_size)
    else:
        # Fallback to pymupdf
        font = pymupdf.Font(fontfile=font_path)
        return font.text_length(text, fontsize=font_size)


def check_overflow_accurate(text: str, font_path: str, font_size: float,
                           available_width: float) -> dict:
    """
    Accurate overflow check for the V8 renderer.
    Uses HarfBuzz when available for precise measurement.
    """
    shaper = get_shaper(font_path)
    
    if shaper:
        return shaper.will_overflow(text, font_size, available_width)
    else:
        # Fallback
        font = pymupdf.Font(fontfile=font_path)
        width = font.text_length(text, fontsize=font_size)
        overflow = width - available_width
        
        return {
            "overflows": overflow > 0,
            "text_width": round(width, 2),
            "available_width": round(available_width, 2),
            "overflow_amount": round(overflow, 2),
            "suggested_font_size": round(font_size * available_width / max(width, 0.1), 2),
            "shrink_ratio": round(available_width / max(width, 0.1), 3),
            "backend": "pymupdf_fallback",
        }


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="HarfBuzz Text Shaping — V8")
    subparsers = parser.add_subparsers(dest="command")
    
    # Measure
    measure_p = subparsers.add_parser("measure", help="Measure text width")
    measure_p.add_argument("--text", "-t", required=True)
    measure_p.add_argument("--font", "-f", required=True)
    measure_p.add_argument("--size", "-s", type=float, default=12)
    
    # Compare
    compare_p = subparsers.add_parser("compare", help="Compare HarfBuzz vs pymupdf")
    compare_p.add_argument("--text", "-t", required=True)
    compare_p.add_argument("--font", "-f", required=True)
    compare_p.add_argument("--size", "-s", type=float, default=12)
    
    # Check status
    subparsers.add_parser("status", help="Check HarfBuzz availability")
    
    args = parser.parse_args()
    
    if args.command == "measure":
        if not HAS_HARFBUZZ:
            print("HarfBuzz not available. Install: pip install uharfbuzz")
            return
        
        shaper = TextShaper(args.font)
        result = shaper.shape_text(args.text, args.size)
        
        print(f"Text: \"{result['text']}\"")
        print(f"Font size: {result['font_size']}pt")
        print(f"Width: {result['total_width']}pt")
        print(f"Glyphs: {result['glyph_count']} (chars: {result['char_count']})")
        
        if result['glyph_count'] != result['char_count']:
            print(f"  Note: glyph count differs from char count (ligatures/shaping applied)")
    
    elif args.command == "compare":
        result = compare_measurements(args.text, args.font, args.size)
        
        print(f"Text: \"{result['text']}\"")
        print(f"Font size: {result['font_size']}pt")
        print(f"PyMuPDF width: {result['pymupdf_width']}pt")
        print(f"HarfBuzz width: {result['harfbuzz_width']}pt")
        print(f"Difference: {result['difference_pts']}pt ({result['difference_pct']}%)")
    
    elif args.command == "status":
        print(f"HarfBuzz available: {HAS_HARFBUZZ}")
        if HAS_HARFBUZZ:
            print(f"  uharfbuzz version: {hb.version_string()}")
    
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
