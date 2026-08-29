"""
Render a translated book using the single V8 engine (dev/CLI helper).

§2.1: There is ONE production render path — `replace_text_in_pdf` in
pdf_translate_v8.py — which builds the canonical DocumentScene region graph
internally and drives the render from it. The old divergent `scene_renderer`
path has been removed (R2: no competing renderers).

Book-agnostic (R1): source PDF, book id and language are CLI arguments; nothing
is hardcoded to any specific title.

Usage:
    python render_book.py --source "book.pdf" --book-id 2 --language af \
        --fonts-dir ../Fonts --output-name 2_af_v8.pdf
"""
import argparse
import os
import sqlite3
import sys
import time

SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR = os.path.dirname(SCRIPTS_DIR)
DB_PATH = os.path.join(BASE_DIR, "database", "database.sqlite")
OUTPUT_DIR = os.path.join(BASE_DIR, "storage", "app", "public", "books", "translated")

sys.path.insert(0, SCRIPTS_DIR)

from pdf_translate_v8 import replace_text_in_pdf


def get_translations_from_db(book_id, language):
    """Get all translated pages for a book+language from the database."""
    db = sqlite3.connect(DB_PATH)
    db.row_factory = sqlite3.Row
    rows = db.execute(
        """
        SELECT tp.page_number, tp.translated_text
        FROM translated_pages tp
        JOIN translations t ON tp.translation_id = t.id
        WHERE t.book_id = ? AND t.language_code = ?
        ORDER BY tp.page_number
        """,
        (book_id, language),
    ).fetchall()
    db.close()

    pages = [
        {"page_number": r["page_number"], "translated_text": r["translated_text"] or ""}
        for r in rows
    ]
    return {"pages": pages}


def main():
    parser = argparse.ArgumentParser(description="Render a translated book (single V8 engine)")
    parser.add_argument("--source", "-s", required=True, help="Source PDF path")
    parser.add_argument("--book-id", "-b", type=int, required=True, help="Book id in the DB")
    parser.add_argument("--language", "-l", default="af", help="Language code (default: af)")
    parser.add_argument("--fonts-dir", "-f", required=True, help="Fonts directory")
    parser.add_argument("--output-name", "-o", default=None,
                        help="Output filename (default: <book-id>_<lang>_v8.pdf)")
    parser.add_argument("--update-db", action="store_true",
                        help="Update translations.rendered_pdf_path after render")
    args = parser.parse_args()

    if not os.path.isfile(args.source):
        print(f"ERROR: Source PDF not found: {args.source}")
        return 1
    if not os.path.isdir(args.fonts_dir):
        print(f"ERROR: Fonts directory not found: {args.fonts_dir}")
        return 1

    output_name = args.output_name or f"{args.book_id}_{args.language}_v8.pdf"

    print("=" * 60)
    print("V8 RENDER — single region-graph engine")
    print("=" * 60)

    print("\n1. Fetching translations from database...")
    translations = get_translations_from_db(args.book_id, args.language)
    print(f"   Got {len(translations['pages'])} pages")

    os.makedirs(OUTPUT_DIR, exist_ok=True)
    output_path = os.path.join(OUTPUT_DIR, output_name)

    print("\n2. Rendering (DocumentScene region graph -> per-region render)...")
    print(f"   Source: {os.path.basename(args.source)}")
    print(f"   Output: {output_path}")
    print(f"   Fonts:  {args.fonts_dir}")

    start_time = time.time()
    report = replace_text_in_pdf(
        input_pdf=args.source,
        output_pdf=output_path,
        translations=translations,
        fonts_dir=args.fonts_dir,
    )
    elapsed = time.time() - start_time

    print(f"\n3. Render complete in {elapsed:.1f}s")
    print(f"   Pages processed: {report['pages_processed']}")
    print(f"   Spans replaced:  {report['spans_replaced']}")
    print(f"   Page types:      {report['page_types']}")
    print(f"   Render status:   {report.get('render_status')}")
    print(f"   Publishable:     {report.get('publishable')}")
    if report.get("review_pages"):
        print(f"   Review pages:    {report['review_pages']}")

    if report.get("errors"):
        print(f"\n   ERRORS ({len(report['errors'])}):")
        for err in report["errors"]:
            print(f"     Page {err.get('page', '?')}: {err.get('error', 'unknown')}")

    if os.path.isfile(output_path):
        size = os.path.getsize(output_path)
        print(f"\n4. Output: {output_path} ({size / 1024:.0f} KB)")

    if args.update_db:
        db = sqlite3.connect(DB_PATH)
        db.execute(
            "UPDATE translations SET rendered_pdf_path = ?, status = ?, updated_at = datetime('now') "
            "WHERE book_id = ? AND language_code = ?",
            (f"books/translated/{output_name}", "rendered", args.book_id, args.language),
        )
        db.commit()
        db.close()
        print("\n   Database updated with new render path.")

    print("\n" + "=" * 60)
    print("DONE")
    print("=" * 60)
    return 0


if __name__ == "__main__":
    sys.exit(main())
