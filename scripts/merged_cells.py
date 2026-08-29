"""
Merged Cell Detection — Digital Bookstore V8
==============================================
Detects cells that span multiple columns or rows in a table grid.

Strategy:
- Compare text bbox against cell grid boundaries
- If text spans across a column boundary → merged horizontally
- If text spans across a row boundary → merged vertically
- Update TextUnit.column_span / row_span accordingly

Common in Kolulu: Headers that span all 5 columns ("WORDS", "HIGH FREQUENCY WORDS")

Usage:
    from merged_cells import detect_merged_cells
    merges = detect_merged_cells(text_units, table_grid)
"""

import os
import sys
from dataclasses import dataclass
from typing import Optional

SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPTS_DIR)

from universal_containers import TableGrid


# =============================================================================
# DATA TYPES
# =============================================================================

@dataclass
class MergedCell:
    """A detected merged cell spanning multiple columns/rows."""
    unit_id: str
    text: str
    start_col: int
    end_col: int       # Exclusive (span = end_col - start_col)
    start_row: int
    end_row: int       # Exclusive
    col_span: int
    row_span: int
    bbox: tuple


# =============================================================================
# DETECTION
# =============================================================================

def detect_merged_cells(
    text_units: list,
    table_grid: TableGrid,
    overlap_threshold: float = 0.3,
) -> list:
    """
    Detect text that spans multiple grid cells.
    
    Args:
        text_units: List of text objects with bbox
        table_grid: The detected table grid (from universal_containers)
        overlap_threshold: Minimum overlap ratio to count as spanning a column
    
    Returns:
        List of MergedCell objects.
    """
    if not table_grid or not table_grid.columns:
        return []
    
    merged = []
    
    for unit in text_units:
        # Get unit bbox
        if hasattr(unit, 'bbox'):
            bbox = unit.bbox
            uid = unit.id
            text = unit.source_text
        elif isinstance(unit, dict):
            bbox = unit.get("bbox", [0, 0, 0, 0])
            uid = unit.get("id", "")
            text = unit.get("text_stripped", unit.get("text", ""))
        else:
            continue
        
        unit_x0, unit_y0, unit_x1, unit_y1 = bbox[0], bbox[1], bbox[2], bbox[3]
        unit_width = unit_x1 - unit_x0
        
        if unit_width <= 0:
            continue
        
        # Find which columns this unit overlaps
        overlapping_cols = []
        for col_idx, (col_start, col_end) in enumerate(table_grid.columns):
            # Calculate overlap
            overlap_start = max(unit_x0, col_start)
            overlap_end = min(unit_x1, col_end)
            overlap_width = max(0, overlap_end - overlap_start)
            
            col_width = col_end - col_start
            if col_width > 0 and overlap_width / col_width > overlap_threshold:
                overlapping_cols.append(col_idx)
        
        # Find which rows this unit overlaps
        overlapping_rows = []
        for row_idx, (row_start, row_end) in enumerate(table_grid.rows):
            overlap_start = max(unit_y0, row_start)
            overlap_end = min(unit_y1, row_end)
            overlap_height = max(0, overlap_end - overlap_start)
            
            row_height = row_end - row_start
            if row_height > 0 and overlap_height / row_height > overlap_threshold:
                overlapping_rows.append(row_idx)
        
        # If spans more than 1 column or row, it's merged
        col_span = len(overlapping_cols)
        row_span = len(overlapping_rows)
        
        if col_span > 1 or row_span > 1:
            merged.append(MergedCell(
                unit_id=uid,
                text=text,
                start_col=min(overlapping_cols) if overlapping_cols else 0,
                end_col=max(overlapping_cols) + 1 if overlapping_cols else 1,
                start_row=min(overlapping_rows) if overlapping_rows else 0,
                end_row=max(overlapping_rows) + 1 if overlapping_rows else 1,
                col_span=col_span,
                row_span=row_span,
                bbox=tuple(bbox),
            ))
    
    return merged


# =============================================================================
# CLI
# =============================================================================

def main():
    """Test merged cell detection on Kolulu page 15."""
    import pymupdf
    from universal_containers import detect_table_grid
    
    pdf_path = r"C:\Users\zande\Documents\Digital Bookstore\Kolulu Engl Series 3 - 2 - A Fun Place.pdf"
    doc = pymupdf.open(pdf_path)
    page = doc[14]  # Page 15
    
    # Detect grid
    grid = detect_table_grid(page)
    if not grid:
        print("No grid detected")
        return
    
    print(f"Grid: {len(grid.columns)} cols, {len(grid.rows)} rows")
    
    # Get text units
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
                    "id": f"u_{len(units)}",
                    "text_stripped": text,
                    "bbox": list(span["bbox"]),
                })
    
    # Detect merges
    merges = detect_merged_cells(units, grid)
    
    print(f"\nMerged cells: {len(merges)}")
    for m in merges:
        print(f"  '{m.text}' → cols {m.start_col}-{m.end_col} (span={m.col_span}), rows {m.start_row}-{m.end_row} (span={m.row_span})")
    
    doc.close()


if __name__ == "__main__":
    main()
