"""
Back-cover font-policy tests — the resolution path render_back_cover_v8 uses
============================================================================
Captain Zan (2026-10-10): "on back cover it did not use the correct font." Root cause:
render_back_cover_v8 hardcoded typography_policy=None and role='label' (the BODY
bucket), so a per-book back-cover font was silently discarded and the body font (or a
source-font resolution) was used instead of the title font.

The renderer now resolves via resolve_role_font(role='book_title',
typography_policy=<book policy>, ...). This test exercises that exact call with the
same role + a book policy, proving the policy's chosen title font wins — hermetically,
using built-in fonts, no page/PDF/DB.

Run: python scripts/test_back_cover_font_policy.py
"""

import os
import sys
import tempfile

import pymupdf

SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
if SCRIPTS_DIR not in sys.path:
    sys.path.insert(0, SCRIPTS_DIR)

from font_policy import resolve_role_font, _norm  # noqa: E402

_passed = _failed = 0


def check(label, cond):
    global _passed, _failed
    if cond:
        _passed += 1
        print(f"  [OK] {label}")
    else:
        _failed += 1
        print(f"  [FAIL] {label}")


def _write_builtin(builtin_name, dest_path):
    with open(dest_path, "wb") as fh:
        fh.write(pymupdf.Font(builtin_name).buffer)


def main():
    print("=" * 60)
    print("BACK-COVER FONT-POLICY TESTS (correct-font fix)")
    print("=" * 60)

    with tempfile.TemporaryDirectory() as tmp:
        # Two distinct approved families present: a body font and a title font.
        _write_builtin("helv", os.path.join(tmp, "PlaypenSans-Regular.ttf"))   # body
        _write_builtin("tiro", os.path.join(tmp, "PatrickHand-Regular.ttf"))   # title

        # A book typography policy that sets the TITLE bucket to PatrickHand. The back
        # cover resolves role 'book_title' → title bucket, so this must win.
        policy = {
            "version": 1,
            "publisher_default": {"body": "PlaypenSans", "title": "PlaypenSans"},
            "roles": {"title": {"font_asset_id": "PatrickHand"}},
        }

        # 1. WITH policy (the fixed path): back cover gets the policy's title font.
        spec = resolve_role_font(
            role="book_title", fonts_dir=tmp, typography_policy=policy,
            source_font="OzHandicraftBT", is_bold=False)
        check("back cover honours the policy title font (PatrickHand)",
              "patrickhand" in _norm(spec.get("resolvedFamily") or ""))
        check("resolution reports it came from the policy",
              spec.get("resolvedBy") in ("policy", "fallback") and spec.get("policyChoice") == "PatrickHand")

        # 2. Role maps to the TITLE bucket (not body) — the second half of the bug.
        check("role 'book_title' resolves in the title bucket", spec.get("bucket") == "title")

        # 3. Regression: the OLD role 'label' maps to the BODY bucket, so a title-only
        #    policy would NOT reach it — demonstrates WHY 'label' was wrong for the back
        #    cover series title.
        spec_label = resolve_role_font(
            role="label", fonts_dir=tmp, typography_policy=policy,
            source_font="OzHandicraftBT", is_bold=False)
        check("old role 'label' sits in the body bucket (the bug)",
              spec_label.get("bucket") == "body")
        check("old role 'label' does NOT pick up the title-only policy font",
              "patrickhand" not in _norm(spec_label.get("resolvedFamily") or ""))

        # 4. Provenance is always recorded (approval + family + resolvedBy).
        check("resolution records provenance",
              bool(spec.get("resolvedFamily")) and spec.get("approved") is True
              and bool(spec.get("resolvedBy")))

    print("=" * 60)
    print(f"RESULTS: {_passed} passed, {_failed} failed")
    print("=" * 60)
    return 1 if _failed else 0


if __name__ == "__main__":
    sys.exit(main())
