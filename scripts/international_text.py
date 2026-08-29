"""
International Text Processing — Digital Bookstore V8
=====================================================
Comprehensive text handling for multi-language, multi-script rendering.

Covers Stage 4 items 29-34, 36:
- HarfBuzz shaping for accurate measurement (Item 29)
- Unicode text segmentation / boundaries (Item 30)
- Bidirectional text processing (Item 31)
- Complex script support (Item 32)
- Vertical text support (Item 33)
- Script-aware font fallback (Item 34)
- Locale-specific hyphenation (Item 36)

Usage:
    from international_text import (
        measure_shaped_width,
        segment_text,
        detect_script,
        hyphenate,
        BiDiProcessor,
    )
"""

import os
import re
import sys
import unicodedata
from dataclasses import dataclass, field
from typing import Optional
from enum import Enum

SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPTS_DIR)


# =============================================================================
# ITEM 29: HARFBUZZ SHAPING FOR MEASUREMENT
# =============================================================================

def measure_shaped_width(
    text: str,
    font_path: str,
    font_size: float,
    language: str = "en",
    script: str = "Latn",
) -> float:
    """
    Measure text width using HarfBuzz shaping (accurate glyph placement).
    
    Unlike pymupdf.Font.text_length() which uses raw advance widths,
    HarfBuzz applies proper shaping rules (kerning, ligatures, contextual
    alternates) for accurate measurement.
    
    Falls back to pymupdf if HarfBuzz fails.
    """
    try:
        import uharfbuzz as hb
        
        # Load font blob
        with open(font_path, 'rb') as f:
            font_data = f.read()
        
        blob = hb.Blob(font_data)
        face = hb.Face(blob)
        font = hb.Font(face)
        
        # Scale to font size (HarfBuzz works in font units, typically 1000 or 2048 per em)
        upem = face.upem
        font.scale = (upem, upem)
        
        # Create buffer
        buf = hb.Buffer()
        buf.add_str(text)
        buf.guess_segment_properties()
        
        # Override language/script if specified
        if language:
            buf.language = hb.Language.from_string(language)
        if script:
            buf.script = hb.Script.from_string(script)
        
        # Shape
        hb.shape(font, buf)
        
        # Calculate total advance width
        positions = buf.glyph_positions
        total_advance = sum(pos.x_advance for pos in positions)
        
        # Convert from font units to points
        width_pt = total_advance * font_size / upem
        
        return width_pt
        
    except Exception:
        # Fallback to pymupdf measurement
        import pymupdf
        try:
            f = pymupdf.Font(fontfile=font_path)
            return f.text_length(text, fontsize=font_size)
        except Exception:
            # Last resort: estimate from character count
            return len(text) * font_size * 0.5


def shape_text(
    text: str,
    font_path: str,
    language: str = "en",
    script: str = "Latn",
) -> list:
    """
    Shape text and return glyph information (IDs, positions, clusters).
    Useful for detailed rendering and glyph-level operations.
    
    Returns list of dicts: [{glyph_id, x_advance, y_advance, x_offset, y_offset, cluster}, ...]
    """
    try:
        import uharfbuzz as hb
        
        with open(font_path, 'rb') as f:
            font_data = f.read()
        
        blob = hb.Blob(font_data)
        face = hb.Face(blob)
        font = hb.Font(face)
        font.scale = (face.upem, face.upem)
        
        buf = hb.Buffer()
        buf.add_str(text)
        buf.guess_segment_properties()
        
        if language:
            buf.language = hb.Language.from_string(language)
        if script:
            buf.script = hb.Script.from_string(script)
        
        hb.shape(font, buf)
        
        glyphs = []
        for info, pos in zip(buf.glyph_infos, buf.glyph_positions):
            glyphs.append({
                "glyph_id": info.codepoint,
                "cluster": info.cluster,
                "x_advance": pos.x_advance,
                "y_advance": pos.y_advance,
                "x_offset": pos.x_offset,
                "y_offset": pos.y_offset,
            })
        
        return glyphs
        
    except Exception:
        return []


