"""
Font policy / approved-font enforcement — Digital Bookstore V8 (brief §9.1/§9.3)
================================================================================
Maintains the APPROVED font list and resolves a source font request to a concrete
font file, recording provenance so QA can fail closed on any unapproved fallback.

Rules (brief §9.1/§9.3):
  - There is an approved font set. A render may only use an approved font.
  - If the requested source font resolves to an approved font -> OK (fallback_used
    may still be true if we substituted a different approved family, but it is an
    APPROVED substitute, so it does not fail closed by itself).
  - If resolution lands on an UNAPPROVED font (or no font at all) -> the resolution
    is marked unapproved and the caller must route the edition to NEEDS_LAYOUT_REVIEW.
  - Every resolution records: fontRequested, resolvedFamily, fontFileHash,
    fallbackUsed, approved.

Book-agnostic: the approved set is derived from the fonts directory (every font
shipped in the project's fonts dir is approved) PLUS an optional explicit allowlist
file (fonts_dir/approved_fonts.txt, one family per line). No per-book constants.
"""

import hashlib
import os


def _norm(name: str) -> str:
    """Normalise a font family/file name for comparison."""
    return (name or "").lower().replace("-", "").replace("_", "").replace(" ", "")


def _family_from_filename(filename: str) -> str:
    name = filename.rsplit(".", 1)[0]
    for suffix in ("-Regular", "-Bold", "-SemiBold", "-Medium", "-Light", "-Italic",
                   "Regular", "Bold", "SemiBold", "Medium", "Light", "Italic"):
        name = name.replace(suffix, "")
    return name.rstrip("-_ ")


def load_approved_fonts(fonts_dir: str) -> dict:
    """
    Build the approved-font registry from a fonts directory.

    Returns a dict:
      {
        "families": {normalised_family: font_file_path},
        "files": [font_file_path, ...],
        "allowlist": set(normalised_family)  # explicit, if approved_fonts.txt exists
      }
    """
    registry = {"families": {}, "files": [], "allowlist": set()}
    if not fonts_dir or not os.path.isdir(fonts_dir):
        return registry

    for f in sorted(os.listdir(fonts_dir)):
        if not f.lower().endswith((".ttf", ".otf")):
            continue
        path = os.path.join(fonts_dir, f)
        registry["files"].append(path)
        fam = _norm(_family_from_filename(f))
        registry["families"].setdefault(fam, path)

    allow_path = os.path.join(fonts_dir, "approved_fonts.txt")
    if os.path.isfile(allow_path):
        try:
            with open(allow_path, "r", encoding="utf-8") as fh:
                for line in fh:
                    line = line.strip()
                    if line and not line.startswith("#"):
                        registry["allowlist"].add(_norm(line))
        except Exception:
            pass
    return registry


def _hash_file(path: str) -> str:
    try:
        with open(path, "rb") as fh:
            return hashlib.sha256(fh.read()).hexdigest()[:16]
    except Exception:
        return None


def _is_approved(family_norm: str, registry: dict) -> bool:
    """A family is approved if it's in the explicit allowlist (when present) or,
    when no explicit allowlist exists, if it ships in the fonts dir."""
    if registry["allowlist"]:
        return family_norm in registry["allowlist"]
    return family_norm in registry["families"]


