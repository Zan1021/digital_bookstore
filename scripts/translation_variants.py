"""
Translation Variants — Digital Bookstore V8
=============================================
Manages primary and compact translation variants for overflow handling.

When the primary translation doesn't fit in the container, the system
can fall back to a "compact" variant — a shorter version that preserves
meaning but uses fewer characters.

Strategy:
1. AI translator provides two variants per item: primary + compact
2. During rendering, try primary first
3. If primary overflows container → try compact
4. If compact also overflows → apply font shrink + flag for review

Semantic safeguards:
- Compact must preserve ALL proper nouns
- Compact must preserve numerical values
- Compact must not change meaning (just reduce verbosity)
- Compact may abbreviate common words, use shorter synonyms

Usage:
    from translation_variants import TranslationVariants, select_best_variant

    variants = TranslationVariants(
        primary="Die groot jakkals het oor die rivier gespring",
        compact="Die jakkals spring oor die rivier",
    )
    
    best = select_best_variant(variants, container_width, font_path, font_size)
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
class TranslationVariants:
    """A translation with primary and compact variants."""
    unit_id: str = ""
    primary: str = ""
    compact: str = ""
    # Metadata
    primary_char_count: int = 0
    compact_char_count: int = 0
    compression_ratio: float = 0.0  # compact_length / primary_length
    
    def __post_init__(self):
        self.primary_char_count = len(self.primary)
        self.compact_char_count = len(self.compact) if self.compact else 0
        if self.primary_char_count > 0 and self.compact_char_count > 0:
            self.compression_ratio = self.compact_char_count / self.primary_char_count

    @property
    def has_compact(self) -> bool:
        return bool(self.compact and self.compact.strip())
    
    @property
    def savings_pct(self) -> float:
        """How much shorter the compact version is (as percentage)."""
        if not self.has_compact or self.primary_char_count == 0:
            return 0.0
        return (1 - self.compression_ratio) * 100


@dataclass
class VariantSelection:
    """Result of selecting the best variant for a container."""
    selected: str               # "primary" or "compact"
    text: str                   # The selected text
    fits: bool                  # Whether it fits in the container
    font_size: float            # Size used (may be shrunk)
    shrink_applied: float       # How much shrink was needed
    overflow: bool              # True if neither variant fits
    primary_width: float = 0.0
    compact_width: float = 0.0
    container_width: float = 0.0


# =============================================================================
# VARIANT SELECTION
# =============================================================================

def select_best_variant(
    variants: TranslationVariants,
    container_width: float,
    font_path: Optional[str] = None,
    font_size: float = 12.0,
    max_shrink: float = 0.15,
    min_font_size: float = 7.0,
) -> VariantSelection:
    """
    Select the best variant that fits in the container.
    
    Priority:
    1. Primary at full size
    2. Primary with shrink (up to max_shrink)
    3. Compact at full size
    4. Compact with shrink
    5. Compact at min_size (overflow flagged)
    """
    import pymupdf
    
    if font_path and os.path.isfile(font_path):
        font = pymupdf.Font(fontfile=font_path)
    else:
        font = pymupdf.Font("helv")
    
    primary_text = variants.primary
    compact_text = variants.compact if variants.has_compact else variants.primary
    
    # Measure primary
    primary_width = font.text_length(primary_text, fontsize=font_size)
    
    # 1. Primary fits at full size
    if primary_width <= container_width:
        return VariantSelection(
            selected="primary", text=primary_text, fits=True,
            font_size=font_size, shrink_applied=0, overflow=False,
            primary_width=primary_width, container_width=container_width,
        )
    
    # 2. Primary with shrink
    min_primary_size = font_size * (1 - max_shrink)
    primary_shrunk_width = font.text_length(primary_text, fontsize=min_primary_size)
    
    if primary_shrunk_width <= container_width:
        # Binary search for best size
        best_size = _binary_search_fit(font, primary_text, container_width, min_primary_size, font_size)
        shrink = (font_size - best_size) / font_size
        return VariantSelection(
            selected="primary", text=primary_text, fits=True,
            font_size=best_size, shrink_applied=shrink, overflow=False,
            primary_width=primary_width, container_width=container_width,
        )
    
    # 3. Compact at full size
    compact_width = font.text_length(compact_text, fontsize=font_size)
    
    if compact_width <= container_width:
        return VariantSelection(
            selected="compact", text=compact_text, fits=True,
            font_size=font_size, shrink_applied=0, overflow=False,
            primary_width=primary_width, compact_width=compact_width,
            container_width=container_width,
        )
    
    # 4. Compact with shrink
    min_compact_size = max(min_font_size, font_size * (1 - max_shrink))
    compact_shrunk_width = font.text_length(compact_text, fontsize=min_compact_size)
    
    if compact_shrunk_width <= container_width:
        best_size = _binary_search_fit(font, compact_text, container_width, min_compact_size, font_size)
        shrink = (font_size - best_size) / font_size
        return VariantSelection(
            selected="compact", text=compact_text, fits=True,
            font_size=best_size, shrink_applied=shrink, overflow=False,
            primary_width=primary_width, compact_width=compact_width,
            container_width=container_width,
        )
    
    # 5. Nothing fits — use compact at min size, flag overflow
    return VariantSelection(
        selected="compact", text=compact_text, fits=False,
        font_size=min_font_size, shrink_applied=(font_size - min_font_size) / font_size,
        overflow=True,
        primary_width=primary_width, compact_width=compact_width,
        container_width=container_width,
    )


def _binary_search_fit(font, text: str, max_width: float, low: float, high: float) -> float:
    """Binary search for the largest font size where text fits in max_width."""
    best = low
    for _ in range(15):
        mid = (low + high) / 2
        w = font.text_length(text, fontsize=mid)
        if w <= max_width:
            best = mid
            low = mid
        else:
            high = mid
        if high - low < 0.1:
            break
    return best


# =============================================================================
# TRANSLATION REQUEST EXTENSION — Request variants from AI
# =============================================================================

def build_variant_prompt_extension() -> str:
    """
    Additional prompt instruction for the AI to provide compact variants.
    Append this to the standard translation system prompt.
    """
    return """

