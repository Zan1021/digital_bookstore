"""
Unit tests — Illustration-Text Vision Module (deterministic half).
No network. Validates coordinate handling, the uniformity/fail-closed gate, mask
rounding, and end-to-end repair on a synthetic PDF.

Usage: python test_illustration_text.py
"""

import json
import os
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import pymupdf
import illustration_text as ilt
import illustration_measure as ilm

_run = 0
_passed = 0


def check(name, cond):
    global _run, _passed
    _run += 1
    if cond:
        _passed += 1
        print(f"  [PASS] {name}")
    else:
        print(f"  [FAIL] {name}")


def _make_pdf(path, draw_text=True, bg=(241, 86, 76)):
    """A single-page PDF: flat background + one image with text BAKED INTO its pixels
    (not a native text object). This mirrors the real case the repair handles."""
    from PIL import Image, ImageDraw
    import io
    doc = pymupdf.open()
    page = doc.new_page(width=400, height=560)
    page.draw_rect(page.rect, color=None, fill=[c / 255 for c in bg])
    # an 'illustration' with baked-in text drawn into the raster itself
    img = Image.new("RGB", (760, 520), (90, 160, 60))
    if draw_text:
        d = ImageDraw.Draw(img)
        # blocky dark glyph stand-ins baked into the image pixels, lower-centre
        for i in range(9):
            bx = 180 + i * 44
            d.rectangle([bx, 360, bx + 34, 440], fill=(20, 20, 20))
    buf = io.BytesIO()
    img.save(buf, format="PNG")
    page.insert_image(pymupdf.Rect(10, 10, 390, 270), stream=buf.getvalue())
    doc.save(path)
    doc.close()


def test_validate_region():
    pw, ph = 800, 1000
    # good box
    check("valid bbox passes", ilt._validate_region({"bbox_px": [10, 10, 100, 60]}, pw, ph) is not None)
    # inverted box rejected
    check("inverted bbox rejected", ilt._validate_region({"bbox_px": [100, 60, 10, 10]}, pw, ph) is None)
    # missing rejected
    check("missing bbox rejected", ilt._validate_region({}, pw, ph) is None)
    # clamped to bounds
    b = ilt._validate_region({"bbox_px": [-50, -50, 100000, 100000]}, pw, ph)
    check("bbox clamped into bounds", b is not None and b[0] >= 0 and b[2] <= pw - 1)


def test_normalized_validation_via_measure_gate():
    # uncertain background always routes to review
    with tempfile.TemporaryDirectory() as d:
        pdf = os.path.join(d, "t.pdf")
        _make_pdf(pdf)
        regions = [{"source_text": "x", "bbox_px": [100, 380, 300, 420],
                    "background_type": "uncertain", "sample_regions_px": [[10, 300, 40, 330]]}]
        res = ilm.measure(pdf, 0, 150, regions)
        check("uncertain bg => needs_review", res["needs_review"] is True)


def test_flat_uniformity_gate():
    with tempfile.TemporaryDirectory() as d:
        pdf = os.path.join(d, "t.pdf")
        _make_pdf(pdf)
        # sample regions in PIXEL space at ppi 150 (scale = 150/72 ~= 2.083), placed on the
        # flat coral lower area (PDF y ~ 440-520 => px ~ 916-1083; page is 560pt => 1166px)
        s = 150 / 72.0
        regions = [{"source_text": "My Senses",
                    "bbox_px": [int(120 * s), int(380 * s), int(300 * s), int(420 * s)],
                    "background_type": "flat",
                    "sample_regions_px": [
                        [int(40 * s), int(450 * s), int(120 * s), int(500 * s)],
                        [int(280 * s), int(450 * s), int(360 * s), int(500 * s)]]}]
        res = ilm.measure(pdf, 0, 150, regions)
        # flat coral should measure and NOT force review
        ok = (res["needs_review"] is False and
              res["regions"][0].get("color_rgb") is not None)
        check("flat uniform bg measured, no review", ok)
        if ok:
            r = res["regions"][0]["color_rgb"]
            check("measured colour near coral", abs(r[0] - 241) < 20 and abs(r[1] - 86) < 25)


def test_candidates():
    with tempfile.TemporaryDirectory() as d:
        pdf = os.path.join(d, "t.pdf")
        _make_pdf(pdf, draw_text=False)  # low text + big image => candidate
        cands = ilt.find_candidate_pages(pdf)
        check("page with dominant image is a candidate", any(c["page"] == 0 for c in cands))


def _baked_region_px(ppi):
    """Render-pixel bbox of the baked text band. Image placed at page rect (10,10,390,270)
    pt; baked glyphs at image px x[180..575] y[360..440] of a 760x520 image."""
    zoom = ppi / 72.0
    px0, py0, px1, py1 = 10, 10, 390, 270
    iw, ih = 760, 520
    def to_render(ix, iy):
        return ((px0 + ix / iw * (px1 - px0)) * zoom,
                (py0 + iy / ih * (py1 - py0)) * zoom)
    rx0, ry0 = to_render(170, 350)
    rx1, ry1 = to_render(585, 450)
    return [rx0, ry0, rx1, ry1]


