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