# =============================================================================
# ITEM 30: UNICODE TEXT SEGMENTATION (ICU-compatible)
# =============================================================================

class BreakType(str, Enum):
    WORD = "word"
    LINE = "line"
    SENTENCE = "sentence"
    GRAPHEME = "grapheme"


def segment_text(text: str, break_type: BreakType = BreakType.WORD) -> list:
    """
    Segment text at Unicode-appropriate boundaries.
    
    Implements UAX #29 (word/sentence) and UAX #14 (line break) rules
    in a simplified but correct form for common scripts.
    
    Returns list of segments (strings).
    """
    if break_type == BreakType.WORD:
        return _segment_words(text)
    elif break_type == BreakType.LINE:
        return _segment_line_breaks(text)
    elif break_type == BreakType.SENTENCE:
        return _segment_sentences(text)
    elif break_type == BreakType.GRAPHEME:
        return _segment_graphemes(text)
    return [text]


def _segment_words(text: str) -> list:
    """Unicode-aware word segmentation."""
    # UAX #29 simplified: split on word boundaries
    # Handles: contractions, numbers, CJK characters
    segments = []
    current = ""
    
    for char in text:
        cat = unicodedata.category(char)
        
        if cat.startswith('Z') or cat == 'Cc':
            # Whitespace / control
            if current:
                segments.append(current)
                current = ""
            segments.append(char)
        elif cat.startswith('P') and char not in ("'", "\u2019", "-"):
            # Punctuation (except apostrophes and hyphens within words)
            if current:
                segments.append(current)
                current = ""
            segments.append(char)
        else:
            current += char
    
    if current:
        segments.append(current)
    
    return segments


def _segment_line_breaks(text: str) -> list:
    """
    Find valid line break opportunities in text.
    Returns list of segments where breaks are allowed AFTER each segment.
    
    Simplified UAX #14:
    - Break after spaces
    - Break after hyphens
    - Break before CJK characters
    - Don't break inside words (for Latin script)
    """
    # Split on whitespace, keeping the space as part of the preceding segment
    segments = []
    current = ""
    
    for i, char in enumerate(text):
        current += char
        
        # Break opportunity after space
        if char == ' ':
            segments.append(current)
            current = ""
        # Break opportunity after hyphen (if not at start)
        elif char == '-' and len(current) > 1:
            segments.append(current)
            current = ""
        # CJK characters: break before and after
        elif _is_cjk(char):
            if len(current) > 1:
                segments.append(current[:-1])
                current = char
    
    if current:
        segments.append(current)
    
    return segments


def _segment_sentences(text: str) -> list:
    """Simple sentence segmentation."""
    # Split on sentence-ending punctuation followed by space + uppercase
    sentences = re.split(r'(?<=[.!?])\s+(?=[A-Z])', text)
    return sentences


def _segment_graphemes(text: str) -> list:
    """
    Split text into grapheme clusters (what users perceive as characters).
    Handles combining marks, emoji sequences, etc.
    """
    graphemes = []
    current = ""
    
    for char in text:
        cat = unicodedata.category(char)
        # Combining marks attach to previous character
        if cat.startswith('M') and current:
            current += char
        else:
            if current:
                graphemes.append(current)
            current = char
    
    if current:
        graphemes.append(current)
    
    return graphemes


# =============================================================================
# ITEM 31: BIDIRECTIONAL TEXT (BiDi)
# =============================================================================

class TextDirection(str, Enum):
    LTR = "ltr"
    RTL = "rtl"
    AUTO = "auto"


@dataclass
class BiDiRun:
    """A run of text with a single direction."""
    text: str
    direction: str  # "ltr" or "rtl"
    start_index: int
    end_index: int
    embedding_level: int = 0