VARIANT REQUIREMENT:
For each item, provide TWO translations:
- "translation": The natural, full translation (primary)
- "compact": A shorter version that fits tighter spaces (same meaning, fewer words)

The compact version MUST:
- Preserve all proper nouns exactly
- Preserve all numbers and measurements
- Keep the same meaning and tone
- Use shorter synonyms or omit redundant words
- Be at least 15% shorter than the primary

Example:
  Source: "The big brown fox jumped quickly over the lazy sleeping dog"
  translation: "Die groot bruin jakkals het vinnig oor die lui slapende hond gespring"
  compact: "Die bruin jakkals spring oor die lui hond"

If a compact version would change the meaning, set compact to the same as translation.
"""


def validate_variants(items: list) -> dict:
    """
    Validate that translation response includes valid compact variants.
    
    Args:
        items: List of response dicts with 'id', 'translation', 'compact'
    
    Returns:
        Validation report.
    """
    total = len(items)
    has_compact = 0
    valid_compact = 0
    issues = []
    
    for item in items:
        item_id = item.get("id", "?")
        primary = item.get("translation", "")
        compact = item.get("compact", "")
        
        if compact and compact.strip():
            has_compact += 1
            
            # Check: compact should be shorter
            if len(compact) >= len(primary):
                issues.append(f"{item_id}: compact not shorter than primary")
            elif len(compact) < len(primary) * 0.5:
                issues.append(f"{item_id}: compact suspiciously short (>50% reduction)")
            else:
                valid_compact += 1
    
    return {
        "total_items": total,
        "items_with_compact": has_compact,
        "valid_compact": valid_compact,
        "issues": issues,
        "compact_coverage_pct": (has_compact / total * 100) if total > 0 else 0,
    }


# =============================================================================
# CLI
# =============================================================================

def main():
    """Test variant selection."""
    fonts_dir = r"C:\Users\zande\Documents\Digital Bookstore\Fonts"
    font_path = os.path.join(fonts_dir, "PlaypenSans-Regular.ttf")
    
    print("=== Translation Variants ===\n")
    
    # Test cases
    tests = [
        TranslationVariants(
            unit_id="test1",
            primary="Die groot jakkals het oor die rivier gespring",
            compact="Die jakkals spring oor die rivier",
        ),
        TranslationVariants(
            unit_id="test2",
            primary="verantwoordelikheid",
            compact="verantw.",
        ),
        TranslationVariants(
            unit_id="test3",
            primary="kort",
            compact="",
        ),
    ]
    
    for v in tests:
        print(f"Unit: {v.unit_id}")
        print(f"  Primary ({v.primary_char_count} chars): '{v.primary}'")
        if v.has_compact:
            print(f"  Compact ({v.compact_char_count} chars, {v.savings_pct:.0f}% shorter): '{v.compact}'")
        
        # Select for a 100pt container
        result = select_best_variant(v, container_width=100, font_path=font_path, font_size=12)
        print(f"  Selected: {result.selected} (fits={result.fits}, size={result.font_size:.1f}, overflow={result.overflow})")
        print()


if __name__ == "__main__":
    main()
