"""
Glyph Preflight Verification — Digital Bookstore V8
=====================================================
Verifies that ALL characters in translated text have corresponding
glyphs in the target font BEFORE rendering begins.

Prevents .notdef (tofu boxes) from appearing in output PDFs.

Process:
1. Collect all translated text for the document
2. Extract unique characters
3. Check each against the rendering font's cmap
4. Report missing characters with affected unit IDs
5. Block rendering if critical glyphs are missing

Usage:
    from glyph_preflight import preflight_document, PreflightResult
    
    result = preflight_document(translations, font_path)
    if not result.passed:
        print(f"Missing glyphs: {result.missing_chars}")
"""

import os
import sys
from dataclasses import dataclass, field
from typing import Optional

import pymupdf

SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPTS_DIR)


# =============================================================================
# DATA TYPES
# =============================================================================

@dataclass
class MissingGlyph:
    """A character that's missing from the font."""
    char: str
    codepoint: int
    unicode_name: str
    affected_unit_ids: list = field(default_factory=list)
    occurrence_count: int = 0


@dataclass
class PreflightResult:
    """Result of glyph preflight check."""
    passed: bool                    # True if all glyphs are available
    font_path: str
    total_unique_chars: int
    chars_covered: int
    chars_missing: int
    missing_glyphs: list = field(default_factory=list)  # [MissingGlyph]
    affected_units: int = 0         # Number of translation units affected
    severity: str = "ok"            # "ok", "warning", "critical"
    
    @property
    def coverage_pct(self) -> float:
        if self.total_unique_chars == 0:
            return 100.0
        return (self.chars_covered / self.total_unique_chars) * 100

    def summary(self) -> str:
        """Human-readable summary."""
        if self.passed:
            return f"PASS: All {self.total_unique_chars} unique characters have glyphs"
        return (
            f"FAIL: {self.chars_missing} missing chars "
            f"({self.coverage_pct:.1f}% coverage, "
            f"{self.affected_units} units affected)"
        )


# =============================================================================
# PREFLIGHT CHECK
# =============================================================================

def preflight_document(
    translations: dict,
    font_path: str,
    block_on_missing: bool = True,
) -> PreflightResult:
    """
    Check all translated text against the rendering font.
    
    Args:
        translations: Dict of unit_id → translated_text
        font_path: Path to the font that will be used for rendering
        block_on_missing: If True, severity is "critical" when glyphs missing
    
    Returns:
        PreflightResult with details of any missing glyphs.
    """
    # Load font
    try:
        font = pymupdf.Font(fontfile=font_path)
    except Exception as e:
        return PreflightResult(
            passed=False, font_path=font_path,
            total_unique_chars=0, chars_covered=0, chars_missing=0,
            severity="critical",
            missing_glyphs=[MissingGlyph(char="?", codepoint=0, unicode_name="FONT_LOAD_ERROR")],
        )
    
    # Collect all unique characters and track which units use them
    char_to_units = {}  # char → [unit_ids]
    
    for unit_id, text in translations.items():
        if not text:
            continue
        for char in text:
            if char in (' ', '\n', '\t', '\r'):
                continue
            if char not in char_to_units:
                char_to_units[char] = []
            char_to_units[char].append(unit_id)
    
    # Check each character
    missing_glyphs = []
    chars_covered = 0
    
    for char, unit_ids in char_to_units.items():
        codepoint = ord(char)
        has_glyph = font.has_glyph(codepoint)
        
        if has_glyph:
            chars_covered += 1
        else:
            try:
                name = _get_unicode_name(char)
            except Exception:
                name = f"U+{codepoint:04X}"
            
            missing_glyphs.append(MissingGlyph(
                char=char,
                codepoint=codepoint,
                unicode_name=name,
                affected_unit_ids=list(set(unit_ids))[:10],  # Cap at 10
                occurrence_count=len(unit_ids),
            ))
    
    total_chars = len(char_to_units)
    chars_missing = len(missing_glyphs)
    
    # Determine severity
    if chars_missing == 0:
        severity = "ok"
        passed = True
    elif chars_missing <= 2:
        severity = "warning"
        passed = not block_on_missing
    else:
        severity = "critical"
        passed = False
    
    # Count affected units
    affected_unit_set = set()
    for mg in missing_glyphs:
        affected_unit_set.update(mg.affected_unit_ids)
    
    return PreflightResult(
        passed=passed,
        font_path=font_path,
        total_unique_chars=total_chars,
        chars_covered=chars_covered,
        chars_missing=chars_missing,
        missing_glyphs=missing_glyphs,
        affected_units=len(affected_unit_set),
        severity=severity,
    )


