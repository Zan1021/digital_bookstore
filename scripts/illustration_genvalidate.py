#!/usr/bin/env python3
"""
illustration_genvalidate.py — sanity-check a generative reconstruction before we trust it.

The images-edit model can return a plausible-looking-but-wrong reconstruction (garbage
texture, wrong colours, or it ignored the mask). Since the deterministic erase already
ran, a bad generative result should simply be REJECTED (keep the clean deterministic
erase). This checks, for each text region, that the generated region's median colour is
close to the surrounding background ring's median. Prints {"ok": bool, "regions": [...]}.

Pure PyMuPDF. Usage:
  python illustration_genvalidate.py --gen <gen.png> --orig-pdf <pdf> --page N --ppi P
        --regions <json>
"""

import argparse
import json
import statistics
import sys

import pymupdf


def _median_rgb(pix, x0, y0, x1, y1):
    x0 = max(0, min(int(x0), pix.width - 1)); x1 = max(0, min(int(x1), pix.width - 1))
    y0 = max(0, min(int(y0), pix.height - 1)); y1 = max(0, min(int(y1), pix.height - 1))
    if x1 <= x0 or y1 <= y0:
        return None
    rs, gs, bs = [], [], []
    xs = max(1, (x1 - x0) // 20); ys = max(1, (y1 - y0) // 20)
    for y in range(y0, y1 + 1, ys):
        for x in range(x0, x1 + 1, xs):
            p = pix.pixel(x, y)
            rs.append(p[0]); gs.append(p[1]); bs.append(p[2])
    if not rs:
        return None
    return (statistics.median(rs), statistics.median(gs), statistics.median(bs))


def validate(gen_path, orig_pdf, page_index, ppi, regions):
    gen = pymupdf.Pixmap(gen_path)
    if gen.alpha:
        gen = pymupdf.Pixmap(gen, 0)

    doc = pymupdf.open(orig_pdf)
    page = doc[page_index]
    zoom = ppi / 72.0
    orig = page.get_pixmap(matrix=pymupdf.Matrix(zoom, zoom), alpha=False)
    doc.close()

    ok_all = True
    out = []
    gap = max(4, int(ppi / 40))
    for r in regions:
        b = r.get("bbox_px")
        if not (isinstance(b, (list, tuple)) and len(b) == 4):
            continue
        x0, y0, x1, y1 = b
        gen_med = _median_rgb(gen, x0, y0, x1, y1)
        # surrounding ring from the ORIGINAL (clean background reference around the text)
        ring_med = _median_rgb(orig, x0 - gap, y0 - gap, x1 + gap, y0)  # strip above
        if gen_med is None or ring_med is None:
            out.append({"region": r.get("source_text"), "ok": False, "reason": "sample failed"})
            ok_all = False
            continue
        dist = sum(abs(gen_med[i] - ring_med[i]) for i in range(3))
        # generous threshold: reject only clearly-wrong reconstructions
        region_ok = dist < 140
        if not region_ok:
            ok_all = False
        out.append({"region": r.get("source_text"), "ok": region_ok,
                    "dist": round(dist, 1)})
    return {"ok": ok_all, "regions": out}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--gen", required=True)
    ap.add_argument("--orig-pdf", required=True)
    ap.add_argument("--page", type=int, required=True)
    ap.add_argument("--ppi", type=int, default=300)
    ap.add_argument("--regions", required=True)
    args = ap.parse_args()

    with open(args.regions, "r", encoding="utf-8") as f:
        data = json.load(f)
    regions = data.get("regions", data if isinstance(data, list) else [])
    print(json.dumps(validate(args.gen, args.orig_pdf, args.page, args.ppi, regions)))


if __name__ == "__main__":
    main()
