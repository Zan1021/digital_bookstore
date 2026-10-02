"""
world-class-render-engine spec, Phase 2 — regression test for FIT BEFORE ERASE on the
generic (non-table) render path.

Invariant proven: when a translated element does NOT fit its resolved container, the
engine must NOT erase the source glyphs and must NOT stamp overflowing text — it leaves
the SOURCE in place and flags the element as overflow (fail closed, spec Req 3.2).
Conversely, a translation that fits is placed normally.

Book-agnostic: uses the real corpus page if present, else SKIPS cleanly. No per-book
constants — the too-long string is synthetic and the target element is found by role.

Run: python scripts/test_fit_before_erase.py
"""
import copy
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import pymupdf
from document_model import build_document_scene
from pdf_translate_v8 import replace_text_in_pdf

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FONTS = os.path.join(BASE, "storage", "app", "fonts")
OUT = os.path.join(BASE, "storage", "app", "temp")
# Any copyright/imprint-bearing corpus PDF works; prefer the My House fixture.
CANDIDATES = [
    os.path.join(os.path.dirname(BASE), "Book_Pdfs", "Mthombothi Studios Kolulu Taktaki",
                 "Kolulu S2 BK4 Engl - My House.pdf"),
    os.path.join(os.path.dirname(BASE), "Kolulu Engl Series 3 - 2 - A Fun Place.pdf"),
]

_passed = _failed = 0


def check(name, cond):
    global _passed, _failed
    if cond:
        _passed += 1
        print(f"  [OK] {name}")
    else:
        _failed += 1
        print(f"  [FAIL] {name}")


def _contract_payload(scene):
    items = scene.to_translation_request("af")["items"]
    skeys = ("source_text", "semantic_role", "cell_box", "align_h", "align_v",
             "peer_group_id", "column_span", "is_merged")
    payload = []
    for order, it in enumerate(items):
        e = {"id": it["id"], "page_number": it.get("page_number"), "reading_order": order,
             "translation": (it.get("source_text") or "x")}
        for k in skeys:
            if k in it:
                e[k] = it[k]
        payload.append(e)
    return payload


src = next((p for p in CANDIDATES if os.path.isfile(p)), None)
if not src:
    print("SKIP: no corpus PDF available")
    sys.exit(0)

os.makedirs(OUT, exist_ok=True)
scene = build_document_scene(src)
payload = _contract_payload(scene)

# Find the most VERTICALLY CONSTRAINED prose element: in the renderer each unit is
# anchored at its OWN ink top and flows down to the container bottom, so the real room is
# (container_bottom - unit_top). A line near the bottom of its column has the least room
# and is the honest overflow stress case. We mirror that computation from the scene.
scene2 = build_document_scene(src)
room_by_id = {}
for pg in scene2.pages:
    for u in pg.text_units:
        b = getattr(u, "safe_box", None) or getattr(u, "layout_container", None)
        if b and u.translation_policy in ("translate", "educational_adaptation"):
            room = b[3] - u.bbox[1]          # container bottom minus the unit's own top
            room_by_id[u.id] = (room, pg.page_number)

target, tpage, best_room = None, None, 1e9
for it in payload:
    st = (it.get("source_text") or "")
    if len(st) <= 20:
        continue
    rr = room_by_id.get(it["id"])
    if not rr:
        continue
    room, pn = rr
    # Scope to pages the GENERIC renderer actually handles (copyright/generic). Vocabulary
    # and back-cover/title-list pages have their own renderers; fit-before-erase here is
    # the generic path's contract. We detect a generic page as one whose classify_page is
    # 'copyright' (the My House p2 case) via the scene page_type.
    page_type = next((p.page_type for p in scene2.pages if p.page_number == pn), None)
    if page_type != "copyright":
        continue
    if room < best_room:
        best_room, target, tpage = room, it["id"], pn

check("found a vertically-constrained prose element to overflow", target is not None)
if target is None:
    print(f"RESULTS: {_passed} passed, {_failed} failed")
    sys.exit(1 if _failed else 0)

# Capture that element's SOURCE text so we can assert it survives.
src_text = next((it["source_text"] for it in payload if it["id"] == target), "")
src_probe = (src_text or "")[:18]

long_text = ("Hierdie absurd lang vertaling kan onmoontlik in sy klein houer pas en herhaal "
             "homself oor en oor en oor en oor en oor en oor en oor en oor en oor en oor. ") * 4
over = copy.deepcopy(payload)
for it in over:
    if it["id"] == target:
        it["translation"] = long_text

rep = replace_text_in_pdf(src, os.path.join(OUT, "_fbe_over.pdf"), {"items": over}, FONTS)
sg = rep.get("scene_generic", {}).get(str(tpage), {})
check("overflowing element flagged as overflow", target in (sg.get("overflow") or []))

doc = pymupdf.open(os.path.join(OUT, "_fbe_over.pdf"))
page_txt = doc[tpage - 1].get_text()
doc.close()
check("source text NOT erased when translation does not fit",
      bool(src_probe) and src_probe in page_txt)

# Control: the same page rendered with the real (fitting) translation flags NO overflow
# for that element.
rep2 = replace_text_in_pdf(src, os.path.join(OUT, "_fbe_fit.pdf"), {"items": payload}, FONTS)
sg2 = rep2.get("scene_generic", {}).get(str(tpage), {})
check("fitting translation is not flagged overflow", target not in (sg2.get("overflow") or []))

print("=" * 60)
print(f"RESULTS: {_passed} passed, {_failed} failed")
print("=" * 60)
sys.exit(1 if _failed else 0)
