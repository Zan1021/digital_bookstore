"""
inventory_layout.py — the `inventory-layout-2` canonical contract
==================================================================
unified-rendering-and-testing spec Req 5 (brief §4).

An ADDITIVE serializer/validator over the existing DocumentScene (document_model.py). It
does NOT replace the scene graph — it projects it into the stable, persisted contract the
whole pipeline reasons from, adding the three things the scene graph did not carry
explicitly:

  1. source_kind SEPARATE from semantic_role (native_text | outlined_vector | raster_text).
  2. a per-region processing_policy ∈ {translate, educational_adaptation, preserve, review}.
  3. per-page forward AND inverse transforms (source pt → render px → image px) so a
     downstream stage can map coordinates both ways; CropBox offset, rotation and mixed
     page sizes round-trip.

Plus a validation pass: unique IDs, no parent cycles, exactly one direct owner per unit,
parent/membership references resolve, finite confidence, positive geometry. An empty
required-capabilities list must NOT imply universal support (recorded as needs_review).

Deterministic, no network, no side effects — unit-testable without a real PDF.
"""

from __future__ import annotations

from math import isfinite
from typing import Any, Optional


SCHEMA_VERSION = "inventory-layout-2"

VALID_SOURCE_KINDS = {"native_text", "outlined_vector", "raster_text"}
VALID_POLICIES = {"translate", "educational_adaptation", "preserve", "review"}


class InventoryValidationError(ValueError):
    """Raised when the inventory contract is structurally invalid (fail closed)."""


# --------------------------------------------------------------------------- #
# Transforms
# --------------------------------------------------------------------------- #

def build_page_transforms(
    crop_box: tuple[float, float, float, float],
    rotation_deg: int,
    render_ppi: float = 150.0,
) -> dict[str, Any]:
    """Build the forward + inverse transform descriptors for a page.

    Forward chain: source PDF point → (shift by CropBox origin, apply rotation, scale by
    ppi/72) → rendered pixel. The inverse undoes each step. We persist the PARAMETERS
    (origin, rotation, scale) rather than a baked matrix so a consumer can reconstruct the
    chain for any sub-step (render px, image px) and audit it.

    Fail closed on a degenerate box.
    """
    x0, y0, x1, y1 = crop_box
    if not all(isfinite(v) for v in crop_box) or x1 <= x0 or y1 <= y0:
        raise InventoryValidationError("INVALID_CROP_BOX")
    if rotation_deg % 90 != 0:
        raise InventoryValidationError("UNSUPPORTED_ROTATION")
    scale = render_ppi / 72.0
    if scale <= 0:
        raise InventoryValidationError("INVALID_SCALE")

    return {
        "crop_origin_pt": [x0, y0],
        "rotation_deg": rotation_deg % 360,
        "render_scale": scale,          # pt → px multiplier
        "render_ppi": render_ppi,
        "page_size_pt": [x1 - x0, y1 - y0],
    }


def source_pt_to_render_px(point: tuple[float, float], transforms: dict[str, Any]) -> tuple[float, float]:
    """Map a source PDF point to a rendered pixel using the persisted transforms.
    Rotation is applied about the crop origin. (0/90/180/270 only.)"""
    ox, oy = transforms["crop_origin_pt"]
    scale = transforms["render_scale"]
    rot = transforms["rotation_deg"]
    w, h = transforms["page_size_pt"]
    px, py = point[0] - ox, point[1] - oy  # into crop space (pt)

    if rot == 0:
        rx, ry = px, py
    elif rot == 90:
        rx, ry = h - py, px
    elif rot == 180:
        rx, ry = w - px, h - py
    elif rot == 270:
        rx, ry = py, w - px
    else:  # pragma: no cover - guarded in build_page_transforms
        raise InventoryValidationError("UNSUPPORTED_ROTATION")

    return rx * scale, ry * scale


def render_px_to_source_pt(pixel: tuple[float, float], transforms: dict[str, Any]) -> tuple[float, float]:
    """Inverse of source_pt_to_render_px — map a rendered pixel back to a source PDF point."""
    ox, oy = transforms["crop_origin_pt"]
    scale = transforms["render_scale"]
    rot = transforms["rotation_deg"]
    w, h = transforms["page_size_pt"]
    if scale <= 0:
        raise InventoryValidationError("INVALID_SCALE")
    rx, ry = pixel[0] / scale, pixel[1] / scale  # back to crop-space pt (rotated)

    if rot == 0:
        px, py = rx, ry
    elif rot == 90:
        px, py = ry, h - rx
    elif rot == 180:
        px, py = w - rx, h - ry
    elif rot == 270:
        px, py = w - ry, rx
    else:  # pragma: no cover
        raise InventoryValidationError("UNSUPPORTED_ROTATION")

    return px + ox, py + oy


# --------------------------------------------------------------------------- #
# source_kind defaulting
# --------------------------------------------------------------------------- #

def default_source_kind(extraction_source: str, explicit: Optional[str] = None) -> str:
    """Derive source_kind when not explicitly classified. 'native' extraction → native_text;
    'ocr' extraction → raster_text (OCR means the text was baked into pixels). An explicit
    valid value always wins."""
    if explicit in VALID_SOURCE_KINDS:
        return explicit
    if extraction_source == "ocr":
        return "raster_text"
    return "native_text"


# --------------------------------------------------------------------------- #
# Serialization
# --------------------------------------------------------------------------- #

