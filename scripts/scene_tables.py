#!/usr/bin/env python3
"""
scene_tables.py — tables on a page: rows, columns, header cells and merged cells
(task 11.3, Req 4.2, 9.1, design §6 stage 3). Used by scene_extract.py; book-agnostic.

Ruled tables come from PyMuPDF's line-based table finder (it reports merged cells as gaps in
a row). A found grid counts as a table only when at least two cells hold text and no cell
covers most of the page — crop marks and bleed lines make page-sized "frames" that are not
tables. Borderless tables are inferred from the blocks (the idea of borderless_table.py,
kept conservative): two or more side-by-side columns of short lines (≥ 4 lines, ≤ 3 words a
line, same size, tops level), with short blocks just above them as the header row.

Header row: the first row, when every cell is short (≤ 4 words) and the row either is much
shorter than the body rows or is set differently (bold, capitals, another size).

Each table becomes a `table` unit with its cells in reading order (row by row, left to
right). A cell's text is its blocks joined top to bottom (a blank line between blocks); its
`cell_box` is the space the cell offers and becomes the block's allowed area.
"""

from __future__ import annotations

import contextlib
import io
import statistics

FRAME_SHARE = 0.6          # a cell covering more than this share of the page is a frame, not a table
EDGE_TOLERANCE = 1.5       # points: cell edges this close are the same grid line
HEAD_COVER = 0.25          # a heading belongs to a column when it covers this share of the column's width


def _centre(b):
    return ((b[0] + b[2]) / 2, (b[1] + b[3]) / 2)


def _inside(point, box, pad=1.0):
    return box[0] - pad <= point[0] <= box[2] + pad and box[1] - pad <= point[1] <= box[3] + pad


def _edges(values):
    out = []
    for v in sorted(values):
        if not out or v - out[-1] > EDGE_TOLERANCE:
            out.append(v)
    return out


def _span(lo, hi, edges):
    """How many grid intervals [edges[i], edges[i+1]] the range lo–hi covers."""
    return max(1, sum(1 for a, b in zip(edges, edges[1:]) if a >= lo - EDGE_TOLERANCE and b <= hi + EDGE_TOLERANCE))


def _index(lo, edges):
    return min(range(len(edges)), key=lambda i: abs(edges[i] - lo))


def _words(text):
    return len(text.split())


def _cell_from_blocks(blocks, row, col, rowspan, colspan, cell_box):
    blocks = sorted(blocks, key=lambda b: (b["bbox"][1], b["bbox"][0]))
    first = blocks[0]
    return {
        "bbox": [min(b["bbox"][0] for b in blocks), min(b["bbox"][1] for b in blocks),
                 max(b["bbox"][2] for b in blocks), max(b["bbox"][3] for b in blocks)],
        "text": "\n\n".join(b["text"] for b in blocks),
        "lines": [l for b in blocks for l in b["lines"]],
        "style": dict(first["style"]),
        "unmapped": sum(b.get("unmapped", 0) for b in blocks),
        "kind": "cell",
        "cell_box": [round(v, 2) for v in cell_box],
        "structure": {"row": row, "col": col, "rowspan": rowspan, "colspan": colspan, "header": False},
    }


def _mark_header(cells, row_heights):
    """Row 0 is a header row when its cells are short and the row looks different from the body."""
    if len(row_heights) < 2:
        return
    top = [c for c in cells if c["structure"]["row"] == 0]
    body = [c for c in cells if c["structure"]["row"] > 0]
    if not top or not body or any(_words(c["text"]) > 4 for c in top):
        return
    short_row = row_heights[0] <= 0.5 * statistics.median(row_heights[1:])
    looks_different = (all(c["text"].replace("\n", " ").isupper() for c in top)
                       or any(c["style"]["weight"] != body[0]["style"]["weight"] for c in top)
                       or any(abs(c["style"]["size"] - body[0]["style"]["size"]) > 0.1 * body[0]["style"]["size"] for c in top))
    if short_row or looks_different:
        for c in top:
            c["structure"]["header"] = True


def _table_unit(cells, bbox, col_edges, row_edges, ruled):
    cells.sort(key=lambda c: (c["structure"]["row"], c["structure"]["col"]))
    return {"kind": "table", "bbox": [round(v, 2) for v in bbox], "cells": cells,
            "style": dict(cells[0]["style"]),
            "structure": {"rows": len(row_edges) - 1, "cols": len(col_edges) - 1, "ruled": ruled,
                          "col_edges": [round(v, 2) for v in col_edges], "row_edges": [round(v, 2) for v in row_edges]}}


# ----------------------------------------------------------------------------- ruled tables

