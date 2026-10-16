#!/usr/bin/env python3
"""
scene_structure.py — paragraphs, lists, columns and reading order for one page's blocks
(task 11.2, Req 4.1–4.2, design §6 stage 3). Used by scene_extract.py; book-agnostic.

Input: the blocks of scene_extract.extract_page (box, text, lines, style).
Output: the same blocks plus list parents, in reading order, each with
  kind       paragraph | list | list_item
  parent     index of its list block, or None
  structure  {"marker": "1 -"} for a list item; {"column": i, "columns": n} for text in a
             multi-column region; None otherwise

Paragraphs. PDFs usually store a paragraph as one text object, so blocks arrive whole. Lines of
running text that arrive as separate blocks are joined only when every sign agrees: same
style, aligned left edges, a normal line gap, the upper line ends mid-sentence and is nearly
full, the lower line starts in lower case and its first word would not have fitted above, and
neither looks like an address, web address or e-mail (separate lines on a credits page).

Lists. Lines starting with a number marker ("1.", "2)", "3 -") or a bullet glyph (•, ▪, ◦, …)
are list items; a block holding several becomes a list with one item each, and consecutive
one-item blocks of the same style join one list. A leading dash ("- ") counts as a bullet only
when at least two consecutive items have two or more words after it, so phonics patterns such
as "- tch" or "- ee" stay plain text.

Reading order. Recursive cuts through empty space: a vertical gap that runs down a region whose
two sides each fill at least half its height splits it into columns (read left to right);
otherwise horizontal gaps split it into bands (read top to bottom); a region with no gap is
read top to bottom, then left to right.
"""

from __future__ import annotations

import re

MIN_GAP = 4.0                # points of empty space that count as a gap
COLUMN_FILL = 0.5            # each side of a column cut must fill half the region's height
NUMBER_MARKER = re.compile(r"^\s*((?:\d{1,3}|[a-z]|[ivxlc]{1,5})(?:[.)]|\s+[-–—]))\s+(\S.*)$", re.I)
BULLET_MARKER = re.compile(r"^\s*([•●○◦▪■·‣⁃*])\s+(\S.*)$")
DASH_MARKER = re.compile(r"^\s*([-–])\s+(\S.*)$")
NOT_PROSE = re.compile(r"@|www\.|https?:|\.(com|net|org|co\.\w+)\b", re.I)
SENTENCE_END = re.compile(r"[.!?:;\"'”’)\]]\s*$")


# ----------------------------------------------------------------------------- paragraphs

def _same_style(a: dict, b: dict) -> bool:
    sa, sb = a["style"], b["style"]
    return (sa["font"] == sb["font"] and sa["color"] == sb["color"] and sa.get("rotation") == sb.get("rotation")
            and abs(sa["size"] - sb["size"]) <= 0.1 * max(sa["size"], sb["size"]))


def _continues(a: dict, b: dict) -> bool:
    """Does block b carry on the running text of block a?"""
    if not _same_style(a, b) or a["style"].get("rotation", 0) != 0:
        return False
    last, first = a["lines"][-1], b["lines"][0]
    size = a["style"]["size"]
    gap = b["bbox"][1] - a["bbox"][3]
    if not (-0.2 * size <= gap <= 0.6 * size) or abs(a["bbox"][0] - b["bbox"][0]) > 3:
        return False
    if NOT_PROSE.search(last["text"]) or NOT_PROSE.search(first["text"]):
        return False
    if SENTENCE_END.search(last["text"]) or not first["text"][:1].islower():
        return False
    measure = max(a["bbox"][2], b["bbox"][2]) - min(a["bbox"][0], b["bbox"][0])
    last_width = last["bbox"][2] - last["bbox"][0]
    if last_width < 0.85 * measure:
        return False
    # Wrapped text would have pulled b's first word up if it fitted: estimate its width.
    first_word = first["text"].split()[0]
    first_line_width = first["bbox"][2] - first["bbox"][0]
    word_width = first_line_width * len(first_word) / max(len(first["text"]), 1)
    return last_width + 0.3 * size + word_width > measure


