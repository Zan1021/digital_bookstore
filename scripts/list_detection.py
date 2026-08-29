"""
Semantic List Detection — Digital Bookstore V8
================================================
Detects bulleted lists, numbered lists, and indented items in PDF text
so they can be preserved as structured items during translation.

Without this, list items get merged into paragraphs (bad for vocabulary
pages, back cover title lists, and educational content).

Detection signals:
- Bullet characters (•, -, *, ○, ▪, ►)
- Numbering patterns (1., 2., a), b), i., ii.)
- Consistent indentation with short lines
- Repeated vertical spacing pattern
- Short lines of similar length aligned at same X

Usage:
    from list_detection import detect_lists
    lists = detect_lists(text_units, page_type)
"""

import re
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
class ListItem:
    """A single detected list item."""
    index: int
    text: str
    unit_id: str = ""
    bullet_char: str = ""       # The bullet/number prefix (if any)
    content_text: str = ""      # Text without the bullet prefix
    indent_level: int = 0       # Nesting depth (0 = top level)
    bbox: tuple = (0, 0, 0, 0)


@dataclass
class DetectedList:
    """A detected semantic list."""
    id: str
    list_type: str              # "bulleted", "numbered", "indented", "title_list"
    items: list                 # [ListItem]
    confidence: float = 0.0
    bbox: tuple = (0, 0, 0, 0)  # Bounding box of entire list
    heading: Optional[str] = None  # List heading/title if detected
    metadata: dict = field(default_factory=dict)

    @property
    def item_count(self) -> int:
        return len(self.items)


# =============================================================================
# BULLET PATTERNS
# =============================================================================

BULLET_CHARS = set('•·●○▪▸►▻‣⁃–—-*')

NUMBERED_PATTERNS = [
    re.compile(r'^(\d{1,3})[.)]\s+(.+)'),           # 1. or 1) 
    re.compile(r'^([a-z])[.)]\s+(.+)'),              # a. or a)
    re.compile(r'^([ivxlc]{1,5})[.)]\s+(.+)', re.I), # i. or iv)
    re.compile(r'^(\d{1,3})\s*[-–]\s+(.+)'),         # 1 - text
]

BULLET_PATTERNS = [
    re.compile(r'^([•·●○▪▸►▻‣⁃–—\-*])\s*(.+)'),   # • text
    re.compile(r'^[\s]*[•·●○▪▸►▻‣⁃–—\-*]\s+(.+)'), # indented bullet
]


# =============================================================================
# MAIN DETECTION
# =============================================================================

def detect_lists(
    text_units: list,
    page_type: str = "unknown",
    min_items: int = 3,
    x_tolerance: float = 10.0,
) -> list:
    """
    Detect semantic lists from text units on a page.
    
    Args:
        text_units: List of text objects (TextUnit or dicts with bbox, text)
        page_type: Page classification (helps with heuristics)
        min_items: Minimum items to consider a valid list
        x_tolerance: How close X positions must be to count as aligned
    
    Returns:
        List of DetectedList objects.
    """
    if len(text_units) < min_items:
        return []
    
    # Normalize to standard format
    items = _normalize_units(text_units)
    
    detected_lists = []
    
    # Strategy 1: Bullet detection
    bullet_list = _detect_bulleted_list(items, min_items, x_tolerance)
    if bullet_list:
        detected_lists.append(bullet_list)
    
    # Strategy 2: Numbered list detection
    numbered_list = _detect_numbered_list(items, min_items, x_tolerance)
    if numbered_list:
        detected_lists.append(numbered_list)
    
    # Strategy 3: Title list detection (back cover pattern)
    if page_type in ("back_cover", "title_list"):
        title_list = _detect_title_list(items, min_items)
        if title_list:
            detected_lists.append(title_list)
    
    # Strategy 4: Indented list (consistent indent from left margin)
    if not detected_lists:
        indent_list = _detect_indented_list(items, min_items, x_tolerance)
        if indent_list:
            detected_lists.append(indent_list)
    
    return detected_lists


# =============================================================================
# BULLET LIST DETECTION
# =============================================================================

def _detect_bulleted_list(items: list, min_items: int, x_tolerance: float) -> Optional[DetectedList]:
    """Detect a bulleted list from text content."""
    bullet_items = []
    
    for item in items:
        text = item["text"]
        
        # Check if starts with a bullet character
        if text and text[0] in BULLET_CHARS:
            for pattern in BULLET_PATTERNS:
                match = pattern.match(text)
                if match:
                    groups = match.groups()
                    bullet_char = groups[0] if len(groups) > 1 else text[0]
                    content = groups[-1] if groups else text[1:].strip()
                    bullet_items.append(ListItem(
                        index=len(bullet_items),
                        text=text,
                        unit_id=item.get("id", ""),
                        bullet_char=bullet_char,
                        content_text=content,
                        bbox=tuple(item["bbox"]),
                    ))
                    break
    
    if len(bullet_items) >= min_items:
        all_bboxes = [item.bbox for item in bullet_items]
        bbox = (
            min(b[0] for b in all_bboxes),
            min(b[1] for b in all_bboxes),
            max(b[2] for b in all_bboxes),
            max(b[3] for b in all_bboxes),
        )
        return DetectedList(
            id="list-bullet-0",
            list_type="bulleted",
            items=bullet_items,
            confidence=0.9,
            bbox=bbox,
        )
    
    return None


