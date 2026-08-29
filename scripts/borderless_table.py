"""
Borderless Table Inference — Digital Bookstore V8
==================================================
Detects table-like layouts from text alignment and whitespace when no
vector grid lines are present.

Strategy:
1. Cluster text spans by X-position → detect columns
2. Cluster text spans by Y-position → detect rows
3. Validate: consistent spacing, aligned items, repeated pattern
4. Output column boundaries for container assignment

This handles:
- Word lists without borders (just aligned columns)
- Vocabulary pages with spacing-only layout
- Multi-column back cover title lists
- Info panels with label: value pairs

Usage:
    from borderless_table import infer_table_from_text
    result = infer_table_from_text(text_units, page_width, page_height)
"""

import os
import sys
from collections import Counter
from dataclasses import dataclass, field
from typing import Optional

SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPTS_DIR)


# =============================================================================
# DATA TYPES
# =============================================================================

@dataclass
class InferredColumn:
    """A detected column from text alignment."""
    index: int
    x_start: float
    x_end: float
    item_count: int
    avg_font_size: float
    confidence: float = 0.0

    @property
    def width(self) -> float:
        return self.x_end - self.x_start


@dataclass
class InferredRow:
    """A detected row from text Y-alignment."""
    index: int
    y_start: float
    y_end: float
    item_count: int


@dataclass
class InferredTable:
    """Result of borderless table inference."""
    detected: bool
    columns: list  # [InferredColumn]
    rows: list     # [InferredRow]
    confidence: float = 0.0
    row_count: int = 0
    col_count: int = 0
    content_bbox: tuple = (0, 0, 0, 0)  # (x0, y0, x1, y1)
    metadata: dict = field(default_factory=dict)


# =============================================================================
# MAIN DETECTION
# =============================================================================

def infer_table_from_text(
    text_units: list,
    page_width: float,
    page_height: float,
    min_columns: int = 2,
    min_rows: int = 3,
    x_tolerance: float = 15.0,
    y_tolerance: float = 5.0,
) -> InferredTable:
    """
    Infer a table structure from text unit positions.
    
    Args:
        text_units: List of dicts/objects with 'bbox', 'origin', 'font_size', 'text'
        page_width: Page width in points
        page_height: Page height in points
        min_columns: Minimum columns to detect (default 2)
        min_rows: Minimum rows to detect (default 3)
        x_tolerance: How close X positions must be to form a column
        y_tolerance: How close Y positions must be to form a row
    
    Returns:
        InferredTable with detected columns, rows, and confidence.
    """
    if len(text_units) < min_columns * min_rows:
        return InferredTable(detected=False, columns=[], rows=[])
    
    # Extract positions
    positions = []
    for unit in text_units:
        if hasattr(unit, 'bbox'):
            bbox = unit.bbox
            origin = unit.baseline if hasattr(unit, 'baseline') else (bbox[0], bbox[1])
            font_size = 12.0
            text = unit.source_text if hasattr(unit, 'source_text') else ""
        else:
            bbox = unit.get("bbox", [0, 0, 0, 0])
            origin = unit.get("origin", [bbox[0], bbox[1]])
            font_size = unit.get("font_size", 12.0)
            text = unit.get("text_stripped", unit.get("text", ""))
        
        positions.append({
            "x": origin[0] if isinstance(origin, (list, tuple)) else origin.x,
            "y": origin[1] if isinstance(origin, (list, tuple)) else origin.y,
            "x0": bbox[0],
            "y0": bbox[1],
            "x1": bbox[2],
            "y1": bbox[3],
            "font_size": font_size,
            "text": text,
        })
    
    # === STEP 1: Detect columns by clustering X positions ===
    x_positions = [p["x"] for p in positions]
    x_clusters = _cluster_values(x_positions, x_tolerance)
    
    if len(x_clusters) < min_columns:
        return InferredTable(detected=False, columns=[], rows=[])
    
    # === STEP 2: Detect rows by clustering Y positions ===
    y_positions = [p["y"] for p in positions]
    y_clusters = _cluster_values(y_positions, y_tolerance)
    
    if len(y_clusters) < min_rows:
        return InferredTable(detected=False, columns=[], rows=[])
    
    # === STEP 3: Validate table structure ===
    # Assign each item to its column and row
    col_assignments = _assign_to_clusters(x_positions, x_clusters, x_tolerance)
    row_assignments = _assign_to_clusters(y_positions, y_clusters, y_tolerance)
    
    # Count items per column
    col_counts = Counter(col_assignments)
    row_counts = Counter(row_assignments)
    
    # A valid table should have relatively even distribution across columns
    if col_counts:
        max_col_items = max(col_counts.values())
        min_col_items = min(col_counts.values())
        # Very uneven distribution suggests it's NOT a table
        if min_col_items < max_col_items * 0.3:
            # Some columns have very few items — could still be valid if
            # most columns are populated
            populated_cols = sum(1 for c in col_counts.values() if c >= min_rows)
            if populated_cols < min_columns:
                return InferredTable(detected=False, columns=[], rows=[])
    
    # === STEP 4: Build column definitions ===
    columns = []
    sorted_x_clusters = sorted(x_clusters)
    
    for col_idx, x_center in enumerate(sorted_x_clusters):
        # Find all items in this column
        col_items = [
            positions[i] for i, c in enumerate(col_assignments)
            if c == x_clusters.index(x_center)
        ]
        
        if not col_items:
            continue
        
        # Column bounds: leftmost origin to rightmost bbox edge
        col_x_start = min(item["x0"] for item in col_items)
        col_x_end = max(item["x1"] for item in col_items)
        avg_size = sum(item["font_size"] for item in col_items) / len(col_items)
        
        # Extend to midpoint between this column and next
        if col_idx < len(sorted_x_clusters) - 1:
            next_center = sorted_x_clusters[col_idx + 1]
            midpoint = (x_center + next_center) / 2
            col_x_end = max(col_x_end, midpoint - 2)
        else:
            # Last column — extend to page margin
            col_x_end = min(col_x_end + 20, page_width - 20)
        
        # Left edge: midpoint to previous column or page edge
        if col_idx > 0:
            prev_center = sorted_x_clusters[col_idx - 1]
            midpoint = (prev_center + x_center) / 2
            col_x_start = min(col_x_start, midpoint + 2)
        else:
            col_x_start = max(col_x_start - 10, 20)
        
        columns.append(InferredColumn(
            index=col_idx,
            x_start=col_x_start,
            x_end=col_x_end,
            item_count=len(col_items),
            avg_font_size=avg_size,
            confidence=min(1.0, len(col_items) / max(len(y_clusters), 1)),
        ))
    
    # === STEP 5: Build row definitions ===
    rows = []
    sorted_y_clusters = sorted(y_clusters)
    
    for row_idx, y_center in enumerate(sorted_y_clusters):
        row_items = [
            positions[i] for i, r in enumerate(row_assignments)
            if r == y_clusters.index(y_center)
        ]
        
        if not row_items:
            continue
        
        row_y_start = min(item["y0"] for item in row_items)
        row_y_end = max(item["y1"] for item in row_items)
        
        rows.append(InferredRow(
            index=row_idx,
            y_start=row_y_start,
            y_end=row_y_end,
            item_count=len(row_items),
        ))
    
    # === STEP 6: Calculate confidence ===
    # Higher confidence when:
    # - More columns detected
    # - Even distribution of items
    # - Consistent row spacing
    col_confidence = min(1.0, len(columns) / 3)
    
    # Row spacing consistency
    if len(rows) >= 2:
        spacings = [rows[i+1].y_start - rows[i].y_end for i in range(len(rows)-1)]
        if spacings:
            avg_spacing = sum(spacings) / len(spacings)
            spacing_variance = sum((s - avg_spacing)**2 for s in spacings) / len(spacings)
            spacing_confidence = max(0, 1.0 - spacing_variance / 100)
        else:
            spacing_confidence = 0.5
    else:
        spacing_confidence = 0.5
    
    overall_confidence = (col_confidence + spacing_confidence) / 2
    
    # Content bbox
    all_x0 = min(p["x0"] for p in positions)
    all_y0 = min(p["y0"] for p in positions)
    all_x1 = max(p["x1"] for p in positions)
    all_y1 = max(p["y1"] for p in positions)
    
    return InferredTable(
        detected=True,
        columns=columns,
        rows=rows,
        confidence=overall_confidence,
        row_count=len(rows),
        col_count=len(columns),
        content_bbox=(all_x0, all_y0, all_x1, all_y1),
        metadata={
            "x_tolerance": x_tolerance,
            "y_tolerance": y_tolerance,
            "total_items": len(positions),
        },
    )


