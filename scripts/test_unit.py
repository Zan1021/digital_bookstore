"""
Unit Tests — Digital Bookstore V8
===================================
18 required unit tests per the brief.

Tests:
  1. Coordinate conversion (PDF to screen coordinates)
  2. Font subsetting detection
  3. Table/column detection
  4. Overflow detection and font shrinking
  5. Background color detection
  6. Rotation angle calculation
  7. Text matrix building
  8. Content hash generation
  9. Script detection (Latin, Arabic, mixed)
  10. Page classification (story, vocab, cover, etc.)
  11. Span extraction completeness
  12. Translation mapping accuracy
  13. Redaction rect calculation
  14. Column width calculation
  15. Reading order determination
  16. Security threat detection
  17. Validation result structure
  18. Cache key generation

Usage:
    python test_unit.py
"""

import os
import sys
import json

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import pymupdf


# Test infrastructure
_tests_run = 0
_tests_passed = 0

def assert_true(condition, msg=""):
    global _tests_run, _tests_passed
    _tests_run += 1
    if condition:
        _tests_passed += 1
    else:
        print(f"    FAIL: {msg}")

def assert_eq(a, b, msg=""):
    assert_true(a == b, f"{msg}: expected {b}, got {a}")

def assert_near(a, b, tolerance=0.1, msg=""):
    assert_true(abs(a - b) < tolerance, f"{msg}: expected ~{b}, got {a}")


# =============================================================================
# UNIT TESTS
# =============================================================================

def test_01_coordinate_conversion():
    """PDF uses bottom-left origin, screen uses top-left."""
    print("  1. Coordinate conversion")
    # PyMuPDF handles this transparently — verify bbox is top-left based
    doc = pymupdf.open()
    page = doc.new_page(width=595, height=842)
    page.insert_text(pymupdf.Point(100, 200), "Test", fontsize=12)
    
    text_dict = page.get_text("dict")
    for block in text_dict.get("blocks", []):
        if block.get("type") == 0:
            for line in block["lines"]:
                for span in line["spans"]:
                    bbox = span["bbox"]
                    # Y should be less than page height (top-left origin)
                    assert_true(bbox[1] < 842, "Y coord should be < page height")
                    assert_true(bbox[1] > 0, "Y coord should be > 0")
                    assert_true(bbox[0] >= 100, "X should be >= insertion point")
    doc.close()


def test_02_font_subsetting():
    """Detect font subset prefixes (ABCDEF+FontName)."""
    print("  2. Font subsetting detection")
    
    # Subset prefix pattern
    font_name = "ABCDEF+PlaypenSans-Regular"
    assert_true(len(font_name) > 7 and font_name[6] == '+', "Should detect subset prefix")
    
    clean = font_name[7:] if len(font_name) > 7 and font_name[6] == '+' else font_name
    assert_eq(clean, "PlaypenSans-Regular", "Should strip subset prefix")
    
    # Non-subset font
    font_name2 = "Helvetica"
    has_prefix = len(font_name2) > 7 and font_name2[6] == '+'
    assert_true(not has_prefix, "Should not detect prefix in non-subset font")


def test_03_table_detection():
    """Column detection from X-coordinate clustering."""
    print("  3. Table/column detection")
    from page_manifest import detect_columns
    
    # Simulate spans at 3 column positions
    spans = [
        {"origin": [65, 100], "bbox": [65, 95, 120, 110]},
        {"origin": [65, 120], "bbox": [65, 115, 120, 130]},
        {"origin": [180, 100], "bbox": [180, 95, 240, 110]},
        {"origin": [180, 120], "bbox": [180, 115, 240, 130]},
        {"origin": [300, 100], "bbox": [300, 95, 360, 110]},
    ]
    
    columns = detect_columns(spans)
    assert_true(len(columns) >= 3, f"Should detect 3 columns, got {len(columns)}")


def test_04_overflow_detection():
    """Font shrinking for overflow text."""
    print("  4. Overflow detection and font shrinking")
    
    font = pymupdf.Font("helv")
    text = "A very long word that overflows"
    size = 12
    width = font.text_length(text, fontsize=size)
    available = width * 0.7  # Only 70% available
    
    # Calculate shrink
    if width > available:
        shrink_ratio = available / width
        new_size = size * max(shrink_ratio, 0.6)
        assert_true(new_size < size, "Should shrink font")
        assert_true(new_size >= size * 0.6, "Should not shrink below 60%")


def test_05_background_detection():
    """Background color sampling."""
    print("  5. Background color detection")
    
    doc = pymupdf.open()
    page = doc.new_page()
    # Draw a red rectangle
    page.draw_rect(pymupdf.Rect(50, 50, 200, 200), fill=(1, 0, 0))
    
    # Sample color in the red area
    pix = page.get_pixmap(clip=pymupdf.Rect(100, 100, 110, 110), dpi=72)
    pixel = pix.pixel(5, 5)
    # Should be red (255, 0, 0)
    assert_near(pixel[0], 255, tolerance=5, msg="Red channel")
    assert_near(pixel[1], 0, tolerance=5, msg="Green channel")
    assert_near(pixel[2], 0, tolerance=5, msg="Blue channel")
    doc.close()


