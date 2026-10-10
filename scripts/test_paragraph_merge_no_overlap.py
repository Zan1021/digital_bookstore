"""Regression test for the My House p2 bio-block VERTICAL OVERLAP bug.

Bug: the scene builder splits a source paragraph into one TextUnit per source LINE under a
shared paragraph_id. The generic placer drew EACH line-unit at its own source y, so a longer
translated upper line flowed down into the lower unit's box and the two collided (the last
word "self." rode ~6pt up into the line above).

Fix: _merge_paragraph_units collapses units sharing a paragraph_id into one logical paragraph
drawn with a single flowing draw_paragraph_text inside the shared layout_container.

This test is book-agnostic + offline (NO OpenAI): it builds the DETERMINISTIC scene for
My House p2, supplies a realistic longer-than-source Afrikaans translation per bio unit, runs
the real render_page_from_scene_generic into a blank page, then asserts the drawn bio lines
step DOWN monotonically with no crammed/overlapping line.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import pymupdf

from document_model import build_document_scene
from pdf_translate_v8 import (
    render_page_from_scene_generic,
    _merge_paragraph_units,
)

_passed = 0
_failed = 0


def check(label, cond):
    global _passed, _failed
    if cond:
        _passed += 1
        print(f"  [PASS] {label}")
    else:
        _failed += 1
        print(f"  [FAIL] {label}")


def _find_my_house():
    base = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    for cand in (
        os.path.join(base, "storage", "app", "public", "books", "pdfs"),
        base,
    ):
        if os.path.isdir(cand):
            for f in os.listdir(cand):
                if "My House" in f and f.lower().endswith(".pdf"):
                    return os.path.join(cand, f)
    return None


# ---------------------------------------------------------------------------
# UNIT: _merge_paragraph_units collapses a paragraph group, keeps solos alone.
# ---------------------------------------------------------------------------
print("Unit: _merge_paragraph_units grouping")


class _FakeUnit:
    def __init__(self, uid, bbox, pid, ro, role="copyright", source_text="x"):
        self.id = uid
        self.bbox = bbox
        self.paragraph_id = pid
        self.reading_order = ro
        self.semantic_role = role
        self.align_h = "left"
        self.safe_box = (320, 536, 480, 628)
        self.layout_container = (320, 536, 480, 628)
        self.translation_policy = "translate"
        self.source_text = source_text


# Bio paragraph: a head line left open by a comma, a TALL pre-merged prose bulk, and a
# trailing single-word wrap fragment — the exact shape _split_into_prose_runs must merge.
u1 = _FakeUnit("a", (321, 537, 476, 547), "para1", 1,
               source_text="The name and character, Kolulu Taktaki,")
u2 = _FakeUnit("b", (321, 546, 476, 619), "para1", 2,
               source_text="were invented by Simon, a six year-old boy, while in the "
                           "foundation phase. Some of the books are also written by his "
                           "sister. Because this kind of imagination")
u3 = _FakeUnit("c", (321, 618, 362, 628), "para1", 3, source_text="themselves.")
solo = _FakeUnit("z", (60, 100, 200, 115), None, 4, source_text="Alone")
resolved = {"a": "Eerste reel,", "b": "Middel reels hier wat lank genoeg is om te vloei",
            "c": "self.", "z": "Alleen"}

merged = _merge_paragraph_units([u1, u2, u3, solo], resolved)
# One merged paragraph unit + the solo = 2 placeables.
check("paragraph group collapses to a single unit (+ solo kept)", len(merged) == 2)
para = [m for m in merged if getattr(m, "paragraph_id", None) == "para1"]
check("exactly one merged paragraph unit produced", len(para) == 1)
if para:
    p = para[0]
    check("merged text concatenates members in reading order",
          resolved[p.id] == "Eerste reel, Middel reels hier wat lank genoeg is om te vloei self.")
    check("merged bbox is the UNION of member boxes",
          p.bbox == (321, 537, 476, 628))
    check("redaction_bboxes lists all 3 member source boxes",
          len(p.redaction_bboxes) == 3)
check("solo unit (no paragraph_id) left untouched",
      any(getattr(m, "id", None) == "z" for m in merged))


# ---------------------------------------------------------------------------
# NEGATIVE: a stack of distinct single-line FIELDS sharing a paragraph_id must NOT
# merge (publisher / PO box / ISBN / email) — the p2 copyright regression.
# ---------------------------------------------------------------------------
print("\nUnit: distinct metadata fields are NOT merged")
f1 = _FakeUnit("f1", (321, 476, 476, 487), "paraX", 1,
               source_text="Published by Mthombothi Studios (Pty) Ltd")
f2 = _FakeUnit("f2", (321, 488, 476, 499), "paraX", 2,
               source_text="PO Box 40011, Aspen Hills 2059, Johannesburg")
f3 = _FakeUnit("f3", (321, 500, 476, 511), "paraX", 3, source_text="South Africa")
f4 = _FakeUnit("f4", (321, 512, 476, 523), "paraX", 4, source_text="ISBN 978-1920519209")
f5 = _FakeUnit("f5", (321, 524, 476, 535), "paraX", 5, source_text="www.themba.net")
resolved_fields = {"f1": "Gepubliseer deur Mthombothi Studios (Edms) Bpk",
                   "f2": "Posbus 40011, Aspen Hills 2059, Johannesburg",
                   "f3": "Suid-Afrika", "f4": "ISBN 978-1920519209", "f5": "www.themba.net"}
merged_fields = _merge_paragraph_units([f1, f2, f3, f4, f5], resolved_fields)
check("5 distinct metadata fields stay 5 separate units (no over-merge)",
      len(merged_fields) == 5)
check("no merged (multi-member) unit produced for the field stack",
      all((getattr(m, "redaction_bboxes", None) is None or
           len(getattr(m, "redaction_bboxes")) == 1) for m in merged_fields))


# ---------------------------------------------------------------------------
# INTEGRATION: real scene + longer translation -> no overlapping bio lines.
# ---------------------------------------------------------------------------
print("\nIntegration: My House p2 bio renders with no overlap")

src = _find_my_house()
if not src:
    print("  [SKIP] My House source PDF not present")
else:
    scene = build_document_scene(src)
    page_scene = scene.get_page(2)

    # Build a translation contract keyed by stable id. Afrikaans is LONGER than English
    # (the real-world trigger): give the bulk unit a long paragraph so the block must
    # shrink/flow to fit — exactly the condition that used to cram "self.".
    id_to_translation = {}
    # The bio paragraph is the units sharing paragraph_id "p02-para03" (s0015/s0016/s0024).
    # Identify it generically: the copyright-page paragraph group with the MOST members
    # whose combined source is the longest prose block (the bio). Then supply a longer
    # Afrikaans translation per member so the block must flow/shrink to fit.
    from collections import defaultdict
    groups = defaultdict(list)
    for u in page_scene.text_units:
        pid = getattr(u, "paragraph_id", None)
        if pid:
            groups[pid].append(u)
    # Pick the group whose head line starts the bio.
    bio_pid = None
    for pid, members in groups.items():
        if any((m.source_text or "").startswith("The name and character") for m in members):
            bio_pid = pid
            break
    check("found the bio paragraph group", bio_pid is not None)

    bio_units = sorted(groups.get(bio_pid, []),
                       key=lambda u: (getattr(u, "reading_order", 0) or 0,
                                      round(u.bbox[1]), u.bbox[0]))
    _af = {
        0: "Die naam en karakter, Kolulu Taktaki,",
        1: ("is uitgedink deur Simon, 'n sesjarige seun, terwyl hy in die "
            "grondslagfase was. Sommige van die boeke is ook geskryf deur sy "
            "suster. Omdat hierdie soort verbeelding goed verstaan word deur "
            "ander kinders van hul ouderdom, kan die boeke 'n inspirasie wees "
            "vir alle leerders in die grondslagfase en hulle entoesiasme gee "
            "vir lees en skryf"),
        2: "self.",
    }
    for i, u in enumerate(bio_units):
        id_to_translation[u.id] = _af.get(i, u.source_text)

    check("bio paragraph has 3 member units", len(bio_units) == 3)

    # Render into a fresh page sized to the source page.
    from pdf_translate_v8 import _extract_spans_for_manifest
    srcdoc = pymupdf.open(src)
    src_page = srcdoc[1]  # p2 (0-based index 1)
    page_spans = _extract_spans_for_manifest(src_page, 2)

    doc = pymupdf.open()
    page = doc.new_page(width=page_scene.width_pt, height=page_scene.height_pt)
    report = {"spans_replaced": 0}
    fonts_dir = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))),
                             "storage", "app", "fonts")

    render_page_from_scene_generic(
        page, page_scene, id_to_translation, fonts_dir, 2, report,
        page_spans=page_spans, typography_policy=None, language="af")

    # Probe the drawn BIO band (right column, below y=535 — the bio paragraph region).
    spans = []
    for b in page.get_text("dict")["blocks"]:
        for ln in b.get("lines", []):
            for s in ln.get("spans", []):
                if s["bbox"][0] > 300 and s["bbox"][1] >= 535 and s["text"].strip():
                    spans.append((s["bbox"][1], s["bbox"][3], s["size"], s["text"]))
    spans.sort()

    print(f"    drew {len(spans)} right-column lines")
    for y0, y1, size, text in spans:
        print(f"      y0={y0:7.2f} size={size:4.1f} {text[:46]!r}")

    check("bio rendered as multiple lines", len(spans) >= 5)

    # Compute per-line steps; the paragraph must step DOWN consistently. The old bug made
    # the final step ~3.75pt vs a ~9.1pt rhythm (a backwards cram). Assert every step is at
    # least HALF the median step (no crammed/overlapping line).
    y0s = [s[0] for s in spans]
    steps = [b - a for a, b in zip(y0s, y0s[1:])]
    check("all lines step strictly downward (monotonic y)",
          all(st > 0 for st in steps))
    if steps:
        ordered = sorted(steps)
        median = ordered[len(ordered) // 2]
        min_step = min(steps)
        print(f"    median step={median:.2f}  min step={min_step:.2f}")
        check("no crammed line (min step >= 0.5 x median step)",
              min_step >= 0.5 * median)
        # The last word must NOT ride up into the previous line.
        check("final line does not overlap the previous (last step ~ median)",
              steps[-1] >= 0.6 * median)


print(f"\n{_passed} passed, {_failed} failed")
sys.exit(1 if _failed else 0)
