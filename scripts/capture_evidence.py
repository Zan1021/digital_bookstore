#!/usr/bin/env python3
"""
capture_evidence.py — Phase 8.3 (R11.4): retain before/after visual evidence for the key
pages (cover, the p2 copyright regression, and an artwork/mask page), via BOTH the PyMuPDF
pixmap AND the PDF.js harness (the two renderers that disagreed on soft masks — keeping both
is the whole point).

This does NOT run the translation engine (that is the LVx live pass). It captures the
CURRENT state of a given PDF so a reviewer has a durable artifact. Point it at a source PDF
(before) and/or a translated PDF (after).

CLI:
  python capture_evidence.py --pdf <file.pdf> --pages 1,2,8 --out <dir> [--label before]
Writes <out>/<label>_p<N>_pymupdf.png and (if node available) <out>/<label>_p<N>_pdfjs.png,
and prints a JSON manifest of what was captured (incl. the pdfjs blank/variance signal).
"""

from __future__ import annotations

import argparse
import json
import os
import subprocess

import pymupdf


def _pymupdf_png(pdf, page_index, out_png, dpi=150):
    doc = pymupdf.open(pdf)
    if page_index < 0 or page_index >= doc.page_count:
        doc.close()
        return None
    pix = doc[page_index].get_pixmap(dpi=dpi)
    os.makedirs(os.path.dirname(out_png), exist_ok=True)
    pix.save(out_png)
    doc.close()
    return {"w": pix.width, "h": pix.height}


def _pdfjs_png(pdf, page_index, out_png, scale=2.0):
    """Render via the PDF.js harness; returns its JSON (incl. blank/variance) or None."""
    script = os.path.join(os.path.dirname(os.path.abspath(__file__)), "render_pdfjs.mjs")
    if not os.path.isfile(script):
        return None
    try:
        r = subprocess.run(["node", script, pdf, str(page_index), out_png, str(scale)],
                           capture_output=True, text=True, timeout=120)
        if r.returncode != 0:
            return {"error": r.stderr.strip()[:300]}
        return json.loads(r.stdout.strip())
    except Exception as e:  # node missing / timeout
        return {"error": str(e)}


def capture(pdf, pages, out_dir, label):
    manifest = {"pdf": pdf, "label": label, "pages": {}}
    for pn in pages:
        pi = pn - 1
        pm_png = os.path.join(out_dir, f"{label}_p{pn}_pymupdf.png")
        pj_png = os.path.join(out_dir, f"{label}_p{pn}_pdfjs.png")
        manifest["pages"][pn] = {
            "pymupdf": _pymupdf_png(pdf, pi, pm_png),
            "pdfjs": _pdfjs_png(pdf, pi, pj_png),
        }
    return manifest


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--pdf", required=True)
    ap.add_argument("--pages", required=True, help="comma-separated 1-based page numbers")
    ap.add_argument("--out", required=True)
    ap.add_argument("--label", default="capture")
    args = ap.parse_args()
    pages = [int(x) for x in args.pages.split(",") if x.strip()]
    os.makedirs(args.out, exist_ok=True)
    print(json.dumps(capture(args.pdf, pages, args.out, args.label), ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