# =============================================================================
# NUMBERED LIST DETECTION
# =============================================================================

def _detect_numbered_list(items: list, min_items: int, x_tolerance: float) -> Optional[DetectedList]:
    """Detect a numbered list from text content."""
    numbered_items = []
    
    for item in items:
        text = item["text"]
        
        for pattern in NUMBERED_PATTERNS:
            match = pattern.match(text)
            if match:
                groups = match.groups()
                number = groups[0]
                content = groups[1] if len(groups) > 1 else text
                numbered_items.append(ListItem(
                    index=len(numbered_items),
                    text=text,
                    unit_id=item.get("id", ""),
                    bullet_char=number,
                    content_text=content,
                    bbox=tuple(item["bbox"]),
                ))
                break
    
    if len(numbered_items) >= min_items:
        # Verify sequential numbering
        is_sequential = _verify_sequential(numbered_items)
        confidence = 0.95 if is_sequential else 0.7
        
        all_bboxes = [item.bbox for item in numbered_items]
        bbox = (
            min(b[0] for b in all_bboxes),
            min(b[1] for b in all_bboxes),
            max(b[2] for b in all_bboxes),
            max(b[3] for b in all_bboxes),
        )
        return DetectedList(
            id="list-numbered-0",
            list_type="numbered",
            items=numbered_items,
            confidence=confidence,
            bbox=bbox,
        )
    
    return None


# =============================================================================
# TITLE LIST DETECTION (Back cover style)
# =============================================================================

def _detect_title_list(items: list, min_items: int) -> Optional[DetectedList]:
    """
    Detect a title list (like back cover book titles).
    Pattern: multiple short lines, similar font size, centered or left-aligned.
    """
    # Filter to items with reasonable text length (not single chars, not paragraphs)
    potential = [
        item for item in items
        if 3 <= len(item["text"]) <= 60
        and not item["text"].isdigit()
    ]
    
    if len(potential) < min_items:
        return None
    
    # Check if they're vertically sequential with consistent spacing
    sorted_items = sorted(potential, key=lambda x: x["bbox"][1])
    
    # Check for consistent Y spacing
    spacings = []
    for i in range(len(sorted_items) - 1):
        gap = sorted_items[i+1]["bbox"][1] - sorted_items[i]["bbox"][3]
        spacings.append(gap)
    
    if not spacings:
        return None
    
    avg_spacing = sum(spacings) / len(spacings)
    # Consistent spacing means most gaps are similar
    consistent = sum(1 for s in spacings if abs(s - avg_spacing) < avg_spacing * 0.5)
    
    if consistent < len(spacings) * 0.6:
        return None  # Too inconsistent
    
    # Build list items
    list_items = []
    heading = None
    
    for i, item in enumerate(sorted_items):
        # First item might be a heading if it's a different size
        if i == 0 and len(sorted_items) > 3:
            # Check if first item is different (heading)
            first_size = item.get("font_size", 12)
            other_sizes = [it.get("font_size", 12) for it in sorted_items[1:4]]
            avg_other = sum(other_sizes) / len(other_sizes) if other_sizes else first_size
            if abs(first_size - avg_other) > 3:
                heading = item["text"]
                continue
        
        list_items.append(ListItem(
            index=len(list_items),
            text=item["text"],
            unit_id=item.get("id", ""),
            content_text=item["text"],
            bbox=tuple(item["bbox"]),
        ))
    
    if len(list_items) >= min_items:
        all_bboxes = [item.bbox for item in list_items]
        bbox = (
            min(b[0] for b in all_bboxes),
            min(b[1] for b in all_bboxes),
            max(b[2] for b in all_bboxes),
            max(b[3] for b in all_bboxes),
        )
        return DetectedList(
            id="list-titles-0",
            list_type="title_list",
            items=list_items,
            confidence=0.85,
            bbox=bbox,
            heading=heading,
        )
    
    return None


# =============================================================================
# INDENTED LIST DETECTION
# =============================================================================

