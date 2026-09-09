#!/usr/bin/env python3
"""
illustration_genmask.py — prepare a SQUARE base image + alpha mask for the OpenAI images
edit endpoint (generative background reconstruction, opt-in route; brief §4).

The edit endpoint needs a square RGBA PNG and a mask PNG where TRANSPARENT pixels mark the
area to regenerate. We:
  1. render page N at --ppi to an opaque RGB raster,
  2. pad it to a square canvas (top-left origin; padding is fully transparent in the mask so
     the model won't touch it — but the base image keeps the artwork so it has context),
  3. build a mask that is OPAQUE (keep) everywhere EXCEPT the text region boxes, which are
     TRANSPARENT (regenerate) — plus the pad area transparent is avoided by making pad OPAQUE
     in the mask so the model leaves the pad alone.

Pure PyMuPDF (Pixmap RGBA). No numpy/PIL required. Prints the square side to stdout.

Usage:
  python illustration_genmask.py --input <pdf> --page N --ppi 300 --regions <json>
        --out-image <png> --out-mask <png>
"""

import argparse
import json
import os
import sys

import pymupdf


def _square_side(w, h):
    """Square canvas side = at least max(w,h). Snap UP to a supported edit size when the
    page already fits inside one; otherwise use max(w,h) (the edit call downsizes as needed)."""
    side = max(w, h)
    for s in (256, 512, 1024):
        if side <= s:
            return s
    return side


def build(input_pdf, page_index, ppi, regions, out_image, out_mask):
    doc = pymupdf.open(input_pdf)
    page = doc[page_index]
    zoom = ppi / 72.0
    pix = page.get_pixmap(matrix=pymupdf.Matrix(zoom, zoom), alpha=False)
    doc.close()

    w, h = pix.width, pix.height
    side = _square_side(w, h)

    # --- BASE: square RGB with the page top-left, padded white; then add alpha => RGBA. ---
    # Build an RGB square via a raw buffer (3 channels), copying row-by-row honouring stride.
    sn = pix.n            # source channels (3 for RGB, no alpha)
    src = pix.samples
    sstride = pix.stride  # bytes per source row (may exceed w*sn)
    base_rgb = bytearray([255]) * (side * side * 3)
    for y in range(h):
        srow = y * sstride
        drow = y * side * 3
        for x in range(w):
            si = srow + x * sn
            di = drow + x * 3
            base_rgb[di] = src[si]
            base_rgb[di + 1] = src[si + 1]
            base_rgb[di + 2] = src[si + 2]
    base = pymupdf.Pixmap(pymupdf.csRGB, side, side, bytes(base_rgb), False)  # RGB, no alpha
    base.save(out_image)  # PNG; opaque base is fine for the edit endpoint

    # --- MASK: square RGBA. Opaque (keep) everywhere; alpha 0 (regenerate) over text boxes.
    holes = _write_alpha_mask(side, regions, ppi, out_mask)

    print(side)
    return {"side": side, "holes": holes, "page_px": [w, h]}


def _write_alpha_mask(side, regions, ppi, out_mask):
    """Write an RGBA PNG mask: white opaque everywhere, fully transparent over text boxes.
    Uses a raw samples buffer so it works on any PyMuPDF version (no per-pixel alpha API).
    Returns the number of holes punched."""
    # 4 channels RGBA
    n = 4
    buf = bytearray([255]) * (side * side * n)  # init all 255 (white, opaque)
    pad = max(2, int(ppi / 100))
    holes = 0
    for r in regions:
        b = r.get("bbox_px")
        if not (isinstance(b, (list, tuple)) and len(b) == 4):
            continue
        x0, y0, x1, y1 = (int(round(v)) for v in b)
        x0 = max(0, x0 - pad); y0 = max(0, y0 - pad)
        x1 = min(side - 1, x1 + pad); y1 = min(side - 1, y1 + pad)
        if x1 <= x0 or y1 <= y0:
            continue
        for y in range(y0, y1 + 1):
            row = y * side * n
            for x in range(x0, x1 + 1):
                i = row + x * n
                buf[i + 3] = 0  # alpha 0 => transparent => regenerate
        holes += 1
    pix = pymupdf.Pixmap(pymupdf.csRGB, side, side, bytes(buf), True)
    pix.save(out_mask)
    return holes


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--input", "-i", required=True)
    ap.add_argument("--page", "-p", type=int, required=True)
    ap.add_argument("--ppi", type=int, default=300)
    ap.add_argument("--regions", required=True)
    ap.add_argument("--out-image", required=True)
    ap.add_argument("--out-mask", required=True)
    args = ap.parse_args()

    with open(args.regions, "r", encoding="utf-8") as f:
        data = json.load(f)
    regions = data.get("regions", data if isinstance(data, list) else [])

    report = build(args.input, args.page, args.ppi, regions, args.out_image, args.out_mask)
    print(json.dumps(report), file=sys.stderr)


if __name__ == "__main__":
    main()