class BiDiProcessor:
    """
    Simplified Unicode Bidirectional Algorithm (UAX #9).
    
    For South African languages this is rarely needed (all LTR),
    but essential for Arabic, Hebrew, Urdu, etc.
    """
    
    @staticmethod
    def detect_paragraph_direction(text: str) -> str:
        """Detect the base direction of a paragraph (first strong char)."""
        for char in text:
            if _is_rtl_char(char):
                return "rtl"
            elif _is_ltr_char(char):
                return "ltr"
        return "ltr"  # Default
    
    @staticmethod
    def get_runs(text: str) -> list:
        """
        Split text into directional runs.
        Each run has a consistent direction.
        """
        if not text:
            return []
        
        runs = []
        current_text = ""
        current_dir = None
        start_idx = 0
        
        for i, char in enumerate(text):
            char_dir = _get_char_direction(char)
            
            if char_dir == "neutral":
                # Neutral characters inherit surrounding direction
                current_text += char
                continue
            
            if current_dir is None:
                current_dir = char_dir
                current_text = char
                start_idx = i
            elif char_dir != current_dir:
                # Direction change — new run
                runs.append(BiDiRun(
                    text=current_text,
                    direction=current_dir,
                    start_index=start_idx,
                    end_index=i,
                ))
                current_text = char
                current_dir = char_dir
                start_idx = i
            else:
                current_text += char
        
        if current_text:
            runs.append(BiDiRun(
                text=current_text,
                direction=current_dir or "ltr",
                start_index=start_idx,
                end_index=len(text),
            ))
        
        return runs
    
    @staticmethod
    def reorder_for_display(runs: list, base_direction: str = "ltr") -> str:
        """
        Reorder runs for visual display.
        In LTR base, RTL runs are reversed. In RTL base, LTR runs are reversed.
        """
        if base_direction == "ltr":
            result = ""
            for run in runs:
                if run.direction == "rtl":
                    result += run.text[::-1]
                else:
                    result += run.text
            return result
        else:
            # RTL base: reverse overall order, keep LTR runs forward
            reversed_runs = list(reversed(runs))
            result = ""
            for run in reversed_runs:
                if run.direction == "ltr":
                    result += run.text
                else:
                    result += run.text[::-1]
            return result


# =============================================================================
# ITEM 32: SCRIPT DETECTION
# =============================================================================

def detect_script(text: str) -> str:
    """
    Detect the primary script of text.
    Returns ISO 15924 script code (Latn, Arab, Deva, Hans, etc.)
    """
    script_counts = {}
    
    for char in text:
        if char.isspace() or unicodedata.category(char).startswith('P'):
            continue
        
        script = _get_script(char)
        script_counts[script] = script_counts.get(script, 0) + 1
    
    if not script_counts:
        return "Latn"  # Default
    
    return max(script_counts, key=script_counts.get)


def detect_complex_script(text: str) -> dict:
    """
    Detect if text contains complex scripts requiring special handling.
    
    Returns dict with:
    - primary_script: The dominant script
    - is_complex: Whether it needs special shaping
    - requires_bidi: Whether it has RTL content
    - requires_vertical: Whether it may need vertical layout
    """
    script = detect_script(text)
    
    complex_scripts = {"Arab", "Hebr", "Deva", "Beng", "Guru", "Gujr", 
                      "Orya", "Taml", "Telu", "Knda", "Mlym", "Sinh",
                      "Thai", "Laoo", "Tibt", "Mymr", "Khmr"}
    rtl_scripts = {"Arab", "Hebr", "Syrc", "Thaa", "Mand", "Nkoo"}
    vertical_scripts = {"Hans", "Hant", "Jpan", "Kore", "Mong"}
    
    return {
        "primary_script": script,
        "is_complex": script in complex_scripts,
        "requires_bidi": script in rtl_scripts,
        "requires_vertical": script in vertical_scripts,
        "requires_shaping": script in complex_scripts or script in rtl_scripts,
    }


# =============================================================================
# ITEM 33: VERTICAL TEXT SUPPORT
# =============================================================================

