"""
Typography hierarchy + minimum-readability tests (brief §10.1/§10.2).

Drives render_gate.validate_typography with synthetic region-graph scene records
to prove each rule fires (and does not false-positive on legitimately varying prose).

Book-agnostic. Run: python scripts/test_typography.py
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from render_gate import validate_typography
from readability_policy import min_readable_size

_passed = 0
_failed = 0


def check(name, cond):
    global _passed, _failed
    if cond:
        _passed += 1
        print(f"  [OK] {name}")
    else:
        _failed += 1
        print(f"  [FAIL] {name}")


def _scene(regions, units):
    return {"scene": {"1": {"page_type": "vocabulary", "regions": regions, "units": units}}}


def _region(rid, rtype):
    return {"region_id": rid, "region_type": rtype, "semantic_type": rtype}


def _unit(uid, rid, size, role=None):
    return {"id": uid, "region_id": rid, "nominal_size_pt": size, "role": role}


def test_min_readable_size_policy():
    check("children's book floor stricter than novel",
          min_readable_size("childrens_book") > min_readable_size("novel"))
    check("large_print market wins over document type",
          min_readable_size("novel", market="large_print") >= 14.0)


def test_below_min_size_fails():
    rep = _scene([_region("r1", "paragraph")], [_unit("u1", "r1", 5.0)])
    res = validate_typography(rep, document_type="childrens_book")
    check("text below min readable size fails",
          not res["ok"] and any(f["constraint"] == "minReadableSize"
                                 for f in res["pages"][1]["failures"]))


def test_heading_not_bigger_than_body_fails():
    regions = [_region("h", "heading"), _region("b", "paragraph")]
    units = [_unit("u1", "h", 12.0), _unit("u2", "b", 14.0)]
    res = validate_typography(_scene(regions, units))
    check("heading <= body fails hierarchy",
          not res["ok"] and any(f["constraint"] == "typographyHierarchy"
                                 for f in res["pages"][1]["failures"]))


def test_heading_bigger_than_body_passes():
    regions = [_region("h", "heading"), _region("b", "paragraph")]
    units = [_unit("u1", "h", 20.0), _unit("u2", "b", 12.0)]
    res = validate_typography(_scene(regions, units))
    check("heading > body passes hierarchy", res["ok"])


def test_table_header_smaller_than_cell_fails():
    regions = [_region("th", "table_header"), _region("tc", "table_cell")]
    units = [_unit("u1", "th", 10.0), _unit("u2", "tc", 14.0)]
    res = validate_typography(_scene(regions, units))
    check("table header < cell fails",
          not res["ok"] and any(f["constraint"] == "tableHeaderHierarchy"
                                 for f in res["pages"][1]["failures"]))


def test_peer_variance_uniform_role_fails():
    regions = [_region("c1", "word_item"), _region("c2", "word_item")]
    units = [_unit("u1", "c1", 10.0), _unit("u2", "c2", 14.0)]  # >15% variance
    res = validate_typography(_scene(regions, units))
    check("uniform peer role variance fails",
          not res["ok"] and any(f["constraint"] == "peerRegionVariance"
                                 for f in res["pages"][1]["failures"]))


def test_prose_variance_does_not_false_positive():
    regions = [_region("p1", "story_prose"), _region("p2", "story_prose")]
    units = [_unit("u1", "p1", 30.0), _unit("u2", "p2", 39.0)]  # source design varies
    res = validate_typography(_scene(regions, units))
    check("prose size variance does NOT fail (excluded)", res["ok"])


if __name__ == "__main__":
    print("=" * 60)
    print("TYPOGRAPHY HIERARCHY + READABILITY TESTS (brief §10.1/§10.2)")
    print("=" * 60)
    test_min_readable_size_policy()
    test_below_min_size_fails()
    test_heading_not_bigger_than_body_fails()
    test_heading_bigger_than_body_passes()
    test_table_header_smaller_than_cell_fails()
    test_peer_variance_uniform_role_fails()
    test_prose_variance_does_not_false_positive()
    print("=" * 60)
    print(f"RESULTS: {_passed} passed, {_failed} failed")
    print("=" * 60)
    sys.exit(1 if _failed else 0)
