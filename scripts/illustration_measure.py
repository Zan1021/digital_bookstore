#!/usr/bin/env python3
"""
illustration_measure.py — deterministic background colour measurement + uniformity gate.

Spec: .kiro/specs/illustration-text-vision (R1.4, R2.4). The vision model PROPOSES sample
regions; we MEASURE the actual pixels here and decide whether the flat-fill route is safe.
Pure PyMuPDF, no numpy. Reads regions (pixel-space bboxes at --ppi) on --regions JSON,
prints an enriched regions JSON with measured color_rgb + text_color_rgb + a needs_review flag.

Usage:
  python illustration_measure.py --input <pdf> --page N --ppi 300 --regions <in.json>
Output (stdout): {"needs_review": bool, "regions": [ {..., "color_rgb":[r,g,b],
                  "text_color_rgb":[r,g,b], "background_type": "..."} ]}
"""

import argparse
import json
import statistics
import sys

import pymupdf


def _median_color(pix, box):
    x0, y0, x1, y1 = (int(round(v)) for v in box)
    x0 = max(0, min(x0, pix.width - 1))
    y0 = max(0, min(y0, pix.height - 1))
    x1 = max(0, min(x1, pix.width - 1))
    y1 = max(0, min(y1, pix.height - 1))
    if x1 <= x0 or y1 <= y0:
        return None, None
    rs, gs, bs = [], [], []
    step = max(1, (x1 - x0) // 24)
    ystep = max(1, (y1 - y0) // 24)
    for y in range(y0, y1 + 1, ystep):
        for x in range(x0, x1 + 1, step):
            p = pix.pixel(x, y)
            rs.append(p[0]); gs.append(p[1]); bs.append(p[2])
    if not rs:
        return None, None
    med = (int(statistics.median(rs)), int(statistics.median(gs)), int(statistics.median(bs)))
    # dispersion (spread) across channels — high spread => not a flat background
    spread = statistics.pstdev(rs) + statistics.pstdev(gs) + statistics.pstdev(bs)
    return med, spread


def measure(pdf_path, page_index, ppi, regions):
    doc = pymupdf.open(pdf_path)
    page = doc[page_index]
    zoom = ppi / 72.0
    pix = page.get_pixmap(matrix=pymupdf.Matrix(zoom, zoom), alpha=False)
    doc.close()

    UNIFORM_MAX_SPREAD = 18.0  # per-channel stdev sum; above this the bg is not flat
    needs_review = False
    out_regions = []

    for r in regions:
        bg_type = r.get("background_type", "uncertain")
        samples = r.get("sample_regions_px") or []
        meds, spreads = [], []
        for s in samples:
            med, spread = _median_color(pix, s)
            if med is not None:
                meds.append(med)
                spreads.append(spread)

        if bg_type == "uncertain" or not meds:
            needs_review = True
            r["needs_review"] = True
            out_regions.append(r)
            continue

        # agreement between sample patches
        avg = tuple(int(statistics.median([m[c] for m in meds])) for c in range(3))
        max_spread = max(spreads) if spreads else 999
        patch_disagreement = max(
            abs(m[c] - avg[c]) for m in meds for c in range(3)
        ) if meds else 999

        if bg_type == "flat":
            if max_spread > UNIFORM_MAX_SPREAD or patch_disagreement > 24:
                # not actually flat/uniform => don't risk a rectangle; review.
                needs_review = True
                r["needs_review"] = True
            else:
                r["color_rgb"] = list(avg)
                # readable text colour: cream on dark, dark on light (luminance heuristic)
                lum = 0.299 * avg[0] + 0.587 * avg[1] + 0.114 * avg[2]
                r["text_color_rgb"] = [255, 245, 200] if lum < 140 else [40, 30, 30]
        else:
            # gradient/illustration: measured colour informs nothing safe for solid fill;
            # deterministic edge-fill inpaint will be used. Provide avg as a hint only.
            r["color_rgb"] = list(avg)
            lum = 0.299 * avg[0] + 0.587 * avg[1] + 0.114 * avg[2]
            r["text_color_rgb"] = [255, 245, 200] if lum < 140 else [40, 30, 30]

        out_regions.append(r)

    return {"needs_review": needs_review, "regions": out_regions}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--input", "-i", required=True)
    ap.add_argument("--page", "-p", type=int, required=True)
    ap.add_argument("--ppi", type=int, default=300)
    ap.add_argument("--regions", required=True)
    args = ap.parse_args()

    with open(args.regions, "r", encoding="utf-8") as f:
        data = json.load(f)
    regions = data.get("regions", data if isinstance(data, list) else [])

    result = measure(args.input, args.page, args.ppi, regions)
    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
