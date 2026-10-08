"""
Font-Asset-Integrity Preflight — Digital Bookstore V8
======================================================
Verifies, BEFORE the engine renders, that each font file on disk actually IS the
family the book requests — the exact class of bug that shipped a "Bangers" file
renamed to "AdLibBT-Regular.ttf" and rendered the wrong typeface for weeks with
nothing catching it.

This module adds ONE piece of new logic — comparing a font file's INTERNAL name
(from the embedded name table, via pymupdf.Font().name) against the requested
family — and REUSES the already-built subsystem for everything else:
  - font_policy._norm / _RETIRED_FONT_ALIASES  → consistent name normalisation + the
    approved retired-font aliases (so "matches" means the same thing it does to the
    live resolver).
  - glyph_preflight.preflight_single_text       → glyph-gap detection for the target
    language's required characters.

Contract (R-W3 / R-W3.1):
  Given a fonts directory + the families a book requests (from the manifest), return
  a per-font verdict {requested, file, internalName, matches, glyphGaps, aliasApplied,
  status, uploadPrompt?} plus an overall publishable/needs-review decision. A mismatch
  or glyph gap on a REQUIRED font fails closed (edition → NEEDS_LAYOUT_REVIEW) and
  surfaces an actionable "upload the correct TTF/OTF for <family>" prompt. An approved
  alias is recorded as a deliberate substitution, NOT a counterfeit.

CLI (invoked as a subprocess from PHP, JSON in / JSON out):
    python scripts/font_integrity.py --fonts-dir <dir> --families '["AdLibBT","PlaypenSans"]' \
        [--language af]
  → prints a JSON report to stdout; exit 0 always (the verdict is in the JSON, never
    the exit code — PHP reads status, not $?).
"""

import argparse
import json
import os
import sys

import pymupdf

SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
if SCRIPTS_DIR not in sys.path:
    sys.path.insert(0, SCRIPTS_DIR)

# Reuse the LIVE resolver's normalisation + approved aliases so "matches" is defined
# identically here and in font_policy (no second, divergent notion of equality).
from font_policy import _norm, _RETIRED_FONT_ALIASES, _family_from_filename  # noqa: E402
import glyph_preflight  # noqa: E402


# Status values recorded per font (and rolled up to the edition verdict).
STATUS_OK = "ok"                    # file's internal name matches the requested family
STATUS_ALIAS = "approved_alias"     # requested family is a retired font → approved substitute
STATUS_MISMATCH = "counterfeit"     # file's internal name does NOT match requested family
STATUS_GLYPH_GAP = "glyph_gap"      # file matches but is missing required glyphs
STATUS_MISSING = "missing"          # no file on disk for the requested family


def _internal_name(font_path: str) -> str:
    """Return a font file's embedded (internal) name, or '' if unreadable.

    This is the counterfeit detector: the shipped AdLibBT-Regular.ttf returns
    'Bangers' / 'Bangers Regular' here, revealing the file is not what its name claims.
    """
    try:
        return (pymupdf.Font(fontfile=font_path).name or "").strip()
    except Exception:
        return ""


def _find_file_for_family(family_norm: str, fonts_dir: str):
    """Find the font FILE whose filename-derived family matches the requested one.

    Deliberately keyed off the FILENAME (not the internal name) because the whole
    point is to detect the case where filename and internal name disagree.
    """
    if not os.path.isdir(fonts_dir):
        return None
    for f in sorted(os.listdir(fonts_dir)):
        if not f.lower().endswith((".ttf", ".otf")):
            continue
        fam = _norm(_family_from_filename(f))
        if family_norm and (family_norm in fam or fam in family_norm):
            return os.path.join(fonts_dir, f)
    return None


def _upload_prompt(requested_family: str, reason: str) -> str:
    """Actionable publisher-facing prompt wired to the existing FontManager upload path
    (R-W3.1). The engine never silently substitutes a counterfeit — it asks for the
    genuine file."""
    return (
        f"Upload the correct TTF/OTF for '{requested_family}'. "
        f"Reason: {reason}. The edition stays in review until a matching font is supplied."
    )