def preflight_single_text(text: str, font_path: str) -> dict:
    """
    Quick preflight check for a single text string.
    Returns dict with missing chars (if any).
    """
    try:
        font = pymupdf.Font(fontfile=font_path)
    except Exception:
        return {"valid": False, "error": "Cannot load font"}
    
    missing = []
    for char in set(text):
        if char in (' ', '\n', '\t'):
            continue
        if not font.has_glyph(ord(char)):
            missing.append(char)
    
    return {
        "valid": len(missing) == 0,
        "text_length": len(text),
        "unique_chars": len(set(text) - {' ', '\n', '\t'}),
        "missing_chars": missing,
        "missing_count": len(missing),
    }


# =============================================================================
# FONT SUGGESTION — When preflight fails
# =============================================================================

def suggest_alternative_font(
    missing_chars: list,
    fonts_dir: str,
) -> Optional[str]:
    """
    When the primary font is missing glyphs, find an alternative that covers them.
    
    Args:
        missing_chars: List of characters that need coverage
        fonts_dir: Directory to search for alternative fonts
    
    Returns:
        Path to an alternative font that covers the missing chars, or None.
    """
    if not os.path.isdir(fonts_dir):
        return None
    
    for filename in sorted(os.listdir(fonts_dir)):
        if not filename.lower().endswith(('.ttf', '.otf')):
            continue
        
        filepath = os.path.join(fonts_dir, filename)
        try:
            font = pymupdf.Font(fontfile=filepath)
        except Exception:
            continue
        
        # Check if this font covers all missing chars
        all_covered = all(font.has_glyph(ord(c)) for c in missing_chars)
        if all_covered:
            return filepath
    
    return None


# =============================================================================
# HELPERS
# =============================================================================

def _get_unicode_name(char: str) -> str:
    """Get the Unicode name of a character."""
    import unicodedata
    try:
        return unicodedata.name(char)
    except ValueError:
        return f"U+{ord(char):04X}"


# =============================================================================
# CLI
# =============================================================================

def main():
    """Test glyph preflight with sample translations."""
    fonts_dir = r"C:\Users\zande\Documents\Digital Bookstore\Fonts"
    font_path = os.path.join(fonts_dir, "PlaypenSans-Regular.ttf")
    
    print("=== Glyph Preflight ===\n")
    
    # Test with normal Afrikaans text (should pass)
    translations = {
        "p01_s001": "Die vinnige bruin jakkals",
        "p01_s002": "spring oor die lui hond",
        "p02_s001": "Verantwoordelikheid en geleentheid",
    }
    
    result = preflight_document(translations, font_path)
    print(f"Test 1 (Afrikaans): {result.summary()}")
    print(f"  Coverage: {result.coverage_pct:.1f}%")
    
    # Test with characters that might be missing
    translations_with_special = {
        "p01_s001": "Normal text hier",
        "p02_s001": "Text with emoji \U0001f600 and CJK \u4e16",  # emoji + Chinese
    }
    
    result2 = preflight_document(translations_with_special, font_path)
    print(f"\nTest 2 (special chars): {result2.summary()}")
    if result2.missing_glyphs:
        for mg in result2.missing_glyphs:
            print(f"  Missing: U+{mg.codepoint:04X} ({mg.unicode_name}) - {mg.occurrence_count} occurrences")
    
    # Test font suggestion
    if result2.missing_glyphs:
        missing = [mg.char for mg in result2.missing_glyphs]
        alt = suggest_alternative_font(missing, fonts_dir)
        if alt:
            print(f"  Alternative font: {os.path.basename(alt)}")
        else:
            print(f"  No alternative font covers all missing chars")


if __name__ == "__main__":
    main()
