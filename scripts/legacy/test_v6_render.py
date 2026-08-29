"""
Test V6 rendering engine directly (bypasses PHP).
Reads translations from the database and builds the JSON, then calls replace.
"""
import json
import os
import sqlite3
import sys

base_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
db_path = os.path.join(base_dir, "database", "database.sqlite")
fonts_dir = os.path.join(base_dir, "storage", "app", "fonts")
input_pdf = os.path.join(base_dir, "storage", "app", "public", "books", "pdfs",
                          "1787824220_Kolulu Engl Series 3 - 2 - A Fun Place.pdf")
output_pdf = os.path.join(base_dir, "storage", "app", "public", "books", "translated", "2_af.pdf")

# Read translations from SQLite
conn = sqlite3.connect(db_path)
conn.row_factory = sqlite3.Row
cursor = conn.cursor()

# Get translation ID 4 (Afrikaans for book 2)
cursor.execute("""
    SELECT tp.page_number, tp.translated_text 
    FROM translated_pages tp 
    WHERE tp.translation_id = 4 
    ORDER BY tp.page_number
""")

rows = cursor.fetchall()
conn.close()

print(f"Found {len(rows)} translated pages")

# Build translations JSON
translations = {"pages": []}
for row in rows:
    page_num = row["page_number"]
    text = row["translated_text"]
    if text and text.strip():
        translations["pages"].append({
            "page_number": page_num,
            "translated_text": text.strip()
        })
        print(f"  Page {page_num}: {len(text)} chars — \"{text[:60]}...\"")

# Write temp JSON
temp_json = os.path.join(base_dir, "storage", "app", "temp", "test_v6_translations.json")
os.makedirs(os.path.dirname(temp_json), exist_ok=True)
with open(temp_json, 'w', encoding='utf-8') as f:
    json.dump(translations, f, ensure_ascii=False, indent=2)

print(f"\nTranslations JSON: {temp_json}")
print(f"Input PDF: {input_pdf}")
print(f"Output PDF: {output_pdf}")
print(f"Fonts dir: {fonts_dir}")
print(f"\nRunning V6 engine...")

# Import and run the engine
sys.path.insert(0, os.path.join(base_dir, "scripts"))
from pdf_translate import replace_text_in_pdf

report = replace_text_in_pdf(
    input_pdf=input_pdf,
    output_pdf=output_pdf,
    translations=translations,
    fonts_dir=fonts_dir,
)

print(f"\n{'='*50}")
print("V6 RENDER REPORT")
print(f"{'='*50}")
print(json.dumps(report, indent=2, ensure_ascii=False))
print(f"\nOutput saved to: {output_pdf}")

# Cleanup
os.remove(temp_json)
print("Done!")
