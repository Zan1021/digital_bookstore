"""
Thin stdin/stdout wrapper around visual_coverage.visual_coverage_issues so PHP
(BookTestingService) and the Python test suite share ONE implementation of the coverage
rules (unified-rendering-and-testing Req 4 / B1.3).

Input  (stdin, JSON): {"expected_pages": [int], "records": [ {...} ], "fingerprint": str}
Output (stdout, JSON): {"issues": [ {...} ], "covered": bool}
"""

import sys
import os
import json

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from visual_coverage import visual_coverage_issues  # noqa: E402


def main() -> int:
    try:
        payload = json.loads(sys.stdin.read() or "{}")
    except json.JSONDecodeError as exc:
        print(json.dumps({"issues": [{"code": "INVALID_INPUT_JSON", "detail": str(exc)}], "covered": False}))
        return 1

    issues = visual_coverage_issues(
        payload.get("expected_pages", []),
        payload.get("records", []),
        payload.get("fingerprint", ""),
    )
    print(json.dumps({"issues": issues, "covered": not issues}))
    return 0


if __name__ == "__main__":
    sys.exit(main())
