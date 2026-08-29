"""
Constraint-Based Text Fit Solver — Digital Bookstore V8
========================================================
Replaces the naive "shrink by up to 15%" approach with a proper solver
that finds the best-fit font size for translated text within a container.

The solver considers:
- Container dimensions (width × height)
- Font metrics (character widths, ascender/descender, line height)
- Word-wrap potential (how text breaks into lines)
- Minimum readable size (hard floor)
- Maximum allowed shrink from source size
- Single-line vs multi-line constraints per region type

Algorithm:
1. Try source font size first
2. If text fits → done (no shrink needed)
3. If overflow → binary search for largest size that fits
4. If can't fit at minimum size → flag for review, use minimum

Usage:
    from text_fit_solver import solve_text_fit, FitConstraints, FitResult

    constraints = FitConstraints(
        container_width=200,
        container_height=50,
        source_font_size=17.0,
        min_font_size=8.0,
        max_shrink_ratio=0.15,
        allow_multiline=True,
    )
    result = solve_text_fit("Die translated text hier", constraints, font_path="font.ttf")
"""

import os
import sys
from dataclasses import dataclass, field
from typing import Optional

import pymupdf


# =============================================================================
# DATA TYPES
# =============================================================================

@dataclass
class FitConstraints:
    """Constraints for text fitting within a container."""
    container_width: float          # Available width in points
    container_height: float         # Available height in points
    source_font_size: float         # Original font size in the source PDF
    min_font_size: float = 7.0      # Hard floor — never go below this
    max_shrink_ratio: float = 0.15  # Maximum allowed shrink (0.15 = 85% of source)
    allow_multiline: bool = True    # Whether text can wrap to multiple lines
    max_lines: int = 20             # Maximum number of lines allowed
    line_height_ratio: float = 1.3  # Line height as ratio of font size
    padding_x: float = 2.0         # Horizontal padding inside container
    padding_y: float = 2.0         # Vertical padding inside container
    alignment: str = "left"         # Text alignment (left, center, right, justify)
    # Per-region type constraints
    single_word: bool = False       # If True, don't allow word-wrap (vocabulary items)
    preserve_line_breaks: bool = False  # If True, respect \n in source


@dataclass
class FitResult:
    """Result of text fitting."""
    fits: bool                      # Whether text fits in container
    font_size: float                # Final font size to use
    lines: list                     # List of text lines after wrapping
    line_count: int                 # Number of lines
    total_height: float             # Total height of rendered text
    shrink_applied: float           # How much shrink was applied (0 = none, 0.15 = max)
    overflow: bool                  # True if text doesn't fit even at min size
    overflow_amount: float = 0.0    # How many points of overflow (negative = fits)
    strategy: str = "exact"         # "exact", "shrink", "wrap", "overflow"
    warnings: list = field(default_factory=list)

    @property
    def shrink_pct(self) -> float:
        """Shrink as percentage (e.g., 12.5%)."""
        return self.shrink_applied * 100


# =============================================================================
# MAIN SOLVER
# =============================================================================

