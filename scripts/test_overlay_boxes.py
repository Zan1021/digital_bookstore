#!/usr/bin/env python3
"""
test_overlay_boxes.py — Phase 7.1 (R10.1): overlay-data emits the richer box set
(layoutContainer / targetGlyphBounds / eraseMask / protectedArtwork / contentClass) so the
admin overlay can toggle each independently.

Run: python scripts/test_overlay_boxes.py
"""
import json
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import pymupdf  # noqa: E402
import pdf_translate_v8 as eng  # noqa: E402


class OverlayBoxes(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp()
        self.pdf = os.path.join(self.tmp, "r.pdf")
        doc = pymupdf.open()
        page = doc.new_page(width=400, height=560)
        page.insert_text((60, 100), "Hello", fontsize=14)
        doc.save(self.pdf)
        doc.close()

        # a manifest carrying BOTH legacy and the new box fields for page 1
        self.manifest = os.path.join(self.tmp, "m.json")
        with open(self.manifest, "w", encoding="utf-8") as f:
            json.dump({"pages": [{
                "page": 1, "page_type": "story", "status": "OK", "structureOk": True,
                "regions": [{
                    "regionId": "p01_s0001", "semanticType": "paragraph",
                    "sourceBounds": [60, 90, 200, 110],
                    "safeInnerBounds": [58, 88, 210, 150],
                    "renderedGlyphBounds": [60, 90, 180, 108],
                    "layoutContainer": [55, 85, 215, 160],
                    "eraseMask": [61, 91, 179, 107],
                    "protectedArtwork": [220, 300, 380, 460],
                    "contentClass": "native",
                }],
            }]}, f)

    def test_new_boxes_present_and_in_pixels(self):
        img = os.path.join(self.tmp, "p1.png")
        data = eng.build_overlay_data(self.pdf, 1, self.manifest, img, dpi=144)  # scale 2.0
        r = data["regions"][0]
        for k in ("layoutContainer", "targetGlyphBounds", "eraseMask",
                  "protectedArtwork", "contentClass"):
            self.assertIn(k, r)
        # layoutContainer [55,85,215,160] * 2.0 => [110,170,430,320]
        self.assertAlmostEqual(r["layoutContainer"][0], 110, delta=1)
        self.assertAlmostEqual(r["layoutContainer"][2], 430, delta=1)
        self.assertEqual(r["contentClass"], "native")
        self.assertIsNotNone(r["eraseMask"])
        self.assertIsNotNone(r["protectedArtwork"])

    def test_container_defaults_to_safe_inner_when_absent(self):
        # manifest WITHOUT layoutContainer => falls back to safeInnerBounds
        m2 = os.path.join(self.tmp, "m2.json")
        with open(m2, "w", encoding="utf-8") as f:
            json.dump({"pages": [{"page": 1, "page_type": "story", "status": "OK",
                "regions": [{"regionId": "x", "semanticType": "paragraph",
                             "safeInnerBounds": [10, 10, 100, 50]}]}]}, f)
        img = os.path.join(self.tmp, "p2.png")
        data = eng.build_overlay_data(self.pdf, 1, m2, img, dpi=72)  # scale 1.0
        r = data["regions"][0]
        self.assertEqual(r["layoutContainer"], r["safeInnerBounds"])

    def test_absent_mask_and_artwork_are_null(self):
        m3 = os.path.join(self.tmp, "m3.json")
        with open(m3, "w", encoding="utf-8") as f:
            json.dump({"pages": [{"page": 1, "page_type": "story", "status": "OK",
                "regions": [{"regionId": "x", "semanticType": "paragraph",
                             "sourceBounds": [10, 10, 100, 50]}]}]}, f)
        img = os.path.join(self.tmp, "p3.png")
        data = eng.build_overlay_data(self.pdf, 1, m3, img, dpi=72)
        r = data["regions"][0]
        self.assertIsNone(r["eraseMask"])
        self.assertIsNone(r["protectedArtwork"])


if __name__ == "__main__":
    unittest.main(verbosity=2)
