"""
PDF validation module (pdf_validation.py): standalone check, source-vs-output comparison,
and the post-render report that decides whether an edition needs review.

Uses a real corpus book (skips if absent). The render reports are synthetic, so the
assertions are about how validation reacts to them, not about any particular book.

Run: python scripts/test_validation.py
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from corpus_fixtures import require_corpus_book
from pdf_validation import validate_against_source, validate_pdf_standalone, validate_render_output

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
print("PDF VALIDATION")
print("=" * 60)

standalone = validate_pdf_standalone(pdf)
d = standalone.to_dict()
check("standalone validation of a source book passes", standalone.valid)
check("standalone validation ran checks", d["total_checks"] > 0)

self_compare = validate_against_source(pdf, pdf)
check("a book compared with itself passes", self_compare.valid)

clean_report = {
    "pages_processed": 16, "spans_replaced": 145, "errors": [],
    "coverage": {"pages_with_gaps": [], "total_source_spans": 200, "total_translated": 145},
}
clean = validate_render_output(pdf, pdf, clean_report)
check("clean render report is valid", clean["overall_valid"])
check("clean render report does not require review", not clean["requires_review"])

error_report = {
    "pages_processed": 16, "spans_replaced": 100,
    "errors": [{"page": 3, "error": "Story htmlbox failed"}],
    "coverage": {"pages_with_gaps": [{"page": 5, "source_spans": 20, "replaced": 5}],
                 "total_source_spans": 200, "total_translated": 100},
}
bad = validate_render_output(pdf, pdf, error_report)
issues = bad["render_report_analysis"]["issues"]
check("render error makes the edition invalid (fail closed)", not bad["overall_valid"])
check("render error requires review", bad["requires_review"])
check("render error is named with its page", any(i.startswith("Page 3:") for i in issues))
check("coverage gap is named with its page", any(i.startswith("Page 5:") for i in issues))

gap_only = dict(clean_report, coverage={"pages_with_gaps": [{"page": 7, "source_spans": 10,
                                                              "replaced": 2}]})
check("coverage gap alone still requires review",
      validate_render_output(pdf, pdf, gap_only)["requires_review"])

print("=" * 60)
print(f"RESULTS: {_passed} passed, {_failed} failed")
print("=" * 60)
sys.exit(1 if _failed else 0)
