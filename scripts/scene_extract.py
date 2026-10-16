#!/usr/bin/env python3
"""
scene_extract.py — text blocks of a PDF page for the Scene Engine (task 11.1, Req 4.1, design §6).

Spans → lines → blocks. PyMuPDF's own blocks are the starting point, split wherever a line's
style changes (font, size beyond 10 %, colour, direction) or the gap to the previous line is
larger than a normal line step, so a cover's title and its publisher line become two blocks.
Each block carries:
  bbox            [x0, y0, x1, y1] in points, top-left origin, page coordinates
  text            the lines joined with "\\n" (the original line breaks)
  lines           [{text, bbox}] per line
  style           font, size, color (#rrggbb), weight (400/700), italic, align
                  (left/center/right/justify), rotation (degrees, counter-clockwise),
                  line_height (baseline step ÷ size, multi-line blocks only)
  unmapped        characters the font left unmapped (U+FFFD) — shown as unknown, never guessed
Blocks then go through scene_structure.py (task 11.2): paragraphs, lists (kind/parent/structure)
and reading order. --raw skips that step.
Book-agnostic: no fonts, titles or positions are known to the code.

CLI: python scene_extract.py --input book.pdf [--page N]   (N is 1-based; default: all pages)
Prints JSON: {"pages": [{"number", "width", "height", "rotation", "blocks": [...]}]}
"""

from __future__ import annotations

import argparse
import json
import math
import re
import statistics
import sys

import pymupdf

from scene_structure import structure_page
from scene_tables import find_tables

SIZE_TOLERANCE = 0.10      # a size change beyond 10 % starts a new block
GAP_FACTOR = 1.6           # a gap wider than 1.6 × the line height starts a new block
ALIGN_TOLERANCE = 2.5      # points of jitter allowed when deciding alignment

SUBSET_PREFIX = re.compile(r"^[A-Z]{6}\+")


def _font_name(name: str) -> str:
    return SUBSET_PREFIX.sub("", name or "")


def _line_style(line: dict) -> dict:
    """The line's dominant span style (by character count)."""
    spans = [s for s in line["spans"] if s["text"].strip()] or line["spans"]
    main = max(spans, key=lambda s: len(s["text"].strip()))
    font = _font_name(main["font"])
    bold = bool(main["flags"] & 16) or bool(re.search(r"bold|black|heavy", font, re.I))
    italic = bool(main["flags"] & 2) or bool(re.search(r"italic|oblique", font, re.I))
    dx, dy = line["dir"]
    return {
        "font": font,
        "size": round(main["size"], 2),
        "color": "#{:06x}".format(main["color"] & 0xFFFFFF),
        "weight": 700 if bold else 400,
        "italic": italic,
        "rotation": round(math.degrees(math.atan2(-dy, dx)), 1) % 360,
    }


def _same_style(a: dict, b: dict) -> bool:
    return (a["font"] == b["font"] and a["color"] == b["color"] and a["rotation"] == b["rotation"]
            and abs(a["size"] - b["size"]) <= SIZE_TOLERANCE * max(a["size"], b["size"]))


def _stacks_under(prev: dict, prev_style: dict, line: dict, style: dict) -> bool:
    """Does `line` continue the block whose last line is `prev` (directly below it)?"""
    if not _same_style(prev_style, style):
        return False
    height = max(prev["bbox"][3] - prev["bbox"][1], 1.0)
    gap = line["bbox"][1] - prev["bbox"][3]
    if gap > GAP_FACTOR * height or line["bbox"][1] < prev["bbox"][1] + 0.5 * height:
        return False                       # too far below, or on the same row (side by side)
    overlap = min(prev["bbox"][2], line["bbox"][2]) - max(prev["bbox"][0], line["bbox"][0])
    narrower = min(prev["bbox"][2] - prev["bbox"][0], line["bbox"][2] - line["bbox"][0])
    return overlap > 0.3 * max(narrower, 1.0)


def _line_text(line: dict) -> str:
    return "".join(s["text"] for s in line["spans"]).rstrip()


