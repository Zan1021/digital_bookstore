#!/usr/bin/env python3
"""
test_crop_transform.py — Phase 5.3 (R7) coordinate-transform contract.

Meaningful-output tests (R11.2): we assert the ACTUAL mapped coordinates and that
degenerate transforms fail closed — not merely that a helper was called.

Run: python scripts/test_crop_transform.py
"""
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import pymupdf  # noqa: E402
from crop_transform import CropTransform, LayoutReviewRequired  # noqa: E402


def _make_pdf_with_image(path, page_w=538, page_h=751, img_w=400, img_h=300,
                         place=(69, 100, 469, 400), rotation=0):
    """Build a 1-page PDF with a single embedded raster placed at `place` (PDF points)."""
    doc = pymupdf.open()
    page = doc.new_page(width=page_w, height=page_h)
    # a small opaque raster
    pix = pymupdf.Pixmap(pymupdf.csRGB, pymupdf.IRect(0, 0, img_w, img_h), False)
    pix.set_rect(pix.irect, (200, 120, 40))
    page.insert_image(pymupdf.Rect(*place), pixmap=pix)
    if rotation:
        page.set_rotation(rotation)
    doc.save(path)
    doc.close()


class CropTransformChain(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp()
        self.pdf = os.path.join(self.tmp, "img.pdf")
        self.place = (69, 100, 469, 400)  # 400pt x 300pt on the page
        _make_pdf_with_image(self.pdf, place=self.place)
        self.doc = pymupdf.open(self.pdf)
        self.page = self.doc[0]
        self.xref = self.page.get_images(full=True)[0][0]

    def tearDown(self):
        self.doc.close()

    def test_crop_box_matches_placed_rect_scaled_by_ppi(self):
        ppi = 144  # zoom = 2.0
        ct = CropTransform.for_image_instance(self.page, self.xref, ppi=ppi)
        x0, y0, x1, y1 = ct.crop_rect_px
        # placed 69..469 x, 100..400 y in points, *2.0 => 138..938 x, 200..800 y
        self.assertAlmostEqual(x0, 138, delta=2)
        self.assertAlmostEqual(y0, 200, delta=2)
        self.assertAlmostEqual(x1, 938, delta=2)
        self.assertAlmostEqual(y1, 800, delta=2)
        self.assertEqual(ct.crop_size_px, (x1 - x0, y1 - y0))

    def test_render_px_to_crop_px_is_origin_shifted(self):
        ct = CropTransform.for_image_instance(self.page, self.xref, ppi=72)
        cx0, cy0, _, _ = ct.crop_rect_px
        # a render point at the crop's top-left maps to (0,0) in crop space
        lx, ly = ct.render_px_to_crop_px(cx0, cy0)
        self.assertAlmostEqual(lx, 0.0, delta=0.5)
        self.assertAlmostEqual(ly, 0.0, delta=0.5)

    def test_model_actual_size_drives_mapping_not_requested(self):
        """R7.2: a model that returns 512x512 for a larger crop must scale accordingly."""
        ct = CropTransform.for_image_instance(self.page, self.xref, ppi=72)
        cw, ch = ct.crop_size_px  # ~400x300 at 72ppi
        ct.set_model_size(512, 512)
        # crop centre maps into model space proportionally
        mx, my = ct.crop_px_to_model_px(cw / 2, ch / 2)
        self.assertAlmostEqual(mx, 256, delta=1)
        self.assertAlmostEqual(my, 256, delta=1)
        # and the inverse round-trips back to the crop centre (R7.3 resize-before-composite)
        bx, by = ct.model_px_to_crop_px(mx, my)
        self.assertAlmostEqual(bx, cw / 2, delta=0.5)
        self.assertAlmostEqual(by, ch / 2, delta=0.5)

    def test_model_scale_resizes_patch_back_to_source_crop(self):
        ct = CropTransform.for_image_instance(self.page, self.xref, ppi=72)
        cw, ch = ct.crop_size_px
        ct.set_model_size(1024, 768)
        sx, sy = ct.model_scale()
        self.assertAlmostEqual(sx, cw / 1024, delta=1e-6)
        self.assertAlmostEqual(sy, ch / 768, delta=1e-6)

    def test_source_pt_render_px_roundtrip(self):
        ct = CropTransform.for_image_instance(self.page, self.xref, ppi=216)  # zoom 3
        rx, ry = ct.source_pt_to_render_px(100, 50)
        self.assertAlmostEqual(rx, 300, delta=0.01)
        self.assertAlmostEqual(ry, 150, delta=0.01)
        bx, by = ct.render_px_to_source_pt(rx, ry)
        self.assertAlmostEqual(bx, 100, delta=0.01)
        self.assertAlmostEqual(by, 50, delta=0.01)

    def test_to_dict_is_serializable_and_complete(self):
        ct = CropTransform.for_image_instance(self.page, self.xref, ppi=150)
        ct.set_model_size(640, 480)
        d = ct.to_dict()
        for k in ("page_index", "page_rotation", "ppi", "render_size_px",
                  "source_rect_pt", "crop_rect_px", "crop_size_px", "model_size_px"):
            self.assertIn(k, d)
        import json
        json.dumps(d)  # must not raise


class CropTransformFailClosed(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp()
        self.pdf = os.path.join(self.tmp, "img.pdf")
        _make_pdf_with_image(self.pdf)
        self.doc = pymupdf.open(self.pdf)
        self.page = self.doc[0]
        self.xref = self.page.get_images(full=True)[0][0]

    def tearDown(self):
        self.doc.close()

    def test_degenerate_crop_raises(self):
        with self.assertRaises(LayoutReviewRequired) as cm:
            CropTransform(
                page_index=0, page_rotation=0, ppi=72,
                render_size_px=(538, 751),
                source_rect_pt=(10, 10, 100, 100),
                crop_rect_px=(50, 50, 50, 120),  # zero width
            )
        self.assertEqual(cm.exception.reason, "DEGENERATE_TRANSFORM")

    def test_degenerate_source_rect_raises(self):
        with self.assertRaises(LayoutReviewRequired):
            CropTransform(
                page_index=0, page_rotation=0, ppi=72,
                render_size_px=(538, 751),
                source_rect_pt=(10, 10, 10, 100),  # zero width in points
                crop_rect_px=(10, 10, 100, 100),
            )

    def test_zero_model_size_raises(self):
        ct = CropTransform.for_image_instance(self.page, self.xref, ppi=72)
        with self.assertRaises(LayoutReviewRequired):
            ct.set_model_size(0, 400)

    def test_missing_image_instance_raises(self):
        with self.assertRaises(LayoutReviewRequired) as cm:
            CropTransform.for_image_instance(self.page, self.xref, ppi=72, instance=5)
        self.assertEqual(cm.exception.reason, "IMAGE_INSTANCE_OUT_OF_RANGE")

    def test_unknown_xref_raises(self):
        with self.assertRaises(LayoutReviewRequired) as cm:
            CropTransform.for_image_instance(self.page, 99999, ppi=72)
        self.assertEqual(cm.exception.reason, "IMAGE_XREF_INVALID")


class CropTransformRotation(unittest.TestCase):
    def test_rotation_is_recorded_for_roundtrip(self):
        tmp = tempfile.mkdtemp()
        pdf = os.path.join(tmp, "rot.pdf")
        _make_pdf_with_image(pdf, rotation=90)
        doc = pymupdf.open(pdf)
        page = doc[0]
        xref = page.get_images(full=True)[0][0]
        ct = CropTransform.for_image_instance(page, xref, ppi=72)
        self.assertEqual(ct.page_rotation, 90)
        self.assertTrue(ct.is_rotated())
        doc.close()


if __name__ == "__main__":
    unittest.main(verbosity=2)
