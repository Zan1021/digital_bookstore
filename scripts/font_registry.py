"""
Font Registry — Digital Bookstore V8
======================================
Tracks available fonts, their glyph coverage for target languages,
and metadata for intelligent font selection.

The registry:
- Scans a fonts directory and catalogs available fonts
- Checks glyph coverage for specific language scripts
- Resolves the best font for a given text + language
- Reports missing glyphs before rendering (prevent .notdef)

Usage:
    from font_registry import FontRegistry
    
    registry = FontRegistry("./fonts")
    registry.scan()
    
    # Check if a font covers Afrikaans
    coverage = registry.check_coverage("PlaypenSans-Regular.ttf", "af")
    
    # Find best font for text
    font = registry.resolve_font("Verantwoordelikheid", language="af", weight="regular")
"""

import os
import sys
from dataclasses import dataclass, field
from typing import Optional

import pymupdf


# =============================================================================
# LANGUAGE SCRIPT REQUIREMENTS
# =============================================================================

# Characters needed for South African languages beyond basic Latin
LANGUAGE_CHARS = {
    "af": "àáâãäèéêëìíîïòóôõöùúûüý",  # Afrikaans diacritics
    "zu": "àáâãäèéêëìíîïòóôõöùúûü",    # isiZulu
    "xh": "àáâãäèéêëìíîïòóôõöùúûü",    # isiXhosa
    "st": "àáâãäèéêëìíîïòóôõöùúûüŠšŽž", # Sesotho
    "tn": "àáâãäèéêëìíîïòóôõöùúûüŠšŽž", # Setswana
    "nso": "àáâãäèéêëìíîïòóôõöùúûüŠšŽž",# Sepedi
    "ts": "àáâãäèéêëìíîïòóôõöùúûü",     # Xitsonga
    "ss": "àáâãäèéêëìíîïòóôõöùúûü",     # siSwati
    "ve": "àáâãäèéêëìíîïòóôõöùúûüḒḓṊṋṰṱḼḽ", # Tshivenda (has unique chars)
    "nr": "àáâãäèéêëìíîïòóôõöùúûü",     # isiNdebele
    "fr": "àâæçéèêëïîôœùûüÿ",           # French
    "pt": "àáâãçéêíóôõú",               # Portuguese
}

# Basic Latin + common punctuation that ALL fonts must cover
BASIC_LATIN = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789 .,!?;:'\"-()/"


# =============================================================================
# DATA TYPES
# =============================================================================

@dataclass
class FontInfo:
    """Information about a registered font."""
    filename: str
    filepath: str
    family: str
    weight: str             # "regular", "bold", "semibold", "light", "medium"
    weight_value: int       # 100-900
    italic: bool
    format: str             # "ttf", "otf"
    glyph_count: int = 0
    has_basic_latin: bool = True
    language_coverage: dict = field(default_factory=dict)  # lang_code → coverage_pct
    missing_chars: dict = field(default_factory=dict)      # lang_code → [missing chars]
    variable: bool = False
    variable_axes: list = field(default_factory=list)


@dataclass
class CoverageReport:
    """Result of checking font coverage for a language."""
    font: str
    language: str
    coverage_pct: float
    total_chars_needed: int
    chars_covered: int
    chars_missing: list
    suitable: bool          # True if coverage > 95%


# =============================================================================
# FONT REGISTRY
# =============================================================================

