"""
world-class-render-engine spec, Phase 3 — font policy resolver tests.

Proves the ONE shared resolver (font_policy.resolve_role_font) honours the precedence
chain and that missing-glyph detection works. Book-agnostic: uses whatever approved
fonts ship in storage/app/fonts; SKIPS cleanly if the dir is empty.

Run: python scripts/test_font_policy_roles.py
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from font_policy import resolve_role_font, check_glyph_coverage, load_approved_fonts

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FONTS = os.path.join(BASE, "storage", "app", "fonts")

_passed = _failed = 0


def check(name, cond):
    global _passed, _failed
    if cond:
        _passed += 1
        print(f"  [OK] {name}")
    else:
        _failed += 1
        print(f"  [FAIL] {name}")


reg = load_approved_fonts(FONTS)
fams = set(reg["families"].keys())
if not fams:
    print("SKIP: no approved fonts available")
    sys.exit(0)

# Pick two distinct approved families to prove the policy actually switches the font.
have_playpen = any("playpen" in f for f in fams)
have_patrick = any("patrickhand" in f for f in fams)
have_kalam = any("kalam" in f for f in fams)

# 1. UNIT override beats everything.
pol = {"version": 1, "roles": {"body": {"font_asset_id": "PlaypenSans"}},
       "unit_overrides": {"pX": {"font_asset_id": "Kalam"}}}
if have_kalam and have_playpen:
    r = resolve_role_font("paragraph", FONTS, pol, source_font="Edu-Aid", unit_id="pX")
    check("unit override wins (Kalam)", "kalam" in (r.get("resolvedFamily") or "").lower())

# 2. BOOK role override beats source when no unit override.
if have_patrick:
    pol2 = {"version": 1, "roles": {"body": {"font_asset_id": "PatrickHand"}}}
    r = resolve_role_font("copyright", FONTS, pol2, source_font="ComicSansMS", unit_id="pY")
    check("book role override wins over source (PatrickHand)",
          "patrickhand" in (r.get("resolvedFamily") or "").lower())

# 3. LANGUAGE/edition override beats book role.
if have_kalam and have_patrick:
    pol3 = {"version": 1, "roles": {"body": {"font_asset_id": "PatrickHand"}},
            "language_overrides": {"af": {"body": {"font_asset_id": "Kalam"}}}}
    r = resolve_role_font("paragraph", FONTS, pol3, source_font="ComicSansMS",
                          language="af", unit_id="pZ")
    check("language override wins over book role (Kalam)",
          "kalam" in (r.get("resolvedFamily") or "").lower())

# 4. No policy -> source font is honoured when it is an approved family. Only meaningful
#    when ComicSansMS actually ships in the fonts dir; skip cleanly otherwise (book-agnostic,
#    like the empty-dir skip at the top — a missing optional fixture is not a failure).
r = resolve_role_font("paragraph", FONTS, None, source_font="ComicSansMS")
if any("comicsansms" in f for f in fams):
    check("no policy -> source font honoured (ComicSansMS)",
          "comicsansms" in (r.get("resolvedFamily") or "").lower())
else:
    print("  [SKIP] no policy -> source font honoured (ComicSansMS not installed)")

# 5. Resolution always records provenance.
check("resolution records family + hash + resolvedBy",
      bool(r.get("resolvedFamily")) and bool(r.get("fontFileHash")) and bool(r.get("resolvedBy")))

# 6. Glyph coverage: a Latin font covers ASCII; an exotic char is flagged if unsupported.
latin_font = reg["families"].get(next(iter(fams)))
cov_ok = check_glyph_coverage(latin_font, "Hello Afrikaans groete")
check("glyph coverage ok for Latin text", cov_ok["ok"] is True)
# A char virtually no children's font has a glyph for (CJK) should be flagged missing.
cov_cjk = check_glyph_coverage(latin_font, "A \u4e2d B")
check("glyph coverage flags an unsupported CJK glyph",
      cov_cjk["ok"] is False and "\u4e2d" in cov_cjk["missing"])

print("=" * 60)
print(f"RESULTS: {_passed} passed, {_failed} failed")
print("=" * 60)
sys.exit(1 if _failed else 0)