def _alignment(lines: list[dict]) -> str:
    if len(lines) < 2:
        return "left"
    lefts = [l["bbox"][0] for l in lines]
    rights = [l["bbox"][2] for l in lines]
    centres = [(l["bbox"][0] + l["bbox"][2]) / 2 for l in lines]
    spread = lambda v: max(v) - min(v)  # noqa: E731
    left_ok = spread(lefts) <= ALIGN_TOLERANCE
    # The last line of a justified paragraph is short: judge right edges without it.
    right_ok = spread(rights[:-1] if len(rights) > 2 else rights) <= ALIGN_TOLERANCE
    if left_ok and right_ok and len(lines) > 2:
        return "justify"
    if left_ok:
        return "left"
    if spread(rights) <= ALIGN_TOLERANCE:
        return "right"
    if spread(centres) <= ALIGN_TOLERANCE * 2:
        return "center"
    return "left"


def _make_block(lines: list[dict], styles: list[dict]) -> dict:
    x0 = min(l["bbox"][0] for l in lines)
    y0 = min(l["bbox"][1] for l in lines)
    x1 = max(l["bbox"][2] for l in lines)
    y1 = max(l["bbox"][3] for l in lines)
    texts = [_line_text(l) for l in lines]
    style = dict(styles[0])
    style["size"] = round(statistics.median(s["size"] for s in styles), 2)
    style["align"] = _alignment(lines)
    if len(lines) > 1:
        baselines = [l["spans"][0]["origin"][1] for l in lines]
        steps = [b - a for a, b in zip(baselines, baselines[1:]) if b > a]
        style["line_height"] = round(statistics.median(steps) / style["size"], 3) if steps and style["size"] else None
    else:
        style["line_height"] = None
    text = "\n".join(texts)
    return {
        "bbox": [round(v, 2) for v in (x0, y0, x1, y1)],
        "text": text,
        "lines": [{"text": t, "bbox": [round(v, 2) for v in l["bbox"]]} for t, l in zip(texts, lines)],
        "style": style,
        "unmapped": text.count("�"),
    }


def extract_page(page, raw_blocks: bool = False) -> dict:
    blocks = []
    for raw in page.get_text("dict")["blocks"]:
        if raw.get("type") != 0:
            continue
        # PyMuPDF lists a block's lines in content order; work top to bottom. A line joins the
        # group whose last line sits directly above it (same style, overlapping horizontally,
        # a normal gap); otherwise it starts a group. So text side by side in one PDF text
        # object (a table's header row) becomes separate blocks.
        lines = sorted((l for l in raw["lines"] if _line_text(l).strip()), key=lambda l: (l["bbox"][1], l["bbox"][0]))
        groups: list[tuple[list, list]] = []
        for line in lines:
            style = _line_style(line)
            home = next((g for g in reversed(groups) if _stacks_under(g[0][-1], g[1][-1], line, style)), None)
            if home is None:
                groups.append(([line], [style]))
            else:
                home[0].append(line)
                home[1].append(style)
        blocks.extend(_make_block(g_lines, g_styles) for g_lines, g_styles in groups)

    if raw_blocks:
        blocks.sort(key=lambda b: (round(b["bbox"][1] / 4), b["bbox"][0]))
    else:
        # Task 11.3: tables first (so text inside cells is not read as lists or paragraphs),
        # then task 11.2: paragraphs, lists, columns and reading order.
        tables, rest = find_tables(page, blocks)
        blocks = structure_page(rest, tables)
    return {"number": page.number + 1, "width": round(page.rect.width, 2), "height": round(page.rect.height, 2),
            "rotation": page.rotation, "blocks": blocks}


def extract(path: str, page_number: int | None = None, raw_blocks: bool = False) -> dict:
    doc = pymupdf.open(path)
    numbers = [page_number] if page_number else range(1, len(doc) + 1)
    pages = []
    for n in numbers:
        if not 1 <= n <= len(doc):
            raise ValueError(f"page {n} is outside 1–{len(doc)}")
        pages.append(extract_page(doc[n - 1], raw_blocks))
    doc.close()
    return {"pages": pages}


def main(argv=None) -> int:
    # Book text is not ASCII (’ – é ŉ …): always write UTF-8, whatever the console or pipe
    # default is (on Windows a pipe defaults to cp1252, which garbles it).
    sys.stdout.reconfigure(encoding="utf-8")
    ap = argparse.ArgumentParser()
    ap.add_argument("--input", required=True)
    ap.add_argument("--page", type=int)
    ap.add_argument("--raw", action="store_true", help="blocks only, without paragraphs/lists/reading order")
    a = ap.parse_args(argv)
    try:
        print(json.dumps(extract(a.input, a.page, a.raw), ensure_ascii=False))
    except Exception as e:  # a parseable failure, never a silent empty page
        print(json.dumps({"error": str(e)}))
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
