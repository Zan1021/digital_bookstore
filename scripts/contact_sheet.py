#!/usr/bin/env python3
"""
contact_sheet.py — one PNG showing every page of an edition next to its source page
(task 8.2, design §22; the reviewer contact sheet of task 18.3 builds on it).

Each cell is a pair: source page | translated page, labelled with the page number. Pages
flagged for review get a red frame so a glance shows where to look. Missing translated
pages are drawn as a grey placeholder (never silently skipped).

CLI:
  python contact_sheet.py --source src.pdf [--translated out.pdf] --out sheet.png
                          [--title "Book (af)"] [--flagged 3,7] [--per-row 4] [--thumb-height 220]
Prints a JSON summary: {"ok": true, "pages": n, "width": w, "height": h}
"""

from __future__ import annotations

import argparse
import json
import sys

import pymupdf
from PIL import Image, ImageDraw, ImageFont

GAP = 12
PAIR_GAP = 4
LABEL_H = 22
TITLE_H = 36
BG = (245, 245, 245)
MISSING = (200, 200, 200)
FLAG = (220, 38, 38)


def _render(doc, index: int, height: int):
    if doc is None or index >= len(doc):
        return None
    page = doc[index]
    zoom = height / page.rect.height
    pix = page.get_pixmap(matrix=pymupdf.Matrix(zoom, zoom), alpha=False)
    return Image.frombytes("RGB", (pix.width, pix.height), pix.samples)


def _font(size: int):
    for name in ("arial.ttf", "DejaVuSans.ttf"):
        try:
            return ImageFont.truetype(name, size)
        except OSError:
            continue
    return ImageFont.load_default()


def build(source: str, translated: str | None, out: str, title: str = "",
          flagged: set[int] | None = None, per_row: int = 4, thumb_height: int = 220) -> dict:
    flagged = flagged or set()
    src = pymupdf.open(source)
    trn = pymupdf.open(translated) if translated else None
    pages = max(len(src), len(trn) if trn else 0)

    # Cell width from the widest source page at thumb height.
    widths = [int(p.rect.width * thumb_height / p.rect.height) for p in src] or [thumb_height]
    thumb_w = max(widths)
    cell_w = thumb_w * 2 + PAIR_GAP
    cell_h = thumb_height + LABEL_H
    cols = max(1, min(per_row, pages))
    rows = (pages + cols - 1) // cols
    width = GAP + cols * (cell_w + GAP)
    height = TITLE_H + GAP + rows * (cell_h + GAP)

    sheet = Image.new("RGB", (width, height), BG)
    draw = ImageDraw.Draw(sheet)
    draw.text((GAP, 8), title, fill=(30, 30, 30), font=_font(20))
    label_font = _font(14)

    for i in range(pages):
        r, c = divmod(i, cols)
        x = GAP + c * (cell_w + GAP)
        y = TITLE_H + GAP + r * (cell_h + GAP)
        for side, doc in enumerate((src, trn)):
            img = _render(doc, i, thumb_height)
            px = x + side * (thumb_w + PAIR_GAP)
            if img is None:
                draw.rectangle([px, y, px + thumb_w - 1, y + thumb_height - 1], fill=MISSING)
                if side == 1 and trn is not None:
                    draw.text((px + 8, y + 8), "missing", fill=(90, 90, 90), font=label_font)
            else:
                sheet.paste(img, (px + (thumb_w - img.width) // 2, y))
        if (i + 1) in flagged:
            draw.rectangle([x - 3, y - 3, x + cell_w + 2, y + thumb_height + 2], outline=FLAG, width=3)
        label = f"p{i + 1}" + ("  REVIEW" if (i + 1) in flagged else "")
        draw.text((x, y + thumb_height + 3), label, fill=FLAG if (i + 1) in flagged else (60, 60, 60), font=label_font)

    sheet.save(out, optimize=True)
    return {"ok": True, "pages": pages, "width": width, "height": height}


def main(argv=None) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--source", required=True)
    ap.add_argument("--translated")
    ap.add_argument("--out", required=True)
    ap.add_argument("--title", default="")
    ap.add_argument("--flagged", default="")
    ap.add_argument("--per-row", type=int, default=4)
    ap.add_argument("--thumb-height", type=int, default=220)
    a = ap.parse_args(argv)
    flagged = {int(p) for p in a.flagged.split(",") if p.strip().isdigit()}
    try:
        print(json.dumps(build(a.source, a.translated, a.out, a.title, flagged, a.per_row, a.thumb_height)))
    except Exception as e:  # fail loudly with a parseable answer, never a silent pass
        print(json.dumps({"ok": False, "error": str(e)}))
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
