#!/usr/bin/env python3
"""
illustration_classify.py — SOURCE-based content-class classifier (Phase 4 deferred item 1,
spec R5.1).

Each detected text region on a page is one of THREE content classes, determined from the
SOURCE PDF (I5 — never from a rendered screenshot):

  * native         — the glyphs exist as selectable PDF TEXT objects over the artwork. The
                     V8 contract renderer owns these; they are redacted/replaced as text.
  * outlined_vector— the lettering is drawn as PDF VECTOR path objects (fills/strokes/
                     curves), NOT a text layer and NOT raster pixels. Deleting a text layer
                     would NOT remove it (there is none); it needs path removal or raster
                     repair, and must be flagged distinctly (the brief's "hidden OCR layer
                     is not proof visible lettering can be removed by deleting text" trap).
  * raster_text    — the glyphs are baked into image pixels. Repaired by artwork_repair.

Decision (per region bbox, in PDF POINTS):
  1. If a native text LINE overlaps the box  -> native.
  2. Else if dense vector DRAWING paths cover the box (many short path segments, typical of
     outlined lettering) AND no text layer -> outlined_vector.
  3. Else -> raster_text.

Pure PyMuPDF, no numpy. Reads regions (bbox in PIXEL space at --ppi) and prints the same
regions with a `content_class` (and `classified_by`) added.

Usage:
  python illustration_classify.py --input <pdf> --page N --ppi P --regions <in.json>
Output (stdout): {"regions": [ {..., "content_class": "...", "classified_by": "..."} ]}
"""

import argparse
import json
import sys

import pymupdf


def _overlap(a, b) -> float:
    """Fraction of box `a` (x0,y0,x1,y1) covered by its intersection with `b`."""
    ix0 = max(a[0], b[0]); iy0 = max(a[1], b[1])
    ix1 = min(a[2], b[2]); iy1 = min(a[3], b[3])
    iw = max(0.0, ix1 - ix0); ih = max(0.0, iy1 - iy0)
    inter = iw * ih
    area_a = max(1e-6, (a[2] - a[0]) * (a[3] - a[1]))
    return inter / area_a


def _native_lines(page) -> list:
    out = []
    for b in page.get_text("dict").get("blocks", []):
        for l in b.get("lines", []):
            txt = "".join(s["text"] for s in l.get("spans", [])).strip()
            if txt:
                out.append(tuple(l["bbox"]))
    return out


def _vector_boxes(page) -> list:
    """Bounding boxes of vector drawing paths that look like lettering: small-to-medium
    filled/stroked path items. We return each drawing's rect; density is judged by COUNT of
    path items overlapping a region (outlined text = many small glyph paths)."""
    boxes = []
    try:
        drawings = page.get_drawings()
    except Exception:
        return boxes
    for d in drawings:
        r = d.get("rect")
        if r is None:
            continue
        # ignore page-spanning background fills (not lettering)
        pr = page.rect
        if (r.width >= pr.width * 0.9) and (r.height >= pr.height * 0.9):
            continue
        boxes.append((r.x0, r.y0, r.x1, r.y1))
    return boxes


def classify(pdf_path, page_index, ppi, regions):
    doc = pymupdf.open(pdf_path)
    page = doc[page_index]
    scale = 72.0 / ppi  # px -> pt
    native = _native_lines(page)
    vboxes = _vector_boxes(page)
    doc.close()

    out = []
    for r in regions:
        b = r.get("bbox_px")
        if not (isinstance(b, (list, tuple)) and len(b) == 4):
            r["content_class"] = r.get("content_class", "raster_text")
            r["classified_by"] = "no_bbox"
            out.append(r)
            continue
        box_pt = (b[0] * scale, b[1] * scale, b[2] * scale, b[3] * scale)

        # 1. native text layer overlap
        if any(_overlap(box_pt, nl) > 0.3 or _overlap(nl, box_pt) > 0.5 for nl in native):
            r["content_class"] = "native"
            r["classified_by"] = "text_layer"
            out.append(r)
            continue

        # 2. dense vector paths over the box, no text layer => outlined/vector lettering
        hits = sum(1 for vb in vboxes if _overlap(box_pt, vb) > 0.05 or _overlap(vb, box_pt) > 0.2)
        if hits >= 3:
            r["content_class"] = "outlined_vector"
            r["classified_by"] = f"vector_paths:{hits}"
            out.append(r)
            continue

        # 3. default: baked into raster pixels
        r["content_class"] = "raster_text"
        r["classified_by"] = "raster_default"
        out.append(r)

    return {"regions": out}


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
    print(json.dumps(classify(args.input, args.page, args.ppi, regions), ensure_ascii=False))


if __name__ == "__main__":
    main()