def solve_text_fit(
    text: str,
    constraints: FitConstraints,
    font_path: Optional[str] = None,
) -> FitResult:
    """
    Find the optimal font size and line breaks for text in a container.
    
    Algorithm:
    1. Try source size — if it fits, return immediately
    2. If single_word constraint, try shrinking only (no wrap)
    3. Otherwise, try wrapping at source size first
    4. If wrapping overflows height, binary search for best size
    5. If min size still overflows, flag overflow
    """
    # Load font for measurement
    if font_path and os.path.isfile(font_path):
        font = pymupdf.Font(fontfile=font_path)
    else:
        font = pymupdf.Font("helv")
    
    # Effective container dimensions (minus padding)
    eff_width = constraints.container_width - (constraints.padding_x * 2)
    eff_height = constraints.container_height - (constraints.padding_y * 2)
    
    if eff_width <= 0 or eff_height <= 0:
        return FitResult(
            fits=False, font_size=constraints.min_font_size,
            lines=[text], line_count=1, total_height=0,
            shrink_applied=0, overflow=True, strategy="error",
            warnings=["Container too small (negative effective dimensions)"],
        )
    
    source_size = constraints.source_font_size
    min_size = max(constraints.min_font_size, source_size * (1 - constraints.max_shrink_ratio))
    
    # === STRATEGY 1: Try source size, single line ===
    text_width = font.text_length(text, fontsize=source_size)
    line_height = source_size * constraints.line_height_ratio
    
    if text_width <= eff_width and line_height <= eff_height:
        # Fits perfectly at source size, single line
        return FitResult(
            fits=True, font_size=source_size,
            lines=[text], line_count=1, total_height=line_height,
            shrink_applied=0.0, overflow=False, strategy="exact",
        )
    
    # === STRATEGY 2: Single-word mode (no wrap allowed) ===
    if constraints.single_word or not constraints.allow_multiline:
        # Binary search for largest font that fits in one line
        result = _binary_search_single_line(text, font, eff_width, eff_height, source_size, min_size, constraints)
        return result
    
    # === STRATEGY 3: Try word-wrap at source size ===
    lines = _word_wrap(text, font, source_size, eff_width, constraints)
    total_height = len(lines) * line_height
    
    if total_height <= eff_height and len(lines) <= constraints.max_lines:
        # Fits with wrapping at source size
        return FitResult(
            fits=True, font_size=source_size,
            lines=lines, line_count=len(lines), total_height=total_height,
            shrink_applied=0.0, overflow=False, strategy="wrap",
        )
    
    # === STRATEGY 4: Binary search for best font size with wrapping ===
    result = _binary_search_multiline(text, font, eff_width, eff_height, source_size, min_size, constraints)
    return result


# =============================================================================
# BINARY SEARCH — Single line
# =============================================================================

def _binary_search_single_line(
    text: str,
    font: pymupdf.Font,
    eff_width: float,
    eff_height: float,
    source_size: float,
    min_size: float,
    constraints: FitConstraints,
) -> FitResult:
    """Find the largest font size that fits text in a single line."""
    low = min_size
    high = source_size
    best_size = min_size
    
    # Quick check: does it fit at min size?
    min_width = font.text_length(text, fontsize=min_size)
    min_height = min_size * constraints.line_height_ratio
    
    if min_width > eff_width or min_height > eff_height:
        # Doesn't fit even at minimum — overflow
        return FitResult(
            fits=False, font_size=min_size,
            lines=[text], line_count=1,
            total_height=min_height,
            shrink_applied=(source_size - min_size) / source_size,
            overflow=True,
            overflow_amount=min_width - eff_width,
            strategy="overflow",
            warnings=[f"Text overflows by {min_width - eff_width:.1f}pt at min size {min_size}pt"],
        )
    
    # Binary search
    iterations = 0
    while high - low > 0.25 and iterations < 20:
        mid = (low + high) / 2
        w = font.text_length(text, fontsize=mid)
        h = mid * constraints.line_height_ratio
        
        if w <= eff_width and h <= eff_height:
            best_size = mid
            low = mid
        else:
            high = mid
        iterations += 1
    
    shrink = (source_size - best_size) / source_size
    return FitResult(
        fits=True, font_size=best_size,
        lines=[text], line_count=1,
        total_height=best_size * constraints.line_height_ratio,
        shrink_applied=shrink,
        overflow=False,
        strategy="shrink",
    )


# =============================================================================
# BINARY SEARCH — Multi-line with word wrap
# =============================================================================