def _detect_indented_list(items: list, min_items: int, x_tolerance: float) -> Optional[DetectedList]:
    """
    Detect a list from consistent indentation pattern.
    Lines that are short, left-aligned at the same X, with consistent spacing.
    """
    if len(items) < min_items:
        return None
    
    # Sort by Y position
    sorted_items = sorted(items, key=lambda x: x["bbox"][1])
    
    # Group by X position (left edge alignment)
    from collections import Counter
    x_starts = [round(item["bbox"][0]) for item in sorted_items]
    x_counts = Counter(x_starts)
    
    # Find the most common left alignment
    if not x_counts:
        return None
    
    most_common_x, count = x_counts.most_common(1)[0]
    
    if count < min_items:
        return None
    
    # Collect items aligned at the most common X
    aligned_items = [
        item for item in sorted_items
        if abs(item["bbox"][0] - most_common_x) <= x_tolerance
    ]
    
    # Check if they're short lines (not full paragraphs)
    avg_width = sum(item["bbox"][2] - item["bbox"][0] for item in aligned_items) / len(aligned_items)
    page_width_approx = max(item["bbox"][2] for item in items) - min(item["bbox"][0] for item in items)
    
    # Short lines = less than 60% of available width
    if avg_width > page_width_approx * 0.6:
        return None  # These are paragraphs, not list items
    
    # Build list items
    list_items = []
    for item in aligned_items:
        list_items.append(ListItem(
            index=len(list_items),
            text=item["text"],
            unit_id=item.get("id", ""),
            content_text=item["text"],
            bbox=tuple(item["bbox"]),
        ))
    
    if len(list_items) >= min_items:
        all_bboxes = [item.bbox for item in list_items]
        bbox = (
            min(b[0] for b in all_bboxes),
            min(b[1] for b in all_bboxes),
            max(b[2] for b in all_bboxes),
            max(b[3] for b in all_bboxes),
        )
        return DetectedList(
            id="list-indent-0",
            list_type="indented",
            items=list_items,
            confidence=0.7,
            bbox=bbox,
        )
    
    return None


# =============================================================================
# HELPERS
# =============================================================================

def _normalize_units(text_units: list) -> list:
    """Normalize text units to a standard dict format."""
    normalized = []
    for unit in text_units:
        if hasattr(unit, 'bbox'):
            # TextUnit dataclass
            normalized.append({
                "id": unit.id,
                "text": unit.source_text,
                "bbox": list(unit.bbox),
                "font_size": 12.0,  # Will be overridden if style available
            })
        elif isinstance(unit, dict):
            normalized.append({
                "id": unit.get("id", ""),
                "text": unit.get("text_stripped", unit.get("text", "")),
                "bbox": unit.get("bbox", [0, 0, 0, 0]),
                "font_size": unit.get("font_size", 12.0),
            })
    return normalized


def _verify_sequential(items: list) -> bool:
    """Verify that numbered list items are sequential."""
    numbers = []
    for item in items:
        try:
            numbers.append(int(item.bullet_char))
        except (ValueError, TypeError):
            # Non-numeric (a, b, c or i, ii, iii)
            return True  # Assume valid if not numeric
    
    if not numbers:
        return True
    
    # Check if sequential (1,2,3... or any start)
    for i in range(len(numbers) - 1):
        if numbers[i+1] != numbers[i] + 1:
            return False
    return True


# =============================================================================
# CLI
# =============================================================================

def main():
    """Test list detection on Kolulu back cover (page 16)."""
    import pymupdf
    
    pdf_path = r"C:\Users\zande\Documents\Digital Bookstore\Kolulu Engl Series 3 - 2 - A Fun Place.pdf"
    doc = pymupdf.open(pdf_path)
    
    # Test on back cover (page 16 — title list)
    page = doc[15]
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
    units = []
    for block in text_dict.get("blocks", []):
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                text = span["text"].strip()
                if not text:
                    continue
                units.append({
                    "id": f"test_{len(units)}",
                    "text_stripped": text,
                    "bbox": list(span["bbox"]),
                    "font_size": span["size"],
                })
    
    print(f"=== Page 16 (Back Cover) ===")
    print(f"Text units: {len(units)}")
    
    lists = detect_lists(units, page_type="back_cover")
    
    for lst in lists:
        print(f"\nDetected: {lst.list_type} (confidence={lst.confidence:.2f})")
        if lst.heading:
            print(f"  Heading: '{lst.heading}'")
        print(f"  Items ({lst.item_count}):")
        for item in lst.items[:8]:
            print(f"    [{item.index}] {item.text}")
        if lst.item_count > 8:
            print(f"    ... and {lst.item_count - 8} more")
    
    if not lists:
        print("  No lists detected")
        print("  Sample text:")
        for u in units[:10]:
            print(f"    '{u['text_stripped']}'")
    
    doc.close()


if __name__ == "__main__":
    main()