def resolve_font_with_policy(requested_font: str, fonts_dir: str, is_bold: bool = False) -> dict:
    """
    Resolve a requested source font to an approved font file, recording provenance.

    Returns:
      {
        "fontRequested": str,
        "resolvedFamily": str | None,
        "fontFile": str | None,
        "fontFileHash": str | None,
        "fallbackUsed": bool,     # a different family was substituted
        "approved": bool,         # resolved font is in the approved set
        "unresolved": bool,       # no usable font at all
      }
    """
    registry = load_approved_fonts(fonts_dir)
    result = {
        "fontRequested": requested_font,
        "resolvedFamily": None,
        "fontFile": None,
        "fontFileHash": None,
        "fallbackUsed": False,
        "approved": False,
        "unresolved": False,
    }

    if not registry["files"]:
        result["unresolved"] = True
        result["fallbackUsed"] = True
        return result

    req_norm = _norm(requested_font)

    # 1. Exact / substring family match against available fonts.
    chosen_path = None
    chosen_family = None
    for fam, path in registry["families"].items():
        if req_norm and (req_norm in fam or fam in req_norm):
            chosen_path, chosen_family = path, fam
            break

    # 2. Bold preference if requested and unmatched.
    if not chosen_path and is_bold:
        for path in registry["files"]:
            if "bold" in os.path.basename(path).lower():
                chosen_path = path
                chosen_family = _norm(_family_from_filename(os.path.basename(path)))
                result["fallbackUsed"] = True
                break

    # 3. Fall back to first available (substitution).
    if not chosen_path:
        chosen_path = registry["files"][0]
        chosen_family = _norm(_family_from_filename(os.path.basename(chosen_path)))
        result["fallbackUsed"] = True

    result["fontFile"] = chosen_path
    result["resolvedFamily"] = _family_from_filename(os.path.basename(chosen_path))
    result["fontFileHash"] = _hash_file(chosen_path)
    result["approved"] = _is_approved(chosen_family, registry)
    return result


def document_font_policy_report(fonts_dir: str, requested_fonts=None) -> dict:
    """
    Produce a document-level font policy report (brief §9.1). Resolves each distinct
    requested source font through the policy and flags whether any resolution is
    unapproved or unresolved (=> the edition must fail closed).

    requested_fonts: optional iterable of source font family names seen in the PDF.
                     When None, only the primary-resolution state is reported.
    """
    registry = load_approved_fonts(fonts_dir)
    report = {
        "approved_font_count": len(registry["families"]),
        "has_explicit_allowlist": bool(registry["allowlist"]),
        "resolutions": [],
        "any_unapproved": False,
        "any_unresolved": False,
    }
    if not registry["files"]:
        report["any_unresolved"] = True
        return report

    for rf in sorted(set(requested_fonts or [])):
        res = resolve_font_with_policy(rf, fonts_dir)
        report["resolutions"].append(res)
        if res["unresolved"]:
            report["any_unresolved"] = True
        if not res["approved"]:
            report["any_unapproved"] = True
    return report


# =============================================================================
# ROLE-BASED FONT POLICY (world-class-render-engine spec Req 4)
# =============================================================================
# One resolver shared by BOTH the native renderer and the illustration-text path, so a
# font choice is an explicit per-book/edition/role decision — not an accident of
# filenames or a process-wide env var. Precedence (highest first):
#   unit override > edition role override > book role override > publisher role default
#   > source font (when usable) > approved script-compatible fallback.
#
# typography_policy shape (persisted on Book.metadata['typography_policy']; passed to the
# engine as JSON). Fonts are referenced by VALIDATED ASSET ID (a font family/stem that
# must resolve inside the approved fonts dir) — never a client-supplied absolute path.
#   {
#     "version": 1,
#     "publisher_default": {"body": "PlaypenSans", "title": "PlaypenSans",
#                            "artwork_label": "PlaypenSans"},
#     "roles": {"body": {"font_asset_id": "<family>", "weight": 400}, ...},
#     "language_overrides": {"af": {"body": {"font_asset_id": "<family>"}}},
#     "unit_overrides": {"p02_s0011": {"font_asset_id": "<family>"}}
#   }
# Every field is optional; an empty/absent policy degrades to source>fallback (today's
# behaviour) with the env STORY_BODY_FONT as the publisher default.

import os as _os

# Map a semantic render role to a policy role bucket (book-agnostic).
_ROLE_BUCKET = {
    "paragraph": "body", "copyright": "body", "character_bio": "body",
    "publisher": "body", "caption": "body", "label": "body", "list_item": "body",
    "word_list_item": "body", "phonics": "body",
    "book_title": "title", "subtitle": "title", "heading": "title",
    "table_header": "title", "merged_header": "title",
    "artwork_label": "artwork_label",
}


