#!/usr/bin/env python3
"""Tests for scene_structure.py (task 11.2): paragraphs, lists, columns and reading order."""

import os
import sys
import tempfile

import pymupdf

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from scene_extract import extract  # noqa: E402
from scene_structure import structure_page  # noqa: E402

failures = []


def check(cond, msg):
    if not cond:
        failures.append(msg)


def block(text, x0, y0, x1=None, size=12.0, font="Body", color="#000000", line_step=None):
    """A block as scene_extract builds it: one line per text line, `line_step` apart."""
    lines, step = [], line_step or size * 1.2
    for i, t in enumerate(text.split("\n")):
        width = (x1 - x0) if x1 else len(t) * size * 0.5
        lines.append({"text": t, "bbox": [x0, y0 + i * step, x0 + width, y0 + i * step + size]})
    return {"bbox": [x0, y0, max(l["bbox"][2] for l in lines), lines[-1]["bbox"][3]], "text": text, "lines": lines,
            "style": {"font": font, "size": size, "color": color, "weight": 400, "italic": False, "rotation": 0.0,
                      "align": "left", "line_height": None}, "unmapped": 0}


def texts(out):
    return [(b["kind"], b["text"].split("\n")[0]) for b in out]


def main():
    # 1. Two columns under a full-width title, with a footer: title, left column, right column, footer.
    page = [
        block("Footer note", 40, 760, 300),
        block("Right one\nstill right", 320, 120, 560),
        block("The Big Title", 40, 40, 560, size=30),
        block("Left one\nstill left", 40, 120, 280),
        block("Right two", 320, 400, 560),
        block("Left two\nmore left", 40, 400, 280),
    ]
    out = structure_page(page)
    check([t for _, t in texts(out)] == ["The Big Title", "Left one", "Left two", "Right one", "Right two", "Footer note"],
          f"two-column order: {[t for _, t in texts(out)]}")
    cols = {b["text"].split("\n")[0]: b["structure"] for b in out}
    check(cols["Left one"] == {"column": 0, "columns": 2} and cols["Right two"] == {"column": 1, "columns": 2}, f"columns: {cols}")
    check(cols["The Big Title"] is None and cols["Footer note"] is None, "full-width text has no column")

    # 2. A numbered list inside one block → a list with one item each.
    out = structure_page([block("Titles in the series:", 60, 40, 400), block("1 - First Book\n2 - Second Book\n3 - Third Book", 80, 80, 300)])
    check(texts(out) == [("paragraph", "Titles in the series:"), ("list", "1 - First Book"), ("list_item", "1 - First Book"),
                         ("list_item", "2 - Second Book"), ("list_item", "3 - Third Book")], f"numbered list: {texts(out)}")
    check([b["parent"] for b in out] == [None, None, 1, 1, 1], f"list parents: {[b['parent'] for b in out]}")
    check([b["structure"].get("marker") for b in out[2:]] == ["1 -", "2 -", "3 -"], "markers kept")

    # 3. Bullet items arriving as separate blocks join one list; a continuation line stays with its item.
    b1 = block("• Pack a hat", 60, 100, 300)
    b2 = block("• Fill the water bottle\n   before you leave", 60, 116, 300, line_step=14)
    b2["lines"][1]["bbox"][0] = 75
    out = structure_page([b1, b2, block("• Bring a friend", 60, 148, 300)])
    check(texts(out)[0][0] == "list" and len(out) == 4, f"bullets joined: {texts(out)}")
    check(out[2]["text"] == "• Fill the water bottle\n   before you leave", f"continuation kept: {out[2]['text']!r}")

    # 4. Phonics dashes are not bullets; dashes before phrases are.
    out = structure_page([block("- tch\n- ee\n- sh", 60, 100, 120)])
    check(texts(out) == [("paragraph", "- tch")], f"phonics stays text: {texts(out)}")
    out = structure_page([block("- Pack a warm hat\n- Fill the water bottle", 60, 100, 300)])
    check([k for k, _ in texts(out)] == ["list", "list_item", "list_item"], f"dash list: {texts(out)}")

    # 5. Running text split into one block per line is joined; a credits page is not.
    a = block("Once upon a time there was a kite that", 60, 100, 400)
    b = block("loved the wind more than anything.", 60, 114.4, 360)
    out = structure_page([a, b])
    check(len(out) == 1 and out[0]["text"] == "Once upon a time there was a kite that\nloved the wind more than anything.", f"wrapped join: {texts(out)}")
    credits = [block(t, 60, 100 + i * 12, 60 + len(t) * 4, size=7.5) for i, t in enumerate(
        ["Published by Little Press", "PO Box 12, Somewhere 2059", "Story by: A. Writer", "www.littlepress.example", "hello@littlepress.example"])]
    out = structure_page(credits)
    check(len(out) == 5, f"credit lines stay separate: {texts(out)}")
    sentence = [block("The kite flew away.", 60, 100, 400), block("then it came back", 60, 114.4, 300)]
    check(len(structure_page(sentence)) == 2, "a finished sentence is not joined to the next block")
    short = [block("Short line", 60, 100, 160), block("next words here", 60, 114.4, 300)]
    check(len(structure_page(short)) == 2, "a short line is a deliberate break, not a wrap")

    # 6. A real PDF: header row in one text object → three heading blocks, read with their columns.
    with tempfile.TemporaryDirectory() as d:
        path = os.path.join(d, "table.pdf")
        doc = pymupdf.open()
        pg = doc.new_page(width=420, height=595)
        font = pymupdf.Font("helv")
        tw = pymupdf.TextWriter(pg.rect)
        for x, t in ((60, "WORDS"), (180, "COMMON"), (300, "SOUNDS")):
            tw.append((x, 80), t, font=font, fontsize=14)
        for col, words in enumerate((["cat", "dog", "sun"], ["the", "and", "is"], ["- sh", "- ch", "- th"])):
            for i, w in enumerate(words):
                tw.append((60 + col * 120, 120 + i * 18), w, font=font, fontsize=14)
        tw.write_text(pg)
        doc.save(path)
        out = extract(path, 1)["pages"][0]["blocks"]
        heads = [b["text"] for b in out if b["text"] in ("WORDS", "COMMON", "SOUNDS")]
        check(heads == ["WORDS", "COMMON", "SOUNDS"], f"side-by-side headings separate, in column order: {[b['text'] for b in out]}")
        check(all(b["kind"] == "paragraph" for b in out), "phonics column is not a list")
        idx = {b["text"].split("\n")[0]: i for i, b in enumerate(out)}
        check(idx["WORDS"] < idx["cat"] < idx["COMMON"] < idx["the"] < idx["SOUNDS"], f"column-major order: {idx}")

    for f in failures:
        print("FAIL:", f)
    print("scene_structure:", "FAILED" if failures else "OK")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