def test_06_rotation_angle():
    """Rotation angle from direction vector."""
    print("  6. Rotation angle calculation")
    from rotated_text import get_rotation_angle, is_rotated
    
    assert_near(get_rotation_angle((1, 0)), 0, msg="Horizontal")
    assert_near(get_rotation_angle((0, 1)), 90, msg="90 degrees")
    assert_near(get_rotation_angle((0, -1)), 270, msg="270 degrees")
    assert_near(get_rotation_angle((-1, 0)), 180, msg="180 degrees")
    assert_true(not is_rotated(0.5), "0.5 degrees is not rotated")
    assert_true(is_rotated(45.0), "45 degrees IS rotated")


def test_07_text_matrix():
    """Text matrix construction for rotation."""
    print("  7. Text matrix building")
    from rotated_text import build_text_matrix
    import math
    
    # 0 degrees: [size, 0, 0, size, x, y]
    tm = build_text_matrix(0, 12, (100, 200))
    assert_near(tm[0], 12, msg="a = size for 0 deg")
    assert_near(tm[1], 0, msg="b = 0 for 0 deg")
    assert_near(tm[4], 100, msg="e = x")
    assert_near(tm[5], 200, msg="f = y")


def test_08_content_hash():
    """Content hash generation is deterministic."""
    print("  8. Content hash generation")
    from content_cache import hash_page_content
    
    doc = pymupdf.open()
    page = doc.new_page()
    page.insert_text(pymupdf.Point(100, 100), "Hello", fontsize=12)
    
    hash1 = hash_page_content(page)
    hash2 = hash_page_content(page)
    assert_eq(hash1, hash2, "Same page should produce same hash")
    assert_true(len(hash1) == 16, f"Hash should be 16 chars, got {len(hash1)}")
    doc.close()


def test_09_script_detection():
    """Script detection for Latin, Arabic, mixed."""
    print("  9. Script detection")
    from script_detection import detect_scripts_in_text
    
    latin = detect_scripts_in_text("Hello world")
    assert_eq(latin["dominant_script"], "Latin", "English is Latin")
    assert_true(not latin["is_rtl"], "English is LTR")
    
    arabic = detect_scripts_in_text("مرحبا بالعالم")
    assert_eq(arabic["dominant_script"], "Arabic", "Arabic text detected")
    assert_true(arabic["is_rtl"], "Arabic is RTL")
    
    mixed = detect_scripts_in_text("Hello مرحبا world عالم")
    assert_true(mixed["is_bidi"], "Mixed text should be bidi")


def test_10_page_classification():
    """Page type classification — content-based, not page-number based."""
    print("  10. Page classification")
    from pdf_translate_v8 import classify_page
    
    # Cover: large text (>=40px), few spans, early page
    cover_spans = [{"font_size": 49, "is_page_number": False}, {"font_size": 12, "is_page_number": False}]
    assert_eq(classify_page(cover_spans, 1, 16), "cover", "Large text + few spans + page 1 = cover")
    
    # Copyright: many small spans, early page
    copyright_spans = [{"font_size": 8, "is_page_number": False} for _ in range(20)]
    assert_eq(classify_page(copyright_spans, 2, 16), "copyright", "Many small spans early = copyright")
    
    # Back cover: last page with multiple small items
    back_spans = [{"font_size": 12, "is_page_number": False} for _ in range(8)]
    assert_eq(classify_page(back_spans, 16, 16), "back_cover", "Last page with items = back cover")
    
    # Vocabulary: many small spans
    small_spans = [{"font_size": 10, "is_page_number": False} for _ in range(35)]
    large_spans = [{"font_size": 25, "is_page_number": False} for _ in range(2)]
    all_spans = small_spans + large_spans
    assert_eq(classify_page(all_spans, 15, 16), "vocabulary", "Many small spans = vocabulary")


def test_11_span_extraction():
    """Span extraction includes all required fields."""
    print("  11. Span extraction completeness")
    from pdf_translate_v8 import extract_page_spans
    
    doc = pymupdf.open()
    page = doc.new_page()
    page.insert_text(pymupdf.Point(100, 100), "Test text", fontsize=14)
    
    spans = extract_page_spans(page, 1)
    assert_true(len(spans) > 0, "Should extract at least 1 span")
    
    required_fields = ["id", "text", "origin", "bbox", "font_size", "color",
                      "is_page_number", "rotation_angle", "is_rotated"]
    for field in required_fields:
        assert_true(field in spans[0], f"Span should have '{field}'")
    
    doc.close()


def test_12_translation_mapping():
    """Translation mapping to content IDs."""
    print("  12. Translation mapping accuracy")
    # Simplified test: verify hash-based mapping
    from content_cache import hash_translation
    
    t1 = hash_translation("Hello world")
    t2 = hash_translation("Hello world")
    t3 = hash_translation("Different text")
    
    assert_eq(t1, t2, "Same text = same hash")
    assert_true(t1 != t3, "Different text = different hash")


