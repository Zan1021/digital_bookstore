"""Quick test of the document model / scene graph."""
import sys, json, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from document_model import build_document_scene, summarize_document_scene

PDF = r'C:\Users\zande\Documents\Digital Bookstore\Kolulu Engl Series 3 - 2 - A Fun Place.pdf'

print("Building document scene...")
scene = build_document_scene(PDF)
print(f"Done. {scene.total_pages} pages, {len(scene.get_all_translatable_units())} translatable units.")

# Test translation request generation
print("\n--- Translation Request (stable-ID contract) ---")
request = scene.to_translation_request("af")
print(f"Items: {len(request['items'])}")
print(f"Schema: {request['schema_version']}")
print(f"Target: {request['target_language']}")
print("\nSample items:")
for item in request['items'][:5]:
    print(f"  {item['id']}: \"{item['source_text'][:40]}\" [{item['semantic_role']}]")

# Test validation
print("\n--- Validation Test ---")
# Simulate a valid response
mock_response = {"items": []}
for item in request['items']:
    mock_response['items'].append({
        "id": item['id'],
        "translation": f"[AF] {item['source_text'][:20]}",
        "status": "translated",
    })

result = scene.validate_translation_response(mock_response)
print(f"Valid: {result['valid']}")
print(f"Coverage: {result['coverage']}")

# Simulate a bad response (missing IDs)
print("\n--- Validation Test (incomplete) ---")
partial_response = {"items": mock_response['items'][:5]}
result2 = scene.validate_translation_response(partial_response)
print(f"Valid: {result2['valid']}")
print(f"Errors: {result2['errors'][:2]}")
print(f"Coverage: {result2['coverage']}")

# Page family info
print(f"\n--- Page Families ---")
for family_id, pages in scene.page_families.items():
    print(f"  {family_id}: pages {pages}")

print("\nALL SCENE GRAPH TESTS PASSED")