class FontRegistry:
    """Registry of available fonts with coverage analysis."""
    
    def __init__(self, fonts_dir: str):
        self.fonts_dir = fonts_dir
        self.fonts: dict[str, FontInfo] = {}  # filename → FontInfo
        self._scanned = False
    
    def scan(self) -> int:
        """
        Scan the fonts directory and catalog all available fonts.
        Returns the number of fonts found.
        """
        if not os.path.isdir(self.fonts_dir):
            return 0
        
        self.fonts = {}
        
        for filename in sorted(os.listdir(self.fonts_dir)):
            if not filename.lower().endswith(('.ttf', '.otf')):
                continue
            
            filepath = os.path.join(self.fonts_dir, filename)
            font_info = self._analyze_font(filename, filepath)
            if font_info:
                self.fonts[filename] = font_info
        
        self._scanned = True
        return len(self.fonts)
    
    def _analyze_font(self, filename: str, filepath: str) -> Optional[FontInfo]:
        """Analyze a font file and extract metadata."""
        try:
            font = pymupdf.Font(fontfile=filepath)
        except Exception:
            return None
        
        # Derive weight from filename
        name_lower = filename.lower()
        if "bold" in name_lower:
            weight = "bold"
            weight_value = 700
        elif "semibold" in name_lower:
            weight = "semibold"
            weight_value = 600
        elif "medium" in name_lower:
            weight = "medium"
            weight_value = 500
        elif "light" in name_lower:
            weight = "light"
            weight_value = 300
        elif "thin" in name_lower:
            weight = "thin"
            weight_value = 100
        else:
            weight = "regular"
            weight_value = 400
        
        italic = "italic" in name_lower or "oblique" in name_lower
        
        # Derive family name
        family = filename.rsplit('.', 1)[0]
        for suffix in ['-Regular', '-Bold', '-SemiBold', '-Medium', '-Light',
                      '-Thin', '-Italic', '-BoldItalic', 'Regular', 'Bold']:
            family = family.replace(suffix, '')
        family = family.rstrip('-').rstrip('_')
        
        # Check basic Latin coverage
        has_basic = all(font.has_glyph(ord(c)) for c in BASIC_LATIN if c != ' ')
        
        # Get glyph count
        glyph_count = font.glyph_count
        
        fmt = "otf" if filename.lower().endswith('.otf') else "ttf"
        
        return FontInfo(
            filename=filename,
            filepath=filepath,
            family=family,
            weight=weight,
            weight_value=weight_value,
            italic=italic,
            format=fmt,
            glyph_count=glyph_count,
            has_basic_latin=has_basic,
        )
    
    def check_coverage(self, filename: str, language: str) -> CoverageReport:
        """
        Check a font's glyph coverage for a specific language.
        
        Returns a CoverageReport with details on what's covered and what's missing.
        """
        if filename not in self.fonts:
            return CoverageReport(
                font=filename, language=language,
                coverage_pct=0, total_chars_needed=0,
                chars_covered=0, chars_missing=[],
                suitable=False,
            )
        
        font_info = self.fonts[filename]
        filepath = font_info.filepath
        
        # Get required characters for this language
        required_chars = BASIC_LATIN + LANGUAGE_CHARS.get(language, "")
        unique_chars = list(set(required_chars))
        
        # Check each character
        try:
            font = pymupdf.Font(fontfile=filepath)
        except Exception:
            return CoverageReport(
                font=filename, language=language,
                coverage_pct=0, total_chars_needed=len(unique_chars),
                chars_covered=0, chars_missing=unique_chars,
                suitable=False,
            )
        
        missing = []
        covered = 0
        for char in unique_chars:
            if char == ' ':
                covered += 1
                continue
            if font.has_glyph(ord(char)):
                covered += 1
            else:
                missing.append(char)
        
        total = len(unique_chars)
        pct = (covered / total * 100) if total > 0 else 0
        
        # Cache result
        font_info.language_coverage[language] = pct
        font_info.missing_chars[language] = missing
        
        return CoverageReport(
            font=filename,
            language=language,
            coverage_pct=pct,
            total_chars_needed=total,
            chars_covered=covered,
            chars_missing=missing,
            suitable=pct >= 95.0,
        )
    
    def resolve_font(
        self,
        text: str = "",
        language: str = "af",
        weight: str = "regular",
        italic: bool = False,
        prefer_family: str = None,
    ) -> Optional[FontInfo]:
        """
        Resolve the best font for given text, language, and style requirements.
        
        Priority:
        1. Preferred family match (if specified)
        2. Weight match
        3. Language coverage
        4. First available font
        """
        if not self._scanned:
            self.scan()
        
        candidates = list(self.fonts.values())
        
        if not candidates:
            return None
        
        # Filter by family preference
        if prefer_family:
            family_match = [f for f in candidates if prefer_family.lower() in f.family.lower()]
            if family_match:
                candidates = family_match
        
        # Filter by weight
        weight_map = {"regular": 400, "bold": 700, "semibold": 600, "medium": 500, "light": 300}
        target_weight = weight_map.get(weight, 400)
        
        # Sort by weight distance
        candidates.sort(key=lambda f: abs(f.weight_value - target_weight))
        
        # Filter by italic
        if italic:
            italic_match = [f for f in candidates if f.italic]
            if italic_match:
                candidates = italic_match
        else:
            non_italic = [f for f in candidates if not f.italic]
            if non_italic:
                candidates = non_italic
        
        # Check language coverage for top candidates
        if language and text:
            for candidate in candidates[:5]:
                report = self.check_coverage(candidate.filename, language)
                if report.suitable:
                    return candidate
        
        # If no candidate has full coverage, sort by coverage and return best
        if language:
            candidates.sort(
                key=lambda f: f.language_coverage.get(language, 0),
                reverse=True,
            )
        
        # Return best available
        return candidates[0] if candidates else None
    
    def preflight_text(self, text: str, font_filename: str) -> dict:
        """
        Check if ALL glyphs in the given text are available in the font.
        Returns dict with missing characters (if any).
        """
        if font_filename not in self.fonts:
            return {"valid": False, "error": "Font not in registry"}
        
        filepath = self.fonts[font_filename].filepath
        try:
            font = pymupdf.Font(fontfile=filepath)
        except Exception:
            return {"valid": False, "error": "Cannot load font"}
        
        missing = []
        for char in text:
            if char == ' ' or char == '\n':
                continue
            if not font.has_glyph(ord(char)):
                missing.append(char)
        
        return {
            "valid": len(missing) == 0,
            "missing_chars": list(set(missing)),
            "missing_count": len(set(missing)),
            "total_chars": len(text),
        }
    
    def summary(self) -> dict:
        """Get a summary of the registry."""
        if not self._scanned:
            self.scan()
        
        families = set(f.family for f in self.fonts.values())
        return {
            "fonts_dir": self.fonts_dir,
            "total_fonts": len(self.fonts),
            "families": sorted(families),
            "fonts": [
                {
                    "filename": f.filename,
                    "family": f.family,
                    "weight": f.weight,
                    "glyphs": f.glyph_count,
                    "has_basic_latin": f.has_basic_latin,
                }
                for f in self.fonts.values()
            ],
        }


