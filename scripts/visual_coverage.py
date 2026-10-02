"""
Whole-book visual coverage gate — Digital Bookstore
=====================================================
unified-rendering-and-testing spec Req 4 (brief §9 "Reference final-page coverage gate").

The visual QA pass checks pages one at a time. On its own it CANNOT prove that every
required page of the final PDF was actually checked against the EXACT candidate: a missing
file, an API timeout, a crashed page render, or a stale result from a previous candidate
all used to vanish silently and let an edition look "clean". This gate closes that hole.

Given:
  - expected_pages : the full set of 1-based page numbers derived from the FINAL staged
                     PDF / inventory (NOT the translated-page DB rows — blank, preserved and
                     image-only pages count too);
  - records        : one per-page visual result, each already schema+identity validated by
                     the caller, carrying at least {page_number, status, candidate_fingerprint};
  - fingerprint    : the candidate fingerprint being gated.

it returns a list of issue dicts. An EMPTY list means every expected page has a passed
result bound to this candidate. Any of these is a blocking issue:

  PAGE_NOT_CHECKED            — an expected page has no record at all;
  STALE_VISUAL_RESULT         — a record's candidate_fingerprint != the gated fingerprint;
  VISUAL_CHECK_UNRESOLVED     — a record exists + is current but status != "passed"
                                ("review", "failed", "not_run", "running", missing all count
                                as unresolved — uncertainty never passes);
  UNEXPECTED_OR_DUPLICATE_PAGE — a record for a page not in the expected set, or a second
                                record for a page already seen.

Fail closed: an empty expected set is itself an error (nothing was derived from the PDF),
NOT a vacuous pass.
"""

from typing import Any

PASSED = "passed"

CODE_PAGE_NOT_CHECKED = "PAGE_NOT_CHECKED"
CODE_STALE = "STALE_VISUAL_RESULT"
CODE_UNRESOLVED = "VISUAL_CHECK_UNRESOLVED"
CODE_UNEXPECTED_OR_DUP = "UNEXPECTED_OR_DUPLICATE_PAGE"
CODE_EMPTY_EXPECTED = "EMPTY_EXPECTED_PAGE_SET"


def visual_coverage_issues(
    expected_pages: list[int],
    records: list[dict[str, Any]],
    fingerprint: str,
) -> list[dict[str, Any]]:
    """Return the list of coverage issues (empty == full, current, passing coverage).

    Individual records must ALREADY have schema/identity validation applied by the caller;
    this gate reasons about coverage + freshness only.
    """
    # Fail closed: no expected pages means the page set was never derived — not a pass.
    expected = set(expected_pages)
    if not expected:
        return [{"code": CODE_EMPTY_EXPECTED}]

    issues: list[dict[str, Any]] = []
    seen: set[int] = set()

    for record in records:
        page = record.get("page_number")
        if page not in expected or page in seen:
            issues.append({"code": CODE_UNEXPECTED_OR_DUP, "page_number": page})
            continue
        seen.add(page)

        if record.get("candidate_fingerprint") != fingerprint:
            issues.append({"code": CODE_STALE, "page_number": page})
        elif record.get("status") != PASSED:
            issues.append({
                "code": CODE_UNRESOLVED,
                "page_number": page,
                "status": record.get("status", "missing"),
            })

    for page in sorted(expected - seen):
        issues.append({"code": CODE_PAGE_NOT_CHECKED, "page_number": page})

    return issues


def is_fully_covered(expected_pages: list[int], records: list[dict[str, Any]], fingerprint: str) -> bool:
    """Convenience: True only when there are zero coverage issues."""
    return not visual_coverage_issues(expected_pages, records, fingerprint)
