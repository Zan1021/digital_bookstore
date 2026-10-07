"""
Tests for font_integrity.py (T6/T8) — Digital Bookstore V8
===========================================================
Built from the REAL counterfeit case: a font FILE whose filename claims one family
but whose INTERNAL name is a different typeface (the "AdLibBT file is actually Bangers"
bug). We reproduce that hermetically using PyMuPDF's built-in base-14 fonts (Helvetica,
Times-Roman) written to disk — genuine, distinct font files with known internal names,
no external font library or network needed, fully book-agnostic.

The lie: write Helvetica's bytes to a file named 'AdLibBT-Regular.ttf'. Its internal
name is 'Helvetica', which does NOT match the requested family 'AdLibBT' → counterfeit.

Runs as a standalone script (like the other scripts/test_*.py here): prints results,
sys.exit(0/1). No pytest required.
"""

import os
import sys
import tempfile

import pymupdf

SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
if SCRIPTS_DIR not in sys.path:
    sys.path.insert(0, SCRIPTS_DIR)

import font_integrity as fi

_passed = 0
_failed = 0


def check(label, cond):
    global _passed, _failed
    if cond:
        _passed += 1
        print(f"  [OK] {label}")
    else:
        _failed += 1
        print(f"  [FAIL] {label}")


def _write_builtin(builtin_name: str, dest_path: str):
    """Write a PyMuPDF built-in font's bytes to a real file on disk."""
    buf = pymupdf.Font(builtin_name).buffer
    with open(dest_path, "wb") as fh:
        fh.write(buf)


def main():
    print("=" * 60)
    print("FONT INTEGRITY TESTS (R-W3 / R-W3.1)")
    print("=" * 60)

    with tempfile.TemporaryDirectory() as tmp:
        # Counterfeit: a real font whose FILE NAME claims 'StoryDisplay' but whose
        # embedded internal name is something else entirely. NOTE: we deliberately use
        # a family that is NOT in font_policy._RETIRED_FONT_ALIASES — an aliased name
        # (like the real 'AdLibBT') is intentionally redirected to an approved
        # substitute and would never reach the counterfeit path. The counterfeit
        # DETECTOR is what we're testing here, so the requested family must be one the
        # policy does not already know is retired.
        claimed = "StoryDisplay"
        counterfeit = os.path.join(tmp, f"{claimed}-Regular.ttf")
        _write_builtin("helv", counterfeit)

        # Discover the ACTUAL on-disk internal name of the built-in we wrote (MuPDF may
        # embed a metrically-compatible face, e.g. 'Nimbus Sans', not 'Helvetica'). The
        # test must assert against on-disk truth, not the in-memory alias — that is
        # exactly the filename-vs-embedded-name gap the detector exists to catch.
        real_name_1 = fi._internal_name(counterfeit)              # e.g. "Nimbus Sans Regular"
        genuine_family_1 = real_name_1.split()[0] if real_name_1 else "Nimbus"

        # Genuine: same bytes, but named honestly after its real embedded family.
        genuine = os.path.join(tmp, f"{genuine_family_1}-Regular.ttf")
        _write_builtin("helv", genuine)

        # A second genuine, DISTINCT family.
        times = os.path.join(tmp, "Times-Roman.ttf")
        _write_builtin("tiro", times)
        real_name_2 = fi._internal_name(times)
        genuine_family_2 = real_name_2.split()[0] if real_name_2 else "Times"

        # --- 1. Counterfeit StoryDisplay is caught --------------------------------
        v = fi.check_font(claimed, tmp, language="af")
        check("counterfeit: matches=False", v["matches"] is False)
        check("counterfeit: status=counterfeit", v["status"] == fi.STATUS_MISMATCH)
        check("counterfeit: internal name is NOT the claimed family",
              claimed.lower() not in (v["internalName"] or "").lower())
        check("counterfeit: internal name revealed (the real embedded face)",
              bool(v["internalName"]) and v["internalName"].lower() == real_name_1.lower())
        check("counterfeit: emits an upload prompt for the right family",
              bool(v["uploadPrompt"]) and claimed in v["uploadPrompt"])

        # --- 2. Genuine (honestly-named) font passes ------------------------------
        v2 = fi.check_font(genuine_family_1, tmp, language="af")
        check("genuine font: matches=True", v2["matches"] is True)
        check("genuine font: status=ok", v2["status"] == fi.STATUS_OK)
        check("genuine font: no upload prompt", v2["uploadPrompt"] is None)

        # --- 3. Approved alias is a deliberate substitution, NOT a counterfeit ----
        #     'Calibri' is a retired alias → PlaypenSans (per font_policy). PlaypenSans
        #     is absent here, so it resolves 'missing' — but it must be flagged as an
        #     ALIAS attempt, never mislabeled a counterfeit.
        v3 = fi.check_font("Calibri", tmp, language="af")
        check("retired alias Calibri: aliasApplied=True", v3["aliasApplied"] is True)
        check("retired alias Calibri: aliasFrom recorded", v3["aliasFrom"] == "Calibri")
        check("retired alias Calibri: NOT labeled counterfeit",
              v3["status"] != fi.STATUS_MISMATCH)

        # --- 4. Missing family → missing status + upload prompt -------------------
        v4 = fi.check_font("NonExistentFace", tmp, language="af")
        check("missing family: status=missing", v4["status"] == fi.STATUS_MISSING)
        check("missing family: emits upload prompt", bool(v4["uploadPrompt"]))

        # --- 5. Overall preflight verdict (required font fails closed) ------------
        report = fi.preflight(tmp, [claimed, genuine_family_1], language="af")
        check("preflight: not publishable (counterfeit present)",
              report["publishable"] is False and report["needsReview"] is True)
        check("preflight: counterfeit listed as offending", claimed in report["offending"])
        check("preflight: genuine font NOT offending",
              genuine_family_1 not in report["offending"])
        check("preflight: at least one upload prompt surfaced", len(report["uploadPrompts"]) >= 1)

        # --- 6. The resolution loop: an all-honest set is publishable -------------
        #     Simulates the state AFTER the publisher uploads correct files: every
        #     requested family's file is internally what it claims.
        report2 = fi.preflight(tmp, [genuine_family_1, genuine_family_2], language="af")
        check("preflight after honest set: publishable=True", report2["publishable"] is True)
        check("preflight after honest set: no offending fonts", report2["offending"] == [])

    print("=" * 60)
    print(f"RESULTS: {_passed} passed, {_failed} failed")
    print("=" * 60)
    return 1 if _failed else 0


if __name__ == "__main__":
    sys.exit(main())
