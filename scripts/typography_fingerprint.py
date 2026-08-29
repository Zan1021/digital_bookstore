"""
Typography Fingerprints — Digital Bookstore V8
================================================
Builds a visual signature for each font to enable automatic font matching.

When Johan's books use fonts we don't have in our library, we need to pick
the closest available match by visual characteristics, not just name.

A fingerprint includes:
- x-height / cap-height ratio
- Average character width / cap-height ratio
- Stroke contrast (ratio of thinnest to thickest stroke)
- Weight class (thin → black)
- Width class (condensed → extended)
- Serif classification (serif, sans-serif, slab, mono)
- Character density (glyphs per em-width of typical word)

Usage:
    from typography_fingerprint import build_fingerprint, match_font
    
    # Build fingerprint for source font (from PDF extraction)
    source_fp = build_fingerprint_from_metrics(source_metrics)
    
    # Find best match in our font library
    best = match_font(source_fp, available_fonts)
"""

import os
import sys
import math
from dataclasses import dataclass, field
from typing import Optional

import pymupdf

SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPTS_DIR)


# =============================================================================
# DATA TYPES
# =============================================================================

@dataclass
class TypographyFingerprint:
    """Visual signature of a font for matching purposes."""
    font_name: str
    font_path: str = ""
    # Proportional metrics (dimensionless ratios)
    x_height_ratio: float = 0.0    # x-height / ascender
    cap_ratio: float = 0.0         # cap-height / ascender
    width_ratio: float = 0.0       # avg char width / x-height
    # Classification
    weight_class: int = 400        # 100-900
    width_class: str = "normal"    # condensed, normal, extended
    serif_class: str = "unknown"   # serif, sans-serif, slab, mono, handwriting
    # Character metrics
    char_density: float = 0.0      # chars per em-width of "test"
    avg_word_width: float = 0.0    # Average width of standard words (relative)
    # Stroke metrics
    is_monospace: bool = False
    # Source info (when extracted from PDF)
    source_font_name: str = ""     # Original PDF font name

    def distance_to(self, other: 'TypographyFingerprint') -> float:
        """Calculate visual distance between two fingerprints (0 = identical)."""
        # Weighted Euclidean distance on key metrics
        d = 0.0
        
        # x-height ratio difference (heavily weighted — most visible)
        d += (self.x_height_ratio - other.x_height_ratio) ** 2 * 10.0
        
        # Width ratio difference
        d += (self.width_ratio - other.width_ratio) ** 2 * 5.0
        
        # Weight class distance
        weight_diff = abs(self.weight_class - other.weight_class) / 100.0
        d += weight_diff ** 2 * 3.0
        
        # Serif class mismatch (big penalty)
        if self.serif_class != other.serif_class and self.serif_class != "unknown" and other.serif_class != "unknown":
            d += 5.0
        
        # Width class mismatch
        if self.width_class != other.width_class:
            d += 1.0
        
        # Char density difference
        if self.char_density > 0 and other.char_density > 0:
            d += ((self.char_density - other.char_density) / max(self.char_density, 1)) ** 2 * 2.0
        
        return math.sqrt(d)


# =============================================================================
# FINGERPRINT BUILDER
# =============================================================================

def build_fingerprint(font_path: str) -> TypographyFingerprint:
    """Build a typography fingerprint from a font file."""
    font_name = os.path.basename(font_path).rsplit('.', 1)[0]
    
    try:
        font = pymupdf.Font(fontfile=font_path)
    except Exception:
        return TypographyFingerprint(font_name=font_name, font_path=font_path)
    
    fp = TypographyFingerprint(font_name=font_name, font_path=font_path)
    
    size = 100.0
    
    # Ascender/descender ratios
    ascender = font.ascender if font.ascender else 0.8
    descender = abs(font.descender) if font.descender else 0.2
    
    fp.x_height_ratio = 0.7  # Standard estimate; better with actual rendering
    fp.cap_ratio = 0.95
    
    # Width metrics
    lower_width = font.text_length("abcdefghijklmnopqrstuvwxyz", fontsize=size)
    avg_char = lower_width / 26
    x_height_approx = ascender * size * 0.7
    
    fp.width_ratio = avg_char / x_height_approx if x_height_approx > 0 else 0.5
    
    # Character density
    test_word = "test"
    test_width = font.text_length(test_word, fontsize=size)
    em_width = font.text_length("M", fontsize=size)
    fp.char_density = len(test_word) / (test_width / em_width) if test_width > 0 else 4.0
    
    # Weight from filename
    name_lower = font_name.lower()
    if "bold" in name_lower or "black" in name_lower:
        fp.weight_class = 700
    elif "semibold" in name_lower or "demibold" in name_lower:
        fp.weight_class = 600
    elif "medium" in name_lower:
        fp.weight_class = 500
    elif "light" in name_lower:
        fp.weight_class = 300
    elif "thin" in name_lower or "hairline" in name_lower:
        fp.weight_class = 100
    else:
        fp.weight_class = 400
    
    # Serif classification from name heuristics
    if any(x in name_lower for x in ["mono", "code", "courier", "consolas"]):
        fp.serif_class = "mono"
        fp.is_monospace = True
    elif any(x in name_lower for x in ["playpen", "comic", "handwrit", "script", "cursive"]):
        fp.serif_class = "handwriting"
    elif any(x in name_lower for x in ["slab", "rockwell", "roboto slab"]):
        fp.serif_class = "slab"
    elif any(x in name_lower for x in ["sans", "gothic", "grotesk", "helvetica", "arial"]):
        fp.serif_class = "sans-serif"
    elif any(x in name_lower for x in ["serif", "times", "georgia", "garamond"]):
        fp.serif_class = "serif"
    else:
        fp.serif_class = "unknown"
    
    # Width class
    if "condensed" in name_lower or "narrow" in name_lower:
        fp.width_class = "condensed"
    elif "extended" in name_lower or "wide" in name_lower:
        fp.width_class = "extended"
    else:
        fp.width_class = "normal"
    
    # Monospace detection: check if all chars have same width
    widths = set()
    for c in "abcMiWl":
        w = round(font.text_length(c, fontsize=size), 1)
        widths.add(w)
    if len(widths) == 1:
        fp.is_monospace = True
        fp.serif_class = "mono"
    
    # Average word width (relative to em)
    words = ["the", "and", "for", "are", "but", "not", "you", "all"]
    total_w = sum(font.text_length(w, fontsize=size) for w in words)
    fp.avg_word_width = total_w / (len(words) * em_width) if em_width > 0 else 3.0
    
    return fp


