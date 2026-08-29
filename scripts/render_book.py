"""
Render the Afrikaans translation using V8 engine.

Supports two modes:
  --legacy   : Use the old replace_text_in_pdf (raw span processing)
  (default)  : Use the scene graph renderer (DocumentScene-driven)

The scene graph path builds a DocumentScene first, then renders using
structured regions/units. Legacy translations (flat text per page) are
auto-mapped to stable unit IDs.
"""
import sqlite3
import json
import os
import sys
import time

SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
BASE_DIR = os.path.dirname(SCRIPTS_DIR)
DB_PATH = os.path.join(BASE_DIR, "database", "database.sqlite")
SOURCE_PDF = os.path.join(os.path.dirname(BASE_DIR), "Kolulu Engl Series 3 - 2 - A Fun Place.pdf")
OUTPUT_DIR = os.path.join(BASE_DIR, "storage", "app", "public", "books", "translated")
FONTS_DIR = os.path.join(os.path.dirname(BASE_DIR), "Fonts")

sys.path.insert(0, SCRIPTS_DIR)

from pdf_translate_v8 import replace_text_in_pdf
from scene_renderer import render_from_scene
from document_model import build_document_scene


def get_translations_from_db(book_id=2, language="af"):
    """Get all translated pages from the database."""
    db = sqlite3.connect(DB_PATH)
    db.row_factory = sqlite3.Row
    
    rows = db.execute("""
        SELECT tp.page_number, tp.translated_text 
        FROM translated_pages tp
        JOIN translations t ON tp.translation_id = t.id
        WHERE t.book_id = ? AND t.language_code = ?
        ORDER BY tp.page_number
    """, (book_id, language)).fetchall()
    
    db.close()
    
    pages = []
    for row in rows:
        pages.append({
            "page_number": row["page_number"],
            "translated_text": row["translated_text"] or "",
        })
    
    return {"pages": pages}


def main():
    # Parse --legacy flag
    use_legacy = "--legacy" in sys.argv

    engine_name = "V8 Legacy (raw spans)" if use_legacy else "V8 Scene Graph"
    
    print("=" * 60)
    print(f"V8 RENDER — Kolulu Afrikaans Translation")
    print(f"Engine: {engine_name}")
    print("=" * 60)
    
    # Check prerequisites
    if not os.path.isfile(SOURCE_PDF):
        print(f"ERROR: Source PDF not found: {SOURCE_PDF}")
        return 1
    
    if not os.path.isdir(FONTS_DIR):
        print(f"ERROR: Fonts directory not found: {FONTS_DIR}")
        return 1
    
    # Get translations from database
    print("\n1. Fetching translations from database...")
    translations = get_translations_from_db()
    print(f"   Got {len(translations['pages'])} pages")
    
    # Output path
    os.makedirs(OUTPUT_DIR, exist_ok=True)
    output_path = os.path.join(OUTPUT_DIR, "2_af_v8.pdf")
    
    start_time = time.time()

    if use_legacy:
        # ===== LEGACY PATH (old raw-span processing) =====
        print(f"\n2. Rendering with V8 LEGACY engine...")
        print(f"   Source: {os.path.basename(SOURCE_PDF)}")
        print(f"   Output: {output_path}")
        print(f"   Fonts: {FONTS_DIR}")

        report = replace_text_in_pdf(
            input_pdf=SOURCE_PDF,
            output_pdf=output_path,
            translations=translations,
            fonts_dir=FONTS_DIR,
        )
    else:
        # ===== SCENE GRAPH PATH (new structured rendering) =====
        print(f"\n2. Building scene graph...")
        scene = build_document_scene(SOURCE_PDF)
        print(f"   Pages: {scene.total_pages}")
        print(f"   Translatable units: {len(scene.get_all_translatable_units())}")
        print(f"   Page families: {len(scene.page_families)}")

        # Convert legacy DB format to page_number → text map
        legacy_page_text = {
            p["page_number"]: p["translated_text"]
            for p in translations["pages"]
            if p["translated_text"].strip()
        }

        print(f"\n3. Rendering with scene graph engine...")
        print(f"   Source: {os.path.basename(SOURCE_PDF)}")
        print(f"   Output: {output_path}")
        print(f"   Fonts: {FONTS_DIR}")
        print(f"   Pages with translations: {len(legacy_page_text)}")

        report = render_from_scene(
            scene=scene,
            output_pdf=output_path,
            translations={},  # No ID-mapped translations yet
            fonts_dir=FONTS_DIR,
            legacy_page_text=legacy_page_text,
        )
    
    elapsed = time.time() - start_time
    
    # Print report
    print(f"\n4. Render complete in {elapsed:.1f}s")
    print(f"   Pages processed: {report['pages_processed']}")
    print(f"   Spans replaced: {report['spans_replaced']}")
    print(f"   Page types: {report['page_types']}")
    print(f"   Engine: {report.get('engine', 'v8-legacy')}")
    
    if report['errors']:
        print(f"\n   ERRORS ({len(report['errors'])}):")
        for err in report['errors']:
            print(f"     Page {err.get('page', '?')}: {err.get('error', 'unknown')}")
    
    coverage = report.get('coverage', {})
    if coverage.get('pages_with_gaps'):
        print(f"\n   Coverage gaps:")
        for gap in coverage['pages_with_gaps']:
            page_key = 'translatable_units' if 'translatable_units' in gap else 'source_spans'
            rendered_key = 'rendered' if 'rendered' in gap else 'replaced'
            print(f"     Page {gap['page']}: {gap.get(rendered_key, 0)}/{gap.get(page_key, 0)}")
    
    # Validation results
    validation = report.get('validation', {})
    if validation and not validation.get('skipped'):
        struct = validation.get('structural_validation', {})
        print(f"\n5. Validation:")
        print(f"   Structural: {struct.get('passed', '?')}/{struct.get('total_checks', '?')} checks passed")
        print(f"   Overall valid: {validation.get('overall_valid', '?')}")
        print(f"   Requires review: {validation.get('requires_review', '?')}")
    
    # File size
    if os.path.isfile(output_path):
        size = os.path.getsize(output_path)
        print(f"\n6. Output: {output_path}")
        print(f"   Size: {size / 1024:.0f} KB")
    
    print("\n" + "=" * 60)
    print("DONE - Open the PDF to inspect the render!")
    print("=" * 60)
    
    # Update database with new rendered path
    db = sqlite3.connect(DB_PATH)
    db.execute(
        "UPDATE translations SET rendered_pdf_path = ?, status = ?, updated_at = datetime('now') WHERE book_id = 2 AND language_code = 'af'",
        ("books/translated/2_af_v8.pdf", "rendered")
    )
    db.commit()
    db.close()
    print("\nDatabase updated with new render path.")
    
    return 0


if __name__ == "__main__":
    sys.exit(main())