def _binary_search_multiline(
    text: str,
    font: pymupdf.Font,
    eff_width: float,
    eff_height: float,
    source_size: float,
    min_size: float,
    constraints: FitConstraints,
) -> FitResult:
    """Find the largest font size where wrapped text fits in the container."""
    low = min_size
    high = source_size
    best_size = min_size
    best_lines = [text]
    
    # Quick check at minimum size
    min_lines = _word_wrap(text, font, min_size, eff_width, constraints)
    min_line_height = min_size * constraints.line_height_ratio
    min_total_height = len(min_lines) * min_line_height
    
    if min_total_height > eff_height or len(min_lines) > constraints.max_lines:
        # Doesn't fit even at minimum with wrapping
        return FitResult(
            fits=False, font_size=min_size,
            lines=min_lines, line_count=len(min_lines),
            total_height=min_total_height,
            shrink_applied=(source_size - min_size) / source_size,
            overflow=True,
            overflow_amount=min_total_height - eff_height,
            strategy="overflow",
            warnings=[f"Text overflows height by {min_total_height - eff_height:.1f}pt at min size"],
        )
    
    # Binary search
    iterations = 0
    while high - low > 0.25 and iterations < 20:
        mid = (low + high) / 2
        lines = _word_wrap(text, font, mid, eff_width, constraints)
        line_height = mid * constraints.line_height_ratio
        total_height = len(lines) * line_height
        
        if total_height <= eff_height and len(lines) <= constraints.max_lines:
            best_size = mid
            best_lines = lines
            low = mid
        else:
            high = mid
        iterations += 1
    
    # Final wrap at best size
    final_lines = _word_wrap(text, font, best_size, eff_width, constraints)
    final_height = len(final_lines) * best_size * constraints.line_height_ratio
    shrink = (source_size - best_size) / source_size
    
    return FitResult(
        fits=True, font_size=best_size,
        lines=final_lines, line_count=len(final_lines),
        total_height=final_height,
        shrink_applied=shrink,
        overflow=False,
        strategy="shrink" if shrink > 0.01 else "wrap",
    )


# =============================================================================
# WORD WRAP
# =============================================================================

def _word_wrap(
    text: str,
    font: pymupdf.Font,
    font_size: float,
    max_width: float,
    constraints: FitConstraints,
) -> list:
    """
    Word-wrap text to fit within max_width at the given font size.
    
    Handles:
    - Explicit line breaks (\\n) if preserve_line_breaks is set
    - Hyphenation points (future)
    - Long words that can't be broken (overflow on that line)
    """
    if constraints.preserve_line_breaks:
        # Wrap each explicit line independently
        explicit_lines = text.split('\n')
        result = []
        for line in explicit_lines:
            wrapped = _wrap_single_paragraph(line.strip(), font, font_size, max_width)
            result.extend(wrapped)
        return result
    
    return _wrap_single_paragraph(text, font, font_size, max_width)


def _wrap_single_paragraph(text: str, font: pymupdf.Font, font_size: float, max_width: float) -> list:
    """Wrap a single paragraph of text. Uses hyphenation for long words."""
    words = text.split()
    if not words:
        return [""]
    
    # Import hyphenation (lazy — only when needed)
    try:
        from international_text import hyphenate_for_wrapping
        has_hyphenation = True
    except ImportError:
        has_hyphenation = False
    
    lines = []
    current_line = ""
    
    for word in words:
        if not current_line:
            # First word on line — check if it fits
            word_width = font.text_length(word, fontsize=font_size)
            if word_width <= max_width:
                current_line = word
            elif has_hyphenation and len(word) > 5:
                # Word too long for line — try hyphenation
                hyph_result = hyphenate_for_wrapping(
                    word, max_width, font.name, font_size, language="af"
                )
                if hyph_result:
                    first_part, remaining = hyph_result
                    current_line = first_part
                    # remaining goes to next iteration (push back)
                    words.insert(words.index(word) + 1, remaining) if remaining else None
                else:
                    current_line = word  # Can't break — just overflow
            else:
                current_line = word  # Can't break — just overflow
        else:
            test_line = f"{current_line} {word}"
            test_width = font.text_length(test_line, fontsize=font_size)
            
            if test_width <= max_width:
                current_line = test_line
            else:
                # Line is full, start new line
                lines.append(current_line)
                current_line = word
    
    if current_line:
        lines.append(current_line)
    
    return lines if lines else [""]


# =============================================================================
# BATCH SOLVER — Solve for multiple units at once (for consistent sizing)
# =============================================================================