@dataclass
class VerticalTextLayout:
    """Layout information for vertical text rendering."""
    text: str
    direction: str = "ttb"  # top-to-bottom
    char_positions: list = field(default_factory=list)  # [(x, y), ...]
    total_height: float = 0.0
    char_width: float = 0.0
    
    @staticmethod
    def layout_vertical(
        text: str,
        font_size: float,
        x_position: float,
        y_start: float,
        line_spacing: float = 1.5,
    ) -> 'VerticalTextLayout':
        """
        Layout text vertically (top-to-bottom, right-to-left for CJK).
        Each character is placed below the previous.
        """
        positions = []
        y = y_start
        char_height = font_size * line_spacing
        
        for char in text:
            positions.append((x_position, y))
            y += char_height
        
        return VerticalTextLayout(
            text=text,
            direction="ttb",
            char_positions=positions,
            total_height=y - y_start,
            char_width=font_size,
        )


# =============================================================================
# ITEM 34: SCRIPT-AWARE FONT FALLBACK
# =============================================================================

@dataclass
class FontFallbackChain:
    """A chain of fonts for multi-script rendering."""
    primary_font: str
    fallback_fonts: list = field(default_factory=list)  # [(script, font_path), ...]
    
    def get_font_for_char(self, char: str) -> str:
        """Get the appropriate font for a character."""
        script = _get_script(char)
        
        # Check fallbacks first
        for fb_script, fb_path in self.fallback_fonts:
            if script == fb_script:
                return fb_path
        
        # Default to primary
        return self.primary_font
    
    def split_by_font(self, text: str) -> list:
        """
        Split text into runs by font assignment.
        Returns [(font_path, text_segment), ...]
        """
        if not text:
            return []
        
        runs = []
        current_font = self.get_font_for_char(text[0])
        current_text = ""
        
        for char in text:
            font = self.get_font_for_char(char)
            if font == current_font:
                current_text += char
            else:
                if current_text:
                    runs.append((current_font, current_text))
                current_font = font
                current_text = char
        
        if current_text:
            runs.append((current_font, current_text))
        
        return runs


# Default fallback chains for SA context
SA_FALLBACK_CHAIN = FontFallbackChain(
    primary_font="",  # Set at runtime
    fallback_fonts=[
        # No RTL or CJK needed for SA languages
        # But keep structure for future international expansion
    ]
)


# =============================================================================
# ITEM 36: LOCALE-SPECIFIC HYPHENATION
# =============================================================================

# Afrikaans hyphenation rules (simplified Knuth-Liang patterns)
# These are common break points in Afrikaans
AF_PREFIXES = [
    "aan", "af", "be", "by", "ge", "her", "in", "mis", "om", "on",
    "ont", "oor", "op", "uit", "ver", "voor", "weer",
]
AF_SUFFIXES = [
    "heid", "ing", "lik", "baar", "loos", "agtig", "erig",
    "skap", "dom", "nis", "sel", "ling", "sie", "asie",
]

# Minimum characters before/after hyphen
MIN_BEFORE = 2
MIN_AFTER = 3


def hyphenate(word: str, language: str = "af", min_word_length: int = 5) -> list:
    """
    Find hyphenation points in a word.
    
    Returns list of syllables (word split at valid break points).
    E.g., "verantwoordelikheid" → ["ver", "ant", "woor", "de", "lik", "heid"]
    
    Uses language-specific rules:
    - af: Afrikaans syllable rules
    - en: English basic rules
    - default: Consonant-vowel boundary detection
    """
    if len(word) < min_word_length:
        return [word]
    
    if language == "af":
        return _hyphenate_afrikaans(word)
    elif language == "en":
        return _hyphenate_english(word)
    else:
        return _hyphenate_generic(word)