def _join(a: dict, b: dict) -> dict:
    lines = a["lines"] + b["lines"]
    joined = dict(a)
    joined["lines"] = lines
    joined["text"] = a["text"] + "\n" + b["text"]
    joined["bbox"] = [min(a["bbox"][0], b["bbox"][0]), min(a["bbox"][1], b["bbox"][1]),
                      max(a["bbox"][2], b["bbox"][2]), max(a["bbox"][3], b["bbox"][3])]
    joined["unmapped"] = a.get("unmapped", 0) + b.get("unmapped", 0)
    style = dict(a["style"])
    baselines = [ln["bbox"][3] for ln in lines]
    steps = [q - p for p, q in zip(baselines, baselines[1:]) if q > p]
    if steps and style["size"]:
        style["line_height"] = round(sorted(steps)[len(steps) // 2] / style["size"], 3)
    joined["style"] = style
    return joined


def join_paragraphs(blocks: list[dict]) -> list[dict]:
    ordered = sorted(blocks, key=lambda b: (b["bbox"][1], b["bbox"][0]))
    out: list[dict] = []
    for block in ordered:
        target = next((p for p in reversed(out) if _continues(p, block)), None)
        if target is not None:
            out[out.index(target)] = _join(target, block)
        else:
            out.append(block)
    return out


# ----------------------------------------------------------------------------- lists

def _marker(text: str, allow_dash: bool) -> tuple[str, str] | None:
    for pattern in (NUMBER_MARKER, BULLET_MARKER) + ((DASH_MARKER,) if allow_dash else ()):
        m = pattern.match(text)
        if m:
            return m.group(1).strip(), m.group(2)
    return None


def _items_of(block: dict, allow_dash: bool) -> list[dict] | None:
    """Split a block into list items (a marker line plus its continuation lines), or None."""
    items, current = [], None
    for line in block["lines"]:
        found = _marker(line["text"], allow_dash)
        if found:
            current = {"marker": found[0], "lines": [line]}
            items.append(current)
        elif current is not None and line["bbox"][0] > current["lines"][0]["bbox"][0] + 2:
            current["lines"].append(line)          # an indented continuation line
        else:
            return None                            # a line that is neither: not a list block
    return items or None


def _dash_is_bullet(blocks: list[dict]) -> bool:
    """Dashes are bullets only if two consecutive dash lines carry two or more words."""
    run = 0
    for block in blocks:
        for line in block["lines"]:
            m = DASH_MARKER.match(line["text"])
            run = run + 1 if m and len(m.group(2).split()) >= 2 else 0
            if run >= 2:
                return True
    return False


def _item_block(parent: dict, item: dict) -> dict:
    lines = item["lines"]
    return {
        "bbox": [min(l["bbox"][0] for l in lines), min(l["bbox"][1] for l in lines),
                 max(l["bbox"][2] for l in lines), max(l["bbox"][3] for l in lines)],
        "text": "\n".join(l["text"] for l in lines),
        "lines": lines,
        "style": dict(parent["style"], line_height=parent["style"].get("line_height") if len(lines) > 1 else None),
        "unmapped": sum(l["text"].count("�") for l in lines),
        "kind": "list_item",
        "structure": {"marker": item["marker"]},
    }


def find_lists(blocks: list[dict]) -> list[dict]:
    """Returns units: plain blocks, and lists as {"kind": "list", "items": [...]} units."""
    allow_dash = _dash_is_bullet(blocks)
    units: list[dict] = []
    for block in sorted(blocks, key=lambda b: (b["bbox"][1], b["bbox"][0])):
        items = _items_of(block, allow_dash)
        if not items:
            units.append(block)
            continue
        new_items = [_item_block(block, it) for it in items]
        prev = units[-1] if units else None
        if (prev is not None and prev.get("kind") == "list" and _same_style(prev["items"][-1], new_items[0])
                and abs(prev["items"][-1]["bbox"][0] - new_items[0]["bbox"][0]) <= 3
                and new_items[0]["bbox"][1] - prev["bbox"][3] <= 1.5 * block["style"]["size"]):
            prev["items"].extend(new_items)          # one-item blocks that belong to the same list
            prev["bbox"] = _union([prev["bbox"], block["bbox"]])
        else:
            units.append({"kind": "list", "items": new_items, "bbox": list(block["bbox"]), "style": dict(block["style"])})
    return units


def _union(boxes: list[list[float]]) -> list[float]:
    return [min(b[0] for b in boxes), min(b[1] for b in boxes), max(b[2] for b in boxes), max(b[3] for b in boxes)]


# ----------------------------------------------------------------------------- reading order

def _gaps(intervals: list[tuple[float, float]]) -> list[float]:
    """Positions of empty stretches (wider than MIN_GAP) between projected intervals."""
    cuts, reach = [], None
    for lo, hi in sorted(intervals):
        if reach is not None and lo - reach >= MIN_GAP:
            cuts.append((reach + lo) / 2)
        reach = hi if reach is None else max(reach, hi)
    return cuts


def _gutters(units: list[dict]) -> list[tuple[float, float]]:
    """Empty vertical stretches (x ranges) between the units of a band."""
    ranges, reach = [], None
    for lo, hi in sorted((u["bbox"][0], u["bbox"][2]) for u in units):
        if reach is not None and lo - reach >= MIN_GAP:
            ranges.append((reach, lo))
        reach = hi if reach is None else max(reach, hi)
    return ranges


def _shares_gutter(a: list[tuple[float, float]], b: list[tuple[float, float]]) -> bool:
    return any(min(x1, y1) - max(x0, y0) >= MIN_GAP for x0, x1 in a for y0, y1 in b)


def _merge_column_bands(bands: list[list[dict]]) -> list[list[dict]]:
    """Consecutive bands whose column gutters line up are one multi-column region, read
    column by column (a horizontal gap between paragraphs is not the end of the columns)."""
    merged: list[list[dict]] = []
    for band in bands:
        if merged and _gutters(band) and _shares_gutter(_gutters(merged[-1]), _gutters(band)):
            merged[-1] = merged[-1] + band
        else:
            merged.append(band)
    return merged


def _split(units: list[dict], cuts: list[float], axis: int) -> list[list[dict]]:
    groups: list[list[dict]] = [[] for _ in range(len(cuts) + 1)]
    for u in units:
        centre = (u["bbox"][axis] + u["bbox"][axis + 2]) / 2
        groups[sum(1 for c in cuts if centre > c)].append(u)
    return [g for g in groups if g]


def order_units(units: list[dict], column: tuple[int, int] | None = None) -> list[dict]:
    """Recursive cuts: columns (with fill test) before bands; returns units in reading order."""
    if len(units) <= 1:
        for u in units:
            u["_column"] = column
        return units
    box = _union([u["bbox"] for u in units])
    height = max(box[3] - box[1], 1.0)
    vcuts = _gaps([(u["bbox"][0], u["bbox"][2]) for u in units])
    if vcuts:
        groups = _split(units, vcuts, 0)
        fills = [(_union([u["bbox"] for u in g])[3] - _union([u["bbox"] for u in g])[1]) / height for g in groups]
        if len(groups) > 1 and sorted(fills)[-2] >= COLUMN_FILL:
            ordered = []
            for i, g in enumerate(groups):
                ordered += order_units(g, (i, len(groups)))
            return ordered
    hcuts = _gaps([(u["bbox"][1], u["bbox"][3]) for u in units])
    if hcuts:
        bands = _merge_column_bands(_split(units, hcuts, 1))
        if len(bands) > 1:
            ordered = []
            for g in bands:
                ordered += order_units(g, column)
            return ordered
    if vcuts:
        ordered = []
        for g in _split(units, vcuts, 0):
            ordered += order_units(g, column)
        return ordered
    for u in units:
        u["_column"] = column
    return sorted(units, key=lambda u: (u["bbox"][1], u["bbox"][0]))


# ----------------------------------------------------------------------------- page

def structure_page(blocks: list[dict]) -> list[dict]:
    """Blocks → reading-ordered blocks with kind / parent / structure (list parents included)."""
    units = order_units(find_lists(join_paragraphs(blocks)))
    out: list[dict] = []
    for u in units:
        col = u.pop("_column", None)
        column = {"column": col[0], "columns": col[1]} if col else None
        if u.get("kind") == "list":
            parent_index = len(out)
            items = u["items"]
            out.append({"bbox": [round(v, 2) for v in u["bbox"]], "text": "\n".join(i["text"] for i in items),
                        "lines": [l for i in items for l in i["lines"]], "style": u["style"],
                        "unmapped": sum(i["unmapped"] for i in items), "kind": "list", "parent": None, "structure": column})
            for item in items:
                out.append(dict(item, parent=parent_index, structure=dict(item["structure"], **(column or {}))))
        else:
            out.append(dict(u, kind="paragraph", parent=None, structure=column))
    return out
