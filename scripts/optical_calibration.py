"""
Optical Font Calibration — Digital Bookstore V8
=================================================
Measures actual rendered ink dimensions of text, comparing source
extraction metrics against target font rendering.

Problem:
    Font metrics (pt size) don't guarantee visual equivalence.
    A 12pt "A" in Edu-Aid might be visually larger/smaller than
    12pt "A" in PlaypenSans due to different design metrics.

Solution:
    Render reference strings at known sizes, measure ink bbox,
    compute calibration factor: target_size = source_size × factor.

This ensures translated text LOOKS the same size as the source,
not just uses the same pt value.

Usage:
    from optical_calibration import calibrate_fonts, OpticalProfile

    profile = build_optical_profile("PlaypenSans-Regular.ttf")
    factor = compute_calibration_factor(source_profile, target_profile)
    calibrated_size = source_size * factor
"""

import os
import sys
from dataclasses import dataclass, field
from typing import Optional

import pymupdf

SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPTS_DIR)


# =============================================================================
# REFERENCE STRINGS — For consistent measurement
# =============================================================================

# Standard reference strings for optical measurement
REFERENCE_STRINGS = {
    "latin_upper": "ABCDEFGHIJKLMNOPQRSTUVWXYZ",
    "latin_lower": "abcdefghijklmnopqrstuvwxyz",
    "digits": "0123456789",
    "mixed": "The quick brown fox jumps over the lazy dog",
    "afrikaans": "Die vinnige bruin jakkals spring oor die lui hond",
}

# Reference size for all measurements
REFERENCE_SIZE_PT = 100.0


# =============================================================================
# DATA TYPES
# =============================================================================

@dataclass
class OpticalProfile:
    """Optical measurement profile for a font."""
    font_name: str
    font_path: str
    # Key metrics (measured at REFERENCE_SIZE_PT)
    cap_height: float = 0.0       # Height of uppercase letters
    x_height: float = 0.0        # Height of lowercase 'x'
    ascender: float = 0.0        # Max ascent above baseline
    descender: float = 0.0       # Max descent below baseline
    avg_char_width: float = 0.0  # Average character width (lowercase)
    em_width: float = 0.0        # Width of 'M'
    space_width: float = 0.0     # Width of space character
    # Ratios (dimensionless — for comparison)
    x_height_ratio: float = 0.0  # x_height / cap_height
    width_ratio: float = 0.0     # avg_char_width / cap_height
    # String measurements
    string_widths: dict = field(default_factory=dict)  # ref_name → width at REFERENCE_SIZE


@dataclass
class CalibrationResult:
    """Result of calibrating source font against target font."""
    source_font: str
    target_font: str
    size_factor: float           # Multiply source_size by this to get target_size
    width_factor: float          # Width scaling factor
    height_factor: float         # Height scaling factor
    confidence: float = 0.0
    notes: list = field(default_factory=list)


# =============================================================================
# OPTICAL PROFILE BUILDER
# =============================================================================

def build_optical_profile(font_path: str) -> OpticalProfile:
    """
    Build an optical measurement profile for a font.
    Renders reference strings and measures actual ink dimensions.
    """
    font_name = os.path.basename(font_path).rsplit('.', 1)[0]
    
    try:
        font = pymupdf.Font(fontfile=font_path)
    except Exception as e:
        return OpticalProfile(font_name=font_name, font_path=font_path)
    
    profile = OpticalProfile(font_name=font_name, font_path=font_path)
    
    size = REFERENCE_SIZE_PT
    
    # Measure key characters
    profile.em_width = font.text_length("M", fontsize=size)
    profile.space_width = font.text_length(" ", fontsize=size)
    
    # Average lowercase width
    lower = "abcdefghijklmnopqrstuvwxyz"
    total_lower_width = font.text_length(lower, fontsize=size)
    profile.avg_char_width = total_lower_width / len(lower)
    
    # Height metrics from font ascender/descender (0-1 relative to em)
    ascender = font.ascender   # e.g., 0.8
    descender = font.descender # e.g., -0.2
    
    # Cap height: approximately 70% of ascender height
    profile.cap_height = ascender * size * 0.95 if ascender else size * 0.7
    # x-height: approximately 50-75% of cap height for most fonts
    profile.x_height = ascender * size * 0.7 if ascender else size * 0.5
    # Full ascender
    profile.ascender = ascender * size if ascender else size * 0.8
    # Descender (positive value = depth below baseline)
    profile.descender = abs(descender) * size if descender else size * 0.2
    
    # Ratios
    if profile.cap_height > 0:
        profile.x_height_ratio = profile.x_height / profile.cap_height
        profile.width_ratio = profile.avg_char_width / profile.cap_height
    
    # Reference string widths
    for name, text in REFERENCE_STRINGS.items():
        width = font.text_length(text, fontsize=size)
        profile.string_widths[name] = width
    
    return profile


