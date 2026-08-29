"""Quick test of PDF validation module."""
import sys, os
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from pdf_validation import validate_against_source, validate_pdf_standalone, validate_render_output

pdf = r'C:\Users\zande\Documents\Digital Bookstore\Kolulu Engl Series 3 - 2 - A Fun Place.pdf'

# Test 1: Standalone validation
print("TEST 1: Standalone Validation")
print("-" * 40)
result = validate_pdf_standalone(pdf)
print(f"Result: {result}")
d = result.to_dict()
print(f"Checks: {d['total_checks']}, Passed: {d['passed']}, Warnings: {d['warnings']}, Errors: {d['errors']}")
print()

# Test 2: Self-comparison (source vs itself)
print("TEST 2: Comparative Validation (source vs itself)")
print("-" * 40)
result2 = validate_against_source(pdf, pdf)
print(f"Result: {result2}")
d2 = result2.to_dict()
print(f"Checks: {d2['total_checks']}, Passed: {d2['passed']}")
print()

# Test 3: Post-render integration
print("TEST 3: Post-Render Integration")
print("-" * 40)
mock_report = {
    'pages_processed': 16,
    'spans_replaced': 145,
    'errors': [],
    'coverage': {'pages_with_gaps': [], 'total_source_spans': 200, 'total_translated': 145}
}
combined = validate_render_output(pdf, pdf, mock_report)
print(f"Overall valid: {combined['overall_valid']}")
print(f"Requires review: {combined['requires_review']}")
sv = combined['structural_validation']
print(f"Structural: {sv['passed']}/{sv['total_checks']} passed")
ra = combined['render_report_analysis']
print(f"Render: {ra['pages_processed']} pages, {ra['spans_replaced']} spans, {ra['render_errors']} errors")

# Test 4: Mock with errors
print()
print("TEST 4: Post-Render with Errors")
print("-" * 40)
error_report = {
    'pages_processed': 16,
    'spans_replaced': 100,
    'errors': [{'page': 3, 'error': 'Story htmlbox failed'}],
    'coverage': {
        'pages_with_gaps': [{'page': 5, 'source_spans': 20, 'replaced': 5}],
        'total_source_spans': 200,
        'total_translated': 100
    }
}
combined2 = validate_render_output(pdf, pdf, error_report)
print(f"Overall valid: {combined2['overall_valid']}")
print(f"Requires review: {combined2['requires_review']}")
print(f"Issues: {combined2['render_report_analysis']['issues']}")

print()
print("ALL VALIDATION TESTS PASSED" if result.valid and result2.valid else "SOME TESTS FAILED")