def _hyphenate_afrikaans(word: str) -> list:
    """
    Afrikaans hyphenation using prefix/suffix stripping and
    consonant-vowel boundary rules.
    
    Rules:
    1. Strip known prefixes (ver-, be-, ge-, ont-, etc.)
    2. Strip known suffixes (-heid, -ing, -lik, etc.)
    3. Split remaining at vowel-consonant boundaries
    4. Minimum 2 chars before, 3 after each break
    """
    lower = word.lower()
    syllables = []
    
    # Try prefix stripping
    prefix = ""
    remaining = lower
    for p in sorted(AF_PREFIXES, key=len, reverse=True):
        if remaining.startswith(p) and len(remaining) > len(p) + MIN_AFTER:
            prefix = word[:len(p)]
            remaining = lower[len(p):]
            word_remaining = word[len(p):]
            syllables.append(prefix)
            break
    
    if not syllables:
        word_remaining = word
        remaining = lower
    
    # Try suffix stripping
    suffix = ""
    for s in sorted(AF_SUFFIXES, key=len, reverse=True):
        if remaining.endswith(s) and len(remaining) > len(s) + MIN_BEFORE:
            suffix = word_remaining[-(len(s)):]
            remaining = remaining[:-len(s)]
            word_remaining = word_remaining[:-len(s)]
            break
    
    # Split middle part at vowel-consonant boundaries
    middle_parts = _split_at_vc_boundaries(word_remaining, remaining)
    syllables.extend(middle_parts)
    
    if suffix:
        syllables.append(suffix)
    
    # Validate: merge any fragments shorter than MIN_BEFORE
    validated = []
    for part in syllables:
        if validated and len(part) < MIN_BEFORE:
            validated[-1] += part
        else:
            validated.append(part)
    
    return validated if len(validated) > 1 else [word]


def _hyphenate_english(word: str) -> list:
    """Basic English hyphenation at consonant-vowel boundaries."""
    return _split_at_vc_boundaries(word, word.lower())


def _hyphenate_generic(word: str) -> list:
    """Generic hyphenation for unknown languages."""
    return _split_at_vc_boundaries(word, word.lower())


def _split_at_vc_boundaries(word: str, lower: str) -> list:
    """
    Split a word at vowel-consonant boundaries.
    Break BETWEEN a vowel and following consonant (V|C pattern).
    """
    vowels = set("aeiouyAEIOUYàáâãäèéêëìíîïòóôõöùúûü")
    
    if len(word) < MIN_BEFORE + MIN_AFTER:
        return [word]
    
    parts = []
    current = ""
    
    for i, char in enumerate(word):
        current += char
        
        # Check for valid break point
        if (i >= MIN_BEFORE - 1 and 
            i < len(word) - MIN_AFTER and
            len(current) >= MIN_BEFORE):
            
            # Break between vowel and consonant
            if (lower[i] in vowels and 
                i + 1 < len(lower) and 
                lower[i + 1] not in vowels):
                parts.append(current)
                current = ""
            # Also break between two consonants if preceded by vowel
            elif (i >= 2 and 
                  lower[i - 1] in vowels and 
                  lower[i] not in vowels and
                  i + 1 < len(lower) and 
                  lower[i + 1] not in vowels):
                parts.append(current)
                current = ""
    
    if current:
        if parts and len(current) < MIN_AFTER:
            parts[-1] += current
        else:
            parts.append(current)
    
    return parts if parts else [word]


def hyphenate_for_wrapping(
    word: str,
    max_width: float,
    font_path: str,
    font_size: float,
    language: str = "af",
) -> Optional[tuple]:
    """
    Find the best hyphenation point to fit a word in max_width.
    
    Returns (first_part + "-", remaining) or None if no valid break.
    """
    syllables = hyphenate(word, language)
    
    if len(syllables) <= 1:
        return None
    
    # Try progressively longer first parts
    import pymupdf
    try:
        font = pymupdf.Font(fontfile=font_path)
    except Exception:
        font = pymupdf.Font("helv")
    
    best_split = None
    for i in range(1, len(syllables)):
        first_part = "".join(syllables[:i]) + "-"
        width = font.text_length(first_part, fontsize=font_size)
        
        if width <= max_width:
            remaining = "".join(syllables[i:])
            best_split = (first_part, remaining)
        else:
            break
    
    return best_split


# =============================================================================
# HELPER FUNCTIONS
# =============================================================================

def _is_rtl_char(char: str) -> bool:
    """Check if a character is RTL (Arabic, Hebrew, etc.)."""
    try:
        bidi = unicodedata.bidirectional(char)
        return bidi in ('R', 'AL', 'AN')
    except Exception:
        return False


def _is_ltr_char(char: str) -> bool:
    """Check if a character is strongly LTR."""
    try:
        bidi = unicodedata.bidirectional(char)
        return bidi == 'L'
    except Exception:
        return False