def check_font(requested_family: str, fonts_dir: str, language: str = "af",
               sample_text: str = "") -> dict:
    """Verify a single requested family against what's actually on disk.

    Returns a verdict dict (see module docstring). Pure/no side-effects.
    """
    req_norm = _norm(requested_family)
    verdict = {
        "requested": requested_family,
        "file": None,
        "internalName": None,
        "matches": False,
        "glyphGaps": [],
        "aliasApplied": False,
        "aliasFrom": None,
        "status": None,
        "uploadPrompt": None,
    }

    # 1. Retired-font alias: a deliberate, approved substitution decided by Captain Zan.
    #    This is NOT a counterfeit — record it and resolve against the alias target.
    effective_norm = req_norm
    if req_norm in _RETIRED_FONT_ALIASES:
        verdict["aliasApplied"] = True
        verdict["aliasFrom"] = requested_family
        effective_norm = _norm(_RETIRED_FONT_ALIASES[req_norm])

    # 2. Locate the file on disk for the (effective) family.
    font_path = _find_file_for_family(effective_norm, fonts_dir)
    if not font_path:
        verdict["status"] = STATUS_MISSING
        verdict["uploadPrompt"] = _upload_prompt(
            requested_family, "no matching font file found in the fonts directory")
        return verdict

    verdict["file"] = os.path.basename(font_path)
    internal = _internal_name(font_path)
    verdict["internalName"] = internal
    internal_norm = _norm(internal)

    # 3. THE counterfeit check: does the file's internal name match the family it claims?
    #    Substring either direction tolerates "Bangers" vs "Bangers Regular" style suffixes.
    name_matches = bool(internal_norm) and (
        effective_norm in internal_norm or internal_norm in effective_norm
    )

    if not name_matches:
        # File lies about what it is (e.g. AdLibBT file whose internal name is "Bangers").
        # SUPPLEMENTARY SIGNAL (C7-T28): when the embedded name is unreadable we cannot
        # name-match at all; a typography fingerprint (metric signature) can still describe
        # the file so a reviewer sees WHAT it actually is. Recorded, never flips the verdict
        # on its own (the name mismatch already fails closed). Fail-safe.
        verdict["matches"] = False
        verdict["status"] = STATUS_MISMATCH
        verdict["fingerprintSignal"] = _fingerprint_signal(font_path)
        verdict["uploadPrompt"] = _upload_prompt(
            requested_family,
            f"the file '{verdict['file']}' is internally '{internal or 'unreadable'}', "
            f"not '{requested_family}'",
        )
        return verdict

    # 4. File matches — now check glyph coverage for the target language (reuse glyph_preflight).
    verdict["matches"] = True
    text_to_check = sample_text or _language_probe(language)
    gp = glyph_preflight.preflight_single_text(text_to_check, font_path)
    if not gp.get("valid", False) and gp.get("missing_chars"):
        verdict["glyphGaps"] = gp["missing_chars"]
        verdict["status"] = STATUS_GLYPH_GAP
        verdict["uploadPrompt"] = _upload_prompt(
            requested_family,
            "missing glyphs for the target language: "
            + "".join(gp["missing_chars"][:20]),
        )
        return verdict

    verdict["status"] = STATUS_ALIAS if verdict["aliasApplied"] else STATUS_OK
    return verdict


def _language_probe(language: str) -> str:
    """A small representative string of the required characters for a language, so a
    glyph gap is caught even when no page text is supplied. Reuses font_registry's
    language char table when available; falls back to a plain Latin probe."""
    try:
        from font_registry import BASIC_LATIN, LANGUAGE_CHARS
        return BASIC_LATIN + LANGUAGE_CHARS.get(language, "")
    except Exception:
        return "The quick brown fox jumps over the lazy dog 0123456789"


def _fingerprint_signal(font_path: str) -> dict:
    """Metric-based typography fingerprint for a font file (C7-T28). Used ONLY as a
    supplementary descriptor on the counterfeit path when the embedded name is unreadable,
    so a reviewer can see the file's actual metric signature. Never changes the verdict.
    Fail-safe: returns {available: False} on any error (typography_fingerprint missing, etc.)."""
    try:
        from typography_fingerprint import build_fingerprint
        fp = build_fingerprint(font_path)
        return {
            "available": True,
            "fontName": getattr(fp, "font_name", None),
            "avgCharWidth": round(getattr(fp, "avg_char_width", 0.0), 3),
            "xHeightRatio": round(getattr(fp, "x_height_ratio", 0.0), 3),
            "weightClass": getattr(fp, "weight_class", None),
        }
    except Exception as e:
        return {"available": False, "reason": str(e)}


def preflight(fonts_dir: str, families, language: str = "af",
              required=None, sample_texts=None) -> dict:
    """Run the integrity preflight over every requested family.

    Args:
        fonts_dir:   directory holding the font files.
        families:    iterable of requested family names (from the manifest).
        language:    target language code for glyph-gap checks.
        required:    optional set/list of families that are REQUIRED (a problem on a
                     required font fails the edition). Default: every family is required.
        sample_texts: optional {family: text} to glyph-check against real page text.

    Returns an overall report:
      {
        "fonts": [verdict, ...],
        "publishable": bool,          # False if any REQUIRED font failed closed
        "needsReview": bool,          # inverse of publishable
        "offending": [family, ...],   # required families that failed
        "uploadPrompts": [str, ...],  # actionable prompts for the publisher
      }
    """
    families = list(dict.fromkeys(families or []))  # de-dupe, preserve order
    required_norm = None if required is None else {_norm(r) for r in required}
    sample_texts = sample_texts or {}

    verdicts = []
    offending = []
    prompts = []
    for fam in families:
        v = check_font(fam, fonts_dir, language=language,
                       sample_text=sample_texts.get(fam, ""))
        verdicts.append(v)

        is_required = required_norm is None or _norm(fam) in required_norm
        failed = v["status"] in (STATUS_MISMATCH, STATUS_GLYPH_GAP, STATUS_MISSING)
        if is_required and failed:
            offending.append(fam)
            if v["uploadPrompt"]:
                prompts.append(v["uploadPrompt"])

    publishable = len(offending) == 0
    return {
        "fonts": verdicts,
        "publishable": publishable,
        "needsReview": not publishable,
        "offending": offending,
        "uploadPrompts": prompts,
    }


# =============================================================================
# CLI (JSON in / JSON out — exit code is NOT the verdict; PHP reads the JSON)
# =============================================================================

def main(argv=None):
    ap = argparse.ArgumentParser(description="Font-asset-integrity preflight")
    ap.add_argument("--fonts-dir", required=True)
    ap.add_argument("--families", required=True,
                    help='JSON array of requested family names, e.g. ["AdLibBT","PlaypenSans"]')
    ap.add_argument("--language", default="af")
    ap.add_argument("--required", default=None,
                    help="JSON array of families that are REQUIRED (default: all)")
    args = ap.parse_args(argv)

    try:
        families = json.loads(args.families)
    except json.JSONDecodeError:
        # Tolerate a plain comma-separated list too.
        families = [s.strip() for s in args.families.split(",") if s.strip()]
    required = json.loads(args.required) if args.required else None

    report = preflight(args.fonts_dir, families, language=args.language, required=required)
    print(json.dumps(report, ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
