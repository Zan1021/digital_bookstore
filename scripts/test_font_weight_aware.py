"""
Weight-aware font resolution tests — font_policy (2026-10-10 fix).

Bug: every re-typeset span rendered BOLD because load_approved_fonts collapsed all weight
files of a family to ONE path (and the alias target PlaypenSans-Bold won the sort), so a
REGULAR source span still got the Bold file. Fix: the registry keeps per-weight files and
resolution picks Regular vs Bold from the source span's is_bold.

Hermetic: fabricate PlaypenSans-Regular.ttf + PlaypenSans-Bold.ttf from distinct PyMuPDF
built-ins so the two weight files have different bytes/paths. No external fonts, no network.

Run: python scripts/test_font_weight_aware.py
"""

import os
import sys
import tempfile

import pymupdf

SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
if SCRIPTS_DIR not in sys.path:
    sys.path.insert(0, SCRIPTS_DIR)

from font_policy import resolve_font_with_policy, resolve_role_font, load_approved_fonts  # noqa: E402

_passed = _failed = 0


def check(label, cond):
    global _passed, _failed
    if cond:
        _passed += 1
        print(f"  [OK] {label}")
    else:
        _failed += 1
        print(f"  [FAIL] {label}")


def _write(builtin, dest):
    with open(dest, "wb") as fh:
        fh.write(pymupdf.Font(builtin).buffer)


def main():
    print("=" * 60)
    print("WEIGHT-AWARE FONT RESOLUTION TESTS")
    print("=" * 60)

    with tempfile.TemporaryDirectory() as tmp:
        # Two distinct weight files of ONE family. Use different built-ins so the files are
        # genuinely distinct on disk (helv vs tiro) — we only care which PATH is chosen.
        reg = os.path.join(tmp, "PlaypenSans-Regular.ttf")
        bold = os.path.join(tmp, "PlaypenSans-Bold.ttf")
        _write("helv", reg)
        _write("tiro", bold)

        # Registry keeps both weights for the family.
        regmap = load_approved_fonts(tmp)
        fam = "playpensans"
        check("registry records a 'weights' map", "weights" in regmap)
        check("family has BOTH regular + bold files",
              set(regmap["weights"].get(fam, {}).keys()) >= {"regular", "bold"})

        # 1. Regular source span → regular file.
        r_reg = resolve_font_with_policy("PlaypenSans", tmp, is_bold=False)
        check("regular request resolves to the Regular file",
              os.path.basename(r_reg["fontFile"]) == "PlaypenSans-Regular.ttf")

        # 2. Bold source span → bold file.
        r_bold = resolve_font_with_policy("PlaypenSans", tmp, is_bold=True)
        check("bold request resolves to the Bold file",
              os.path.basename(r_bold["fontFile"]) == "PlaypenSans-Bold.ttf")

        # 3. THE reported bug: a REGULAR AdLibBT source span must NOT come out bold.
        #    AdLibBT now aliases to the PlaypenSans FAMILY, so weight follows is_bold.
        r_alias_reg = resolve_font_with_policy("AdLibBT-Regular", tmp, is_bold=False)
        check("retired AdLibBT (regular source) resolves to the Regular file (NOT bold)",
              os.path.basename(r_alias_reg["fontFile"]) == "PlaypenSans-Regular.ttf")
        check("retired AdLibBT regular still records aliasApplied",
              r_alias_reg.get("aliasApplied") is True)

        # 4. A bold AdLibBT span still gets bold via the alias.
        r_alias_bold = resolve_font_with_policy("AdLibBT", tmp, is_bold=True)
        check("retired AdLibBT (bold source) resolves to the Bold file",
              os.path.basename(r_alias_bold["fontFile"]) == "PlaypenSans-Bold.ttf")

        # 5. Through the role resolver (the live entry point) too.
        rr = resolve_role_font("paragraph", tmp, typography_policy=None,
                               source_font="AdLibBT-Regular", is_bold=False)
        check("role resolver: regular paragraph -> Regular file",
              os.path.basename(rr["fontFile"]) == "PlaypenSans-Regular.ttf")

    print("=" * 60)
    print(f"RESULTS: {_passed} passed, {_failed} failed")
    print("=" * 60)
    return 1 if _failed else 0


if __name__ == "__main__":
    sys.exit(main())