# =============================================================================
# HELPERS
# =============================================================================

def _cluster_values(values: list, tolerance: float) -> list:
    """
    Cluster numeric values into groups within tolerance.
    Returns list of cluster centers.
    """
    if not values:
        return []
    
    sorted_vals = sorted(set(round(v, 1) for v in values))
    clusters = []
    current_cluster = [sorted_vals[0]]
    
    for val in sorted_vals[1:]:
        if val - current_cluster[-1] <= tolerance:
            current_cluster.append(val)
        else:
            clusters.append(sum(current_cluster) / len(current_cluster))
            current_cluster = [val]
    
    clusters.append(sum(current_cluster) / len(current_cluster))
    return clusters


def _assign_to_clusters(values: list, clusters: list, tolerance: float) -> list:
    """Assign each value to its nearest cluster index."""
    assignments = []
    for val in values:
        best_idx = 0
        best_dist = abs(val - clusters[0])
        for i, center in enumerate(clusters[1:], 1):
            dist = abs(val - center)
            if dist < best_dist:
                best_dist = dist
                best_idx = i
        assignments.append(best_idx)
    return assignments


# =============================================================================
# CLI
# =============================================================================

def main():
    """Test with Kolulu page 15 (should detect table even without relying on grid lines)."""
    import json
    import pymupdf
    
    pdf_path = r"C:\Users\zande\Documents\Digital Bookstore\Kolulu Engl Series 3 - 2 - A Fun Place.pdf"
    doc = pymupdf.open(pdf_path)
    page = doc[14]  # Page 15
    
    # Extract text as simple dicts
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
                if text.isdigit() and len(text) <= 3 and span["size"] < 20 and span["bbox"][1] > 600:
                    continue  # Skip page numbers
                units.append({
                    "bbox": span["bbox"],
                    "origin": span["origin"],
                    "font_size": span["size"],
                    "text_stripped": text,
                })
    
    print(f"Text units on page 15: {len(units)}")
    
    result = infer_table_from_text(units, page.rect.width, page.rect.height)
    
    print(f"\nTable detected: {result.detected}")
    print(f"Confidence: {result.confidence:.2f}")
    print(f"Columns: {result.col_count}")
    print(f"Rows: {result.row_count}")
    
    if result.columns:
        print("\nColumn details:")
        for col in result.columns:
            print(f"  Col {col.index}: x=[{col.x_start:.0f}, {col.x_end:.0f}] width={col.width:.0f}pt items={col.item_count}")
    
    doc.close()


if __name__ == "__main__":
    main()
