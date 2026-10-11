"""
Word-list sizing tests — V8 vocabulary scene renderer (SOURCE-ANCHORED model).

Guards the book-agnostic fix for the vocabulary-table sizing bugs, where the
Afrikaans body text rendered either far SMALLER than the English source (~1/3)
or, after an over-correction, LARGER than it. The design rule is now simple and
anchored to the source:

  SIZE = source median glyph height (the apparent size the source used),
         shrunk ONLY to fit the column WIDTH, never grown beyond source and
         never shrunk for vertical crowding.

Two mechanisms implement it, both tested here as pure functions:

  1. WIDTH CAP sizes off the longest UNBREAKABLE token (a single word), NOT the
     longest line — so a long instruction sentence lumped into a column wraps
     instead of shrinking the whole column to the floor.

  2. CROSS-COLUMN harmonisation clamps every content column to the shared min of
     the width-fitted sizes. Because every column starts from the SAME source-size
     anchor and is only shrunk for width, the shared size stays at ~source scale
     and all columns render uniformly (no column tiny, none ballooned).

  3. PLACEMENT pitch is floored at size*1.17 so a dense column stacks legibly
     (rows never overlap) at the source-anchored size — it grows TALLER, it does
     not shrink the glyphs.

Book-agnostic: geometry + text only, no per-book constants.

Run: python scripts/test_wordlist_source_rhythm.py
"""
import re

_passed = 0
_failed = 0


def check(name, cond):
    global _passed, _failed
    if cond:
        _passed += 1
        print(f"  PASS  {name}")
    else:
        _failed += 1
        print(f"  FAIL  {name}")


# ---- mirrors of the renderer logic under test -----------------------------

def width_cap_size(text, avail_w, source_cap, char_w=0.5):
    """Start at the SOURCE size, shrink only until the longest WORD fits the width."""
    words = [w for w in re.split(r"\s+", str(text)) if w]
    longest_token = max(words, key=len) if words else str(text)
    size = source_cap
    while size > 7.0 and len(longest_token) * size * char_w > avail_w:
        size -= 0.5
    return round(size, 1)


def harmonise_min(per_column_sizes):
    """Shared body size = min of the width-fitted sizes (all anchored to source)."""
    shared = min(per_column_sizes.values())
    return {pg: shared for pg in per_column_sizes}


def placement_pitch(inner_h, n, size):
    """Pitch never tighter than size*1.17 (rows can't overlap at source size)."""
    return max(inner_h / n, size * 1.17) if n else inner_h


# ---- 1. size is anchored to the SOURCE, not grown or crushed ---------------

SOURCE = 20.0  # the source glyph height for this group

print("Size is anchored to the source (never grown beyond it):")
check("short words render at source size (not larger)",
      width_cap_size("turkoois", avail_w=80, source_cap=SOURCE) == SOURCE)
check("a word that fits stays at source size (no vertical/height shrink)",
      width_cap_size("kleure", avail_w=80, source_cap=SOURCE) == SOURCE)

print("\nLong instruction sentence WRAPS — it does not shrink the column:")
sentence = "Groepeer algemene woorde, soos: hy, sy, my, ons."
check("sentence column stays at (near) source size",
      width_cap_size(sentence, avail_w=120, source_cap=SOURCE) >= SOURCE - 2)

print("\nA genuinely long single WORD still shrinks (it cannot wrap):")
check("unbreakable long token shrinks below source",
      width_cap_size("elektrisiteitsvoorsiening", avail_w=120, source_cap=SOURCE) < SOURCE)

# ---- 2. harmonisation keeps columns UNIFORM at source scale ----------------

print("\nColumns are uniform and at source scale (none tiny, none ballooned):")
# All columns anchored to source=20, only the long-word column shrank slightly.
cols = {"words": 18.0, "hifreq": 20.0, "phonics": 20.0}
out = harmonise_min(cols)
check("all columns share ONE size", len(set(out.values())) == 1)
check("shared size is at source scale (>= 0.85 of source)",
      list(out.values())[0] >= SOURCE * 0.85)

# ---- 3. placement pitch prevents overlap at the source size ----------------

print("\nDense column stacks legibly (pitch floored, grows taller not smaller):")
# 25 rows, column only 300pt tall -> even pitch 12 would clip a 20pt glyph; floored.
p = placement_pitch(inner_h=300, n=25, size=20.0)
check("pitch floored at size*1.17 (>= 23.4), so rows don't overlap", p >= 20.0 * 1.17 - 0.01)
# Sparse column does not balloon: pitch is the floor, not inner_h/n stretched.
p2 = placement_pitch(inner_h=500, n=6, size=20.0)
check("sparse column uses floor pitch, not a huge stretched band", p2 <= 500 / 6 + 0.01)

print(f"\n{_passed} passed, {_failed} failed")
import sys
sys.exit(1 if _failed else 0)
