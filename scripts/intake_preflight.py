#!/usr/bin/env python3
"""
intake_preflight.py — the intake check for one uploaded PDF (task 10.2, Req 2.3–2.5, design §6).

Runs in its own process before anything else reads the file. Answers two questions:

1. May this file be processed? (Req 2.3) Refused, with a reason a publisher can act on, when it
   is not a PDF, cannot be opened, is password-protected, is too large or has too many pages,
   or contains JavaScript, program-launching actions or attached files. Reuses the threat
   checks of security.py.
2. What are its pages? (Req 2.4–2.5) Per page: size, trim box (from cropmark_detection.py:
   page TrimBox, vector crop marks, CropBox, else the book-wide consensus, else the whole page)
   and whether it has live text, is a scanned image or has no text at all.

Coordinates are PDF points with a top-left origin, [x0, y0, x1, y1].

CLI: python intake_preflight.py --input book.pdf [--max-mb 100] [--max-pages 500]
Prints one JSON object; exit code 0 when the file may be processed, 2 when refused.
"""

from __future__ import annotations

import argparse
import json
import os
import sys

import pymupdf

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

from cropmark_detection import detect_crop_marks_document  # noqa: E402
from security import scan_pdf_security  # noqa: E402

LIVE_TEXT_MIN_CHARS = 3        # fewer visible characters than this is not "live text"
SCANNED_IMAGE_COVERAGE = 0.5   # an image covering at least half the page, with no text, is a scan


def _reason(code: str, message: str) -> dict:
    return {"code": code, "message": message}


def _page_kind(page) -> tuple[str, int]:
    chars = len("".join(page.get_text("text").split()))
    if chars >= LIVE_TEXT_MIN_CHARS:
        return "live_text", chars
    area = abs(page.rect) or 1.0
    covered = 0.0
    for info in page.get_image_info():
        bbox = pymupdf.Rect(info["bbox"]) & page.rect
        covered = max(covered, abs(bbox) / area)
    return ("scanned" if covered >= SCANNED_IMAGE_COVERAGE else "no_text"), chars


def _cover_lines(page) -> list[dict]:
    """Text lines of the cover with their largest font size, top to bottom. Characters the
    font does not map come through as U+FFFD; the title chooser treats them as unknown."""
    lines = []
    for block in page.get_text("dict")["blocks"]:
        for line in block.get("lines", []):
            text = " ".join("".join(s["text"] for s in line["spans"]).split())
            if any(ch.isalpha() for ch in text):
                lines.append({"text": text, "size": round(max(s["size"] for s in line["spans"]), 1),
                              "y": round(line["bbox"][1], 1)})
    return sorted(lines, key=lambda ln: ln["y"])[:40]


def preflight(path: str, max_mb: float = 100, max_pages: int = 500) -> dict:
    out = {"ok": False, "rejections": [], "warnings": [], "page_count": 0, "pages": [], "crop": None,
           "meta": {}, "cover_lines": []}
    reject = lambda code, msg: out["rejections"].append(_reason(code, msg))  # noqa: E731

    size = os.path.getsize(path)
    if size > max_mb * 1024 * 1024:
        reject("too_large", f"The file is {size / 1024 / 1024:.0f} MB; the limit is {max_mb:.0f} MB.")
        return out
    with open(path, "rb") as fh:
        if b"%PDF-" not in fh.read(1024):
            reject("not_pdf", "This file is not a PDF.")
            return out

    try:
        doc = pymupdf.open(path)
    except Exception as e:  # pragma: no cover - exact message depends on the damage
        reject("corrupt", f"The PDF could not be opened ({str(e)[:120]}).")
        return out
    if doc.needs_pass:
        reject("password", "The PDF is password-protected. Upload a copy without a password.")
        return out
    # Opens without a password but carries an owner password (usage restrictions).
    if doc.is_encrypted or (doc.metadata or {}).get("encryption"):
        out["warnings"].append(_reason("restricted", "The PDF has usage restrictions; it can still be processed."))
    out["page_count"] = len(doc)
    if len(doc) == 0:
        reject("no_pages", "The PDF has no pages.")
        return out
    if len(doc) > max_pages:
        reject("too_many_pages", f"The PDF has {len(doc)} pages; the limit is {max_pages}.")
        return out

    threats = scan_pdf_security(path)
    for t in threats.get("threats", []):
        if t["type"] == "javascript":
            reject("javascript", "The PDF contains JavaScript, which is not allowed.")
        elif t["type"] == "launch_action":
            reject("launch_action", "The PDF contains actions that start programs, which are not allowed.")
        elif t["type"] == "corrupt_file":
            reject("corrupt", "The PDF could not be opened.")
        elif t["type"] != "oversized_file":
            reject(t["type"], t.get("detail", "The PDF failed a security check."))
    for w in threats.get("warnings", []):
        if w["type"] == "embedded_files":
            count = len(w.get("files", [])) or 1
            reject("attachments", f"The PDF contains {count} attached file(s). Remove the attachments and upload again.")
        elif w["type"] == "suspicious_uris":
            # javascript:, file:, data:, vbscript: links act like embedded scripts (Req 2.3).
            reject("script_links", "The PDF contains links that run scripts or open local files, which is not allowed.")
    if out["rejections"]:
        return out

    # Title clues (task 10.3, Req 2.7): the PDF's own metadata and the cover's text lines.
    md = doc.metadata or {}
    out["meta"] = {k: (md.get(k) or "").strip() for k in ("title", "author", "subject")}
    out["cover_lines"] = _cover_lines(doc[0])

    crop = detect_crop_marks_document(path)
    consensus = crop.get("consensus_trim_box") if crop.get("has_crop_marks") else None
    per_page = {r["page_number"]: r for r in crop.get("page_results", [])}
    out["crop"] = {"has_crop_marks": bool(crop.get("has_crop_marks")), "consensus_trim_box": consensus,
                   "confidence": crop.get("confidence")}

    for i, page in enumerate(doc):
        kind, chars = _page_kind(page)
        r = per_page.get(i + 1, {})
        if r.get("has_crop_marks") and r.get("recommended_trim"):
            trim, source = [round(v, 2) for v in r["recommended_trim"]], r.get("detection_source")
        elif consensus:
            trim, source = consensus, "book_consensus"
        else:
            trim, source = [0.0, 0.0, round(page.rect.width, 2), round(page.rect.height, 2)], "whole_page"
        out["pages"].append({
            "number": i + 1,
            "width_pt": round(page.rect.width, 2),
            "height_pt": round(page.rect.height, 2),
            "trim_box": trim,
            "trim_source": source,
            "trim_review": bool(r.get("requires_review")),
            "has_live_text": kind == "live_text",
            "kind": kind,
            "text_chars": chars,
        })
    doc.close()
    out["ok"] = True
    return out


def main(argv=None) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--input", required=True)
    ap.add_argument("--max-mb", type=float, default=100)
    ap.add_argument("--max-pages", type=int, default=500)
    a = ap.parse_args(argv)
    try:
        result = preflight(a.input, a.max_mb, a.max_pages)
    except Exception as e:  # fail closed: an unexpected error is a refusal, never a pass
        result = {"ok": False, "rejections": [_reason("check_failed", f"The file could not be checked ({str(e)[:120]}).")],
                  "warnings": [], "page_count": 0, "pages": [], "crop": None}
    print(json.dumps(result))
    return 0 if result["ok"] else 2


if __name__ == "__main__":
    sys.exit(main())
