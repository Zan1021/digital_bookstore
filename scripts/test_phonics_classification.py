"""
Phonics classification tests — V8 scene-graph manifest builder.

Verifies that phonics / sound-pattern exercise spans on a vocabulary page are
classified with semantic_role 'phonics' and translation_policy
'educational_adaptation' (so the model REGENERATES an equivalent target-language
exercise instead of translating the English pattern literally), while ordinary
vocabulary words remain 'word_list_item' with 'translate'.

Book-agnostic + source-language agnostic: detection is by content shape only.

Run: python scripts/test_phonics_classification.py
"""
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from document_model import _is_phonics_span, _classify_span_role, _get_region_key

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


def _span(text, x=100.0, y=100.0):
    return {
        "text_stripped": text,
        "origin": (x, y),
        "bbox": [x, y, x + 50, y + 12],
        "is_page_number": False,
    }


print("Phonics detection — true positives (the exact page-15 defect rows):")
for t in ["oa  -  f l-oa-t", "ai   -  plain, rain", "ay  -  say, play",
          "str  -  str-eam", "wh-en, wh-ere",
          "Recognise wh-  at the beginning of words:",
          "Herken wh- aan die begin van woorde:"]:
    check(f"phonics: {t!r}", _is_phonics_span(t))

print("\nPhonics detection — true negatives (ordinary vocabulary):")
for t in ["house", "meanwhile", "staircase", "always", "when", "where",
          "mother-in-law", "WOORDE"]:
    check(f"not phonics: {t!r}", not _is_phonics_span(t))

print("\nRole classification on a vocabulary page:")
check("'str - str-eam' -> phonics",
      _classify_span_role(_span("str  -  str-eam"), "vocabulary") == "phonics")
check("'wh-en, wh-ere' -> phonics",
      _classify_span_role(_span("wh-en, wh-ere"), "vocabulary") == "phonics")
check("'Recognise wh- ...' -> phonics",
      _classify_span_role(_span("Recognise wh-  at the beginning of words:"),
                          "vocabulary") == "phonics")
check("'house' -> word_list_item",
      _classify_span_role(_span("house"), "vocabulary") == "word_list_item")
check("'WOORDE' -> heading",
      _classify_span_role(_span("WOORDE"), "vocabulary") == "heading")

print("\nRegion grouping — phonics rows share ONE region regardless of x-position:")
keys = {
    _get_region_key(_span("oa - float", x=60), "vocabulary", 15),
    _get_region_key(_span("str - stream", x=500), "vocabulary", 15),
    _get_region_key(_span("wh-en, wh-ere", x=980), "vocabulary", 15),
}
check("all phonics spans map to a single 'phonics' region", keys == {"phonics"})
check("ordinary word still groups by column",
      _get_region_key(_span("house", x=60), "vocabulary", 15).startswith("col-"))

print(f"\n{_passed} passed, {_failed} failed")
sys.exit(1 if _failed else 0)
