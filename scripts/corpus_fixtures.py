#!/usr/bin/env python3
"""
corpus_fixtures.py — deterministic synthetic fixtures for the verification corpus
(world-class-render-engine spec Phase 8.1, R11.1).

The REAL source books available are all one publisher (Mthombothi/Kolulu) in two portrait
sizes + one US-Letter single-pager, all rotation 0. The acceptance matrix also demands
shapes the real set does NOT contain: landscape, rotated pages, a complex-script/RTL
fixture, and a multi-size stress. Those can only be SYNTHETIC — and the spec explicitly
calls for "a complex-script fixture + a rotated/landscape fixture," so this is by design,
not a shortcut.

Each builder returns a path to a 1-page PDF (unless noted) with a known, asserted geometry
so the matrix tests can verify MEANINGFUL output (containment/placement/round-trip), not
"a helper was called." Pure PyMuPDF + PIL (no numpy/cv2).

CLI: python corpus_fixtures.py --out <dir>   # writes all fixtures, prints a JSON manifest
"""

from __future__ import annotations

import argparse
import io
import json
import os

import pymupdf
from PIL import Image, ImageDraw


def _baked_image(w, h, bg, text_boxes):
    """A raster with solid-colour baked 'glyph' blocks (stand-ins for baked artwork text)."""
    img = Image.new("RGB", (w, h), bg)
    d = ImageDraw.Draw(img)
    for (x0, y0, x1, y1, col) in text_boxes:
        d.rectangle([x0, y0, x1, y1], fill=col)
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    return buf.getvalue()


def fixture_landscape(path):
    """Landscape page with one embedded illustration carrying baked text."""
    doc = pymupdf.open()
    page = doc.new_page(width=751, height=538)  # landscape
    page.insert_text((40, 40), "Landscape native line", fontsize=14)
    img = _baked_image(600, 360, (60, 150, 200),
                       [(120, 160, 180, 250, (20, 20, 20)), (200, 160, 260, 250, (20, 20, 20))])
    place = (90, 90, 690, 450)
    page.insert_image(pymupdf.Rect(*place), stream=img)
    doc.save(path)
    doc.close()
    return {"kind": "landscape", "page_size": [751, 538], "rotation": 0,
            "image_place_pt": list(place), "image_px": [600, 360]}


def fixture_rotated(path, rotation=90):
    """Portrait page rotated 90° with an embedded illustration carrying baked text."""
    doc = pymupdf.open()
    page = doc.new_page(width=538, height=751)
    page.insert_text((40, 40), "Rotated native line", fontsize=14)
    img = _baked_image(500, 350, (200, 120, 60),
                       [(100, 150, 160, 240, (15, 15, 15)), (180, 150, 240, 240, (15, 15, 15))])
    place = (69, 100, 469, 400)
    page.insert_image(pymupdf.Rect(*place), stream=img)
    page.set_rotation(rotation)
    doc.save(path)
    doc.close()
    return {"kind": "rotated", "page_size": [538, 751], "rotation": rotation,
            "image_place_pt": list(place), "image_px": [500, 350]}


def fixture_rtl(path):
    """A complex-script / RTL fixture. We embed an Arabic string as NATIVE text so glyph
    coverage + direction handling can be exercised; the renderer must either shape it or
    route to review (R4.5/matrix). Uses the default face — if glyphs are missing that is the
    very signal the engine must catch."""
    doc = pymupdf.open()
    page = doc.new_page(width=420, height=595)  # A5-ish
    page.insert_text((40, 60), "RTL fixture", fontsize=14)
    # Arabic 'مرحبا بالعالم' (hello world); may or may not render in the base font — the point
    # is to drive the glyph-coverage/shaping path, not to look pretty here.
    try:
        page.insert_text((40, 120), "مرحبا بالعالم", fontsize=20)
    except Exception:
        pass
    doc.save(path)
    doc.close()
    return {"kind": "rtl", "page_size": [420, 595], "rotation": 0,
            "note": "arabic native string drives shaping/glyph-coverage or review"}


def fixture_multisize(path):
    """A multi-PAGE doc whose pages are DIFFERENT sizes (A4, A5, square) — stresses that the
    container/placement math is page-size-agnostic (no hardcoded page dims)."""
    doc = pymupdf.open()
    for (w, h) in [(595, 842), (420, 595), (500, 500)]:  # A4, A5, square (points)
        pg = doc.new_page(width=w, height=h)
        pg.insert_text((40, 60), f"Page {int(w)}x{int(h)}", fontsize=14)
    doc.save(path)
    doc.close()
    return {"kind": "multisize", "pages": [[595, 842], [420, 595], [500, 500]], "rotation": 0}


def fixture_reused_image(path):
    """Two pages SHARING one image xref (a logo) with baked text — the reused-image
    isolation case (R6.2)."""
    doc = pymupdf.open()
    logo = _baked_image(300, 200, (230, 230, 230), [(20, 60, 120, 140, (10, 10, 10))])
    for _ in range(2):
        pg = doc.new_page(width=538, height=751)
        pg.insert_image(pymupdf.Rect(100, 100, 400, 300), stream=logo)
    doc.save(path)
    doc.close()
    return {"kind": "reused_image", "pages": 2, "image_place_pt": [100, 100, 400, 300],
            "image_px": [300, 200]}


def fixture_gradient_bg(path):
    """An illustration with a vertical GRADIENT background behind baked text — exercises the
    gradient repair method (R6.3)."""
    w, h = 500, 360
    img = Image.new("RGB", (w, h))
    px = img.load()
    for y in range(h):
        t = y / (h - 1)
        col = (int(30 + 180 * t), int(40 + 120 * t), 90)
        for x in range(w):
            px[x, y] = col
    d = ImageDraw.Draw(img)
    for (x0) in (120, 200, 280):
        d.rectangle([x0, 150, x0 + 50, 230], fill=(250, 250, 250))  # light glyphs on gradient
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    doc = pymupdf.open()
    page = doc.new_page(width=538, height=751)
    place = (69, 120, 469, 400)
    page.insert_image(pymupdf.Rect(*place), stream=buf.getvalue())
    doc.save(path)
    doc.close()
    return {"kind": "gradient_bg", "page_size": [538, 751], "rotation": 0,
            "image_place_pt": list(place), "image_px": [w, h]}


BUILDERS = {
    "landscape": fixture_landscape,
    "rotated": fixture_rotated,
    "rtl": fixture_rtl,
    "multisize": fixture_multisize,
    "reused_image": fixture_reused_image,
    "gradient_bg": fixture_gradient_bg,
}


def build_all(out_dir):
    os.makedirs(out_dir, exist_ok=True)
    manifest = {}
    for name, fn in BUILDERS.items():
        path = os.path.join(out_dir, f"{name}.pdf")
        manifest[name] = {"path": path, **fn(path)}
    return manifest


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", required=True, help="output directory for fixtures")
    args = ap.parse_args()
    manifest = build_all(args.out)
    print(json.dumps(manifest, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
