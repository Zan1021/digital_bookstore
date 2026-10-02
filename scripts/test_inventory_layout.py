"""
Tests for the inventory-layout-2 contract (unified-rendering-and-testing Req 5 / B2).
Run: python scripts/test_inventory_layout.py
"""

import sys
import os

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from inventory_layout import (  # noqa: E402
    SCHEMA_VERSION,
    build_page_transforms,
    source_pt_to_render_px,
    render_px_to_source_pt,
    default_source_kind,
    serialize_inventory,
    validate_inventory,
    InventoryValidationError,
)
from document_model import DocumentScene, PageScene, Region, TextUnit  # noqa: E402


# ---- transforms round-trip -------------------------------------------------

def _assert_roundtrip(point, transforms, tol=1e-6):
    px = source_pt_to_render_px(point, transforms)
    back = render_px_to_source_pt(px, transforms)
    assert abs(back[0] - point[0]) < tol and abs(back[1] - point[1]) < tol, (point, back)


def test_roundtrip_no_rotation_no_crop_offset():
    t = build_page_transforms((0, 0, 510, 722), 0, render_ppi=150)
    _assert_roundtrip((70, 100), t)
    _assert_roundtrip((430, 160), t)


def test_roundtrip_with_crop_offset():
    # CropBox offset (origin not at 0,0) must round-trip.
    t = build_page_transforms((20, 30, 530, 752), 0, render_ppi=150)
    _assert_roundtrip((70, 100), t)


def test_roundtrip_rotation_90():
    t = build_page_transforms((0, 0, 510, 722), 90, render_ppi=96)
    _assert_roundtrip((70, 100), t)
    _assert_roundtrip((500, 700), t)


def test_roundtrip_rotation_270_with_offset():
    t = build_page_transforms((10, 10, 420, 600), 270, render_ppi=120)
    _assert_roundtrip((50, 80), t)


def test_mixed_page_sizes_each_have_own_scale():
    a4 = build_page_transforms((0, 0, 595, 842), 0, 150)
    letter = build_page_transforms((0, 0, 612, 792), 0, 150)
    assert a4["page_size_pt"] != letter["page_size_pt"]
    _assert_roundtrip((100, 100), a4)
    _assert_roundtrip((100, 100), letter)


def test_degenerate_crop_box_fails_closed():
    try:
        build_page_transforms((0, 0, 0, 100), 0)
        assert False, "should have raised"
    except InventoryValidationError as e:
        assert "INVALID_CROP_BOX" in str(e)


def test_unsupported_rotation_fails_closed():
    try:
        build_page_transforms((0, 0, 100, 100), 45)
        assert False
    except InventoryValidationError as e:
        assert "UNSUPPORTED_ROTATION" in str(e)


# ---- source_kind defaulting ------------------------------------------------

def test_default_source_kind():
    assert default_source_kind("native") == "native_text"
    assert default_source_kind("ocr") == "raster_text"
    assert default_source_kind("native", explicit="outlined_vector") == "outlined_vector"
    assert default_source_kind("native", explicit="garbage") == "native_text"


# ---- serialize + validate --------------------------------------------------

def _scene():
    unit = TextUnit(id="u1", source_text="Hi", bbox=(70, 100, 430, 160), baseline=(70, 150),
                    style_id="s1", reading_order=0, semantic_role="paragraph",
                    parent_region_id="r1")
    region = Region(id="r1", region_type="paragraph", bbox=(64, 90, 470, 190),
                    child_ids=["u1"], translation_policy="translate")
    region.source_kind = "native_text"
    page = PageScene(page_number=1, width_pt=510, height_pt=722,
                     media_box=(0, 0, 510, 722), crop_box=(0, 0, 510, 722),
                     regions=[region], text_units=[unit], page_type="story")
    return DocumentScene(document_id="d1", source_path="x.pdf", source_sha256="abc",
                         total_pages=1, pages=[page])


def test_serialize_shape():
    inv = serialize_inventory(_scene())
    assert inv["schema_version"] == SCHEMA_VERSION
    assert inv["source_hash"] == "abc"
    assert len(inv["pages"]) == 1
    assert inv["pages"][0]["region_ids"] == ["r1"]
    r = inv["regions"][0]
    assert r["source_kind"] == "native_text"
    assert r["processing_policy"] == "translate"
    assert r["unit_ids"] == ["u1"]
    assert "transforms" in inv["pages"][0]


def test_valid_inventory_passes_validation():
    assert validate_inventory(serialize_inventory(_scene())) == []


def test_duplicate_region_id_caught():
    inv = serialize_inventory(_scene())
    inv["regions"].append(dict(inv["regions"][0]))  # duplicate r1
    codes = [i["code"] for i in validate_inventory(inv)]
    assert "DUPLICATE_REGION_ID" in codes
    assert "MULTIPLE_OWNERS" in codes  # u1 now owned twice


def test_unresolved_parent_caught():
    inv = serialize_inventory(_scene())
    inv["regions"][0]["parent_id"] = "ghost"
    codes = [i["code"] for i in validate_inventory(inv)]
    assert "UNRESOLVED_PARENT" in codes


def test_invalid_geometry_caught():
    inv = serialize_inventory(_scene())
    inv["regions"][0]["source_ink_box_pt"] = [10, 10, 5, 5]  # x1<x0
    codes = [i["code"] for i in validate_inventory(inv)]
    assert "INVALID_GEOMETRY" in codes


def test_invalid_enums_caught():
    inv = serialize_inventory(_scene())
    inv["regions"][0]["source_kind"] = "bogus"
    inv["regions"][0]["processing_policy"] = "bogus"
    codes = [i["code"] for i in validate_inventory(inv)]
    assert "INVALID_SOURCE_KIND" in codes
    assert "INVALID_POLICY" in codes


def test_parent_cycle_caught():
    inv = serialize_inventory(_scene())
    # make r1 its own parent → cycle
    inv["regions"][0]["parent_id"] = "r1"
    codes = [i["code"] for i in validate_inventory(inv)]
    assert "PARENT_CYCLE" in codes


if __name__ == "__main__":
    import traceback
    fns = [v for k, v in sorted(globals().items()) if k.startswith("test_") and callable(v)]
    passed = 0
    for fn in fns:
        try:
            fn()
            passed += 1
        except Exception:
            print(f"FAIL: {fn.__name__}")
            traceback.print_exc()
    print(f"{passed}/{len(fns)} passed")
    sys.exit(0 if passed == len(fns) else 1)
