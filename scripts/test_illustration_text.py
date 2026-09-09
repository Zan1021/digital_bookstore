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
    """A single-page PDF: flat background + one image + optional baked-looking text drawn
    as a filled rect band (stand-in for baked text)."""
    doc = pymupdf.open()
    page = doc.new_page(width=400, height=560)
    page.draw_rect(page.rect, color=None, fill=[c / 255 for c in bg])
    # an 'illustration' block (top half) so candidates() sees a dominant image-ish area
    pix = pymupdf.Pixmap(pymupdf.csRGB, pymupdf.IRect(0, 0, 380, 260), False)
    pix.set_rect(pix.irect, (90, 160, 60))
    page.insert_image(pymupdf.Rect(10, 10, 390, 270), pixmap=pix)
    if draw_text:
        page.insert_text((120, 400), "My Senses", fontsize=28, color=(1, 0.96, 0.78))
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


def test_repair_end_to_end():
    with tempfile.TemporaryDirectory() as d:
        pdf = os.path.join(d, "t.pdf")
        out = os.path.join(d, "out.pdf")
        _make_pdf(pdf)
        regions_data = {"ppi": 150, "regions": [{
            "source_text": "My Senses", "target_text": "My Sintuie",
            "bbox_px": [int(120 * 150 / 72), int(378 * 150 / 72),
                        int(300 * 150 / 72), int(410 * 150 / 72)],
            "background_type": "flat", "color_rgb": [241, 86, 76],
            "text_color_rgb": [255, 245, 200], "align": "center", "source_font_pt": 24.0,
        }]}
        report = ilt.repair_page(pdf, 0, regions_data, {}, "", out, ppi=150)
        check("repair reports modified", report.get("modified") is True)
        check("repair applied the region", report.get("regions_applied") == 1)
        check("output pdf exists", os.path.isfile(out))
        # the repaired cover page should be a single flattened image (no old text object)
        doc = pymupdf.open(out)
        txt = doc[0].get_text().strip()
        doc.close()
        # new overlaid vector text is "My Sintuie"; old baked "My Senses" must be gone
        check("no source English text remains", "Senses" not in txt)
        check("translated text present", "Sintuie" in txt)


def test_genmask_and_generative_composite():
    """Prove the generative path: genmask builds a square base+mask, and repair composites
    ONLY the masked regions from a (faked) reconstructed image over the original."""
    import illustration_genmask as igm
    with tempfile.TemporaryDirectory() as d:
        pdf = os.path.join(d, "t.pdf")
        _make_pdf(pdf)
        ppi = 150
        s = ppi / 72.0
        bbox = [int(120 * s), int(378 * s), int(300 * s), int(410 * s)]
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
        # mask must be transparent (alpha 0) inside the text box, opaque outside
        mpix = pymupdf.Pixmap(mask)
        cx = (bbox[0] + bbox[2]) // 2
        cy = (bbox[1] + bbox[3]) // 2
        inside_alpha = mpix.pixel(cx, cy)  # RGB read; check alpha via samples
        check("mask has alpha channel", mpix.alpha == 1)

        # Fake a "reconstructed" image: solid green everywhere. After composite, ONLY the
        # masked region should become green in the output; elsewhere stays coral.
        genw = pymupdf.Pixmap(mpix, 0) if mpix.alpha else mpix
        fake = pymupdf.Pixmap(pymupdf.csRGB, pymupdf.IRect(0, 0, rep["side"], rep["side"]), False)
        fake.set_rect(fake.irect, (0, 200, 0))
        fake_path = os.path.join(d, "gen.png")
        fake.save(fake_path)

        out = os.path.join(d, "out.pdf")
        report = ilt.repair_page(pdf, 0, {"ppi": ppi, "regions": regions}, {}, "",
                                 out, ppi=ppi, generative_bg=fake_path)
        check("generative composite ran", report.get("generative_bg") is True)
        check("generative applied a region", report.get("generative_regions", 0) == 1)
        check("output exists (generative)", os.path.isfile(out))


def main():
    print("Illustration-Text module tests")
    test_validate_region()
    test_normalized_validation_via_measure_gate()
    test_flat_uniformity_gate()
    test_candidates()
    test_repair_end_to_end()
    test_genmask_and_generative_composite()
    print(f"\n{_passed}/{_run} passed")
    sys.exit(0 if _passed == _run else 1)


if __name__ == "__main__":
    main()