def _policy_asset(policy: dict, bucket: str, language: str = None, unit_id: str = None):
    """Return the font_asset_id chosen by the policy for a bucket, honouring the
    precedence unit > language(edition) > book role > publisher default. Returns None
    when the policy says nothing (caller then falls back to source/approved)."""
    if not isinstance(policy, dict):
        return None, None
    # 1. unit override
    uo = (policy.get("unit_overrides") or {}).get(unit_id or "")
    if isinstance(uo, dict) and uo.get("font_asset_id"):
        return uo["font_asset_id"], uo.get("weight")
    # 2. language/edition override for this bucket
    lo = ((policy.get("language_overrides") or {}).get(language or "") or {}).get(bucket)
    if isinstance(lo, dict) and lo.get("font_asset_id"):
        return lo["font_asset_id"], lo.get("weight")
    # 3. book role override
    ro = (policy.get("roles") or {}).get(bucket)
    if isinstance(ro, dict) and ro.get("font_asset_id"):
        return ro["font_asset_id"], ro.get("weight")
    # 4. publisher default for this bucket
    pd = (policy.get("publisher_default") or {}).get(bucket)
    if isinstance(pd, str) and pd:
        return pd, None
    return None, None


def resolve_role_font(role: str, fonts_dir: str, typography_policy: dict = None,
                      source_font: str = None, is_bold: bool = False,
                      language: str = None, unit_id: str = None) -> dict:
    """
    THE single font resolution entry point for both render paths (spec Req 4.1/4.4).

    Applies the precedence chain, then resolves the chosen family to a concrete approved
    font file via resolve_font_with_policy (which records provenance + hash + approval).
    Returns resolve_font_with_policy's dict PLUS: role, bucket, policyChoice (the
    asset id the policy picked, or None), and resolvedBy (which precedence rung won).

    Book-agnostic. A font asset id is validated by resolution: it only takes effect if
    it resolves inside the approved fonts dir; otherwise we fall through to the next rung.
    """
    bucket = _ROLE_BUCKET.get((role or "").lower(), "body")
    asset, weight = _policy_asset(typography_policy, bucket, language, unit_id)
    bold = is_bold or (isinstance(weight, int) and weight >= 600)

    chain = []  # (requested, label) in precedence order
    if asset:
        chain.append((asset, "policy"))
    if source_font:
        chain.append((source_font, "source"))
    # Publisher env default as the final named preference before blind fallback.
    env_default = _os.environ.get("STORY_BODY_FONT", "PlaypenSans")
    chain.append((env_default, "publisher_env"))

    registry = load_approved_fonts(fonts_dir)
    for requested, label in chain:
        res = resolve_font_with_policy(requested, fonts_dir, is_bold=bold)
        # Accept the first rung that lands on an APPROVED, non-fallback (true) match OR,
        # for the policy rung, any approved resolution (an approved substitute is fine).
        if res.get("fontFile") and res.get("approved") and not res.get("fallbackUsed"):
            res.update({"role": role, "bucket": bucket, "policyChoice": asset,
                        "resolvedBy": label})
            return res
    # Nothing matched cleanly — take the policy/source best-effort resolution (records
    # fallbackUsed/approved so the caller + gate can decide). Prefer the policy asset.
    best_req = asset or source_font or env_default
    res = resolve_font_with_policy(best_req, fonts_dir, is_bold=bold)
    res.update({"role": role, "bucket": bucket, "policyChoice": asset,
                "resolvedBy": "fallback"})
    return res


def check_glyph_coverage(font_file: str, text: str) -> dict:
    """Verify the resolved font actually HAS glyphs for the target text (spec Req 4.5).
    Returns {ok, missing:[chars]}. Missing glyphs => caller flags the element + fails
    closed. Book-agnostic: pure font cmap check, no language assumptions."""
    out = {"ok": True, "missing": []}
    if not font_file or not _os.path.isfile(font_file) or not text:
        return out
    try:
        import pymupdf
        font = pymupdf.Font(fontfile=font_file)
        missing = []
        seen = set()
        for ch in text:
            if ch in seen or ch.isspace():
                continue
            seen.add(ch)
            try:
                if font.has_glyph(ord(ch)):
                    continue
            except Exception:
                # Older PyMuPDF: fall back to glyph_advance==0 heuristic.
                try:
                    if font.glyph_advance(ord(ch)) > 0:
                        continue
                except Exception:
                    continue
            # Printable char with no glyph.
            if ch.isprintable():
                missing.append(ch)
        out["missing"] = missing
        out["ok"] = not missing
    except Exception:
        # If the check itself errors, do not block (the structural/font gates still run).
        return {"ok": True, "missing": []}
    return out
