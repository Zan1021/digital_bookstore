#!/usr/bin/env python3
"""
artwork_repair.py — artwork-preserving raster repair (Phase 5.1/5.2/5.4, R6).

The OLD illustration repair rendered the WHOLE page to a raster and re-embedded it. That
flattens every native vector paragraph, line-art object, link and label on the page — an
R6.1/R6.5 violation (full-page flatten must be a REVIEWED fallback, never the default).

These books embed artwork as discrete image XObjects (one dominant illustration per story
page), so the correct, faithful repair is SURGICAL:

    1. Locate the embedded image INSTANCE that contains the baked-in text (by xref), using
       the explicit CropTransform coordinate chain (R7). If the xref is reused on other
       pages, we isolate it so only the intended page changes (R6.2).
    2. Extract that image's own pixels.
    3. Build a LETTER-SHAPED mask over the text (R6.2) — not a box — so only the glyph ink
       (plus a small halo) is repaired; the surrounding artwork is untouched.
    4. Fill the masked pixels with a background method chosen by the SAMPLED background type
       (flat / gradient / textured) — no median-strip-only assumption (R6.3).
    5. Re-embed ONLY that image via Page.replace_image(xref), preserving the page's vector
       text, line art, CropBox/MediaBox, rotation, labels and links.

Full-page flatten remains available ONLY as an explicit, review-flagged fallback
(allow_flatten=True) for pages where no single owning image instance can be resolved
(R6.5). It preserves page geometry/rotation and records requires_review=True.

Pure PyMuPDF + PIL (system python has no numpy/cv2). Letter-shaped masking uses PIL's
luminance thresholding against the sampled background — enough to isolate solid glyph ink
over a sign/panel without a CV dependency.
"""

from __future__ import annotations

import io
import os
from dataclasses import dataclass
from typing import List, Optional, Tuple

import pymupdf
from PIL import Image

try:
    from crop_transform import CropTransform, LayoutReviewRequired, _fail_closed
except Exception:  # pragma: no cover
    from scripts.crop_transform import CropTransform, LayoutReviewRequired, _fail_closed  # type: ignore


