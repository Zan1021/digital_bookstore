"""
Universal Container Detection — Digital Bookstore V8
=====================================================
Detects actual text containers from PDF structure rather than relying
on tight glyph bounding boxes.

Container sources (priority order):
1. Table cells — from vector line intersections
2. Clip paths — PDF clipping regions constraining text
3. Page-family consensus — union of text positions across related pages
4. Glyph union (fallback) — tight bbox around actual glyphs

This module is called during DocumentScene building to populate
Region.design_container_bbox with the ACTUAL available space.

Usage:
    from universal_containers import detect_containers
    containers = detect_containers(page, page_num, page_type)
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
class Container:
    """A detected container region — the designed space for text."""
    id: str
    source: str  # "table_cell", "clip_path", "page_family", "vector_rect", "glyph_union"
    bbox: tuple  # (x0, y0, x1, y1)
    confidence: float = 1.0
    column_index: Optional[int] = None
    row_index: Optional[int] = None
    metadata: dict = field(default_factory=dict)

    @property
    def width(self) -> float:
        return self.bbox[2] - self.bbox[0]

    @property
    def height(self) -> float:
        return self.bbox[3] - self.bbox[1]


@dataclass
class TableGrid:
    """A detected table grid from vector lines."""
    horizontal_lines: list  # [(y, x_start, x_end), ...]
    vertical_lines: list    # [(x, y_start, y_end), ...]
    cells: list             # [Container, ...]
    columns: list           # [(x_start, x_end), ...]
    rows: list              # [(y_start, y_end), ...]
    header_row_y: Optional[float] = None


# =============================================================================
# TABLE GRID DETECTION — From vector line intersections
# =============================================================================

def detect_table_grid(page, tolerance: float = 5.0) -> Optional[TableGrid]:
    """
    Detect a table grid from vector line drawings on a page.
    
    Finds horizontal and vertical lines, clusters them into grid positions,
    then derives cell bounding boxes from their intersections.
    
    Args:
        page: PyMuPDF page object
        tolerance: How close lines must be to count as "same position"
    
    Returns:
        TableGrid if a valid grid is found, None otherwise.
    """
    drawings = page.get_drawings()
    
    h_lines = []  # (y, x_start, x_end)
    v_lines = []  # (x, y_start, y_end)
    
    for d in drawings:
        rect = d.get("rect")
        if not rect:
            continue
        
        # Horizontal line: narrow height, reasonable width
        if rect.height < 3 and rect.width > 30:
            y = round((rect.y0 + rect.y1) / 2, 1)
            h_lines.append((y, rect.x0, rect.x1))
        
        # Vertical line: narrow width, reasonable height
        elif rect.width < 3 and rect.height > 30:
            x = round((rect.x0 + rect.x1) / 2, 1)
            v_lines.append((x, rect.y0, rect.y1))
    
    if not h_lines or not v_lines:
        return None
    
    # Cluster horizontal lines by Y position
    h_positions = _cluster_positions([l[0] for l in h_lines], tolerance)
    v_positions = _cluster_positions([l[0] for l in v_lines], tolerance)
    
    # Need at least 2 horizontal (top/bottom) and 2 vertical (left/right) for a grid
    if len(h_positions) < 2 or len(v_positions) < 2:
        return None
    
    # Sort positions
    h_positions.sort()
    v_positions.sort()
    
    # Derive columns from vertical line positions
    columns = []
    for i in range(len(v_positions) - 1):
        columns.append((v_positions[i], v_positions[i + 1]))
    
    # Also add implicit first/last column if vertical lines don't start at page edge
    # but horizontal lines extend beyond them
    h_x_min = min(l[1] for l in h_lines)
    h_x_max = max(l[2] for l in h_lines)
    
    if h_x_min < v_positions[0] - tolerance:
        columns.insert(0, (h_x_min, v_positions[0]))
    if h_x_max > v_positions[-1] + tolerance:
        columns.append((v_positions[-1], h_x_max))
    
    # Derive rows from horizontal line positions
    rows = []
    for i in range(len(h_positions) - 1):
        rows.append((h_positions[i], h_positions[i + 1]))
    
    # If vertical lines extend beyond horizontal, add implicit bottom row
    v_y_max = max(l[2] for l in v_lines)
    if v_y_max > h_positions[-1] + tolerance:
        rows.append((h_positions[-1], v_y_max))
    
    # Build cells from column × row intersections
    cells = []
    cell_idx = 0
    for row_idx, (y_start, y_end) in enumerate(rows):
        for col_idx, (x_start, x_end) in enumerate(columns):
            cell_idx += 1
            # Add internal padding
            padding = 3
            cell_bbox = (
                x_start + padding,
                y_start + padding,
                x_end - padding,
                y_end - padding,
            )
            cells.append(Container(
                id=f"cell-r{row_idx}-c{col_idx}",
                source="table_cell",
                bbox=cell_bbox,
                confidence=0.95,
                column_index=col_idx,
                row_index=row_idx,
                metadata={"raw_bbox": (x_start, y_start, x_end, y_end)},
            ))
    
    # Detect header row (first row if it's narrow)
    header_y = None
    if len(rows) >= 2:
        first_row_height = rows[0][1] - rows[0][0]
        second_row_height = rows[1][1] - rows[1][0] if len(rows) > 1 else first_row_height
        if first_row_height < second_row_height * 0.5:
            header_y = rows[0][0]
    
    return TableGrid(
        horizontal_lines=h_lines,
        vertical_lines=v_lines,
        cells=cells,
        columns=columns,
        rows=rows,
        header_row_y=header_y,
    )


def _cluster_header_spans(header_spans, y_tol=6.0):
    """
    Cluster raw header source spans into logical header LABELS. Header text like a
    two-line "HIGH FREQUENCY / WORDS" arrives as separate spans; we group spans whose
    horizontal extents overlap (same column region) and that are vertically close into
    one label. Book-agnostic: pure geometry.

    Returns a list of {"text", "x0", "y0", "x1", "y1"} sorted left->right.
    """
    spans = [s for s in header_spans if s.get("text_stripped", s.get("text", "")).strip()]
    if not spans:
        return []
    # Group by horizontal overlap (spans stacked over the same column region).
    groups = []
    for s in sorted(spans, key=lambda z: (z["bbox"][0], z["bbox"][1])):
        b = s["bbox"]
        cx = (b[0] + b[2]) / 2
        placed = False
        for g in groups:
            # Overlap if this span's center lies within the group's x-extent (padded)
            # or the group's center lies within this span — i.e. they stack vertically.
            if (g["x0"] - 8 <= cx <= g["x1"] + 8) or (b[0] <= g["cx"] <= b[2]):
                g["parts"].append(s)
                g["x0"] = min(g["x0"], b[0]); g["x1"] = max(g["x1"], b[2])
                g["y0"] = min(g["y0"], b[1]); g["y1"] = max(g["y1"], b[3])
                g["cx"] = (g["x0"] + g["x1"]) / 2
                placed = True
                break
        if not placed:
            groups.append({"parts": [s], "x0": b[0], "y0": b[1], "x1": b[2], "y1": b[3],
                           "cx": cx})
    labels = []
    for g in groups:
        # Order parts top->bottom, then left->right, join into the label text.
        parts = sorted(g["parts"], key=lambda z: (round(z["bbox"][1] / 4), z["bbox"][0]))
        text = " ".join(p.get("text_stripped", p.get("text", "")).strip() for p in parts)
        labels.append({"text": text.strip(), "x0": g["x0"], "y0": g["y0"],
                       "x1": g["x1"], "y1": g["y1"]})
    labels.sort(key=lambda z: z["x0"])
    return labels


def detect_header_cells(grid, header_spans, tolerance=6.0):
    """
    Map header source spans to their TRUE header cells, including MERGED
    (column-spanning) cells. A merged header cell is defined by which VERTICAL GRID
    LINES actually extend into the header band — a merged cell has NO interior vertical
    line crossing it in the header row, even though the content rows below are split
    into more columns. E.g. "WORDS" sits in one header cell (x65-280) that spans three
    content columns because no vertical line crosses x137/x208 within the header band.

    Book-agnostic: derived from the detected grid + header-band vertical lines + header
    span geometry. No per-title constants.

    Returns:
      {
        "header_row_box": (x0, y0, x1, y1) | None,
        "cells": [ {
            "text", "cell_box", "column_start", "column_end", "column_span",
            "source_box"
        }, ... ]  # left -> right
      }
    """
    if grid is None or not getattr(grid, "columns", None) or not grid.rows:
        return {"header_row_box": None, "cells": []}

    # Header band = the first (short) row.
    hb0, hb1 = grid.rows[0]
    columns = grid.columns

    # Vertical lines that TRAVERSE the header band define the header cells. A line
    # only counts if it spans most of the band height (a content-column divider that
    # merely touches the band bottom must NOT split the merged header).
    band_h = max(1.0, hb1 - hb0)
    band_xs = []
    for (x, ys, ye) in grid.vertical_lines:
        overlap = min(ye, hb1) - max(ys, hb0)
        if overlap >= band_h * 0.6:
            band_xs.append(x)
    band_xs = sorted(_cluster_positions(band_xs, tolerance)) if band_xs else []

    # Fall back to the outer column bounds if too few header verticals were found.
    left = columns[0][0]
    right = columns[-1][1]
    edges = [e for e in band_xs if left - tolerance <= e <= right + tolerance]
    if not edges or edges[0] > left + tolerance:
        edges = [left] + edges
    if edges[-1] < right - tolerance:
        edges = edges + [right]
    edges = sorted(_cluster_positions(edges, tolerance))

    # Header cells = consecutive header-band edge pairs.
    header_cells_x = [(edges[i], edges[i + 1]) for i in range(len(edges) - 1)]

    def _content_col_index(x):
        for i, (cs, ce) in enumerate(columns):
            if cs - tolerance <= x <= ce + tolerance:
                return i
        return min(range(len(columns)),
                   key=lambda i: abs(x - (columns[i][0] + columns[i][1]) / 2))

    labels = _cluster_header_spans(header_spans)

    cells = []
    for (cx0, cx1) in header_cells_x:
        # Which header label(s) fall inside this header cell (by center-x)?
        inside = [l for l in labels if cx0 - tolerance <= (l["x0"] + l["x1"]) / 2 <= cx1 + tolerance]
        text = " ".join(l["text"] for l in sorted(inside, key=lambda z: z["y0"])).strip()
        # Content columns this header cell covers (for column_span).
        c_start = _content_col_index(cx0 + 1)
        c_end = _content_col_index(cx1 - 1)
        if c_end < c_start:
            c_start, c_end = c_end, c_start
        if inside:
            sb = (min(l["x0"] for l in inside), min(l["y0"] for l in inside),
                  max(l["x1"] for l in inside), max(l["y1"] for l in inside))
        else:
            sb = (cx0, hb0, cx1, hb1)
        cells.append({
            "text": text,
            "cell_box": (cx0, hb0, cx1, hb1),
            "column_start": c_start,
            "column_end": c_end,
            "column_span": (c_end - c_start + 1),
            "source_box": sb,
        })

    header_row_box = (edges[0], hb0, edges[-1], hb1) if edges else None
    return {"header_row_box": header_row_box, "cells": cells}


# =============================================================================
# VECTOR RECTANGLE DETECTION — Filled/stroked boxes containing text
# =============================================================================

def detect_vector_rectangles(page, min_area: float = 500) -> list:
    """
    Find filled or stroked rectangles that could be text containers.
    
    These are distinct from table grid lines — they're standalone boxes
    (colored backgrounds, bordered panels, etc.)
    """
    drawings = page.get_drawings()
    rectangles = []
    
    for d in drawings:
        rect = d.get("rect")
        if not rect:
            continue
        
        items = d.get("items", [])
        area = rect.width * rect.height
        
        # Skip very small or page-sized
        page_area = page.rect.width * page.rect.height
        if area < min_area or area > page_area * 0.8:
            continue
        
        # Must be roughly rectangular (aspect ratio not extreme)
        aspect = rect.width / max(rect.height, 0.1)
        if aspect > 20 or aspect < 0.05:
            continue  # Likely a line, not a rectangle
        
        # Must be filled or have a visible stroke
        has_fill = d.get("fill") is not None
        has_stroke = d.get("color") is not None and d.get("width", 0) >= 0.5
        
        if not has_fill and not has_stroke:
            continue
        
        # Check if it's actually a rectangle shape (not a complex path)
        is_rect = any(i[0] == "re" for i in items)
        is_4_sided = len([i for i in items if i[0] == "l"]) == 4
        
        if not is_rect and not is_4_sided:
            continue
        
        rectangles.append(Container(
            id=f"vrect-{len(rectangles)}",
            source="vector_rect",
            bbox=(rect.x0, rect.y0, rect.x1, rect.y1),
            confidence=0.8,
            metadata={
                "fill": d.get("fill"),
                "stroke": d.get("color"),
                "stroke_width": d.get("width", 0),
            },
        ))
    
    return rectangles


# =============================================================================
# PAGE-FAMILY CONSENSUS CONTAINERS
# =============================================================================

def compute_family_consensus_containers(
    pages_data: list,
    margin_expansion: float = 10.0,
) -> dict:
    """
    Compute consensus containers from multiple pages in the same family.
    
    For each region type, finds the UNION of all text positions across
    family pages, then expands slightly to give breathing room.
    
    Args:
        pages_data: List of dicts, each with:
            - "page_number": int
            - "regions": [{region_type, bbox}, ...]
        margin_expansion: How many points to expand beyond the union
    
    Returns:
        Dict mapping region_type → Container (consensus bbox)
    """
    # Group regions by type
    type_bboxes = {}
    for page_data in pages_data:
        for region in page_data.get("regions", []):
            rtype = region.get("region_type", "unknown")
            bbox = region.get("bbox")
            if bbox:
                if rtype not in type_bboxes:
                    type_bboxes[rtype] = []
                type_bboxes[rtype].append(bbox)
    
    # Compute consensus (union) per type
    consensus = {}
    for rtype, bboxes in type_bboxes.items():
        if len(bboxes) < 2:
            continue  # Need multiple pages for consensus
        
        # Union of all bboxes
        x0 = min(b[0] for b in bboxes)
        y0 = min(b[1] for b in bboxes)
        x1 = max(b[2] for b in bboxes)
        y1 = max(b[3] for b in bboxes)
        
        # Expand by margin
        consensus_bbox = (
            x0 - margin_expansion,
            y0 - margin_expansion,
            x1 + margin_expansion,
            y1 + margin_expansion,
        )
        
        consensus[rtype] = Container(
            id=f"consensus-{rtype}",
            source="page_family",
            bbox=consensus_bbox,
            confidence=0.9,
            metadata={
                "page_count": len(bboxes),
                "raw_union": (x0, y0, x1, y1),
            },
        )
    
    return consensus


# =============================================================================
# MAIN DETECTION INTERFACE
# =============================================================================

def detect_containers(page, page_num: int, page_type: str) -> dict:
    """
    Detect all containers on a page using multiple strategies.
    
    Returns dict with:
    {
        "table_grid": TableGrid or None,
        "vector_rects": [Container, ...],
        "has_table": bool,
        "column_containers": {col_idx: Container} (derived from grid),
    }
    """
    result = {
        "page_number": page_num,
        "page_type": page_type,
        "table_grid": None,
        "vector_rects": [],
        "has_table": False,
        "column_containers": {},
    }
    
    # Strategy 1: Table grid detection (most useful for vocabulary pages)
    grid = detect_table_grid(page)
    if grid and len(grid.columns) >= 2:
        result["table_grid"] = grid
        result["has_table"] = True
        
        # Build column containers (full-height cells per column)
        for col_idx, (x_start, x_end) in enumerate(grid.columns):
            # Find the content row range (skip header if present)
            content_rows = grid.rows[1:] if grid.header_row_y is not None else grid.rows
            if content_rows:
                y_start = content_rows[0][0]
                y_end = content_rows[-1][1]
                padding = 3
                result["column_containers"][col_idx] = Container(
                    id=f"col-{col_idx}",
                    source="table_cell",
                    bbox=(x_start + padding, y_start + padding, x_end - padding, y_end - padding),
                    confidence=0.95,
                    column_index=col_idx,
                )
    
    # Strategy 2: Vector rectangles (standalone boxes)
    rects = detect_vector_rectangles(page)
    result["vector_rects"] = rects
    
    return result


# =============================================================================
# HELPERS
# =============================================================================

def _cluster_positions(values: list, tolerance: float) -> list:
    """
    Cluster nearby positions into single values (mean of cluster).
    E.g., [65.0, 65.1, 137.0, 137.2] → [65.05, 137.1]
    """
    if not values:
        return []
    
    sorted_vals = sorted(values)
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


# =============================================================================
# CLI
# =============================================================================

def main():
    """CLI for testing container detection."""
    import argparse
    import json
    
    parser = argparse.ArgumentParser(description="Universal Container Detection")
    parser.add_argument("--input", "-i", required=True, help="Input PDF")
    parser.add_argument("--page", "-p", type=int, help="Page number (1-based)")
    
    args = parser.parse_args()
    
    doc = pymupdf.open(args.input)
    
    pages_to_check = [args.page - 1] if args.page else range(len(doc))
    
    for page_idx in pages_to_check:
        page = doc[page_idx]
        page_num = page_idx + 1
        
        result = detect_containers(page, page_num, "unknown")
        
        print(f"\n=== Page {page_num} ===")
        print(f"  Has table: {result['has_table']}")
        
        if result['table_grid']:
            grid = result['table_grid']
            print(f"  Columns: {len(grid.columns)}")
            for i, (x0, x1) in enumerate(grid.columns):
                print(f"    Col {i}: x=[{x0:.0f}, {x1:.0f}] width={x1-x0:.0f}pt")
            print(f"  Rows: {len(grid.rows)}")
            print(f"  Cells: {len(grid.cells)}")
            print(f"  Header row: y={grid.header_row_y}")
        
        if result['vector_rects']:
            print(f"  Vector rectangles: {len(result['vector_rects'])}")
    
    doc.close()


if __name__ == "__main__":
    main()