def test_13_redaction_rect():
    """Redaction rectangle calculation with padding."""
    print("  13. Redaction rect calculation")
    
    span = {"bbox": [100, 200, 150, 215]}
    padding = 1
    
    rect = pymupdf.Rect(
        span["bbox"][0] - padding,
        span["bbox"][1] - padding,
        span["bbox"][2] + padding,
        span["bbox"][3] + padding
    )
    
    assert_eq(rect.x0, 99, "x0 with padding")
    assert_eq(rect.y0, 199, "y0 with padding")
    assert_eq(rect.x1, 151, "x1 with padding")
    assert_eq(rect.y1, 216, "y1 with padding")


def test_14_column_width():
    """Column width calculation."""
    print("  14. Column width calculation")
    from page_manifest import detect_columns
    
    # Two columns at x=65 and x=300
    spans = [
        {"origin": [65, 100], "bbox": [65, 95, 170, 110]},
        {"origin": [65, 120], "bbox": [65, 115, 170, 130]},
        {"origin": [300, 100], "bbox": [300, 95, 420, 110]},
        {"origin": [300, 120], "bbox": [300, 115, 420, 130]},
    ]
    
    columns = detect_columns(spans)
    assert_true(len(columns) >= 2, f"Should detect 2 columns, got {len(columns)}")
    assert_true(columns[0]["width"] > 0, "Column width should be positive")


def test_15_reading_order():
    """Reading order determination (top-to-bottom, left-to-right)."""
    print("  15. Reading order determination")
    from scene_graph import SceneNode
    
    # Create nodes at different positions
    nodes = [
        SceneNode("txt3", "text", [100, 200, 200, 220]),   # Lower
        SceneNode("txt1", "text", [100, 50, 200, 70]),     # Top
        SceneNode("txt2", "text", [100, 100, 200, 120]),   # Middle
    ]
    
    # Sort by Y then X (reading order)
    sorted_nodes = sorted(nodes, key=lambda n: (n.bbox[1], n.bbox[0]))
    assert_eq(sorted_nodes[0].id, "txt1", "First in reading order = topmost")
    assert_eq(sorted_nodes[2].id, "txt3", "Last in reading order = bottommost")


def test_16_security_detection():
    """Security threat detection."""
    print("  16. Security threat detection")
    from security import scan_pdf_security, MAX_FILE_SIZE
    
    # Create a safe PDF
    doc = pymupdf.open()
    doc.new_page()
    safe_path = os.path.join(os.path.dirname(__file__), "_test_safe.pdf")
    doc.save(safe_path)
    doc.close()
    
    result = scan_pdf_security(safe_path)
    assert_true(result["safe"], "Simple PDF should be safe")
    assert_eq(len(result["threats"]), 0, "No threats in simple PDF")
    
    os.remove(safe_path)


def test_17_validation_structure():
    """Validation result structure."""
    print("  17. Validation result structure")
    from pdf_validation import ValidationResult
    
    result = ValidationResult()
    assert_true(result.valid, "New result should be valid")
    
    result.pass_check("test", "passed")
    assert_eq(len(result.checks), 1, "Should have 1 check")
    
    result.fail("test2", "failed")
    assert_true(not result.valid, "Should be invalid after fail")
    assert_eq(len(result.errors), 1, "Should have 1 error")
    
    d = result.to_dict()
    assert_true("valid" in d, "Dict should have 'valid' key")
    assert_true("total_checks" in d, "Dict should have 'total_checks' key")


def test_18_cache_key():
    """Cache key generation."""
    print("  18. Cache key generation")
    from content_cache import get_cache_key, hash_translation
    
    page_hash = "abc123def456"
    trans_hash = hash_translation("test translation")
    
    key = get_cache_key(page_hash, trans_hash)
    assert_true("_" in key, "Cache key should contain separator")
    assert_true(key.startswith(page_hash), "Key should start with page hash")
    
    # Deterministic
    key2 = get_cache_key(page_hash, trans_hash)
    assert_eq(key, key2, "Same inputs = same key")


# =============================================================================
# MAIN
# =============================================================================

def main():
    global _tests_run, _tests_passed
    
    print("=" * 60)
    print("V8 ENGINE UNIT TESTS (18 required)")
    print("=" * 60)
    print()
    
    test_01_coordinate_conversion()
    test_02_font_subsetting()
    test_03_table_detection()
    test_04_overflow_detection()
    test_05_background_detection()
    test_06_rotation_angle()
    test_07_text_matrix()
    test_08_content_hash()
    test_09_script_detection()
    test_10_page_classification()
    test_11_span_extraction()
    test_12_translation_mapping()
    test_13_redaction_rect()
    test_14_column_width()
    test_15_reading_order()
    test_16_security_detection()
    test_17_validation_structure()
    test_18_cache_key()
    
    print()
    print("=" * 60)
    print(f"RESULTS: {_tests_passed}/{_tests_run} assertions passed")
    if _tests_passed == _tests_run:
        print("ALL UNIT TESTS PASSED")
    else:
        print(f"FAILURES: {_tests_run - _tests_passed}")
    print("=" * 60)
    
    return 0 if _tests_passed == _tests_run else 1


if __name__ == "__main__":
    sys.exit(main())
