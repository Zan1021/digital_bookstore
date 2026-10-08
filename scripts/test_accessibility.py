#!/usr/bin/env python3
"""
test_accessibility.py — Phase C4a (R-W10/T18) tagged-PDF accessibility pass.

Meaningful-output tests (R11.2): assert the OUTPUT pdf's catalog actually carries the
/Lang tag after the pass (re-read from disk, not just a return value), that the combined
`pass` fails closed only on a language-write failure, and that a missing structure tree is
a RECOMMENDATION — never a block.

Hermetic: builds its own PyMuPDF documents on disk; no external fixtures, no network.
Run:  python scripts/test_accessibility.py
"""
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import pymupdf  # noqa: E402
from accessibility import (  # noqa: E402
    accessibility_pass,
    set_document_language,
    check_pdf_accessibility,
    LANGUAGE_MAP,
)


def _make_pdf(path, text="Hello world, this is a readable test page."):
    doc = pymupdf.open()
    page = doc.new_page()
    page.insert_text((72, 72), text)
    doc.save(path)
    doc.close()


class AccessibilityPass(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp()
        self.pdf = os.path.join(self.tmp, "book.pdf")
        _make_pdf(self.pdf)

    def _lang_in_catalog(self, path):
        doc = pymupdf.open(path)
        cat = doc.xref_object(doc.pdf_catalog())
        doc.close()
        return "/Lang" in cat

    def test_set_language_stamps_lang_in_place(self):
        """Same-path write must succeed (PyMuPDF 'save to original must be incremental'
        trap handled) and the catalog must actually carry /Lang afterwards."""
        res = set_document_language(self.pdf, self.pdf, "af")
        self.assertTrue(res["success"], res)
        self.assertEqual(res["language_set"], "af-ZA")
        self.assertTrue(self._lang_in_catalog(self.pdf))

    def test_set_language_maps_bcp47(self):
        for code, tag in [("af", "af-ZA"), ("zu", "zu-ZA"), ("en", "en-ZA")]:
            self.assertEqual(LANGUAGE_MAP[code], tag)

    def test_pass_succeeds_and_reports(self):
        result = accessibility_pass(self.pdf, "af")
        self.assertTrue(result["pass"])
        self.assertEqual(result["language_set"], "af-ZA")
        self.assertTrue(result["has_language"])
        self.assertTrue(self._lang_in_catalog(self.pdf))

    def test_missing_structure_is_recommendation_not_block(self):
        """A plain PDF has no structure tree. The pass must still PASS (language stamped),
        surfacing the structure gap as a recommendation — never a fail-closed block."""
        result = accessibility_pass(self.pdf, "af")
        self.assertTrue(result["pass"])
        self.assertFalse(result["has_structure_tree"])
        self.assertTrue(any("structure" in r.lower() for r in result["recommendations"]))

    def test_pass_fails_closed_on_lang_write_failure(self):
        """If the language write cannot happen (unwritable target), the pass reports
        pass=False with reason lang_write_failed — the one true-regression fail-closed."""
        bogus = os.path.join(self.tmp, "nope", "deep", "missing.pdf")
        # input doesn't exist -> set_document_language raises inside -> success False
        result = accessibility_pass(bogus, "af")
        self.assertFalse(result["pass"])
        self.assertEqual(result["reason"], "lang_write_failed")

    def test_alt_text_placeholders_for_images(self):
        """A page with an embedded image yields a reviewable alt-text placeholder."""
        doc = pymupdf.open()
        page = doc.new_page()
        pix = pymupdf.Pixmap(pymupdf.csRGB, pymupdf.IRect(0, 0, 400, 300))
        pix.set_rect(pix.irect, (200, 150, 100))
        img_pdf = os.path.join(self.tmp, "withimg.pdf")
        page.insert_image(pymupdf.Rect(50, 50, 350, 250), pixmap=pix)
        doc.save(img_pdf)
        doc.close()

        result = accessibility_pass(img_pdf, "en")
        self.assertTrue(result["pass"])
        self.assertGreaterEqual(result["images_total"], 1)
        self.assertEqual(result["images_total"], len(result["alt_text_placeholders"]))
        self.assertTrue(all(p["needs_review"] for p in result["alt_text_placeholders"]))


if __name__ == "__main__":
    unittest.main(verbosity=2)
