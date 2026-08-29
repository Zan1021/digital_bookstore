"""
Test the full manifest → translation request → legacy conversion flow.
Validates that existing translations can be mapped to stable content IDs.
"""
import json
import os
import sqlite3
import sys

base_dir = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(base_dir, "scripts"))

from page_manifest import build_document_manifest
from translation_request import (
    build_translation_request,
    legacy_text_to_manifest_items,
    validate_response,
    response_to_translations_map,
)

# Paths
pdf_path = os.path.join(base_dir, "storage", "app", "public", "books", "pdfs",
                         "1787824220_Kolulu Engl Series 3 - 2 - A Fun Place.pdf")
db_path = os.path.join(base_dir, "database", "database.sqlite")

print("=" * 60)
print("MANIFEST + TRANSLATION REQUEST FLOW TEST")
print("=" * 60)

# Step 1: Build manifest
print("\n1. Building document manifest...")
manifest = build_document_manifest(pdf_path)
print(f"   Pages: {manifest['page_count']}")
for page in manifest["pages"]:
    regions = page.get("regions", [])
    items = sum(len(r.get("items", [])) for r in regions)
    print(f"   Page {page['page_number']:2d}: {page['page_type']:12s} — {len(regions)} regions, {items} items")

# Step 2: Build translation request
print("\n2. Building translation request...")
request = build_translation_request(manifest, "af", "Afrikaans")
print(f"   Total translation units: {request['total_units']}")
print(f"   System prompt length: {len(request['system_prompt'])} chars")

# Step 3: Load existing translations from DB
print("\n3. Loading existing translations from database...")
conn = sqlite3.connect(db_path)
conn.row_factory = sqlite3.Row
cursor = conn.cursor()
cursor.execute("""
    SELECT page_number, translated_text 
    FROM translated_pages 
    WHERE translation_id = 4 
    ORDER BY page_number
""")
rows = cursor.fetchall()
conn.close()
print(f"   Found {len(rows)} translated pages")

# Step 4: Convert legacy translations to manifest items
print("\n4. Converting legacy translations to manifest items...")
all_legacy_items = []
for row in rows:
    page_num = row["page_number"]
    text = row["translated_text"]
    if not text or not text.strip():
        continue
    
    # Find page manifest
    page_manifest = next((p for p in manifest["pages"] if p["page_number"] == page_num), None)
    if not page_manifest:
        continue
    
    items = legacy_text_to_manifest_items(text, page_manifest)
    all_legacy_items.extend(items)
    print(f"   Page {page_num:2d}: {len(items)} items mapped")

print(f"\n   Total legacy items mapped: {len(all_legacy_items)}")

# Step 5: Validate coverage
print("\n5. Validating coverage...")
validation = validate_response(request, all_legacy_items)
print(f"   Valid: {validation['valid']}")
print(f"   Expected: {validation['coverage']['expected']}")
print(f"   Received: {validation['coverage']['received']}")
if validation['coverage']['missing_ids']:
    print(f"   Missing: {len(validation['coverage']['missing_ids'])} IDs")
    print(f"   First few: {validation['coverage']['missing_ids'][:5]}")
if validation['errors']:
    print(f"   Errors: {validation['errors'][:3]}")
if validation['warnings']:
    print(f"   Warnings: {validation['warnings'][:3]}")

# Step 6: Show vocab page mapping
print("\n6. Vocabulary page (15) mapping sample:")
vocab_page = next((p for p in manifest["pages"] if p["page_number"] == 15), None)
if vocab_page:
    vocab_items = [i for i in all_legacy_items if i["id"].startswith("p15-")]
    print(f"   Total vocab items mapped: {len(vocab_items)}")
    print(f"   First 10:")
    for item in vocab_items[:10]:
        print(f"     {item['id']}: '{item['translation']}'")

print("\n" + "=" * 60)
print("DONE")
print("=" * 60)