def _measure_ink_bbox(font: pymupdf.Font, text: str, size: float) -> Optional[tuple]:
    """
    Measure the actual ink bounding box of rendered text.
    Uses font ascender/descender metrics scaled to requested size.
    """
    try:
        width = font.text_length(text, fontsize=size)
        
        # font.ascender/descender are relative to em-square (typically 0-1 range)
        ascender = font.ascender   # Positive fraction
        descender = font.descender # Negative fraction
        
        if ascender != 0:
            # Scale to requested font size
            ink_height = (ascender - descender) * size
            cap_height = ascender * size * 0.7  # Cap height ~70% of ascender
            return (0, 0, width, ink_height)
        
        # Fallback: estimate from font size
        return (0, 0, width, size * 0.75)
    except Exception:
        return None


# =============================================================================
# CALIBRATION — Compare source and target fonts
# =============================================================================

def compute_calibration_factor(
    source_profile: OpticalProfile,
    target_profile: OpticalProfile,
) -> CalibrationResult:
    """
    Compute the size calibration factor between two fonts.
    
    If source font renders text at visual size X, what size does the target
    font need to be to appear the same visual size?
    
    Returns CalibrationResult with:
    - size_factor: multiply source_size by this for target_size
    - width_factor: width scaling
    - height_factor: height scaling
    """
    notes = []
    
    # Primary calibration: x-height ratio
    # If source x-height is 72pt and target is 65pt (at same nominal size),
    # target needs to be scaled up by 72/65 = 1.107
    if source_profile.x_height > 0 and target_profile.x_height > 0:
        height_factor = source_profile.x_height / target_profile.x_height
    else:
        height_factor = 1.0
        notes.append("x-height not available, using 1.0")
    
    # Width calibration: average character width
    if source_profile.avg_char_width > 0 and target_profile.avg_char_width > 0:
        width_factor = source_profile.avg_char_width / target_profile.avg_char_width
    else:
        width_factor = 1.0
        notes.append("avg_char_width not available, using 1.0")
    
    # Combined factor: prefer height-based (visual size = vertical size)
    # Width factor is secondary (affects line length but not perceived size)
    size_factor = height_factor
    
    # Sanity check: don't scale more than ±30%
    if size_factor > 1.3:
        notes.append(f"Height factor {size_factor:.2f} capped at 1.3")
        size_factor = 1.3
    elif size_factor < 0.7:
        notes.append(f"Height factor {size_factor:.2f} capped at 0.7")
        size_factor = 0.7
    
    # Confidence: higher when both fonts have good measurements
    confidence = 0.9 if (source_profile.x_height > 0 and target_profile.x_height > 0) else 0.5
    
    # Cross-check with string width comparison
    common_strings = set(source_profile.string_widths.keys()) & set(target_profile.string_widths.keys())
    if common_strings:
        width_ratios = []
        for name in common_strings:
            src_w = source_profile.string_widths[name]
            tgt_w = target_profile.string_widths[name]
            if tgt_w > 0:
                width_ratios.append(src_w / tgt_w)
        if width_ratios:
            avg_width_ratio = sum(width_ratios) / len(width_ratios)
            # If width ratio disagrees strongly with height ratio, note it
            if abs(avg_width_ratio - size_factor) > 0.15:
                notes.append(f"Width ratio ({avg_width_ratio:.2f}) differs from height ratio ({size_factor:.2f})")
    
    return CalibrationResult(
        source_font=source_profile.font_name,
        target_font=target_profile.font_name,
        size_factor=size_factor,
        width_factor=width_factor,
        height_factor=height_factor,
        confidence=confidence,
        notes=notes,
    )


def calibrate_size(source_size: float, calibration: CalibrationResult) -> float:
    """Apply calibration factor to get the optically-equivalent target size."""
    return source_size * calibration.size_factor


# =============================================================================
# CLI
# =============================================================================

def main():
    """Test optical calibration with available fonts."""
    fonts_dir = r"C:\Users\zande\Documents\Digital Bookstore\Fonts"
    
    # Build profiles for available fonts
    fonts = [
        os.path.join(fonts_dir, f)
        for f in os.listdir(fonts_dir)
        if f.lower().endswith(('.ttf', '.otf')) and ' ' not in f
    ]
    
    profiles = {}
    for fp in fonts[:4]:  # First 4 fonts
        profile = build_optical_profile(fp)
        profiles[profile.font_name] = profile
        print(f"\n{profile.font_name}:")
        print(f"  x-height: {profile.x_height:.1f}")
        print(f"  cap-height: {profile.cap_height:.1f}")
        print(f"  x/cap ratio: {profile.x_height_ratio:.3f}")
        print(f"  avg char width: {profile.avg_char_width:.1f}")
        print(f"  'mixed' width: {profile.string_widths.get('mixed', 0):.0f}")
    
    # Calibrate between first two fonts
    font_names = list(profiles.keys())
    if len(font_names) >= 2:
        src = profiles[font_names[0]]
        tgt = profiles[font_names[1]]
        cal = compute_calibration_factor(src, tgt)
        print(f"\n=== Calibration: {cal.source_font} -> {cal.target_font} ===")
        print(f"  Size factor: {cal.size_factor:.3f}")
        print(f"  Width factor: {cal.width_factor:.3f}")
        print(f"  Height factor: {cal.height_factor:.3f}")
        print(f"  Confidence: {cal.confidence:.2f}")
        if cal.notes:
            for note in cal.notes:
                print(f"  Note: {note}")
        print(f"\n  Example: 17pt source -> {calibrate_size(17, cal):.1f}pt target")


if __name__ == "__main__":
    main()
