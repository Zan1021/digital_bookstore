#!/usr/bin/env python3
"""Tests for contact_sheet.py (task 8.2): every page shown, pairs side by side, gaps visible."""

import json
import os
import subprocess
import sys
import tempfile

import pymupdf
from PIL import Image

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import contact_sheet  # noqa: E402


def make_pdf(path, pages, colour):
    doc = pymupdf.open()
    for _ in range(pages):
        page = doc.new_page(width=400, height=600)
        page.draw_rect(page.rect, fill=colour, color=colour)
    doc.save(path)


def main():
    failures = []
    with tempfile.TemporaryDirectory() as d:
        src, trn, out = (os.path.join(d, n) for n in ("src.pdf", "trn.pdf", "sheet.png"))
        make_pdf(src, 6, (0, 0, 1))      # blue source
        make_pdf(trn, 5, (1, 0, 0))      # red translation, one page short

        res = contact_sheet.build(src, trn, out, "Test (af)", flagged={2}, per_row=4, thumb_height=120)
        img = Image.open(out).convert("RGB")
        if res["pages"] != 6:
            failures.append(f"expected 6 pages, got {res['pages']}")
        if img.size != (res["width"], res["height"]):
            failures.append("reported size does not match the image")

        # Geometry: thumb width 80 (400/600*120), cell = 80*2+4, first cell at x=12, y=36+12.
        y = 36 + 12 + 60
        if img.getpixel((12 + 40, y)) != (0, 0, 255):
            failures.append(f"page 1 source not shown on the left: {img.getpixel((52, y))}")
        if img.getpixel((12 + 84 + 40, y)) != (255, 0, 0):
            failures.append(f"page 1 translation not shown on the right: {img.getpixel((136, y))}")
        # Page 6 is the 2nd cell of row 2; its translation is missing -> grey placeholder.
        x6 = 12 + 1 * (164 + 12) + 84 + 40
        y6 = 36 + 12 + (120 + 22 + 12) + 60
        if img.getpixel((x6, y6)) != contact_sheet.MISSING:
            failures.append(f"missing translated page not drawn as placeholder: {img.getpixel((x6, y6))}")
        # Page 2 is flagged -> red frame just outside its cell.
        x2 = 12 + 1 * (164 + 12) - 2
        if img.getpixel((x2, 36 + 12 + 60)) != contact_sheet.FLAG:
            failures.append("flagged page has no red frame")

        # CLI prints a parseable answer, and fails loudly on a bad input.
        ok = subprocess.run([sys.executable, os.path.join(HERE, "contact_sheet.py"), "--source", src,
                             "--out", os.path.join(d, "s2.png")], capture_output=True, text=True)
        if ok.returncode != 0 or not json.loads(ok.stdout)["ok"]:
            failures.append(f"CLI without translation failed: {ok.stdout} {ok.stderr}")
        bad = subprocess.run([sys.executable, os.path.join(HERE, "contact_sheet.py"), "--source",
                              os.path.join(d, "nope.pdf"), "--out", os.path.join(d, "s3.png")],
                             capture_output=True, text=True)
        if bad.returncode == 0 or json.loads(bad.stdout)["ok"]:
            failures.append("CLI reported success for a missing source PDF")

    for f in failures:
        print("FAIL:", f)
    print("contact_sheet:", "FAILED" if failures else "OK")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