def ruled_tables(page, blocks):
    """Tables drawn with lines. Returns (tables, ids of blocks they took)."""
    try:
        # PyMuPDF prints an advert for another package on stdout here; our stdout is JSON.
        with contextlib.redirect_stdout(io.StringIO()):
            found = page.find_tables().tables
    except Exception:
        return [], set()
    page_area = max(abs(page.rect), 1.0)
    tables, used = [], set()
    for t in found:
        boxes = [c for row in t.rows for c in row.cells if c is not None]
        if not boxes or max((b[2] - b[0]) * (b[3] - b[1]) for b in boxes) > FRAME_SHARE * page_area:
            continue
        col_edges = _edges([b[0] for b in boxes] + [b[2] for b in boxes])
        row_edges = _edges([b[1] for b in boxes] + [b[3] for b in boxes])
        cells = []
        for box in boxes:
            inside = [b for b in blocks if id(b) not in used and _inside(_centre(b["bbox"]), box)]
            if not inside:
                continue
            for b in inside:
                used.add(id(b))
            cells.append(_cell_from_blocks(inside, _index(box[1], row_edges), _index(box[0], col_edges),
                                           _span(box[1], box[3], row_edges), _span(box[0], box[2], col_edges), box))
        if len(cells) < 2:
            for c in cells:                 # not a table after all: give the blocks back
                for b in blocks:
                    if _inside(_centre(b["bbox"]), c["cell_box"]):
                        used.discard(id(b))
            continue
        _mark_header(cells, [b - a for a, b in zip(row_edges, row_edges[1:])])
        tables.append(_table_unit(cells, t.bbox, col_edges, row_edges, True))
    return tables, used


# ----------------------------------------------------------------------------- borderless tables

def _is_word_column(b):
    lines = b["lines"]
    return len(lines) >= 4 and all(_words(l["text"]) <= 3 for l in lines)


def borderless_tables(blocks, taken):
    """Side-by-side columns of short lines (word lists) with their headings, no lines drawn."""
    cols = sorted((b for b in blocks if id(b) not in taken and _is_word_column(b)), key=lambda b: b["bbox"][0])
    groups, used = [], set(taken)
    for c in cols:
        size = c["style"]["size"]
        for g in groups:
            ref = g[-1]
            if (abs(ref["style"]["size"] - size) <= 0.1 * size and abs(ref["bbox"][1] - c["bbox"][1]) <= 2 * size
                    and c["bbox"][0] > ref["bbox"][2]):
                g.append(c)
                break
        else:
            groups.append([c])
    tables = []
    for g in groups:
        if len(g) < 2:
            continue
        size = g[0]["style"]["size"]
        top = min(c["bbox"][1] for c in g)
        # Column edges: halfway between neighbouring columns, outer edges at the text.
        xs = [g[0]["bbox"][0]] + [(a["bbox"][2] + b["bbox"][0]) / 2 for a, b in zip(g, g[1:])] + [g[-1]["bbox"][2]]

        def covered(h):
            """Columns whose space the heading covers for at least a quarter of their width."""
            return [i for i in range(len(g))
                    if min(h["bbox"][2], xs[i + 1]) - max(h["bbox"][0], xs[i]) >= HEAD_COVER * (xs[i + 1] - xs[i])]

        heads = [b for b in blocks if id(b) not in used and b not in g and _words(b["text"]) <= 4
                 and b["bbox"][3] <= top and top - b["bbox"][3] <= 2.5 * size and covered(b)]
        has_head = bool(heads)
        head_top = min((h["bbox"][1] for h in heads), default=top)
        row_edges = [head_top, top, max(c["bbox"][3] for c in g)] if has_head else [top, max(c["bbox"][3] for c in g)]
        cells = []
        for i, c in enumerate(g):
            used.add(id(c))
            cells.append(_cell_from_blocks([c], 1 if has_head else 0, i, 1, 1, [xs[i], row_edges[-2], xs[i + 1], row_edges[-1]]))
        for h in heads:
            cols_under = covered(h)
            used.add(id(h))
            cell = _cell_from_blocks([h], 0, cols_under[0], 1, len(cols_under),
                                     [xs[cols_under[0]], row_edges[0], xs[cols_under[-1] + 1], row_edges[1]])
            cell["structure"]["header"] = True
            cells.append(cell)
        bbox = [xs[0], row_edges[0], xs[-1], row_edges[-1]]
        tables.append(_table_unit(cells, bbox, xs, row_edges, False))
    return tables, used


def find_tables(page, blocks):
    """All tables on the page and the remaining (non-table) blocks."""
    ruled, used = ruled_tables(page, blocks)
    loose, used = borderless_tables(blocks, used)
    return ruled + loose, [b for b in blocks if id(b) not in used]
