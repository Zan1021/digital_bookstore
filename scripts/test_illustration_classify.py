#!/usr/bin/env python3
"""
test_illustration_classify.py — Phase-4 deferred item 1 (R5.1): source-based 3-class
content classifier. Meaningful-output: assert the class each region resolves to from the
SOURCE geometry.

Run: python scripts/test_illustration_classify.py
"""
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import pymupdf  # noqa: E402
from illustration_classify import classify  # noqa: E402


def _px(pt, ppi=150):
    return pt * ppi / 72.0


class ClassifyThreeClasses(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp()
        self.pdf = os.path.join(self.tmp, "c.pdf")
        doc = pymupdf.open()
        page = doc.new_page(width=400, height=560)
        # NATIVE: a real text line at ~ (60,100)-(220,120)
        page.insert_text((60, 115), "Native title here", fontsize=16)
        # OUTLINED VECTOR: several small filled path glyph-like shapes around (60,250)
        for i in range(6):
            x = 60 + i * 24
            page.draw_rect(pymupdf.Rect(x, 250, x + 18, 285), fill=(0.1, 0.1, 0.1), color=None)
        # RASTER: an image with no text layer / no vector lettering around (60,400)
        pix = pymupdf.Pixmap(pymupdf.csRGB, pymupdf.IRect(0, 0, 200, 80), False)
        pix.set_rect(pix.irect, (200, 120, 40))
        page.insert_image(pymupdf.Rect(60, 380, 260, 460), pixmap=pix)
        doc.save(self.pdf)
        doc.close()

    def test_native_region(self):
        regions = [{"source_text": "Native title here",
                    "bbox_px": [_px(60), _px(100), _px(220), _px(122)]}]
        res = classify(self.pdf, 0, 150, regions)
        self.assertEqual(res["regions"][0]["content_class"], "native")
        self.assertEqual(res["regions"][0]["classified_by"], "text_layer")

    def test_outlined_vector_region(self):
        regions = [{"source_text": "LOGO",
                    "bbox_px": [_px(55), _px(245), _px(210), _px(290)]}]
        res = classify(self.pdf, 0, 150, regions)
        self.assertEqual(res["regions"][0]["content_class"], "outlined_vector")
        self.assertTrue(res["regions"][0]["classified_by"].startswith("vector_paths:"))

    def test_raster_region(self):
        regions = [{"source_text": "SIGN",
                    "bbox_px": [_px(90), _px(400), _px(230), _px(440)]}]
        res = classify(self.pdf, 0, 150, regions)
        self.assertEqual(res["regions"][0]["content_class"], "raster_text")

    def test_missing_bbox_defaults_raster(self):
        res = classify(self.pdf, 0, 150, [{"source_text": "x"}])
        self.assertEqual(res["regions"][0]["content_class"], "raster_text")
        self.assertEqual(res["regions"][0]["classified_by"], "no_bbox")

    def test_hidden_ocr_layer_not_treated_as_removable(self):
        """A page with a native text line AND baked raster text: the raster region must NOT
        be classed 'native' just because the page has some text layer elsewhere (the brief's
        hidden-OCR trap). The raster region (no overlapping text line) stays raster_text."""
        regions = [{"source_text": "SIGN", "bbox_px": [_px(90), _px(400), _px(230), _px(440)]}]
        res = classify(self.pdf, 0, 150, regions)
        self.assertNotEqual(res["regions"][0]["content_class"], "native")


if __name__ == "__main__":
    unittest.main(verbosity=2)