def solve_batch(
    items: list,
    font_path: Optional[str] = None,
    force_consistent: bool = False,
) -> list:
    """
    Solve text fitting for a batch of items, optionally forcing consistent
    font size across all items (e.g., all words in a vocabulary column).
    
    Args:
        items: List of dicts with 'text', 'constraints' (FitConstraints)
        font_path: Path to font file
        force_consistent: If True, all items use the smallest font that fits all
    
    Returns:
        List of FitResult objects (same order as input)
    """
    results = []
    for item in items:
        result = solve_text_fit(item["text"], item["constraints"], font_path)
        results.append(result)
    
    if force_consistent and results:
        # Find the smallest font size that was needed
        min_font = min(r.font_size for r in results)
        
        # Re-solve all items at the minimum size
        consistent_results = []
        for item in items:
            # Override source size to the consistent minimum
            c = item["constraints"]
            forced_constraints = FitConstraints(
                container_width=c.container_width,
                container_height=c.container_height,
                source_font_size=min_font,
                min_font_size=c.min_font_size,
                max_shrink_ratio=0,  # No further shrink — this IS the size
                allow_multiline=c.allow_multiline,
                max_lines=c.max_lines,
                line_height_ratio=c.line_height_ratio,
                padding_x=c.padding_x,
                padding_y=c.padding_y,
                single_word=c.single_word,
            )
            result = solve_text_fit(item["text"], forced_constraints, font_path)
            result.font_size = min_font  # Ensure consistent
            consistent_results.append(result)
        
        return consistent_results
    
    return results


# =============================================================================
# CONVENIENCE — Quick single-item solve
# =============================================================================

def quick_fit(
    text: str,
    width: float,
    height: float,
    source_size: float,
    font_path: Optional[str] = None,
    single_word: bool = False,
) -> FitResult:
    """Quick convenience function for simple text fitting."""
    constraints = FitConstraints(
        container_width=width,
        container_height=height,
        source_font_size=source_size,
        single_word=single_word,
        allow_multiline=not single_word,
    )
    return solve_text_fit(text, constraints, font_path)


# =============================================================================
# CLI — Testing
# =============================================================================

def main():
    """Test the solver with sample inputs."""
    import json
    
    print("=== Text Fit Solver Tests ===\n")
    
    # Test 1: Short text that fits
    r = quick_fit("Hello", 200, 30, 17.0)
    print(f"Test 1 (short): fits={r.fits}, size={r.font_size:.1f}, strategy={r.strategy}")
    
    # Test 2: Long text needing wrap
    r = quick_fit("This is a much longer sentence that will need to wrap to multiple lines", 200, 100, 17.0)
    print(f"Test 2 (wrap): fits={r.fits}, size={r.font_size:.1f}, lines={r.line_count}, strategy={r.strategy}")
    
    # Test 3: Single word, tight container
    r = quick_fit("Verantwoordelikheid", 72, 20, 12.0, single_word=True)
    print(f"Test 3 (tight word): fits={r.fits}, size={r.font_size:.1f}, shrink={r.shrink_pct:.1f}%, strategy={r.strategy}")
    
    # Test 4: Overflow
    r = quick_fit("Onafhanklikheidsverklaring", 50, 15, 12.0, single_word=True)
    print(f"Test 4 (overflow): fits={r.fits}, size={r.font_size:.1f}, overflow={r.overflow}, strategy={r.strategy}")
    
    # Test 5: Batch with consistency
    items = [
        {"text": "huis", "constraints": FitConstraints(container_width=72, container_height=20, source_font_size=12, single_word=True)},
        {"text": "verantwoordelik", "constraints": FitConstraints(container_width=72, container_height=20, source_font_size=12, single_word=True)},
        {"text": "kat", "constraints": FitConstraints(container_width=72, container_height=20, source_font_size=12, single_word=True)},
    ]
    results = solve_batch(items, force_consistent=True)
    print(f"\nTest 5 (batch consistent):")
    for item, r in zip(items, results):
        print(f"  '{item['text']}': size={r.font_size:.1f}, fits={r.fits}")


if __name__ == "__main__":
    main()
