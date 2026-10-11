"""
Phonics fragment re-join tests — scene-graph continuation merge (2026-10-11).

Bug: PDF extraction split a phonics row so the lone pattern token ("- gg") and its
example word ("eier") landed on separate spans. The conservative continuation-merge
(which needs BOTH lines multi-word, to protect single-word vocab columns) refused to
rejoin them, so a patternless "- gg" and a stray "eier" leaked onto the page instead of
being regenerated as a proper phonics row.

Fix: a narrow, additive case in _continues_into — a bare pattern token (optional leading
dash + 1-4 letters, nothing else) followed by a short example list (<=3 words) is rejoined
into one "<pattern> <examples>" span. Single-word vocabulary columns stay untouched.

Hermetic: fabricates spans with explicit bboxes; no PDF, no network.

Run: python scripts/test_phonics_fragment_merge.py
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from document_model import _merge_continuation_spans

_passed = _failed = 0


def check(name, cond):
    global _passed, _failed
    if cond:
        _passed += 1
        print(f"  PASS  {name}")
    else:
        _failed += 1
        print(f"  FAIL  {name}")


def span(text, x, y, h=12.0, w=60.0):
    return {
        "text_stripped": text,
        "origin": (x, y + h),
        "bbox": [x, y, x + w, y + h],
        "is_page_number": False,
    }


def texts(spans):
    return [s["text_stripped"] for s in spans]


print("Phonics fragment re-join:")

# A torn phonics row: "- gg" at y=100, "eier" directly below at y=113 (tight leading, same column x).
frag = [span("- gg", 50, 100), span("eier", 50, 113)]
out = _merge_continuation_spans(frag, "vocabulary")
check("'- gg' + 'eier' merge into one span", len(out) == 1 and "gg" in out[0]["text_stripped"] and "eier" in out[0]["text_stripped"])

# A pattern then a comma-list of examples: "oe" + "boek, koek".
frag2 = [span("oe", 50, 100), span("boek, koek", 50, 113, w=90)]
out2 = _merge_continuation_spans(frag2, "vocabulary")
check("'oe' + 'boek, koek' merge into one span", len(out2) == 1 and "oe boek, koek" in out2[0]["text_stripped"].replace("  ", " "))

print("\nMUST NOT over-merge ordinary single-word vocabulary column:")
# Two stacked single vocabulary words in a column must remain two separate entries.
vocab = [span("hoor", 50, 100), span("sien", 50, 113), span("klanke", 50, 126, w=80)]
outv = _merge_continuation_spans(vocab, "vocabulary")
check("single-word vocab column stays unmerged (3 entries)", len(outv) == 3)

print("\nMUST NOT merge a complete phonics row with the next complete row:")
# "- ei reis, klein" is already complete; the next complete row must not fold into it.
two = [span("- ei reis, klein", 50, 100, w=120), span("- ou koud, hout", 50, 113, w=120)]
outt = _merge_continuation_spans(two, "vocabulary")
check("two complete phonics rows stay separate", len(outt) == 2)

print("=" * 50)
print(f"RESULTS: {_passed} passed, {_failed} failed")
print("=" * 50)
sys.exit(1 if _failed else 0)
