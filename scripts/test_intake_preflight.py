#!/usr/bin/env python3
"""Tests for intake_preflight.py (task 10.2): refusals with plain reasons, per-page facts."""

import json
import os
import subprocess
import sys
import tempfile

import pymupdf

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from intake_preflight import preflight  # noqa: E402

failures = []


def check(cond, msg):
    if not cond:
        failures.append(msg)


def text_page(doc, text="Kolulu lives in a world of imagination."):
    page = doc.new_page(width=420, height=595)
    page.insert_text((40, 300), text, fontsize=14)
    return page


def codes(result):
    return [r["code"] for r in result["rejections"]]


def main():
    with tempfile.TemporaryDirectory() as d:
        p = lambda name: os.path.join(d, name)  # noqa: E731

        # A normal book: live-text page, scanned page, blank page, page with a TrimBox.
        doc = pymupdf.open()
        text_page(doc)
        scan = doc.new_page(width=420, height=595)
        pix = pymupdf.Pixmap(pymupdf.csRGB, pymupdf.IRect(0, 0, 100, 140), False)
        pix.set_rect(pix.irect, (200, 190, 170))
        scan.insert_image(scan.rect, pixmap=pix)
        doc.new_page(width=420, height=595)
        trimmed = text_page(doc, "A page with crop marks.")
        trimmed.set_trimbox(pymupdf.Rect(20, 20, 400, 575))
        doc.save(p("book.pdf"))

        r = preflight(p("book.pdf"))
        check(r["ok"] and r["rejections"] == [], f"normal book refused: {r['rejections']}")
        check(r["page_count"] == 4, f"page count {r['page_count']}")
        check([pg["kind"] for pg in r["pages"]] == ["live_text", "scanned", "no_text", "live_text"],
              f"page kinds {[pg['kind'] for pg in r['pages']]}")
        check([pg["has_live_text"] for pg in r["pages"]] == [True, False, False, True], "has_live_text per page")
        check(r["pages"][0]["width_pt"] == 420 and r["pages"][0]["height_pt"] == 595, "page size")
        check(r["pages"][3]["trim_box"] == [20, 20, 400, 575] and r["pages"][3]["trim_source"] == "trim_box_metadata",
              f"trim box from metadata: {r['pages'][3]['trim_box']} {r['pages'][3]['trim_source']}")
        check(r["pages"][0]["trim_box"] == [0, 0, 420, 595] and r["pages"][0]["trim_source"] == "whole_page",
              f"no marks -> whole page: {r['pages'][0]['trim_box']} {r['pages'][0]['trim_source']}")

        # Not a PDF.
        with open(p("notes.pdf"), "wb") as fh:
            fh.write(b"just some text pretending to be a pdf")
        check(codes(preflight(p("notes.pdf"))) == ["not_pdf"], "fake pdf not refused as not_pdf")

        # Password-protected.
        doc = pymupdf.open(); text_page(doc)
        doc.save(p("locked.pdf"), encryption=pymupdf.PDF_ENCRYPT_AES_256, user_pw="secret", owner_pw="owner")
        r = preflight(p("locked.pdf"))
        check(codes(r) == ["password"] and "password" in r["rejections"][0]["message"], f"locked: {r['rejections']}")

        # Usage restrictions only (owner password, opens without a password): processed with a warning.
        doc = pymupdf.open(); text_page(doc)
        doc.save(p("restricted.pdf"), encryption=pymupdf.PDF_ENCRYPT_AES_256, owner_pw="owner",
                 permissions=pymupdf.PDF_PERM_PRINT)
        r = preflight(p("restricted.pdf"))
        check(r["ok"] and [w["code"] for w in r["warnings"]] == ["restricted"], f"restricted: {r}")

        # JavaScript opening action.
        doc = pymupdf.open(); text_page(doc)
        doc.xref_set_key(doc.pdf_catalog(), "OpenAction", "<</S/JavaScript/JS(app.alert(1))>>")
        doc.save(p("script.pdf"))
        check("javascript" in codes(preflight(p("script.pdf"))), "JavaScript not refused")

        # Program-launching action.
        doc = pymupdf.open(); text_page(doc)
        doc.xref_set_key(doc.pdf_catalog(), "OpenAction", "<</S/Launch/F(calc.exe)>>")
        doc.save(p("launch.pdf"))
        check("launch_action" in codes(preflight(p("launch.pdf"))), "launch action not refused")

        # Attached file.
        doc = pymupdf.open(); text_page(doc)
        doc.embfile_add("payload.exe", b"MZ fake", filename="payload.exe")
        doc.save(p("attached.pdf"))
        r = preflight(p("attached.pdf"))
        check("attachments" in codes(r) and "1 attached file" in r["rejections"][0]["message"], f"attachment: {r['rejections']}")

        # A link that runs a script.
        doc = pymupdf.open(); page = text_page(doc)
        page.insert_link({"kind": pymupdf.LINK_URI, "from": pymupdf.Rect(40, 280, 200, 310), "uri": "javascript:alert(1)"})
        doc.save(p("jslink.pdf"))
        check("script_links" in codes(preflight(p("jslink.pdf"))), "javascript: link not refused")

        # Limits.
        check(codes(preflight(p("book.pdf"), max_pages=3)) == ["too_many_pages"], "page limit")
        check(codes(preflight(p("book.pdf"), max_mb=0.0001)) == ["too_large"], "size limit")

        # CLI: JSON out, exit 0 for ok and 2 for refused.
        ok = subprocess.run([sys.executable, os.path.join(HERE, "intake_preflight.py"), "--input", p("book.pdf")],
                            capture_output=True, text=True)
        bad = subprocess.run([sys.executable, os.path.join(HERE, "intake_preflight.py"), "--input", p("notes.pdf")],
                             capture_output=True, text=True)
        check(ok.returncode == 0 and json.loads(ok.stdout)["ok"] is True, f"CLI ok: {ok.returncode} {ok.stderr[:200]}")
        check(bad.returncode == 2 and json.loads(bad.stdout)["rejections"][0]["code"] == "not_pdf", "CLI refused")

    for f in failures:
        print("FAIL:", f)
    print("intake_preflight:", "FAILED" if failures else "OK")
    return 1 if failures else 0


if __name__ == "__main__":
    sys.exit(main())
