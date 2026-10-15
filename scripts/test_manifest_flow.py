"""
Manifest -> translation request -> response validation -> page translations map.

Builds the page manifest of a real corpus book (skips if absent), turns it into a
translation request, and checks the request/response contract: every translatable item
becomes one unit with a stable unique id, preserved regions are not sent, a complete
response validates, an incomplete or duplicated one does not, and a validated response
maps back onto the right pages. Synthetic responses only — no AI calls, no database.

Run: python scripts/test_manifest_flow.py
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from corpus_fixtures import require_corpus_book
from page_manifest import build_document_manifest
from translation_request import build_translation_request, response_to_translations_map, validate_response

_passed = _failed = 0


def check(name, ok):
    global _passed, _failed
    print(f"  [{'OK' if ok else 'FAIL'}] {name}")
    if ok:
        _passed += 1
    else:
        _failed += 1


pdf = require_corpus_book("Kolulu Engl Series 3 - 2 - A Fun Place.pdf")

print("=" * 60)
print("MANIFEST + TRANSLATION REQUEST FLOW")
print("=" * 60)

manifest = build_document_manifest(pdf)
pages = manifest["pages"]
check("manifest has one entry per page", manifest["page_count"] == len(pages) > 0)

translatable, preserved = set(), set()
for page in pages:
    for region in page.get("regions", []):
        ids = {item["id"] for item in region.get("items", [])}
        (preserved if region.get("translation_policy", "preserve") == "preserve" else translatable).update(ids)
check("manifest has translatable items", len(translatable) > 0)

request = build_translation_request(manifest, "af", "Afrikaans")
units = request["translation_units"]
unit_ids = [u["id"] for u in units]
check("request has one unit per translatable item", set(unit_ids) == translatable)
check("unit ids are unique", len(unit_ids) == len(set(unit_ids)))
check("preserved items are not sent for translation", not (set(unit_ids) & preserved))
check("every unit carries its source text", all(u["source_text"].strip() for u in units))
check("system prompt names the target language", "Afrikaans" in request["system_prompt"])

full = [{"id": u["id"], "translation": f"[af] {u['source_text']}"} for u in units]
ok = validate_response(request, full)
check("complete response is valid", ok["valid"] and ok["coverage"]["received"] == len(units))

dropped = validate_response(request, full[1:])
check("response missing an item is invalid and names it",
      not dropped["valid"] and dropped["coverage"]["missing_ids"] == [full[0]["id"]])

duplicated = validate_response(request, full + [full[0]])
check("duplicate id is an error", not duplicated["valid"])

check("non-list response is invalid", not validate_response(request, {"id": "x"})["valid"])

page_map = response_to_translations_map(full, manifest)
mapped = {item_id: (page_num, text) for page_num, entry in page_map.items()
          for item_id, text in entry["items"].items()}
unit_page = {u["id"]: u["page_number"] for u in units}
check("every translation maps back to its own page",
      set(mapped) == set(unit_page) and all(mapped[i][0] == unit_page[i] for i in unit_page))

print("=" * 60)
print(f"RESULTS: {_passed} passed, {_failed} failed")
print("=" * 60)
sys.exit(1 if _failed else 0)
