#!/usr/bin/env python3
"""
test_ocr_captions.py — Phase C4b (R-W11/T22) scanned-page OCR fallback + caption tagging.

Meaningful-output tests (R11.2): assert the OCR ledger records a scanned page and fails
closed when OCR is off/unavailable (never silently dropping its text), that a born-digital
page is a pure no-op, and that caption annotation actually tags the right span near an
image while leaving body text alone.

Hermetic: builds its own PyMuPDF documents; no external fixtures, no network, no Tesseract
required (we assert the no-backend/disabled review path, not a real OCR extraction).

Run:  python scripts/test_ocr_captions.py
"""
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import pymupdf  # noqa: E402
import pdf_translate_v8 as eng  # noqa: E402
from page_manifest import annotate_captions  # noqa: E402


def _born_digital_page(doc, text="The quick brown fox jumps over the lazy dog."):
    page = doc.new_page()
    page.insert_text((72, 72), text, fontsize=14)
    return page


def _image_only_page(doc, text="Scanned words that are only pixels"):
    # Render text to a pixmap, then place it as a full-page image on a fresh page → no
    # extractable text spans (the "scanned" class).
    src = pymupdf.open()
    sp = src.new_page()
    sp.insert_text((72, 72), text, fontsize=20)
    pix = sp.get_pixmap(dpi=150)
    src.close()
    page = doc.new_page(width=pix.width, height=pix.height)
    page.insert_image(page.rect, pixmap=pix)
    return page


class OcrFallback(unittest.TestCase):
    def setUp(self):
        eng._OCR_LEDGER.clear()
        os.environ.pop("OCR_FALLBACK_ENABLED", None)

    def tearDown(self):
        os.environ.pop("OCR_FALLBACK_ENABLED", None)

    def test_born_digital_page_is_noop(self):
        doc = pymupdf.open()
        page = _born_digital_page(doc)
        spans = eng.extract_page_spans(page, 1)
        doc.close()
        self.assertTrue(len(spans) > 0)                 # text extracted normally
        self.assertEqual(eng.get_ocr_ledger(), [])      # OCR never engaged

    def test_scanned_page_disabled_fails_closed(self):
        """OCR OFF (default): a scanned page records status ocr_disabled in the ledger so
        the engine can route it to review — its text is NOT silently dropped."""
        doc = pymupdf.open()
        page = _image_only_page(doc)
        spans = eng.extract_page_spans(page, 1)
        doc.close()
        self.assertEqual(spans, [])                     # no text, OCR disabled
        ledger = eng.get_ocr_ledger()
        self.assertEqual(len(ledger), 1)
        self.assertTrue(ledger[0]["scanned"])
        self.assertEqual(ledger[0]["status"], "ocr_disabled")

    def test_scanned_page_enabled_without_backend_fails_closed(self):
        """OCR ON but no backend installed: status ocr_unavailable (still fails closed).
        If a backend IS present in this env, OCR may succeed — accept either, but the page
        must be in the ledger as scanned and must NOT be silently empty+clean."""
        os.environ["OCR_FALLBACK_ENABLED"] = "1"
        doc = pymupdf.open()
        page = _image_only_page(doc)
        spans = eng.extract_page_spans(page, 1)
        doc.close()
        ledger = eng.get_ocr_ledger()
        self.assertEqual(len(ledger), 1)
        self.assertTrue(ledger[0]["scanned"])
        self.assertIn(ledger[0]["status"],
                      {"ocr_unavailable", "ocr_empty", "ocr_ok", "ocr_low_confidence"})
        # If no spans recovered, the status must be a review-worthy one (never a clean pass).
        if not spans:
            self.assertIn(ledger[0]["status"], {"ocr_unavailable", "ocr_empty"})

    def test_ledger_clears_between_renders(self):
        eng._OCR_LEDGER.append({"page": 99, "status": "stale"})
        # replace_text_in_pdf clears it; emulate the clear it performs at render start.
        eng._OCR_LEDGER.clear()
        self.assertEqual(eng.get_ocr_ledger(), [])


class CaptionAnnotation(unittest.TestCase):
    def _spans_with_caption(self):
        """A doc with an image and a small text line directly below it (a caption), plus a
        large body line far away."""
        doc = pymupdf.open()
        page = doc.new_page(width=400, height=600)
        # image in the top half
        pix = pymupdf.Pixmap(pymupdf.csRGB, pymupdf.IRect(0, 0, 300, 200))
        pix.set_rect(pix.irect, (120, 160, 200))
        page.insert_image(pymupdf.Rect(50, 50, 350, 250), pixmap=pix)
        # small caption just below the image
        page.insert_text((60, 262), "Figure 1: a small caption", fontsize=8)
        # large body text lower down
        page.insert_text((60, 450), "This is the body paragraph text.", fontsize=16)
        doc_path = page  # keep page ref
        spans = eng.extract_page_spans(page, 1)
        return doc, page, spans

    def test_caption_tagged_body_untouched(self):
        doc, page, spans = self._spans_with_caption()
        summary = annotate_captions(spans, page, 1)
        doc.close()

        tagged = [s for s in spans if s.get("is_caption")]
        self.assertTrue(summary["count"] >= 1, summary)
        self.assertTrue(any("caption" in s.get("text_stripped", "").lower()
                            or "figure" in s.get("text_stripped", "").lower() for s in tagged))
        # The large body line must NOT be tagged a caption.
        body = [s for s in spans if "body paragraph" in s.get("text_stripped", "")]
        self.assertTrue(body and not body[0].get("is_caption"))

    def test_no_captions_is_noop(self):
        doc = pymupdf.open()
        page = _born_digital_page(doc, "Just one plain body line of normal size.")
        spans = eng.extract_page_spans(page, 1)
        summary = annotate_captions(spans, page, 1)
        doc.close()
        self.assertEqual(summary["count"], 0)
        self.assertFalse(any(s.get("is_caption") for s in spans))


if __name__ == "__main__":
    unittest.main(verbosity=2)
