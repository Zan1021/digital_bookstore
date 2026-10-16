#!/usr/bin/env python3
"""Tests for scene_tables.py (task 11.3): rows, columns, header cells, merged cells."""

import os
import sys
import tempfile

import pymupdf

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from scene_extract import extract  # noqa: E402

failures = []


def check(cond, msg):
    if not cond:
        failures.append(msg)


def grid(page, xs, ys, merged_top=None):
    """Draw a ruled grid; merged_top=(i, j) leaves out the vertical lines inside the top row from column i to j."""
    for y in ys:
        page.draw_line((xs[0], y), (xs[-1], y))
    for k, x in enumerate(xs):
        top = ys[1] if merged_top and merged_top[0] < k < merged_top[1] + 1 else ys[0]
        page.draw_line((x, top), (x, ys[-1]))


def write(page, items, size=12):
    font = pymupdf.Font("helv")
    tw = pymupdf.TextWriter(page.rect)
    for x, y, text in items:
        for i, line in enumerate(text.split("\n")):
            tw.append((x, y + i * size * 1.3), line, font=font, fontsize=size)
    tw.write_text(page)


def blocks_of(doc, d, name):
    path = os.path.join(d, name)
    doc.save(path)
    return extract(path, 1)["pages"][0]["blocks"]


def main():
    with tempfile.TemporaryDirectory() as d:
        # 1. A ruled word table: "WORDS" heading spans the first two columns.
        doc = pymupdf.open(); page = doc.new_page(width=420, height=595)
        grid(page, [40, 140, 240, 340], [60, 90, 300], merged_top=(0, 1))
        write(page, [(110, 80, "WORDS"), (250, 80, "SOUNDS"),
                     (50, 110, "cat\ndog\nsun\nhat"), (150, 110, "run\nfun\nbun\nnut"), (250, 110, "- sh\n- ch"), (250, 200, "- th\n- ng")])
        blocks = blocks_of(doc, d, "ruled.pdf")
        table = next((b for b in blocks if b["kind"] == "table"), None)
        check(table is not None, f"ruled table found: {[b['kind'] for b in blocks]}")
        if table:
            cells = [b for b in blocks if b["kind"] == "cell"]
            check(table["structure"]["rows"] == 2 and table["structure"]["cols"] == 3 and table["structure"]["ruled"], f"shape {table['structure']}")
            words = next(c for c in cells if c["text"] == "WORDS")
            check(words["structure"] == {"row": 0, "col": 0, "rowspan": 1, "colspan": 2, "header": True}, f"merged header {words['structure']}")
            check([c["structure"]["header"] for c in cells] == [True, True, False, False, False], "only the top row is the header")
            check([(c["structure"]["row"], c["structure"]["col"]) for c in cells] == [(0, 0), (0, 2), (1, 0), (1, 1), (1, 2)], "cells row by row")
            sounds = next(c for c in cells if c["text"].startswith("- sh"))
            check(sounds["text"] == "- sh\n- ch\n\n- th\n- ng", f"blocks in one cell joined with a blank line: {sounds['text']!r}")
            check(all(c["parent"] == blocks.index(table) for c in cells), "cells point at their table")
            check(sounds["allowed_area"] == [240, 90, 340, 300], f"allowed area is the cell: {sounds['allowed_area']}")
            check(not any(b["kind"] in ("list", "list_item") for b in blocks), "dash rows inside a cell are not a list")

        # 2. Crop marks / a frame around the page are not a table.
        doc = pymupdf.open(); page = doc.new_page(width=420, height=595)
        for x in (20, 400):
            page.draw_line((x, 0), (x, 595))
        for y in (20, 575):
            page.draw_line((0, y), (420, y))
        write(page, [(60, 100, "A story page"), (60, 300, "with some text")])
        check(not any(b["kind"] == "table" for b in blocks_of(doc, d, "frame.pdf")), "a page frame is not a table")

        # 3. A ruled table whose first row is ordinary data has no header row.
        doc = pymupdf.open(); page = doc.new_page(width=420, height=595)
        grid(page, [40, 180, 320], [60, 120, 180, 240])
        write(page, [(50, 90, "apple"), (190, 90, "red"), (50, 150, "banana"), (190, 150, "yellow"), (50, 210, "lime"), (190, 210, "green")])
        cells = [b for b in blocks_of(doc, d, "data.pdf") if b["kind"] == "cell"]
        check(len(cells) == 6 and not any(c["structure"]["header"] for c in cells), f"no header in a plain data table: {[c['structure'] for c in cells]}")

        # 4. A borderless word table: two columns of words, a heading over both.
        doc = pymupdf.open(); page = doc.new_page(width=420, height=595)
        write(page, [(100, 70, "WORDS TO LEARN"), (60, 110, "cat\ndog\nsun\nhat\nmat"), (220, 110, "run\nfun\nbun\nnut\ncut")])
        blocks = blocks_of(doc, d, "borderless.pdf")
        table = next((b for b in blocks if b["kind"] == "table"), None)
        check(table is not None and table["structure"]["ruled"] is False, f"borderless table: {[(b['kind'], b['text'][:10]) for b in blocks]}")
        if table:
            cells = [b for b in blocks if b["kind"] == "cell"]
            head = next(c for c in cells if c["text"] == "WORDS TO LEARN")
            check(head["structure"] == {"row": 0, "col": 0, "rowspan": 1, "colspan": 2, "header": True}, f"spanning heading {head['structure']}")
            check([c["text"][:3] for c in cells] == ["WOR", "cat", "run"], "heading first, then the columns")

        # 5. A story page with two short lines is not a borderless table.
        doc = pymupdf.open(); page = doc.new_page(width=420, height=595)
        write(page, [(60, 100, "Once upon a time\nthere was a kite"), (240, 100, "It flew\naway")])
        check(not any(b["kind"] == "table" for b in blocks_of(doc, d, "story.pdf")), "short text side by side is not a table")

    for f in failures:
        print("FAIL:", f)
    print("scene_tables:", "FAILED" if failures else "OK")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