# --------------------------------------------------------------------------- #
# background sampling + letter-shaped mask
# --------------------------------------------------------------------------- #
def _median(vals: List[int]) -> int:
    s = sorted(vals)
    n = len(s)
    if n == 0:
        return 0
    return s[n // 2] if n % 2 else (s[n // 2 - 1] + s[n // 2]) // 2


@dataclass
class BackgroundSample:
    """Measured background around a text box: median colour + whether it is flat, and the
    top/bottom medians for gradient interpolation."""
    median: Tuple[int, int, int]
    top_median: Optional[Tuple[int, int, int]]
    bottom_median: Optional[Tuple[int, int, int]]
    kind: str  # 'flat' | 'gradient' | 'textured'


def sample_background(img: Image.Image, box: Tuple[int, int, int, int],
                      gap: int, band: int) -> BackgroundSample:
    """Sample a ring OFFSET outside the text box (so glyph shadows don't contaminate it),
    decide flat/gradient/textured, and return per-channel medians. `img` is RGB."""
    px = img.load()
    W, H = img.size
    x0, y0, x1, y1 = box

    def strip(y: int) -> List[Tuple[int, int, int]]:
        out = []
        yy = max(0, min(H - 1, y))
        for x in range(max(0, x0), min(W, x1 + 1)):
            out.append(px[x, yy])
        return out

    top, bot = [], []
    for i in range(band):
        yt = y0 - gap - i
        yb = y1 + gap + i
        if 0 <= yt < H:
            top += strip(yt)
        if 0 <= yb < H:
            bot += strip(yb)
    ring = top + bot
    if not ring:
        return BackgroundSample((255, 255, 255), None, None, "flat")

    med = (_median([c[0] for c in ring]), _median([c[1] for c in ring]), _median([c[2] for c in ring]))
    top_med = (_median([c[0] for c in top]), _median([c[1] for c in top]), _median([c[2] for c in top])) if top else None
    bot_med = (_median([c[0] for c in bot]), _median([c[1] for c in bot]), _median([c[2] for c in bot])) if bot else None

    spread = 0
    for ch in range(3):
        vals = [c[ch] for c in ring]
        spread += (max(vals) - min(vals))

    side_dist = 0
    if top_med and bot_med:
        side_dist = sum(abs(top_med[i] - bot_med[i]) for i in range(3))

    if spread < 90:
        kind = "flat"
    elif side_dist > 60 and top_med and bot_med:
        kind = "gradient"
    else:
        kind = "textured"
    return BackgroundSample(med, top_med, bot_med, kind)


def build_letter_mask(img: Image.Image, box: Tuple[int, int, int, int],
                      bg: BackgroundSample, dilate: int = 2) -> Image.Image:
    """Return an 'L' (grayscale) mask the size of the box: 255 where glyph ink is detected
    (to be repaired), 0 elsewhere. Letter-shaped — detects pixels that DIFFER strongly from
    the sampled background rather than masking the whole rectangle (R6.2).

    A simple, numpy-free luminance/colour-distance threshold against the background median,
    with a small dilation so antialiased glyph edges and shadows are covered."""
    x0, y0, x1, y1 = box
    w, h = max(1, x1 - x0), max(1, y1 - y0)
    region = img.crop((x0, y0, x1, y1)).convert("RGB")
    rpx = region.load()
    mask = Image.new("L", (w, h), 0)
    mpx = mask.load()
    br, bgc, bb = bg.median
    # Threshold: colour distance from background. Glyph ink (dark on light sign, or light
    # on dark) departs far from the local background median.
    thresh = 70
    for yy in range(h):
        for xx in range(w):
            r, g, b = rpx[xx, yy]
            dist = abs(r - br) + abs(g - bgc) + abs(b - bb)
            if dist > thresh:
                mpx[xx, yy] = 255
    if dilate > 0:
        mask = _dilate(mask, dilate)
    return mask


def _dilate(mask: Image.Image, radius: int) -> Image.Image:
    """Max-filter dilation without numpy/cv2: PIL MaxFilter needs odd kernel >=3."""
    from PIL import ImageFilter
    k = max(3, radius * 2 + 1)
    if k % 2 == 0:
        k += 1
    return mask.filter(ImageFilter.MaxFilter(k))


def _fill_pixel_for(bg: BackgroundSample, yy: int, h: int) -> Tuple[int, int, int]:
    """Background colour for row yy of the box, honouring the sampled background kind."""
    if bg.kind == "gradient" and bg.top_median and bg.bottom_median:
        t = yy / max(1, h - 1)
        return (
            int(bg.top_median[0] * (1 - t) + bg.bottom_median[0] * t),
            int(bg.top_median[1] * (1 - t) + bg.bottom_median[1] * t),
            int(bg.top_median[2] * (1 - t) + bg.bottom_median[2] * t),
        )
    # flat and textured both fall back to the ring median; textured is still better than a
    # white rectangle and the result is review-gated upstream for uncertain structure.
    return bg.median


def repair_image_region(img: Image.Image, box: Tuple[int, int, int, int],
                        ppi: float, flat_color: Optional[Tuple[int, int, int]] = None,
                        ) -> Tuple[Image.Image, str]:
    """Repair ONE text box inside the image `img` with a letter-shaped mask. Returns the
    modified image (in place) + the chosen background kind. `flat_color` overrides the
    sampled fill when the caller measured an exact flat colour."""
    W, H = img.size
    x0, y0, x1, y1 = (int(round(v)) for v in box)
    x0 = max(0, min(x0, W - 1)); y0 = max(0, min(y0, H - 1))
    x1 = max(0, min(x1, W)); y1 = max(0, min(y1, H))
    if x1 <= x0 or y1 <= y0:
        raise _fail_closed("DEGENERATE_TRANSFORM",
                           {"where": "repair_image_region", "box": [x0, y0, x1, y1]})

    gap = max(3, int(ppi / 40))
    band = max(2, int(ppi / 120))
    bg = sample_background(img, (x0, y0, x1, y1), gap, band)
    if flat_color is not None:
        bg = BackgroundSample(tuple(int(c) for c in flat_color), None, None, "flat")

    mask = build_letter_mask(img, (x0, y0, x1, y1), bg, dilate=max(1, int(ppi / 150)))
    mpx = mask.load()
    px = img.load()
    bw, bh = mask.size
    for yy in range(bh):
        fill = _fill_pixel_for(bg, yy, bh)
        for xx in range(bw):
            if mpx[xx, yy]:
                px[x0 + xx, y0 + yy] = fill
    return img, bg.kind


# --------------------------------------------------------------------------- #
# surgical page repair — edit the owning image instance only
# --------------------------------------------------------------------------- #
def _pil_from_pixmap(pix: "pymupdf.Pixmap") -> Image.Image:
    mode = "RGB" if pix.n < 4 else "RGBA"
    img = Image.frombytes(mode, (pix.width, pix.height), pix.samples)
    return img.convert("RGB")


def find_owning_image(page: "pymupdf.Page", box_px: Tuple[int, int, int, int],
                      ppi: float) -> Optional[Tuple[int, int, "pymupdf.Rect"]]:
    """Return (xref, instance_index, placed_rect_pt) of the image instance that CONTAINS
    the given render-pixel text box, or None if no single image owns it. Book-agnostic:
    chooses the image whose placed rect (in render px) contains the box centre and is the
    smallest such container (the tightest owner)."""
    zoom = ppi / 72.0
    bx = (box_px[0] + box_px[2]) / 2.0
    by = (box_px[1] + box_px[3]) / 2.0
    best = None
    best_area = None
    for img in page.get_images(full=True):
        xref = img[0]
        rects = page.get_image_rects(xref)
        for idx, r in enumerate(rects):
            rx0, ry0, rx1, ry1 = r.x0 * zoom, r.y0 * zoom, r.x1 * zoom, r.y1 * zoom
            if rx0 <= bx <= rx1 and ry0 <= by <= ry1:
                area = abs((rx1 - rx0) * (ry1 - ry0))
                if best_area is None or area < best_area:
                    best = (xref, idx, r)
                    best_area = area
    return best


def repair_page_surgical(doc: "pymupdf.Document", page_index: int, regions: List[dict],
                         ppi: float) -> dict:
    """Repair baked-in text by editing only the owning image instance(s) — NEVER flattening
    the page. Mutates `doc` in place. Each region dict needs `bbox_px` (render-pixel space)
    and may carry `background_type`/`color_rgb`. Returns a report incl. per-region status
    and the CropTransform records (R7.2)."""
    page = doc[page_index]
    report = {"page_index": page_index, "ppi": ppi, "strategy": "surgical",
              "regions_in": len(regions), "regions_repaired": 0, "transforms": [],
              "skipped": [], "unowned": [], "modified": False}

    # group regions by the image instance that owns them, so we extract/repair/re-embed
    # each image exactly once (idempotent — no compounded inpaint, R9.4).
    by_image: dict = {}
    for r in regions:
        b = r.get("bbox_px")
        if not (isinstance(b, (list, tuple)) and len(b) == 4):
            report["skipped"].append({"reason": "invalid bbox", "region": r.get("source_text")})
            continue
        box = tuple(float(v) for v in b)
        owner = find_owning_image(page, box, ppi)
        if owner is None:
            report["unowned"].append({"region": r.get("source_text"), "bbox_px": list(box)})
            continue
        xref, idx, placed = owner
        by_image.setdefault((xref, idx), {"placed": placed, "regions": []})["regions"].append((r, box))

    if report["unowned"]:
        # A region not owned by any single image instance cannot be repaired surgically →
        # fail closed for this page (caller may retry with allow_flatten as a reviewed
        # fallback). We do NOT silently paint a rectangle.
        return report

    # does any OTHER page use this xref? if so we must NOT mutate the shared object
    # (replace_image edits the xref globally). We detect sharing up front per image.
    def _xref_is_shared(xref: int) -> bool:
        count = 0
        for pno in range(doc.page_count):
            if xref in [im[0] for im in doc[pno].get_images(full=True)]:
                count += 1
                if count > 1:
                    return True
        # also shared if placed multiple times on THIS page
        return len(page.get_image_rects(xref)) > 1

    for (xref, idx), bundle in by_image.items():
        ct = CropTransform.for_image_instance(page, xref, ppi=ppi, instance=idx)
        base = doc.extract_image(xref)
        img = Image.open(io.BytesIO(base["image"])).convert("RGB")
        iw, ih = img.size
        ct.set_model_size(iw, ih)  # the image's OWN raster size (R7.2 actual dimensions)

        # map each region's render-pixel box into this image's own pixel space via the crop
        for (r, box) in bundle["regions"]:
            lx0, ly0 = ct.render_px_to_crop_px(box[0], box[1])
            lx1, ly1 = ct.render_px_to_crop_px(box[2], box[3])
            # crop space is at render resolution; map into the image's own pixel space
            ix0, iy0 = ct.crop_px_to_model_px(lx0, ly0)
            ix1, iy1 = ct.crop_px_to_model_px(lx1, ly1)
            flat = None
            if r.get("background_type") == "flat" and r.get("color_rgb"):
                flat = tuple(int(c) for c in r["color_rgb"])
            try:
                _, kind = repair_image_region(img, (ix0, iy0, ix1, iy1), ppi, flat_color=flat)
                r["_repaired_bg_kind"] = kind
                report["regions_repaired"] += 1
            except LayoutReviewRequired as e:
                report["skipped"].append({"reason": getattr(e, "reason", str(e)),
                                          "region": r.get("source_text")})

        buf = io.BytesIO()
        img.save(buf, format="PNG")
        if _xref_is_shared(xref):
            # ISOLATE (R6.2): the shared object must stay intact for other pages. Overlay
            # the repaired, opaque image as a NEW page-local object at the identical rect,
            # fully occluding the shared original on THIS page only. Other pages untouched.
            page.insert_image(bundle["placed"], stream=buf.getvalue(), keep_proportion=False,
                              overlay=True)
            report.setdefault("isolated_xrefs", []).append(xref)
        else:
            # unique object → in-place replace is correct and cheapest.
            page.replace_image(xref, stream=buf.getvalue())
        report["transforms"].append(ct.to_dict())

    report["modified"] = report["regions_repaired"] > 0
    return report
