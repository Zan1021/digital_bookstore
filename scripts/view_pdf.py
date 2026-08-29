"""
PDF Page Viewer — Renders each page of a PDF as PNG images for visual inspection.
Usage:
    python scripts/view_pdf.py [path_to_pdf] [--pages 1,3,5] [--dpi 150] [--output output_dir]

Defaults:
    - PDF: storage/app/public/books/translated/2_af.pdf
    - DPI: 150 (good balance of quality vs file size)
    - Output: storage/app/temp/pdf_preview/
    - Pages: all pages
"""

import sys
import os
import argparse
import fitz  # PyMuPDF

def render_pdf_pages(pdf_path, output_dir, pages=None, dpi=150):
    """Render PDF pages as PNG images."""
    if not os.path.exists(pdf_path):
        print(f"ERROR: PDF not found at: {pdf_path}")
        sys.exit(1)

    os.makedirs(output_dir, exist_ok=True)

    doc = fitz.open(pdf_path)
    total_pages = len(doc)
    print(f"PDF: {pdf_path}")
    print(f"Total pages: {total_pages}")
    print(f"Output directory: {output_dir}")
    print(f"DPI: {dpi}")
    print("-" * 50)

    # Determine which pages to render
    if pages:
        page_list = []
        for p in pages.split(","):
            p = p.strip()
            if "-" in p:
                start, end = p.split("-")
                page_list.extend(range(int(start), int(end) + 1))
            else:
                page_list.append(int(p))
    else:
        page_list = list(range(1, total_pages + 1))

    rendered = []
    for page_num in page_list:
        if page_num < 1 or page_num > total_pages:
            print(f"  Page {page_num}: SKIPPED (out of range)")
            continue

        page = doc[page_num - 1]  # 0-indexed
        zoom = dpi / 72  # 72 DPI is default
        matrix = fitz.Matrix(zoom, zoom)
        pix = page.get_pixmap(matrix=matrix)

        output_path = os.path.join(output_dir, f"page_{page_num:02d}.png")
        pix.save(output_path)
        file_size_kb = os.path.getsize(output_path) / 1024
        print(f"  Page {page_num:2d}: {pix.width}x{pix.height}px  ({file_size_kb:.0f} KB) -> {output_path}")
        rendered.append(output_path)

    doc.close()
    print("-" * 50)
    print(f"Done! Rendered {len(rendered)} pages.")
    print(f"View images in: {os.path.abspath(output_dir)}")
    return rendered


def main():
    # Determine base path (script is in bookstore/scripts/)
    base_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

    parser = argparse.ArgumentParser(description="Render PDF pages as PNG images for inspection")
    parser.add_argument("pdf", nargs="?",
                        default=os.path.join(base_dir, "storage", "app", "public", "books", "translated", "2_af.pdf"),
                        help="Path to PDF file")
    parser.add_argument("--pages", "-p", type=str, default=None,
                        help="Pages to render: '1,3,5' or '1-5' or '3,7-10' (default: all)")
    parser.add_argument("--dpi", "-d", type=int, default=150,
                        help="Resolution in DPI (default: 150)")
    parser.add_argument("--output", "-o", type=str,
                        default=os.path.join(base_dir, "storage", "app", "temp", "pdf_preview"),
                        help="Output directory for PNG files")

    args = parser.parse_args()
    render_pdf_pages(args.pdf, args.output, args.pages, args.dpi)


if __name__ == "__main__":
    main()