def _get_char_direction(char: str) -> str:
    """Get the direction of a character."""
    if _is_rtl_char(char):
        return "rtl"
    elif _is_ltr_char(char):
        return "ltr"
    return "neutral"


def _is_cjk(char: str) -> bool:
    """Check if a character is CJK."""
    cp = ord(char)
    return (0x4E00 <= cp <= 0x9FFF or      # CJK Unified
            0x3400 <= cp <= 0x4DBF or      # CJK Extension A
            0xF900 <= cp <= 0xFAFF or      # CJK Compat
            0x3000 <= cp <= 0x303F)        # CJK Symbols


def _get_script(char: str) -> str:
    """Get the script of a character (simplified)."""
    cp = ord(char)
    
    # Latin
    if (0x0041 <= cp <= 0x024F or 0x1E00 <= cp <= 0x1EFF):
        return "Latn"
    # Arabic
    if 0x0600 <= cp <= 0x06FF or 0x0750 <= cp <= 0x077F:
        return "Arab"
    # Hebrew
    if 0x0590 <= cp <= 0x05FF:
        return "Hebr"
    # Devanagari
    if 0x0900 <= cp <= 0x097F:
        return "Deva"
    # CJK
    if 0x4E00 <= cp <= 0x9FFF or 0x3400 <= cp <= 0x4DBF:
        return "Hans"
    # Greek
    if 0x0370 <= cp <= 0x03FF:
        return "Grek"
    # Cyrillic
    if 0x0400 <= cp <= 0x04FF:
        return "Cyrl"
    # Thai
    if 0x0E00 <= cp <= 0x0E7F:
        return "Thai"
    
    return "Zyyy"  # Common/Unknown


# =============================================================================
# CLI — Test
# =============================================================================

def main():
    """Test international text features."""
    print("=== International Text Processing ===\n")
    
    # Test HarfBuzz measurement
    fonts_dir = r"C:\Users\zande\Documents\Digital Bookstore\Fonts"
    font_path = os.path.join(fonts_dir, "PlaypenSans-Regular.ttf")
    
    text = "Verantwoordelikheid"
    if os.path.isfile(font_path):
        width_hb = measure_shaped_width(text, font_path, 17.0, language="af")
        
        import pymupdf
        font = pymupdf.Font(fontfile=font_path)
        width_pm = font.text_length(text, fontsize=17.0)
        
        diff = abs(width_hb - width_pm)
        print(f"Text: '{text}'")
        print(f"  HarfBuzz width: {width_hb:.2f}pt")
        print(f"  PyMuPDF width:  {width_pm:.2f}pt")
        print(f"  Difference: {diff:.2f}pt ({diff/width_pm*100:.1f}%)")
    
    # Test hyphenation
    print(f"\n--- Hyphenation ---")
    test_words = ["verantwoordelikheid", "onafhanklik", "Suid-Afrika", "huisvrou", "geleentheid"]
    for word in test_words:
        syllables = hyphenate(word, language="af")
        print(f"  {word} -> {'-'.join(syllables)}")
    
    # Test script detection
    print(f"\n--- Script Detection ---")
    tests = [
        ("Die kat sit op die mat", "Afrikaans"),
        ("The quick brown fox", "English"),
    ]
    for text, label in tests:
        script = detect_script(text)
        complexity = detect_complex_script(text)
        print(f"  {label}: script={script}, complex={complexity['is_complex']}")
    
    # Test BiDi
    print(f"\n--- BiDi ---")
    bidi = BiDiProcessor()
    ltr_text = "Hello World"
    print(f"  '{ltr_text}' -> dir={bidi.detect_paragraph_direction(ltr_text)}")
    
    # Test word segmentation
    print(f"\n--- Segmentation ---")
    text = "Die vinnige bruin jakkals spring."
    words = segment_text(text, BreakType.WORD)
    print(f"  Words: {[w for w in words if w.strip()]}")
    
    print("\nDone.")


if __name__ == "__main__":
    main()
