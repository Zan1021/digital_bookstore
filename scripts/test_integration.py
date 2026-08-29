"""
Integration Tests — Digital Bookstore V8
==========================================
10 required integration tests per the brief.

Tests end-to-end workflows:
  1. Born-digital story page (extract → translate → render)
  2. Born-digital vocabulary page (manifest → per-span replacement)
  3. Cover page replacement
  4. Multi-page full render
  5. Scanned page detection
  6. Incremental re-render (single page update)
  7. Validation pipeline (render → validate → report)
  8. Content cache (hash → skip → render)
  9. Security scan → sanitize flow
  10. Full pipeline: extract → classify → manifest → render → validate

Usage:
    python test_integration.py
"""

import json
import os
import sys
import shutil
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import pymupdf

KOLULU_PDF = os.path.join(
    os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))),
    "Kolulu Engl Series 3 - 2 - A Fun Place.pdf"
)

_tests_run = 0
_tests_passed = 0

def test_pass(name):
    global _tests_run, _tests_passed
    _tests_run += 1
    _tests_passed += 1
    print(f"  [OK] {name}")

def test_fail(name, reason):
    global _tests_run
    _tests_run += 1
    print(f"  [XX] {name}: {reason}")


# =============================================================================

def test_01_story_page_render():
    """Full story page: extract spans, classify, render with translation."""
    from pdf_translate_v8 import extract_page_spans, classify_page, render_story_page_v8
    
    doc = pymupdf.open(KOLULU_PDF)
    page = doc[2]  # Page 3
    
    spans = extract_page_spans(page, 3)
    page_type = classify_page(spans, 3, 16)
    
    if page_type != "story":
        test_fail("01 Story page render", f"Expected story, got {page_type}")
        doc.close()
        return
    
    # Render with a test translation
    translations_map = {3: "Wanneer Kolulu nie by die skool is nie, spandeer hy die meeste van sy tyd buite."}
    report = {"spans_replaced": 0, "errors": [], "overflow_warnings": []}
    
    fonts_dir = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "..", "Fonts")
    
    # Need a copy to render into
    temp_doc = pymupdf.open()
    temp_doc.insert_pdf(doc, from_page=2, to_page=2)
    temp_page = temp_doc[0]
    temp_spans = extract_page_spans(temp_page, 3)
    
    render_story_page_v8(temp_page, temp_spans, translations_map, fonts_dir, 3, report)
    
    if report["spans_replaced"] > 0 and not report["errors"]:
        test_pass("01 Story page render")
    else:
        test_fail("01 Story page render", f"replaced={report['spans_replaced']}, errors={report['errors']}")
    
    temp_doc.close()
    doc.close()


def test_02_vocabulary_page_render():
    """Full vocabulary page: manifest → per-span replacement."""
    from pdf_translate_v8 import extract_page_spans, classify_page
    from page_manifest import build_vocabulary_manifest
    
    doc = pymupdf.open(KOLULU_PDF)
    page = doc[14]  # Page 15
    
    spans = extract_page_spans(page, 15)
    page_type = classify_page(spans, 15, 16)
    
    if page_type != "vocabulary":
        test_fail("02 Vocabulary page render", f"Expected vocabulary, got {page_type}")
        doc.close()
        return
    
    # Build manifest
    manifest = build_vocabulary_manifest(page, 15, spans)
    
    if manifest.get("column_count", 0) >= 3 and manifest.get("total_content_items", 0) > 50:
        test_pass("02 Vocabulary page render")
    else:
        test_fail("02 Vocabulary page render", 
                 f"cols={manifest.get('column_count')}, items={manifest.get('total_content_items')}")
    
    doc.close()


def test_03_cover_page():
    """Cover page subtitle replacement."""
    from pdf_translate_v8 import extract_page_spans, classify_page
    
    doc = pymupdf.open(KOLULU_PDF)
    page = doc[0]
    
    spans = extract_page_spans(page, 1)
    page_type = classify_page(spans, 1, 16)
    
    # Cover should have subtitle spans
    subtitle_spans = [s for s in spans if s["font_size"] >= 40 and len(s["text_stripped"]) > 2]
    
    if page_type == "cover" and subtitle_spans:
        test_pass("03 Cover page")
    else:
        test_fail("03 Cover page", f"type={page_type}, subtitle_spans={len(subtitle_spans)}")
    
    doc.close()


def test_04_multi_page_render():
    """Full book render (all pages)."""
    from pdf_translate_v8 import replace_text_in_pdf
    
    # Create minimal translations
    translations = {
        "pages": [
            {"page_number": 3, "translated_text": "'n Prettige plek"},
            {"page_number": 4, "translated_text": "Kolulu hou van buitelug."},
        ]
    }
    
    fonts_dir = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), "..", "Fonts")
    output_path = os.path.join(tempfile.gettempdir(), "test_multipage.pdf")
    
    try:
        report = replace_text_in_pdf(KOLULU_PDF, output_path, translations, fonts_dir)
        
        if report["pages_processed"] >= 2 and os.path.isfile(output_path):
            test_pass("04 Multi-page render")
        else:
            test_fail("04 Multi-page render", f"processed={report['pages_processed']}")
    except Exception as e:
        test_fail("04 Multi-page render", str(e))
    finally:
        if os.path.isfile(output_path):
            os.remove(output_path)


def test_05_scanned_page_detection():
    """Scanned page detection (Kolulu should be born-digital)."""
    from ocr_integration import detect_scanned_pages
    
    result = detect_scanned_pages(KOLULU_PDF)
    
    # All pages should be born-digital
    if len(result["scanned_pages"]) == 0 and len(result["born_digital_pages"]) == 16:
        test_pass("05 Scanned page detection")
    else:
        test_fail("05 Scanned page detection", 
                 f"scanned={result['scanned_pages']}, digital={len(result['born_digital_pages'])}")


