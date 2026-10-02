"""
Tests for the whole-book visual coverage gate (unified-rendering-and-testing Req 4 / B1.2).
Run: python -m pytest scripts/test_visual_coverage.py   (or python scripts/test_visual_coverage.py)
"""

import sys
import os

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from visual_coverage import (  # noqa: E402
    visual_coverage_issues,
    is_fully_covered,
    CODE_PAGE_NOT_CHECKED,
    CODE_STALE,
    CODE_UNRESOLVED,
    CODE_UNEXPECTED_OR_DUP,
    CODE_EMPTY_EXPECTED,
)

FP = "fp-current"


def rec(page, status="passed", fp=FP):
    return {"page_number": page, "status": status, "candidate_fingerprint": fp}


def codes(issues):
    return [i["code"] for i in issues]


def test_full_passing_coverage_has_no_issues():
    records = [rec(1), rec(2), rec(3)]
    assert visual_coverage_issues([1, 2, 3], records, FP) == []
    assert is_fully_covered([1, 2, 3], records, FP)


def test_missing_page_is_flagged():
    issues = visual_coverage_issues([1, 2, 3], [rec(1), rec(2)], FP)
    assert codes(issues) == [CODE_PAGE_NOT_CHECKED]
    assert issues[0]["page_number"] == 3


def test_stale_fingerprint_is_flagged():
    issues = visual_coverage_issues([1], [rec(1, fp="fp-OLD")], FP)
    assert codes(issues) == [CODE_STALE]


def test_unresolved_status_is_flagged():
    # 'review' counts as unresolved — uncertainty never passes.
    issues = visual_coverage_issues([1], [rec(1, status="review")], FP)
    assert codes(issues) == [CODE_UNRESOLVED]
    assert issues[0]["status"] == "review"


def test_failed_status_is_unresolved():
    issues = visual_coverage_issues([1], [rec(1, status="failed")], FP)
    assert codes(issues) == [CODE_UNRESOLVED]


def test_unexpected_page_is_flagged():
    issues = visual_coverage_issues([1], [rec(1), rec(99)], FP)
    assert CODE_UNEXPECTED_OR_DUP in codes(issues)


def test_duplicate_page_is_flagged():
    issues = visual_coverage_issues([1, 2], [rec(1), rec(1), rec(2)], FP)
    assert CODE_UNEXPECTED_OR_DUP in codes(issues)


def test_empty_expected_set_fails_closed():
    issues = visual_coverage_issues([], [], FP)
    assert codes(issues) == [CODE_EMPTY_EXPECTED]
    assert not is_fully_covered([], [], FP)


def test_blank_image_only_pages_must_be_covered():
    # Pages 2 and 4 have no translated text but are still expected (blank/image-only).
    issues = visual_coverage_issues([1, 2, 3, 4], [rec(1), rec(3)], FP)
    assert codes(issues) == [CODE_PAGE_NOT_CHECKED, CODE_PAGE_NOT_CHECKED]
    assert {i["page_number"] for i in issues} == {2, 4}


def test_mixed_failures_all_reported():
    records = [rec(1), rec(2, status="review"), rec(3, fp="old"), rec(77)]
    issues = visual_coverage_issues([1, 2, 3, 4], records, FP)
    c = codes(issues)
    assert CODE_UNRESOLVED in c       # page 2
    assert CODE_STALE in c            # page 3
    assert CODE_UNEXPECTED_OR_DUP in c  # page 77
    assert CODE_PAGE_NOT_CHECKED in c   # page 4


if __name__ == "__main__":
    import traceback
    fns = [v for k, v in sorted(globals().items()) if k.startswith("test_") and callable(v)]
    passed = 0
    for fn in fns:
        try:
            fn()
            passed += 1
        except Exception:
            print(f"FAIL: {fn.__name__}")
            traceback.print_exc()
    print(f"{passed}/{len(fns)} passed")
    sys.exit(0 if passed == len(fns) else 1)
