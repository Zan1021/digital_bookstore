"""Quick test of the document model / scene graph (§2.1 single region graph).

Book-agnostic (R1): pass a PDF path as argv[1], or set BOOKSTORE_TEST_PDF.
If no PDF is available the test skips cleanly instead of failing.
"""
import sys, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from document_model import build_document_scene


def _find_pdf():
    if len(sys.argv) > 1 and os.path.isfile(sys.argv[1]):
        return sys.argv[1]
    env = os.environ.get("BOOKSTORE_TEST_PDF")
    if env and os.path.isfile(env):
        return env
    # Look for any PDF under the project's public books dir.
    base = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    for root, _dirs, files in os.walk(os.path.join(base, "storage", "app", "public", "books")):
        for f in files:
            if f.lower().endswith(".pdf"):
                return os.path.join(root, f)
    return None


def main():
    pdf = _find_pdf()
    if not pdf:
        print("SKIP: no test PDF available (pass a path or set BOOKSTORE_TEST_PDF)")
        return 0

    print(f"Building document scene from: {os.path.basename(pdf)}")
    scene = build_document_scene(pdf)
    print(f"Done. {scene.total_pages} pages, {len(scene.get_all_translatable_units())} translatable units.")

    # Stable-ID translation contract (§2.2).
    request = scene.to_translation_request("af")
    assert request["schema_version"], "translation request must carry a schema version"
    assert all("id" in it and "source_text" in it for it in request["items"]), "items need stable IDs"
    print(f"Translation request: {len(request['items'])} items, schema {request['schema_version']}")

    # Round-trip validation.
    mock = {"items": [{"id": it["id"], "translation": f"[AF] {it['source_text'][:20]}"}
                      for it in request["items"]]}
    result = scene.validate_translation_response(mock)
    assert result["valid"], f"round-trip response should validate: {result['errors'][:2]}"

    partial = {"items": mock["items"][:max(0, len(mock['items']) - 1)]}
    result2 = scene.validate_translation_response(partial)
    if len(request["items"]) > 0:
        assert not result2["valid"], "incomplete response must be flagged invalid"

    print("ALL SCENE GRAPH TESTS PASSED")
    return 0


if __name__ == "__main__":
    sys.exit(main())