def build_fingerprint_from_metrics(
    font_name: str,
    avg_char_width: float,
    cap_height: float,
    x_height: float = 0,
    weight: int = 400,
) -> TypographyFingerprint:
    """
    Build a fingerprint from extracted PDF metrics (when we don't have the font file).
    Used for matching source fonts in PDFs we're translating.
    """
    fp = TypographyFingerprint(
        font_name=font_name,
        source_font_name=font_name,
        weight_class=weight,
    )
    
    if cap_height > 0:
        fp.x_height_ratio = x_height / cap_height if x_height > 0 else 0.7
        fp.width_ratio = avg_char_width / cap_height if cap_height > 0 else 0.5
    
    # Infer serif class from name
    name_lower = font_name.lower()
    if any(x in name_lower for x in ["sans", "gothic", "grotesk"]):
        fp.serif_class = "sans-serif"
    elif any(x in name_lower for x in ["edu", "playpen", "comic", "handwrit"]):
        fp.serif_class = "handwriting"
    elif any(x in name_lower for x in ["mono", "code"]):
        fp.serif_class = "mono"
    else:
        fp.serif_class = "unknown"
    
    return fp


# =============================================================================
# FONT MATCHING
# =============================================================================

def match_font(
    source_fingerprint: TypographyFingerprint,
    available_fonts: list,
    max_distance: float = 5.0,
) -> list:
    """
    Find the best matching fonts for a source fingerprint.
    
    Args:
        source_fingerprint: The font we're trying to match
        available_fonts: List of TypographyFingerprint objects for our font library
        max_distance: Maximum acceptable distance (higher = more lenient)
    
    Returns:
        List of (distance, fingerprint) tuples, sorted by distance (best first).
    """
    matches = []
    
    for candidate in available_fonts:
        dist = source_fingerprint.distance_to(candidate)
        if dist <= max_distance:
            matches.append((dist, candidate))
    
    matches.sort(key=lambda x: x[0])
    return matches


# =============================================================================
# CLI
# =============================================================================

def main():
    """Build fingerprints for all available fonts and test matching."""
    fonts_dir = r"C:\Users\zande\Documents\Digital Bookstore\Fonts"
    
    # Build fingerprints
    fingerprints = []
    for filename in sorted(os.listdir(fonts_dir)):
        if not filename.lower().endswith(('.ttf', '.otf')):
            continue
        if ' ' in filename:
            continue
        
        fp = build_fingerprint(os.path.join(fonts_dir, filename))
        fingerprints.append(fp)
        print(f"{fp.font_name}:")
        print(f"  class={fp.serif_class}, weight={fp.weight_class}, width={fp.width_class}")
        print(f"  width_ratio={fp.width_ratio:.3f}, density={fp.char_density:.2f}")
    
    # Simulate matching: pretend we're looking for "Edu-Aid" (the source font in Kolulu)
    print("\n=== Matching 'Edu-Aid' (source font) ===")
    source = build_fingerprint_from_metrics(
        font_name="Edu-Aid",
        avg_char_width=8.5,
        cap_height=12.0,
        x_height=8.5,
        weight=400,
    )
    
    matches = match_font(source, fingerprints)
    if matches:
        print("Best matches:")
        for dist, fp in matches[:3]:
            print(f"  {fp.font_name}: distance={dist:.2f}")
    else:
        print("  No matches found within threshold")


if __name__ == "__main__":
    main()