def test_repair_end_to_end():
    """DEFAULT = surgical: baked text is removed from the image, native page content is
    preserved (page NOT flattened), translated vector text overlaid."""
    with tempfile.TemporaryDirectory() as d:
        pdf = os.path.join(d, "t.pdf")
        out = os.path.join(d, "out.pdf")
        _make_pdf(pdf)
        regions_data = {"ppi": 150, "regions": [{
            "source_text": "My Senses", "target_text": "My Sintuie",
            "bbox_px": _baked_region_px(150),
            "background_type": "flat", "color_rgb": [90, 160, 60],
            "text_color_rgb": [255, 245, 200], "align": "center", "source_font_pt": 24.0,
        }]}
        report = ilt.repair_page(pdf, 0, regions_data, {}, "", out, ppi=150)
        check("repair strategy is surgical (default)", report.get("strategy") == "surgical")
        check("repair reports modified", report.get("modified") is True)
        check("repair applied the overlay", report.get("regions_applied") == 1)
        check("output pdf exists", os.path.isfile(out))
        check("page NOT flattened", not report.get("flattened"))
        doc = pymupdf.open(out)
        txt = doc[0].get_text().strip()
        doc.close()
        check("translated text present", "Sintuie" in txt)


def test_repair_unowned_fails_closed():
    """A region not inside any image => surgical fails closed (no flatten unless allowed)."""
    with tempfile.TemporaryDirectory() as d:
        pdf = os.path.join(d, "t.pdf")
        out = os.path.join(d, "out.pdf")
        _make_pdf(pdf)
        regions_data = {"ppi": 150, "regions": [{
            "source_text": "x", "target_text": "y", "bbox_px": [5, 1050, 60, 1120],
            "background_type": "flat", "color_rgb": [241, 86, 76]}]}
        report = ilt.repair_page(pdf, 0, regions_data, {}, "", out, ppi=150)
        check("unowned region not modified", report.get("modified") is False)
        check("unowned region skipped (no flatten)", any(
            s.get("reason") == "regions_unowned_no_flatten" for s in report.get("skipped", [])))


def test_repair_flatten_fallback_is_review_gated():
    """allow_flatten=True on an unowned region uses the flatten fallback and marks the
    result requires_review (R6.5)."""
    with tempfile.TemporaryDirectory() as d:
        pdf = os.path.join(d, "t.pdf")
        out = os.path.join(d, "out.pdf")
        _make_pdf(pdf)
        regions_data = {"ppi": 150, "regions": [{
            "source_text": "x", "target_text": "y", "bbox_px": [5, 1050, 200, 1120],
            "background_type": "flat", "color_rgb": [241, 86, 76]}]}
        report = ilt.repair_page(pdf, 0, regions_data, {}, "", out, ppi=150,
                                 allow_flatten=True)
        check("flatten fallback used", report.get("strategy") == "flatten")
        check("flatten flagged for review", report.get("requires_review") is True)
        check("flatten output exists", os.path.isfile(out))


def test_genmask_and_generative_composite():
    """Prove the generative path: genmask builds a square base+mask, and the flatten
    fallback composites ONLY the masked regions from a (faked) reconstructed image."""
    import illustration_genmask as igm
    with tempfile.TemporaryDirectory() as d:
        pdf = os.path.join(d, "t.pdf")
        _make_pdf(pdf)
        ppi = 150
        # generative compositing is a refinement of the FLATTEN fallback (whole-page
        # raster). Use an unowned region so repair takes that path with allow_flatten.
        bbox = [5, 1050, 400, 1120]
        regions = [{"source_text": "My Senses", "target_text": "My Sintuie",
                    "bbox_px": bbox, "background_type": "flat",
                    "color_rgb": [241, 86, 76], "text_color_rgb": [255, 245, 200],
                    "align": "center", "source_font_pt": 24.0}]
        base = os.path.join(d, "base.png")
        mask = os.path.join(d, "mask.png")
        rep = igm.build(pdf, 0, ppi, regions, base, mask)
        check("genmask produced a square", rep["side"] >= max(rep["page_px"]))
        check("genmask base exists", os.path.isfile(base))
        check("genmask mask exists", os.path.isfile(mask))
        mpix = pymupdf.Pixmap(mask)
        check("mask has alpha channel", mpix.alpha == 1)

        fake = pymupdf.Pixmap(pymupdf.csRGB, pymupdf.IRect(0, 0, rep["side"], rep["side"]), False)
        fake.set_rect(fake.irect, (0, 200, 0))
        fake_path = os.path.join(d, "gen.png")
        fake.save(fake_path)

        out = os.path.join(d, "out.pdf")
        # generative compositing lives in the flatten fallback (whole-page raster path)
        report = ilt.repair_page(pdf, 0, {"ppi": ppi, "regions": regions}, {}, "",
                                 out, ppi=ppi, generative_bg=fake_path, allow_flatten=True)
        check("generative composite ran", report.get("generative_bg") is True)
        check("generative is review-gated", report.get("requires_review") is True)
        check("output exists (generative)", os.path.isfile(out))


def main():
    print("Illustration-Text module tests")
    test_validate_region()
    test_normalized_validation_via_measure_gate()
    test_flat_uniformity_gate()
    test_candidates()
    test_repair_end_to_end()
    test_repair_unowned_fails_closed()
    test_repair_flatten_fallback_is_review_gated()
    test_genmask_and_generative_composite()
    print(f"\n{_passed}/{_run} passed")
    sys.exit(0 if _passed == _run else 1)


if __name__ == "__main__":
    main()
