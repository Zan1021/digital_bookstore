"""
Render a book's first page to a JPEG cover image for the store (task 3.2).

The store shows this image instead of loading the protected source PDF in the browser.
Book-agnostic: always page 1, trimmed by the book's crop fractions (the same trim the
reader applies, so print crop marks and bleed are cut off), scaled to a fixed width.

Run: python scripts/render_cover.py <source.pdf> <out.jpg> [--trim L,T,R,B] [--width 600] [--quality 85]
Prints a JSON line {"ok": true, "width": W, "height": H} or exits non-zero.
"""
import argparse
import json
import os
import sys

import pymupdf


def render_cover(pdf_path, out_path, width=600, quality=85, trim=(0.0, 0.0, 0.0, 0.0)):
    doc = pymupdf.open(pdf_path)
    try:
        if len(doc) == 0:
            raise ValueError("PDF has no pages")
        page = doc[0]
        r = page.rect
        left, top, right, bottom = (max(0.0, min(0.45, float(v))) for v in trim)
        clip = pymupdf.Rect(r.x0 + r.width * left, r.y0 + r.height * top,
                            r.x1 - r.width * right, r.y1 - r.height * bottom)
        zoom = width / clip.width
        pix = page.get_pixmap(matrix=pymupdf.Matrix(zoom, zoom), clip=clip, alpha=False)
        os.makedirs(os.path.dirname(os.path.abspath(out_path)), exist_ok=True)
        pix.save(out_path, jpg_quality=quality)
        return pix.width, pix.height
    finally:
        doc.close()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("pdf")
    ap.add_argument("out")
    ap.add_argument("--width", type=int, default=600)
    ap.add_argument("--quality", type=int, default=85)
    ap.add_argument("--trim", default="0,0,0,0", help="fractions cut from left,top,right,bottom")
    args = ap.parse_args()
    try:
        trim = [float(v) for v in args.trim.split(",")]
        if len(trim) != 4:
            raise ValueError("--trim needs four comma-separated fractions")
        w, h = render_cover(args.pdf, args.out, args.width, args.quality, trim)
    except Exception as exc:  # report and fail; the caller keeps the book cover-less
        print(json.dumps({"ok": False, "error": str(exc)}))
        return 1
    print(json.dumps({"ok": True, "width": w, "height": h}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