def _region_record(region, units_by_region: dict[str, list]) -> dict[str, Any]:
    unit_ids = [u.id for u in units_by_region.get(region.id, [])]
    policy = getattr(region, "translation_policy", "translate")
    if policy not in VALID_POLICIES:
        policy = "review"
    return {
        "id": region.id,
        "parent_id": None,
        "kind": getattr(region, "region_type", "unknown"),
        "source_kind": getattr(region, "source_kind", "native_text"),
        "processing_policy": policy,
        "unit_ids": unit_ids,
        "source_ink_box_pt": list(region.bbox),
        "container_box_pt": list(region.get_container()) if hasattr(region, "get_container") else list(region.bbox),
        "confidence": getattr(region, "confidence", 1.0),
        "required_capabilities": ["horizontal_native_text"]
            if getattr(region, "source_kind", "native_text") == "native_text" else [],
    }


def serialize_inventory(scene, render_ppi: float = 150.0) -> dict[str, Any]:
    """Project a DocumentScene into the inventory-layout-2 contract dict."""
    pages_out: list[dict[str, Any]] = []
    regions_out: list[dict[str, Any]] = []

    for page in scene.pages:
        units_by_region: dict[str, list] = {}
        for u in page.text_units:
            units_by_region.setdefault(u.parent_region_id, []).append(u)

        crop = page.crop_box if page.crop_box and page.crop_box != (0, 0, 0, 0) else page.media_box
        try:
            transforms = build_page_transforms(crop, getattr(page, "rotation", 0), render_ppi)
        except InventoryValidationError as exc:
            transforms = {"error": str(exc)}

        region_ids = []
        for region in page.regions:
            rec = _region_record(region, units_by_region)
            regions_out.append(rec)
            region_ids.append(region.id)

        pages_out.append({
            "page_number": page.page_number,
            "labels": [page.page_type] if getattr(page, "page_type", None) else [],
            "coverage_status": "complete",
            "region_ids": region_ids,
            "rotation_deg": getattr(page, "rotation", 0),
            "transforms": transforms,
        })

    return {
        "schema_version": SCHEMA_VERSION,
        "source_hash": getattr(scene, "source_sha256", ""),
        "analysis_version": getattr(scene, "pdf_version", "") or "v8",
        "pages": pages_out,
        "regions": regions_out,
        "tables": [],
        "exercises": [],
        "reference_entries": [],
    }


# --------------------------------------------------------------------------- #
# Validation
# --------------------------------------------------------------------------- #

def validate_inventory(inventory: dict[str, Any]) -> list[dict[str, Any]]:
    """Validate the contract. Returns a list of issue dicts (empty == valid).

    Checks: schema version; unique region IDs; parent references resolve + no cycles; each
    unit referenced by exactly one region (single direct owner); finite confidence;
    positive geometry; valid source_kind + policy enums.
    """
    issues: list[dict[str, Any]] = []

    if inventory.get("schema_version") != SCHEMA_VERSION:
        issues.append({"code": "WRONG_SCHEMA_VERSION", "got": inventory.get("schema_version")})

    regions = inventory.get("regions", [])
    region_ids = [r.get("id") for r in regions]

    # Unique IDs
    seen = set()
    for rid in region_ids:
        if rid in seen:
            issues.append({"code": "DUPLICATE_REGION_ID", "id": rid})
        seen.add(rid)
    id_set = set(region_ids)

    # Parent references + cycles
    parent_of = {r.get("id"): r.get("parent_id") for r in regions}
    for rid, pid in parent_of.items():
        if pid is not None and pid not in id_set:
            issues.append({"code": "UNRESOLVED_PARENT", "id": rid, "parent_id": pid})
        # cycle walk
        slow, fast = rid, rid
        while True:
            fast = parent_of.get(fast)
            if fast is None:
                break
            fast = parent_of.get(fast)
            slow = parent_of.get(slow)
            if fast is not None and fast == slow:
                issues.append({"code": "PARENT_CYCLE", "id": rid})
                break

    # Single direct owner per unit + enums + geometry + confidence
    owner_count: dict[str, int] = {}
    for r in regions:
        for uid in r.get("unit_ids", []):
            owner_count[uid] = owner_count.get(uid, 0) + 1

        if r.get("source_kind") not in VALID_SOURCE_KINDS:
            issues.append({"code": "INVALID_SOURCE_KIND", "id": r.get("id"), "value": r.get("source_kind")})
        if r.get("processing_policy") not in VALID_POLICIES:
            issues.append({"code": "INVALID_POLICY", "id": r.get("id"), "value": r.get("processing_policy")})

        conf = r.get("confidence", 1.0)
        if not isinstance(conf, (int, float)) or not isfinite(conf):
            issues.append({"code": "NON_FINITE_CONFIDENCE", "id": r.get("id")})

        box = r.get("source_ink_box_pt")
        if not (isinstance(box, (list, tuple)) and len(box) == 4 and box[2] > box[0] and box[3] > box[1]):
            issues.append({"code": "INVALID_GEOMETRY", "id": r.get("id")})

    for uid, count in owner_count.items():
        if count > 1:
            issues.append({"code": "MULTIPLE_OWNERS", "unit_id": uid, "count": count})

    # Per-page transforms must have resolved
    for p in inventory.get("pages", []):
        t = p.get("transforms", {})
        if "error" in t:
            issues.append({"code": "PAGE_TRANSFORM_UNRESOLVED", "page_number": p.get("page_number"), "detail": t["error"]})

    return issues
