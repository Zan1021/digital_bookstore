"""Regression test for the COVER TITLE per-letter split bug.

Bug: a decorative cover title set as ONE SPAN PER LETTER ("C","o","l","o","u","r","s")
became one translation UNIT PER LETTER. A lone letter has no translatable meaning, so the
translator returned noise and the title rendered mangled ("K l  es").

Fix: _merge_display_glyph_runs reassembles a horizontal run of same-baseline, same-size
single-letter display spans into ONE word span (title-bearing pages only), so it translates
+ places as a single word. Multi-char word spans and body text are left untouched.

Offline, book-agnostic (no OpenAI). Uses synthetic spans + the real Kolulu covers.
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from document_model import _merge_display_glyph_runs, build_document_scene

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


def _span(text, x0, x1, y0=100.0, y1=160.0, size=60.0, font="AdLibBT-Regular"):
    return {
        "text": text, "text_stripped": text,
        "bbox": [x0, y0, x1, y1], "font_size": size,
        "font_name": font, "is_page_number": False,
        "origin": [x0, y1],
    }


# ---------------------------------------------------------------------------
# UNIT: abutting single letters merge into one word; gaps become spaces.
# ---------------------------------------------------------------------------
print("Unit: _merge_display_glyph_runs")

# "Colours" as 7 abutting single-letter spans (x1 == next x0).
letters = "Colours"
xs = [144.0]
for _ in letters:
    xs.append(xs[-1] + 36.0)
spans = [_span(ch, xs[i], xs[i + 1]) for i, ch in enumerate(letters)]
merged = _merge_display_glyph_runs(spans, "cover")
titles = [s for s in merged if s["font_size"] >= 24]
check("7 abutting letters merge to ONE span", len(titles) == 1)
check("merged span reads 'Colours'", titles and titles[0]["text_stripped"] == "Colours")

# "My House" — two words with a wider gap in the middle → a SPACE is inserted.
my = "My"
house = "House"
sp = []
x = 100.0
for ch in my:
    sp.append(_span(ch, x, x + 34.0)); x += 34.0
x += 42.0  # word gap (< 1.2 * glyph height = 72, so a space, not a break)
for ch in house:
    sp.append(_span(ch, x, x + 34.0)); x += 34.0
merged2 = _merge_display_glyph_runs(sp, "cover")
t2 = [s for s in merged2 if s["font_size"] >= 24]
check("two words with a gap merge to one span with a space",
      len(t2) == 1 and t2[0]["text_stripped"] == "My House")

# NEGATIVE: a row of already-whole words is NOT merged.
words = [_span("Hello", 100, 200), _span("World", 210, 320)]
merged3 = _merge_display_glyph_runs(words, "cover")
check("whole-word spans are left untouched (no fragment present)",
      len(merged3) == 2)

# NEGATIVE: body-sized letters are not merged (only display >= 24pt).
small = [_span(ch, 100 + i * 8, 108 + i * 8, size=10.0) for i, ch in enumerate("abc")]
merged4 = _merge_display_glyph_runs(small, "cover")
check("body-sized fragments are not merged", len(merged4) == len(small))

# NEGATIVE: on a non-title page type, the pass is a no-op.
merged5 = _merge_display_glyph_runs(spans, "story")
check("no-op on non-title page types", len(merged5) == len(spans))


# ---------------------------------------------------------------------------
# INTEGRATION: real Kolulu covers → one title unit each; back-cover list intact.
# ---------------------------------------------------------------------------
print("\nIntegration: real covers reassemble, lists stay split")

base = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
pdir = os.path.join(base, "storage", "app", "public", "books", "pdfs")
expected = {"My House": "My House", "My Senses": "My Senses", "Colours": "Colours"}
found_any = False
if os.path.isdir(pdir):
    for key, want in expected.items():
        src = next((os.path.join(pdir, f) for f in os.listdir(pdir)
                    if key in f and f.lower().endswith(".pdf")), None)
        if not src:
            print(f"  [SKIP] {key} source not present")
            continue
        found_any = True
        scene = build_document_scene(src)
        p1 = scene.get_page(1)
        subs = [u for u in p1.text_units if u.semantic_role == "subtitle"
                and any(c.isalnum() for c in (u.source_text or ""))]
        check(f"{key}: cover title is a single '{want}' unit",
              any((u.source_text or "").strip() == want for u in subs))
        # Back cover list items must remain separate numbered entries (not merged).
        last = scene.get_page(len(scene.pages))
        items = [u for u in last.text_units if (u.source_text or "").strip().startswith(("1 -", "2 -", "3 -"))]
        check(f"{key}: back-cover list entries stay separate",
              len(items) >= 3)

if not found_any:
    print("  [SKIP] no cover PDFs available for integration check")


print(f"\n{_passed} passed, {_failed} failed")
sys.exit(1 if _failed else 0)