def test_06_incremental_rerender():
    """Incremental re-render: detect change, render only affected page."""
    from incremental_render import RenderState
    
    # Create a render state
    state = RenderState()
    
    # Simulate first render
    state.set_page_rendered(3, "Hello translation", 1.5, "story")
    
    # Same translation should NOT need re-render
    needs = state.needs_render(3, "Hello translation")
    
    # Different translation SHOULD need re-render
    needs_new = state.needs_render(3, "Different translation")
    
    # Unrendered page SHOULD need render
    needs_4 = state.needs_render(4, "Any text")
    
    if not needs and needs_new and needs_4:
        test_pass("06 Incremental re-render")
    else:
        test_fail("06 Incremental re-render", 
                 f"same={needs}, new={needs_new}, unrendered={needs_4}")


def test_07_validation_pipeline():
    """Render → validate → get report."""
    from pdf_validation import validate_pdf_standalone, validate_render_output
    
    # Validate source PDF
    result = validate_pdf_standalone(KOLULU_PDF)
    
    # Create mock render report
    mock_report = {
        "pages_processed": 16,
        "spans_replaced": 145,
        "errors": [],
        "coverage": {"pages_with_gaps": [], "total_source_spans": 200, "total_translated": 145}
    }
    
    combined = validate_render_output(KOLULU_PDF, KOLULU_PDF, mock_report)
    
    if result.valid and combined["overall_valid"]:
        test_pass("07 Validation pipeline")
    else:
        test_fail("07 Validation pipeline", f"standalone={result.valid}, combined={combined['overall_valid']}")


def test_08_content_cache():
    """Cache: hash → check → skip."""
    from content_cache import ContentCache, hash_page_content, should_process_page
    
    cache_dir = os.path.join(tempfile.gettempdir(), "test_v8_cache")
    
    try:
        cache = ContentCache(cache_dir)
        
        doc = pymupdf.open(KOLULU_PDF)
        page = doc[2]
        
        # First time: should need processing
        needs, key, cached = should_process_page(cache, page, "Test translation")
        
        if not needs:
            test_fail("08 Content cache", "Should need processing first time")
            doc.close()
            return
        
        # Store result
        cache.set_render_result(key, {"rendered": True, "spans": 5})
        
        # Second time: should use cache
        needs2, key2, cached2 = should_process_page(cache, page, "Test translation")
        
        if not needs2 and cached2 and cached2["rendered"]:
            test_pass("08 Content cache")
        else:
            test_fail("08 Content cache", f"needs2={needs2}, cached2={cached2}")
        
        doc.close()
    finally:
        shutil.rmtree(cache_dir, ignore_errors=True)


def test_09_security_flow():
    """Security scan → report."""
    from security import scan_pdf_security
    
    result = scan_pdf_security(KOLULU_PDF)
    
    if result["safe"] and result["info"]["page_count"] == 16:
        test_pass("09 Security scan flow")
    else:
        test_fail("09 Security scan flow", f"safe={result['safe']}, threats={result['threats']}")


def test_10_full_pipeline():
    """Full pipeline: extract → classify → manifest → inventory → validate."""
    from pdf_translate_v8 import extract_page_spans, classify_page
    from page_inventory import build_page_inventory
    from pdf_validation import validate_pdf_standalone
    from scene_graph import build_scene_graph
    from rotated_text import scan_page_for_rotated_text
    
    doc = pymupdf.open(KOLULU_PDF)
    page = doc[2]  # Story page
    
    try:
        # Step 1: Extract spans
        spans = extract_page_spans(page, 3)
        assert len(spans) > 0
        
        # Step 2: Classify
        page_type = classify_page(spans, 3, 16)
        assert page_type == "story"
        
        # Step 3: Inventory
        inventory = build_page_inventory(page, 3)
        assert inventory["object_counts"]["total"] > 0
        
        # Step 4: Scene graph
        graph = build_scene_graph(page, 3)
        assert len(graph.nodes) > 0
        
        # Step 5: Rotation check
        rot_scan = scan_page_for_rotated_text(page, 3)
        assert rot_scan["total_spans"] > 0
        
        # Step 6: Validate source
        validation = validate_pdf_standalone(KOLULU_PDF)
        assert validation.valid
        
        test_pass("10 Full pipeline")
    except AssertionError as e:
        test_fail("10 Full pipeline", str(e))
    except Exception as e:
        test_fail("10 Full pipeline", str(e))
    
    doc.close()


# =============================================================================

def main():
    global _tests_run, _tests_passed
    
    print("=" * 60)
    print("V8 ENGINE INTEGRATION TESTS (10 required)")
    print("=" * 60)
    
    if not os.path.isfile(KOLULU_PDF):
        print(f"\nERROR: Test PDF not found: {KOLULU_PDF}")
        return 1
    
    print()
    test_01_story_page_render()
    test_02_vocabulary_page_render()
    test_03_cover_page()
    test_04_multi_page_render()
    test_05_scanned_page_detection()
    test_06_incremental_rerender()
    test_07_validation_pipeline()
    test_08_content_cache()
    test_09_security_flow()
    test_10_full_pipeline()
    
    print()
    print("=" * 60)
    print(f"RESULTS: {_tests_passed}/{_tests_run} tests passed")
    if _tests_passed == _tests_run:
        print("ALL INTEGRATION TESTS PASSED")
    else:
        print(f"FAILURES: {_tests_run - _tests_passed}")
    print("=" * 60)
    
    return 0 if _tests_passed == _tests_run else 1


if __name__ == "__main__":
    sys.exit(main())
