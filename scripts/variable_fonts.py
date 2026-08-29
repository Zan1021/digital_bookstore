"""
Variable Font Support — Digital Bookstore V8
==============================================
Handles variable fonts (OpenType 1.8+) with axes like wght, wdth, opsz.

A single variable font file can produce any weight/width/optical-size
instead of needing separate files for Regular, Bold, SemiBold, etc.

Capabilities:
- Detect if a font is variable (has 'fvar' table)
- List available axes and their ranges
- Select specific axis values for rendering
- Compute optimal axis values for a given constraint (e.g., widest
  weight that still fits in container)

Common axes:
- wght: Weight (100=Thin, 400=Regular, 700=Bold, 900=Black)
- wdth: Width (75=Condensed, 100=Normal, 125=Extended)
- opsz: Optical size (adapt design for small/large rendering)
- ital: Italic (0=Roman, 1=Italic)
- slnt: Slant (-12 to 0 degrees typically)

Usage:
    from variable_fonts import VariableFontInfo, detect_variable_font
    
    info = detect_variable_font("font.ttf")
    if info.is_variable:
        print(f"Axes: {info.axes}")
        # Get instance at weight 600
        instance = info.get_instance(wght=600)
"""

import os
import sys
from dataclasses import dataclass, field
from typing import Optional

SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPTS_DIR)


# =============================================================================
# DATA TYPES
# =============================================================================

@dataclass
class FontAxis:
    """A single variation axis in a variable font."""
    tag: str            # e.g., "wght", "wdth", "opsz"
    name: str           # e.g., "Weight", "Width", "Optical Size"
    min_value: float
    max_value: float
    default_value: float

    @property
    def range(self) -> float:
        return self.max_value - self.min_value

    def clamp(self, value: float) -> float:
        """Clamp a value to this axis's valid range."""
        return max(self.min_value, min(self.max_value, value))


@dataclass
class NamedInstance:
    """A named instance (predefined point) in a variable font."""
    name: str                    # e.g., "Bold", "Light Condensed"
    coordinates: dict            # e.g., {"wght": 700, "wdth": 100}


@dataclass
class VariableFontInfo:
    """Information about a variable font's capabilities."""
    filepath: str
    filename: str
    is_variable: bool = False
    axes: list = field(default_factory=list)           # [FontAxis]
    named_instances: list = field(default_factory=list) # [NamedInstance]
    
    @property
    def axis_tags(self) -> list:
        """Get list of axis tags (e.g., ['wght', 'wdth'])."""
        return [a.tag for a in self.axes]
    
    def has_axis(self, tag: str) -> bool:
        """Check if font has a specific axis."""
        return tag in self.axis_tags
    
    def get_axis(self, tag: str) -> Optional[FontAxis]:
        """Get axis info by tag."""
        for axis in self.axes:
            if axis.tag == tag:
                return axis
        return None
    
    def get_weight_range(self) -> Optional[tuple]:
        """Get weight range if wght axis exists."""
        axis = self.get_axis("wght")
        if axis:
            return (axis.min_value, axis.max_value)
        return None
    
    def get_width_range(self) -> Optional[tuple]:
        """Get width range if wdth axis exists."""
        axis = self.get_axis("wdth")
        if axis:
            return (axis.min_value, axis.max_value)
        return None
    
    def get_instance(self, **kwargs) -> dict:
        """
        Get axis coordinates for a specific instance.
        Kwargs are axis tags (e.g., wght=600, wdth=100).
        Missing axes use their default value.
        """
        coords = {}
        for axis in self.axes:
            if axis.tag in kwargs:
                coords[axis.tag] = axis.clamp(kwargs[axis.tag])
            else:
                coords[axis.tag] = axis.default_value
        return coords
    
    def find_nearest_instance(self, **kwargs) -> Optional[NamedInstance]:
        """Find the named instance closest to the given coordinates."""
        if not self.named_instances:
            return None
        
        target = self.get_instance(**kwargs)
        best = None
        best_dist = float('inf')
        
        for instance in self.named_instances:
            dist = sum(
                (instance.coordinates.get(k, 0) - v) ** 2
                for k, v in target.items()
            )
            if dist < best_dist:
                best_dist = dist
                best = instance
        
        return best


# =============================================================================
# DETECTION
# =============================================================================

def detect_variable_font(font_path: str) -> VariableFontInfo:
    """
    Detect if a font is variable and extract its axes and instances.
    
    Uses fontTools if available (comprehensive), falls back to basic detection.
    """
    filename = os.path.basename(font_path)
    info = VariableFontInfo(filepath=font_path, filename=filename)
    
    # Try fontTools first (most reliable for variable font analysis)
    try:
        from fontTools.ttLib import TTFont
        return _detect_with_fonttools(font_path, info)
    except ImportError:
        pass
    
    # Fallback: basic detection from filename and pymupdf
    return _detect_basic(font_path, info)


