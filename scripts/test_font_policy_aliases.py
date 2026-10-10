"""
Retired-font ALIAS resolution tests — font_policy._RETIRED_FONT_ALIASES
=======================================================================
Hermetic coverage for the counterfeit-font REMEDIATION path (vault 2026-10-07):
the publisher's source PDFs still reference font names whose shipped files were
counterfeits (AdLibBT = a Bangers file, Calibri = Open Sans, OzHandicraftBT = Caveat,
Edu-Aid = retired). font_policy maps each retired source name to an APPROVED
replacement family so the engine renders the approved face instead of the counterfeit
— and records the substitution as a deliberate `aliasApplied`, NOT a blind
`fallbackUsed` (marking it fallbackUsed made resolve_role_font reject the rung and
fall through to the house font — the self-inflicted bug called out in the 10-07 note).

Why this file exists: the existing test_font_policy_roles.py SKIPS when
storage/app/fonts is empty, so the alias path had NO test that runs without the
(gitignored) font assets. This test fabricates the approved replacement fonts from
PyMuPDF's built-in base-14 faces into a temp dir — genuine, distinct font files, no
external library, no network, no DB — so the alias behaviour is proven ANYWHERE.

Run: python scripts/test_font_policy_aliases.py   (prints results, exits 0/1)
"""

import os
import sys
import tempfile

import pymupdf

SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
if SCRIPTS_DIR not in sys.path:
    sys.path.insert(0, SCRIPTS_DIR)

from font_policy import (  # noqa: E402
    resolve_font_with_policy,
    resolve_role_font,
    _RETIRED_FONT_ALIASES,
    _norm,
)

_passed = _failed = 0


def check(label, cond):
    global _passed, _failed
    if cond:
        _passed += 1
        print(f"  [OK] {label}")
    else:
        _failed += 1
        print(f"  [FAIL] {label}")


def _write_builtin(builtin_name: str, dest_path: str):
    """Materialise a PyMuPDF built-in font's bytes to a real file on disk."""
    with open(dest_path, "wb") as fh:
        fh.write(pymupdf.Font(builtin_name).buffer)


def main():
    print("=" * 60)
    print("RETIRED-FONT ALIAS TESTS (counterfeit remediation)")
    print("=" * 60)

    with tempfile.TemporaryDirectory() as tmp:
        # Build an approved fonts dir that contains the alias TARGETS but NOT the
        # retired source names. Each target family is a genuine, distinct font file.
        #   PlaypenSans-Bold  <- AdLibBT     (title weight; NOT comic Bangers)
        #   PlaypenSans       <- Calibri     (house body)
        #   PatrickHand       <- OzHandicraft(genuine handwriting)
        #   PlaywriteZA       <- Edu-Aid     (retired script face)
        # We only need them to be present + distinct; we reuse two base-14 faces.
        _write_builtin("helv", os.path.join(tmp, "PlaypenSans-Bold.ttf"))
        _write_builtin("helv", os.path.join(tmp, "PlaypenSans-Regular.ttf"))
        _write_builtin("tiro", os.path.join(tmp, "PatrickHand-Regular.ttf"))
        _write_builtin("tiro", os.path.join(tmp, "PlaywriteZA-Regular.ttf"))

        # --- 1. The AdLibBT alias (the page-2 subtitle fix) -----------------------
        # A request for the retired 'AdLibBT-Regular' must resolve to the PlaypenSans
        # file via the alias, be flagged aliasApplied, and NOT be a blind fallback.
        r = resolve_font_with_policy("AdLibBT-Regular", tmp)
        check("AdLibBT: aliasApplied=True", r.get("aliasApplied") is True)
        check("AdLibBT: aliasFrom records original source name",
              r.get("aliasFrom") == "AdLibBT-Regular")
        check("AdLibBT: resolves to a PlaypenSans file (not the house fallback)",
              "playpensans" in _norm(r.get("resolvedFamily") or ""))
        check("AdLibBT: NOT marked fallbackUsed (the self-inflicted-bug guard)",
              r.get("fallbackUsed") is False)
        check("AdLibBT: records a font file + hash (provenance)",
              bool(r.get("fontFile")) and bool(r.get("fontFileHash")))

        # --- 2. Alias survives the role resolver (the LIVE entry point) -----------
        # resolve_role_font is what both render paths actually call. An aliased source
        # font with no competing policy must still land on the approved alias target
        # rather than falling through to the publisher-env house default.
        rr = resolve_role_font("subtitle", tmp, typography_policy=None,
                               source_font="AdLibBT-Regular", language="af")
        check("role resolver: AdLibBT subtitle resolves to PlaypenSans",
              "playpensans" in _norm(rr.get("resolvedFamily") or ""))
        check("role resolver: records aliasApplied through the chain",
              rr.get("aliasApplied") is True)

        # --- 3. Every declared retired alias resolves to an APPROVED target -------
        # Guards against a future edit adding an alias whose target isn't shipped
        # (which would silently degrade to the house font).
        for src_norm, target in _RETIRED_FONT_ALIASES.items():
            res = resolve_font_with_policy(src_norm, tmp)
            check(f"alias '{src_norm}' -> approved target '{target}'",
                  res.get("approved") is True and res.get("aliasApplied") is True)

        # --- 4. A NON-retired source font is untouched (no false aliasing) --------
        plain = resolve_font_with_policy("PatrickHand", tmp)
        check("non-retired font: aliasApplied absent/false",
              not plain.get("aliasApplied"))

    print("=" * 60)
    print(f"RESULTS: {_passed} passed, {_failed} failed")
    print("=" * 60)
    return 1 if _failed else 0


if __name__ == "__main__":
    sys.exit(main())
