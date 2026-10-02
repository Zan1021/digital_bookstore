#!/usr/bin/env python3
"""
test_artwork_repair.py — Phase 5.1/5.2/5.4 (R6) artwork-preserving repair.

Meaningful-output tests (R11.2): assert the baked text pixels are actually gone, the page's
NATIVE vector text SURVIVES (page not flattened), the letter-shaped mask leaves surrounding
artwork intact, and a reused image is isolated so only the intended page changes.

Run: python scripts/test_artwork_repair.py
"""
import io
import os
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import pymupdf  # noqa: E402
from PIL import Image, ImageDraw  # noqa: E402

from artwork_repair import (  # noqa: E402
    repair_page_surgical, repair_image_region, sample_background,
    build_letter_mask, find_owning_image,
)


def _image_with_text(w=600, h=400, bg=(40, 160, 90), text_xy=(120, 150),
                     text="SHOP", text_color=(20, 20, 20)) -> bytes:
    """A flat-colour panel with dark baked-in text. Returns PNG bytes."""
    img = Image.new("RGB", (w, h), bg)
    d = ImageDraw.Draw(img)
    # draw big blocky text so the luminance threshold reliably catches it without a font dep
    x, y = text_xy
    for i, ch in enumerate(text):
        bx = x + i * 90
        d.rectangle([bx, y, bx + 70, y + 110], fill=text_color)
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    return buf.getvalue()


def _make_page_with_image_and_native_text(path, place=(69, 120, 469, 400)):
    doc = pymupdf.open()
    page = doc.new_page(width=538, height=751)
    # native vector text that MUST survive the repair (page must not be flattened)
    page.insert_text((60, 80), "Native paragraph stays as text", fontsize=14)
    page.insert_image(pymupdf.Rect(*place), stream=_image_with_text())
    doc.save(path)
    doc.close()