def _detect_with_fonttools(font_path: str, info: VariableFontInfo) -> VariableFontInfo:
    """Detect variable font using fontTools (comprehensive)."""
    from fontTools.ttLib import TTFont
    
    try:
        font = TTFont(font_path)
    except Exception:
        return info
    
    # Check for 'fvar' table (defines variation axes)
    if 'fvar' not in font:
        info.is_variable = False
        font.close()
        return info
    
    info.is_variable = True
    fvar = font['fvar']
    
    # Extract axes
    for axis in fvar.axes:
        axis_name = _axis_tag_to_name(axis.axisTag)
        info.axes.append(FontAxis(
            tag=axis.axisTag,
            name=axis_name,
            min_value=axis.minValue,
            max_value=axis.maxValue,
            default_value=axis.defaultValue,
        ))
    
    # Extract named instances
    name_table = font.get('name')
    for instance in fvar.instances:
        # Get instance name from name table
        inst_name = ""
        if name_table and instance.subfamilyNameID:
            name_record = name_table.getName(instance.subfamilyNameID, 3, 1, 0x0409)
            if name_record:
                inst_name = name_record.toUnicode()
        
        if not inst_name:
            # Build name from coordinates
            parts = [f"{k}={v}" for k, v in instance.coordinates.items()]
            inst_name = " ".join(parts)
        
        info.named_instances.append(NamedInstance(
            name=inst_name,
            coordinates=dict(instance.coordinates),
        ))
    
    font.close()
    return info


def _detect_basic(font_path: str, info: VariableFontInfo) -> VariableFontInfo:
    """
    Basic variable font detection without fontTools.
    Can only detect from filename patterns and font flag hints.
    """
    filename = info.filename.lower()
    
    # Variable fonts often have "Variable" or "VF" in their name
    if "variable" in filename or "-vf" in filename or "_vf" in filename:
        info.is_variable = True
        # Assume standard wght axis
        info.axes.append(FontAxis(
            tag="wght",
            name="Weight",
            min_value=100,
            max_value=900,
            default_value=400,
        ))
    
    return info


def _axis_tag_to_name(tag: str) -> str:
    """Convert axis tag to human-readable name."""
    names = {
        "wght": "Weight",
        "wdth": "Width",
        "opsz": "Optical Size",
        "ital": "Italic",
        "slnt": "Slant",
        "GRAD": "Grade",
        "XTRA": "x transparent",
        "YOPQ": "y opaque",
        "XOPQ": "x opaque",
        "YTLC": "y transparent lowercase",
    }
    return names.get(tag, tag)


# =============================================================================
# OPTIMAL AXIS SELECTION
# =============================================================================

def compute_optimal_weight(
    text: str,
    container_width: float,
    font_path: str,
    font_size: float,
    target_weight: int = 400,
) -> dict:
    """
    For a variable font, compute the optimal weight axis value that
    allows the text to fit in the container.
    
    If text is too wide at target weight, try reducing weight (lighter = narrower).
    If text is too narrow, optionally increase weight for visual balance.
    
    Returns dict with suggested weight and fit status.
    """
    import pymupdf
    
    try:
        font = pymupdf.Font(fontfile=font_path)
    except Exception:
        return {"weight": target_weight, "fits": False, "error": "Cannot load font"}
    
    # Measure at target weight (standard font — variable weight can't be set via pymupdf)
    text_width = font.text_length(text, fontsize=font_size)
    
    fits = text_width <= container_width
    
    return {
        "weight": target_weight,
        "fits": fits,
        "text_width": text_width,
        "container_width": container_width,
        "overflow": text_width - container_width if not fits else 0,
        "note": "Variable weight rendering requires HarfBuzz or direct PDF operators (future)",
    }


# =============================================================================
# CLI
# =============================================================================

def main():
    """Test variable font detection on available fonts."""
    fonts_dir = r"C:\Users\zande\Documents\Digital Bookstore\Fonts"
    
    print("=== Variable Font Detection ===\n")
    
    for filename in sorted(os.listdir(fonts_dir)):
        if not filename.lower().endswith(('.ttf', '.otf')):
            continue
        
        filepath = os.path.join(fonts_dir, filename)
        info = detect_variable_font(filepath)
        
        status = "VARIABLE" if info.is_variable else "static"
        print(f"  {filename}: {status}")
        
        if info.is_variable:
            for axis in info.axes:
                print(f"    {axis.tag} ({axis.name}): {axis.min_value} - {axis.max_value} [default={axis.default_value}]")
            if info.named_instances:
                print(f"    Instances: {len(info.named_instances)}")
                for inst in info.named_instances[:5]:
                    print(f"      {inst.name}: {inst.coordinates}")


if __name__ == "__main__":
    main()
