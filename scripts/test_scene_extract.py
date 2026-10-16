#!/usr/bin/env python3
"""Tests for scene_extract.py (task 11.1): spans → lines → blocks with box, style, rotation and line breaks."""

import json
import os
import subprocess
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


def by_text(page, start):
    return next((b for b in page["blocks"] if b["text"].startswith(start)), None)


def main():
    with tempfile.TemporaryDirectory() as d:
        path = os.path.join(d, "page.pdf")
        doc = pymupdf.open()
        page = doc.new_page(width=420, height=595)
        # A cover-like title and, directly under it, a small publisher line in another style.
        page.insert_text((60, 120), "The Lost Kite", fontsize=36, fontname="hebo", color=(0.4, 0.2, 0.6))
        page.insert_text((60, 140), "LITTLE PRESS", fontsize=9, fontname="helv", color=(0, 0, 0))
        # A paragraph whose three lines keep their breaks.
        for i, line in enumerate(["Once upon a time", "a kite flew away", "over the hills."]):
            page.insert_text((60, 300 + i * 20), line, fontsize=14, fontname="helv")
        # Centred lines written as one text object (PyMuPDF may still split off a first line;
        # joining paragraphs across PyMuPDF blocks is task 11.2).
        font = pymupdf.Font("helv")
        tw = pymupdf.TextWriter(page.rect)
        for i, t in enumerate(["Centred line one", "short", "a middle one"]):
            tw.append(((420 - font.text_length(t, fontsize=12)) / 2, 410 + i * 16), t, font=font, fontsize=12)
        tw.write_text(page)
        # Rotated label and italic note.
        page.insert_text((380, 500), "SIDE LABEL", fontsize=10, fontname="helv", rotate=90)
        page.insert_text((60, 560), "an italic note", fontsize=10, fontname="heit")
        doc.save(path)

        p = extract(path, 1)["pages"][0]
        check((p["number"], p["width"], p["height"]) == (1, 420, 595), f"page header {p['number'], p['width'], p['height']}")

        title, publisher = by_text(p, "The Lost Kite"), by_text(p, "LITTLE PRESS")
        check(title is not None and publisher is not None and title is not publisher, "title and publisher line are separate blocks")
        if title:
            s = title["style"]
            check(s["size"] == 36 and s["weight"] == 700 and s["color"] == "#663399", f"title style {s}")
            check(title["bbox"][1] < publisher["bbox"][1], "title box above the publisher line")

        para = by_text(p, "Once upon a time")
        check(para is not None and para["text"] == "Once upon a time\na kite flew away\nover the hills.", f"line breaks kept: {para and para['text']!r}")
        if para:
            check(len(para["lines"]) == 3 and para["style"]["align"] == "left", f"paragraph lines/align {para['style']}")
            check(abs(para["style"]["line_height"] - 20 / 14) < 0.02, f"line height {para['style']['line_height']}")

        centred = by_text(p, "short")
        check(centred is not None and centred["style"]["align"] == "center", f"centred: {centred and centred['style']['align']}")

        side = by_text(p, "SIDE LABEL")
        check(side is not None and side["style"]["rotation"] == 90.0, f"rotation {side and side['style']['rotation']}")

        italic = by_text(p, "an italic note")
        check(italic is not None and italic["style"]["italic"] is True and italic["style"]["weight"] == 400, f"italic {italic and italic['style']}")

        # Characters a font leaves unmapped arrive as U+FFFD (real Kolulu PDFs: the apostrophe,
        # the registered sign). A test font cannot write one, so the block builder is fed directly.
        from scene_extract import _line_style, _make_block
        line = {"bbox": (10, 10, 60, 20), "dir": (1, 0), "spans": [{"text": "Kolulu�s Home �", "font": "ABCDEF+Edu-Aid",
                "size": 12.0, "color": 0x231F20, "flags": 4, "origin": (10, 18)}]}
        built = _make_block([line], [_line_style(line)])
        check(built["unmapped"] == 2 and built["style"]["font"] == "Edu-Aid", f"unmapped/subset prefix: {built}")

        tops = [b["bbox"][1] for b in p["blocks"]]
        check(tops == sorted(tops) or all(abs(a - b) < 8 or a < b for a, b in zip(tops, tops[1:])), "blocks listed top to bottom")

        # A justified paragraph.
        jpath = os.path.join(d, "justify.pdf")
        jdoc = pymupdf.open()
        jpage = jdoc.new_page(width=420, height=595)
        jpage.insert_textbox(pymupdf.Rect(60, 60, 300, 300), " ".join(["word"] * 60), fontsize=11, fontname="helv", align=3)
        jdoc.save(jpath)
        just = extract(jpath, 1)["pages"][0]["blocks"][0]
        check(just["style"]["align"] == "justify", f"justify: {just['style']['align']}")

        # CLI: JSON for one page, a parseable error for a page that does not exist.
        ok = subprocess.run([sys.executable, os.path.join(HERE, "scene_extract.py"), "--input", path, "--page", "1"], capture_output=True, text=True)
        check(ok.returncode == 0 and len(json.loads(ok.stdout)["pages"]) == 1, f"CLI page: {ok.stderr[:200]}")
        bad = subprocess.run([sys.executable, os.path.join(HERE, "scene_extract.py"), "--input", path, "--page", "9"], capture_output=True, text=True)
        check(bad.returncode == 1 and "outside" in json.loads(bad.stdout)["error"], "CLI bad page")

    for f in failures:
        print("FAIL:", f)
    print("scene_extract:", "FAILED" if failures else "OK")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
