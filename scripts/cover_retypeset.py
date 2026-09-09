#!/usr/bin/env python3
"""
cover_retypeset.py — FRONT-PAGE (cover) post-processor.

Problem it solves
------------------
Some covers draw the subtitle's drop-shadow as a Form XObject through a LUMINOSITY
soft mask. Our per-span replacement (`apply_redactions`) re-serialises the page
content stream and the previously-invisible luminosity backdrop then renders as a
dark/washed box behind the translated subtitle — but ONLY in mask-honouring
renderers (PDF.js in the reader). PyMuPDF's own raster flattens the mask and shows
it clean, so it's invisible to a local pixmap check.

Fix (book-agnostic, renderer-proof, works even for text on illustration)
------------------------------------------------------------------------
FLATTEN the finished cover page to a single opaque high-res raster and re-embed it
as the page content. Flattening composites the soft mask to its intended visual
result (the backdrop returns to invisible, exactly as the SOURCE renders), so the
box cannot appear in ANY renderer. The already-correctly-placed translated subtitle
is baked in during flattening. Because the output page is now just pixels, there is
no soft mask / form / ghost left to mis-composite anywhere.

This mirrors the spec's "flattened-cover fallback" and is the reliable path for a
screen (PDF.js) reader. Print-CMYK is a documented limitation (RGB raster).

CLI
---
  python cover_retypeset.py flatten-cover --input <pdf> --output <pdf> [--page 0] [--ppi 600]

Idempotent-ish: only the given page is flattened; other pages are untouched.
Prints the output path on success; a JSON report to stderr.
"""

import argparse
import json
import sys

import pymupdf


def flatten_cover_page(input_pdf, output_pdf, page_index=0, ppi=600):
    """Flatten one page of `input_pdf` to an opaque raster and re-embed it, writing
    to `output_pdf`. Preserves page geometry (MediaBox/rect), rotation, and the
    other pages. Safe when input_pdf == output_pdf (writes to a temp then replaces).
    Returns a report dict."""
    import os
    import tempfile

    report = {"page_index": page_index, "ppi": ppi, "flattened": False}

    same_path = os.path.abspath(input_pdf) == os.path.abspath(output_pdf)
    # Always render to a distinct temp file first; never save over the file PyMuPDF
    # currently has open (that silently no-ops / can corrupt).
    fd, tmp_out = tempfile.mkstemp(suffix=".pdf", dir=os.path.dirname(os.path.abspath(output_pdf)) or None)
    os.close(fd)

    doc = pymupdf.open(input_pdf)
    if page_index < 0 or page_index >= doc.page_count:
        report["error"] = f"page_index {page_index} out of range (page_count={doc.page_count})"
        doc.close()
        try:
            os.remove(tmp_out)
        except OSError:
            pass
        print(json.dumps(report, ensure_ascii=False), file=sys.stderr)
        print(output_pdf)
        return report

    page = doc[page_index]
    rect = page.rect
    rotation = page.rotation

    # Render the page to an opaque RGB pixmap at target ppi. get_pixmap composites
    # the luminosity soft mask to its INTENDED visual result (backdrop invisible),
    # matching the clean SOURCE appearance — this is exactly what kills the box.
    zoom = ppi / 72.0
    matrix = pymupdf.Matrix(zoom, zoom)
    pix = page.get_pixmap(matrix=matrix, alpha=False)
    img_bytes = pix.tobytes("png")

    # Build a fresh page of identical geometry and place the raster to fill it. A
    # new page (rather than an overlay) guarantees NO original vector/mask content,
    # form XObject, or selectable source text survives on the cover.
    new_doc = pymupdf.open()
    if rotation in (90, 270):
        pw, ph = rect.height, rect.width
    else:
        pw, ph = rect.width, rect.height
    new_page = new_doc.new_page(width=pw, height=ph)
    new_page.insert_image(new_page.rect, stream=img_bytes, keep_proportion=False)

    # Replace the page in the original doc: delete old, insert the flattened one at
    # the same index, preserving the rest of the book.
    doc.delete_page(page_index)
    doc.insert_pdf(new_doc, from_page=0, to_page=0, start_at=page_index)
    new_doc.close()

    report["flattened"] = True
    report["raster_px"] = [pix.width, pix.height]
    report["page_pt"] = [round(pw, 2), round(ph, 2)]

    # Save to temp, close, then move into place (handles same in/out path).
    doc.save(tmp_out, garbage=4, deflate=True)
    doc.close()
    os.replace(tmp_out, output_pdf)

    print(json.dumps(report, ensure_ascii=False), file=sys.stderr)
    print(output_pdf)
    return report


def main():
    parser = argparse.ArgumentParser(description="Cover front-page flatten post-processor")
    sub = parser.add_subparsers(dest="command")

    fp = sub.add_parser("flatten-cover", help="Flatten one page to an opaque raster and re-embed")
    fp.add_argument("--input", "-i", required=True)
    fp.add_argument("--output", "-o", required=True)
    fp.add_argument("--page", "-p", type=int, default=0, help="0-based page index (default 0 = cover)")
    fp.add_argument("--ppi", type=int, default=600)

    args = parser.parse_args()
    if args.command == "flatten-cover":
        flatten_cover_page(args.input, args.output, page_index=args.page, ppi=args.ppi)
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
