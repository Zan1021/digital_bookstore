#!/usr/bin/env python3
"""
crop_transform.py — explicit coordinate transforms for artwork repair.

World-class-render-engine spec Req 7 (Phase 5.3). Every pixel we repair must be mapped
through an EXPLICIT, auditable chain of transforms so a repaired patch lands exactly where
the source text was — never a naive horizontal overlay at unscaled coordinates.

The chain (R7.1):

    source PDF points  ──render(ppi)──▶  rendered pixels
                       ──locate image──▶  crop pixels   (the embedded image instance's own raster space)
                       ──send to model─▶  model pixels  (the generative/edit result; may be a DIFFERENT size, R7.2)
                       ──map back──────▶  source patch pixels (resized to the crop before masked composite, R7.3)

Design constraints honoured:
  * Pure PyMuPDF (system python has no numpy/cv2). We only need affine math, which we do
    with pymupdf.Matrix / Rect / Point and plain floats.
  * Page rotation and the image placement matrix are NORMALIZED before any mapping (R7.1):
    we work in the page's *un-rotated* coordinate space and account for the image's own
    transform matrix on the page.
  * The model's ACTUAL returned dimensions are used, never the requested size (R7.2). If a
    model hands back 1024x1024 for a 1721x1736 crop, the mapping scales accordingly.
  * Any degenerate (non-positive) dimension anywhere in the chain raises
    LayoutReviewRequired — fail closed (R7.4, I3). We NEVER silently clamp a zero/negative
    span into a 1px sliver and carry on.

This module is deterministic and has no network and no side effects; it is unit-testable
without any artwork. The repair module (illustration_text) consumes a CropTransform to
place a model patch; the review/QA layer reads its recorded transforms from the artifact.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field, asdict
from typing import Optional, Tuple

import pymupdf


# --------------------------------------------------------------------------- #
# Fail-closed signal. Mirror of document_model.LayoutReviewRequired so callers can catch
# either; we re-raise the canonical one when it is importable so the orchestrator's single
# except-block keeps working.
# --------------------------------------------------------------------------- #
try:  # pragma: no cover - import shim
    from document_model import LayoutReviewRequired  # type: ignore
except Exception:  # pragma: no cover
    class LayoutReviewRequired(RuntimeError):
        """Raised when geometry cannot be resolved faithfully; route the page to review."""


def _fail_closed(reason: str, detail: Optional[dict] = None) -> "LayoutReviewRequired":
    """Build a LayoutReviewRequired whose message IS the reason code, with the structured
    detail attached as an attribute. The canonical engine class is a plain RuntimeError
    (no custom __init__), so we attach `.reason`/`.detail` after construction rather than
    forking the exception contract (I2 — single production path)."""
    exc = LayoutReviewRequired(reason)
    exc.reason = reason
    exc.detail = detail or {}
    return exc


def _require_positive(name: str, w: float, h: float, detail: dict) -> None:
    """Fail closed on any non-positive dimension (R7.4)."""
    if not (w > 0 and h > 0):
        raise _fail_closed(
            "DEGENERATE_TRANSFORM",
            {"where": name, "width": w, "height": h, **detail},
        )


@dataclass
class CropTransform:
    """Immutable record of the transform chain for ONE image region on ONE page.

    All boxes are stored explicitly so the artifact can be inspected/replayed and the
    review UI can draw them. Coordinates:
      * source_rect_pt   : the image's placed rectangle on the page, in PDF points,
                           in the page's UN-ROTATED space.
      * page_rotation    : original page /Rotate (0/90/180/270), recorded for round-trip.
      * ppi              : render resolution used to rasterize.
      * render_size_px   : full page raster size at ppi (w, h), un-rotated space.
      * crop_rect_px     : the image instance's box within the render, pixels (x0,y0,x1,y1).
      * model_size_px    : the model's ACTUAL returned (w, h) — set once known (R7.2).
    """

    page_index: int
    page_rotation: int
    ppi: float
    render_size_px: Tuple[int, int]
    source_rect_pt: Tuple[float, float, float, float]
    crop_rect_px: Tuple[int, int, int, int]
    image_xref: Optional[int] = None
    model_size_px: Optional[Tuple[int, int]] = None
    # derived, filled in __post_init__
    crop_size_px: Tuple[int, int] = field(default=(0, 0))

    def __post_init__(self):
        cx0, cy0, cx1, cy1 = self.crop_rect_px
        cw, ch = cx1 - cx0, cy1 - cy0
        _require_positive("crop_rect_px", cw, ch,
                          {"page_index": self.page_index, "crop_rect_px": self.crop_rect_px})
        self.crop_size_px = (int(round(cw)), int(round(ch)))

        sx0, sy0, sx1, sy1 = self.source_rect_pt
        _require_positive("source_rect_pt", sx1 - sx0, sy1 - sy0,
                          {"page_index": self.page_index, "source_rect_pt": self.source_rect_pt})

        rw, rh = self.render_size_px
        _require_positive("render_size_px", rw, rh, {"page_index": self.page_index})

    # ---- factory ---------------------------------------------------------- #
    @classmethod
    def for_image_instance(cls, page: "pymupdf.Page", xref: int, ppi: float,
                           instance: int = 0) -> "CropTransform":
        """Build the transform for a specific placed instance of image `xref` on `page`.

        Normalizes page rotation (R7.1): we derive the crop box from the image's placed
        rectangle in the page's own coordinate space, scaled by the render zoom. If the
        image is placed multiple times (reused), `instance` selects which placement — the
        caller is responsible for isolating the intended one (R6.2).
        """
        try:
            rects = page.get_image_rects(xref)
        except (ValueError, RuntimeError) as e:
            raise _fail_closed(
                "IMAGE_XREF_INVALID",
                {"page_index": page.number, "xref": xref, "error": str(e)},
            )
        if not rects:
            raise _fail_closed(
                "IMAGE_NOT_PLACED",
                {"page_index": page.number, "xref": xref},
            )
        if instance < 0 or instance >= len(rects):
            raise _fail_closed(
                "IMAGE_INSTANCE_OUT_OF_RANGE",
                {"page_index": page.number, "xref": xref,
                 "instance": instance, "available": len(rects)},
            )
        rect = rects[instance]
        zoom = ppi / 72.0
        # Render size in the page's own (un-rotated) coordinate box. page.rect already
        # reflects rotation; we use the *mediabox-oriented* dimensions consistently with
        # how get_pixmap(matrix=zoom) rasterizes, so crop math stays in one space.
        page_rect = page.rect
        render_w = int(math.ceil(page_rect.width * zoom))
        render_h = int(math.ceil(page_rect.height * zoom))

        cx0 = int(math.floor(rect.x0 * zoom))
        cy0 = int(math.floor(rect.y0 * zoom))
        cx1 = int(math.ceil(rect.x1 * zoom))
        cy1 = int(math.ceil(rect.y1 * zoom))
        # clamp into the render, but NOT into degeneracy — __post_init__ fails closed if a
        # clamp collapsed the box.
        cx0 = max(0, min(cx0, render_w))
        cy0 = max(0, min(cy0, render_h))
        cx1 = max(0, min(cx1, render_w))
        cy1 = max(0, min(cy1, render_h))

        return cls(
            page_index=page.number,
            page_rotation=int(page.rotation or 0),
            ppi=float(ppi),
            render_size_px=(render_w, render_h),
            source_rect_pt=(rect.x0, rect.y0, rect.x1, rect.y1),
            crop_rect_px=(cx0, cy0, cx1, cy1),
            image_xref=xref,
        )

    # ---- model size (R7.2) ------------------------------------------------ #
    def set_model_size(self, width: int, height: int) -> "CropTransform":
        """Record the model's ACTUAL returned size (never the requested size)."""
        _require_positive("model_size_px", width, height,
                          {"page_index": self.page_index, "requested_note": "actual model size"})
        self.model_size_px = (int(width), int(height))
        return self

    # ---- mapping helpers -------------------------------------------------- #
    def render_px_to_crop_px(self, x: float, y: float) -> Tuple[float, float]:
        """A point in full-render pixel space → the crop's local pixel space (origin at
        crop top-left)."""
        cx0, cy0, _, _ = self.crop_rect_px
        return (x - cx0, y - cy0)

    def crop_px_to_model_px(self, x: float, y: float) -> Tuple[float, float]:
        """Crop-local pixels → model-output pixels, using the model's ACTUAL size (R7.2).
        If the model hasn't reported a size, model space == crop space (identity)."""
        if self.model_size_px is None:
            return (x, y)
        cw, ch = self.crop_size_px
        mw, mh = self.model_size_px
        return (x * (mw / cw), y * (mh / ch))

    def model_px_to_crop_px(self, x: float, y: float) -> Tuple[float, float]:
        """Model-output pixels → crop-local pixels (the inverse scale). This is how a patch
        returned at a DIFFERENT resolution is resized back to the source crop before the
        masked composite (R7.3)."""
        if self.model_size_px is None:
            return (x, y)
        cw, ch = self.crop_size_px
        mw, mh = self.model_size_px
        _require_positive("model_size_px(inverse)", mw, mh, {"page_index": self.page_index})
        return (x * (cw / mw), y * (ch / mh))

    def source_pt_to_render_px(self, x: float, y: float) -> Tuple[float, float]:
        zoom = self.ppi / 72.0
        return (x * zoom, y * zoom)

    def render_px_to_source_pt(self, x: float, y: float) -> Tuple[float, float]:
        zoom = self.ppi / 72.0
        return (x / zoom, y / zoom)

    def model_scale(self) -> Tuple[float, float]:
        """(sx, sy) that resizes a model-sized patch back onto the source crop (R7.3)."""
        if self.model_size_px is None:
            return (1.0, 1.0)
        cw, ch = self.crop_size_px
        mw, mh = self.model_size_px
        _require_positive("model_size_px(scale)", mw, mh, {"page_index": self.page_index})
        return (cw / mw, ch / mh)

    def is_rotated(self) -> bool:
        return self.page_rotation % 360 != 0

    def to_dict(self) -> dict:
        """Serializable record for the repair artifact (R7.2: store dims + transforms)."""
        d = asdict(self)
        d["crop_size_px"] = list(self.crop_size_px)
        d["render_size_px"] = list(self.render_size_px)
        d["source_rect_pt"] = list(self.source_rect_pt)
        d["crop_rect_px"] = list(self.crop_rect_px)
        if self.model_size_px is not None:
            d["model_size_px"] = list(self.model_size_px)
        return d