# =============================================================================
# CLI
# =============================================================================

def main():
    """Test font registry with the Kolulu fonts directory."""
    import json
    
    fonts_dir = r"C:\Users\zande\Documents\Digital Bookstore\Fonts"
    
    registry = FontRegistry(fonts_dir)
    count = registry.scan()
    
    print(f"=== Font Registry ===")
    print(f"Directory: {fonts_dir}")
    print(f"Fonts found: {count}")
    
    for filename, info in registry.fonts.items():
        print(f"\n  {filename}")
        print(f"    Family: {info.family}")
        print(f"    Weight: {info.weight} ({info.weight_value})")
        print(f"    Glyphs: {info.glyph_count}")
        print(f"    Basic Latin: {info.has_basic_latin}")
        
        # Check Afrikaans coverage
        report = registry.check_coverage(filename, "af")
        print(f"    Afrikaans: {report.coverage_pct:.1f}% ({report.chars_covered}/{report.total_chars_needed})")
        if report.chars_missing:
            print(f"    Missing: {''.join(report.chars_missing[:20])}")
        print(f"    Suitable for AF: {report.suitable}")
    
    # Test resolve
    print(f"\n=== Font Resolution ===")
    resolved = registry.resolve_font(text="Verantwoordelikheid", language="af", weight="regular")
    if resolved:
        print(f"  Best font for AF regular: {resolved.filename}")
    
    # Preflight test
    print(f"\n=== Preflight ===")
    if resolved:
        result = registry.preflight_text("Die kat sit op die mat", resolved.filename)
        print(f"  'Die kat sit op die mat': valid={result['valid']}")


if __name__ == "__main__":
    main()
