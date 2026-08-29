"""Test the hybrid renderer (V7 for story, V8 for vocab)."""
import json
import os
import sqlite3
import sys

base_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(base_dir, "scripts"))

db_path = os.path.join(base_dir, "database", "database.sqlite")
fonts_dir = os.path.join(base_dir, "storage", "app", "fonts")
input_pdf = os.path.join(base_dir, "storage", "app", "public", "books", "pdfs",
                          "1787824220_Kolulu Engl Series 3 - 2 - A Fun Place.pdf")
output_pdf = os.path.join(base_dir, "storage", "app", "public", "books", "translated", "2_af_hybrid.pdf")

# Read translations
conn = sqlite3.connect(db_path)
conn.row_factory = sqlite3.Row
cursor = conn.cursor()
cursor.execute("SELECT page_number, translated_text FROM translated_pages WHERE translation_id = 4 ORDER BY page_number")
rows = cursor.fetchall()
conn.close()

translations = {"pages": []}
for row in rows:
    if row["translated_text"] and row["translated_text"].strip():
        translations["pages"].append({
            "page_number": row["page_number"],
            "translated_text": row["translated_text"].strip()
        })

print(f"Found {len(translations['pages'])} translated pages")
print(f"Running hybrid renderer...")

from hybrid_renderer import hybrid_replace_text_in_pdf

report = hybrid_replace_text_in_pdf(
    input_pdf=input_pdf,
    output_pdf=output_pdf,
    translations=translations,
    fonts_dir=fonts_dir,
)

print(f"\n{'='*50}")
print("HYBRID RENDER REPORT")
print(f"{'='*50}")
print(json.dumps(report, indent=2, ensure_ascii=False))
print(f"\nOutput: {output_pdf}")