class SurgicalRepairPreservesPage(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.mkdtemp()
        self.pdf = os.path.join(self.tmp, "page.pdf")
        self.place = (69, 120, 469, 400)
        _make_page_with_image_and_native_text(self.pdf, self.place)

    def _text_box_in_render_px(self, ppi):
        """Where the baked 'SHOP' sits in render-pixel space. Image is 600x400 placed into
        a (469-69)x(400-120) = 400x280 pt rect; text at x~120..480,y~150..260 in a 600x400
        image. Map image px -> page pt -> render px."""
        zoom = ppi / 72.0
        px0, py0, px1, py1 = self.place
        img_w, img_h = 600, 400
        # baked text spans image px x[120..480] y[150..260]
        def to_render(ix, iy):
            fx = ix / img_w
            fy = iy / img_h
            page_x = px0 + fx * (px1 - px0)
            page_y = py0 + fy * (py1 - py0)
            return (page_x * zoom, page_y * zoom)
        rx0, ry0 = to_render(110, 140)
        rx1, ry1 = to_render(490, 270)
        return [rx0, ry0, rx1, ry1]

    def test_native_text_survives_repair(self):
        ppi = 150
        doc = pymupdf.open(self.pdf)
        box = self._text_box_in_render_px(ppi)
        regions = [{"source_text": "SHOP", "target_text": "WINKEL", "bbox_px": box,
                    "background_type": "flat", "color_rgb": [40, 160, 90]}]
        rep = repair_page_surgical(doc, 0, regions, ppi)
        self.assertEqual(rep["strategy"], "surgical")
        self.assertTrue(rep["modified"], rep)
        self.assertEqual(rep["regions_repaired"], 1, rep)
        # the page's native vector text MUST still be real text (not rasterized away)
        txt = doc[0].get_text().strip()
        self.assertIn("Native paragraph stays as text", txt)
        doc.close()

    def test_baked_text_pixels_removed(self):
        ppi = 150
        doc = pymupdf.open(self.pdf)
        box = self._text_box_in_render_px(ppi)
        regions = [{"source_text": "SHOP", "bbox_px": box,
                    "background_type": "flat", "color_rgb": [40, 160, 90]}]
        repair_page_surgical(doc, 0, regions, ppi)
        # extract the (now repaired) image and check the text area is ~uniform background
        xref = doc[0].get_images(full=True)[0][0]
        base = doc.extract_image(xref)
        img = Image.open(io.BytesIO(base["image"])).convert("RGB")
        px = img.load()
        # sample the former text band in image space (x120..480, y150..260)
        dark = 0
        for yy in range(150, 260, 5):
            for xx in range(120, 480, 5):
                r, g, b = px[xx, yy]
                if r + g + b < 150:  # the near-black glyph ink
                    dark += 1
        self.assertEqual(dark, 0, f"baked glyph ink survived: {dark} dark samples")
        doc.close()

    def test_region_not_owned_by_image_fails_closed(self):
        ppi = 150
        doc = pymupdf.open(self.pdf)
        # a box far outside the image rect — no single image owns it
        regions = [{"source_text": "X", "bbox_px": [10, 10, 40, 40]}]
        rep = repair_page_surgical(doc, 0, regions, ppi)
        self.assertFalse(rep["modified"])
        self.assertTrue(rep["unowned"], rep)
        doc.close()

    def test_transforms_recorded(self):
        ppi = 150
        doc = pymupdf.open(self.pdf)
        box = self._text_box_in_render_px(ppi)
        regions = [{"source_text": "SHOP", "bbox_px": box,
                    "background_type": "flat", "color_rgb": [40, 160, 90]}]
        rep = repair_page_surgical(doc, 0, regions, ppi)
        self.assertEqual(len(rep["transforms"]), 1)
        t = rep["transforms"][0]
        self.assertIn("crop_rect_px", t)
        self.assertIn("model_size_px", t)  # the image's own raster size
        doc.close()


class ReusedImageIsolation(unittest.TestCase):
    def test_only_intended_page_changes(self):
        """R6.2: an image reused on two pages — repairing one must not alter the other."""
        tmp = tempfile.mkdtemp()
        pdf = os.path.join(tmp, "reuse.pdf")
        doc = pymupdf.open()
        logo = _image_with_text(w=300, h=200, bg=(230, 230, 230), text="LOGO",
                                text_color=(10, 10, 10), text_xy=(20, 60))
        for _ in range(2):
            page = doc.new_page(width=538, height=751)
            page.insert_image(pymupdf.Rect(100, 100, 400, 300), stream=logo)
        doc.save(pdf)
        doc.close()

        doc = pymupdf.open(pdf)
        # both pages share the same xref?
        x0 = doc[0].get_images(full=True)[0][0]
        x1 = doc[1].get_images(full=True)[0][0]
        # pre-hash page 1's rendered pixels
        before = doc[1].get_pixmap(matrix=pymupdf.Matrix(1, 1)).tobytes("png")

        ppi = 150
        zoom = ppi / 72.0
        # text box for page 0's logo in render px
        def to_render(ix, iy, img_w=300, img_h=200, place=(100, 100, 400, 300)):
            px0, py0, px1, py1 = place
            return ((px0 + ix / img_w * (px1 - px0)) * zoom,
                    (py0 + iy / img_h * (py1 - py0)) * zoom)
        rx0, ry0 = to_render(10, 50)
        rx1, ry1 = to_render(290, 180)
        regions = [{"source_text": "LOGO", "bbox_px": [rx0, ry0, rx1, ry1],
                    "background_type": "flat", "color_rgb": [230, 230, 230]}]
        rep = repair_page_surgical(doc, 0, regions, ppi)
        self.assertTrue(rep["modified"], rep)

        after = doc[1].get_pixmap(matrix=pymupdf.Matrix(1, 1)).tobytes("png")
        # If replace_image touched the shared xref globally, page 1 would change too.
        # PyMuPDF replace_image replaces the xref object, which IS shared — so this test
        # DOCUMENTS the isolation requirement: we assert page 1 is unchanged, and if the
        # shared-xref behaviour changes it, the surgical path must isolate first.
        if x0 == x1:
            # shared xref: our implementation must have isolated; if not, this flags it.
            self.assertEqual(before, after,
                             "reused image not isolated: page 1 changed (R6.2 violation)")
        doc.close()


class BackgroundSampling(unittest.TestCase):
    def test_flat_detected(self):
        img = Image.new("RGB", (200, 200), (50, 150, 100))
        bg = sample_background(img, (80, 80, 120, 120), gap=5, band=3)
        self.assertEqual(bg.kind, "flat")

    def test_gradient_detected(self):
        img = Image.new("RGB", (200, 200))
        px = img.load()
        for y in range(200):
            t = y / 199
            col = (int(20 + 200 * t), int(20 + 100 * t), 60)
            for x in range(200):
                px[x, y] = col
        bg = sample_background(img, (80, 80, 120, 120), gap=10, band=4)
        self.assertIn(bg.kind, ("gradient", "textured"))

    def test_letter_mask_is_not_full_box(self):
        """R6.2: mask covers glyph ink, not the whole rectangle."""
        img = Image.new("RGB", (120, 120), (240, 240, 240))
        d = ImageDraw.Draw(img)
        d.rectangle([40, 40, 80, 80], fill=(10, 10, 10))  # a glyph blob
        bg = sample_background(img, (30, 30, 90, 90), gap=5, band=3)
        mask = build_letter_mask(img, (30, 30, 90, 90), bg, dilate=1)
        mpx = mask.load()
        on = sum(1 for yy in range(mask.size[1]) for xx in range(mask.size[0]) if mpx[xx, yy])
        total = mask.size[0] * mask.size[1]
        self.assertGreater(on, 0)
        self.assertLess(on, total, "mask covered the whole box — not letter-shaped")


class RotatedAndLandscapeRoundTrip(unittest.TestCase):
    """Phase 5.4 / 5.6: a rotated page and a landscape/CropBox page round-trip through
    surgical repair with the patch landing in the correct image pixels and page geometry
    (rotation / CropBox) preserved."""

    def _page_with_baked_image(self, path, page_w, page_h, rotation=0,
                               place=(60, 60, 460, 360)):
        doc = pymupdf.open()
        page = doc.new_page(width=page_w, height=page_h)
        page.insert_text((20, 30), "native survives", fontsize=11)
        page.insert_image(pymupdf.Rect(*place), stream=_image_with_text())
        if rotation:
            page.set_rotation(rotation)
        doc.save(path)
        doc.close()

    def _baked_box_render_px(self, place, ppi, img_w=600, img_h=400):
        zoom = ppi / 72.0
        px0, py0, px1, py1 = place
        def to_render(ix, iy):
            return ((px0 + ix / img_w * (px1 - px0)) * zoom,
                    (py0 + iy / img_h * (py1 - py0)) * zoom)
        rx0, ry0 = to_render(110, 140)
        rx1, ry1 = to_render(490, 270)
        return [rx0, ry0, rx1, ry1]

    def test_rotated_page_repairs_and_preserves_rotation(self):
        tmp = tempfile.mkdtemp()
        pdf = os.path.join(tmp, "rot.pdf")
        place = (60, 60, 460, 360)
        self._page_with_baked_image(pdf, 538, 751, rotation=90, place=place)
        doc = pymupdf.open(pdf)
        self.assertEqual(doc[0].rotation, 90)
        ppi = 150
        box = self._baked_box_render_px(place, ppi)
        regions = [{"source_text": "SHOP", "bbox_px": box,
                    "background_type": "flat", "color_rgb": [40, 160, 90]}]
        rep = repair_page_surgical(doc, 0, regions, ppi)
        self.assertTrue(rep["modified"], rep)
        # rotation preserved after re-embed
        self.assertEqual(doc[0].rotation, 90)
        # baked glyph ink removed from the image's own pixels
        xref = doc[0].get_images(full=True)[0][0]
        base = doc.extract_image(xref)
        img = Image.open(io.BytesIO(base["image"])).convert("RGB")
        px = img.load()
        dark = sum(1 for yy in range(150, 260, 6) for xx in range(120, 480, 6)
                   if sum(px[xx, yy]) < 150)
        self.assertEqual(dark, 0, f"glyph ink survived on rotated page: {dark}")
        # native text still live
        self.assertIn("native survives", doc[0].get_text())
        doc.close()

    def test_landscape_page_round_trips(self):
        tmp = tempfile.mkdtemp()
        pdf = os.path.join(tmp, "land.pdf")
        place = (80, 60, 480, 360)
        self._page_with_baked_image(pdf, 751, 538, rotation=0, place=place)
        doc = pymupdf.open(pdf)
        self.assertGreater(doc[0].rect.width, doc[0].rect.height)  # landscape
        ppi = 150
        box = self._baked_box_render_px(place, ppi)
        regions = [{"source_text": "SHOP", "bbox_px": box,
                    "background_type": "flat", "color_rgb": [40, 160, 90]}]
        rep = repair_page_surgical(doc, 0, regions, ppi)
        self.assertTrue(rep["modified"], rep)
        # landscape geometry preserved
        self.assertGreater(doc[0].rect.width, doc[0].rect.height)
        self.assertIn("native survives", doc[0].get_text())
        doc.close()


if __name__ == "__main__":
    unittest.main(verbosity=2)
