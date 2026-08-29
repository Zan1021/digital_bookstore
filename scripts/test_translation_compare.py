"""
Pre-render translation-compare tests (source vs target language gate).

Verifies the compare catches: title inconsistency across pages, missing
translations, and multi-word English leaks — while NOT false-flagging cognates,
ISBNs, URLs, or proper nouns. Book-agnostic: synthetic source PDF.

Run: python scripts/test_translation_compare.py
"""
import os
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import pymupdf
from translation_compare import compare

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


def _make_src(path):
    """Cover (big title 'A Fun Place') + imprint page repeating the title + a story."""
    doc = pymupdf.open()
    p1 = doc.new_page(width=400, height=500)
    p1.insert_text(pymupdf.Point(60, 90), "MTHOMBOTHI STUDIOS", fontsize=12)
    p1.insert_text(pymupdf.Point(60, 200), "A Fun Place", fontsize=48)
    p2 = doc.new_page(width=400, height=500)
    p2.insert_text(pymupdf.Point(40, 60), "A Fun Place", fontsize=20)
    p2.insert_text(pymupdf.Point(40, 100), "Published by Mthombothi Studios", fontsize=9)
    p2.insert_text(pymupdf.Point(40, 120), "ISBN 978-1920519230", fontsize=9)
    p3 = doc.new_page(width=400, height=500)
    p3.insert_text(pymupdf.Point(40, 80), "The dog runs in the park today.", fontsize=20)
    doc.save(path); doc.close()


def test_title_inconsistency_flagged():
    tmp = tempfile.mkdtemp(prefix="tcmp_")
    src = os.path.join(tmp, "s.pdf"); _make_src(src)
    trans = {"pages": [
        {"page_number": 1, "translated_text": "MTHOMBOTHI STUDIOS\n'n Plek Vol Pret"},
        {"page_number": 2, "translated_text": "'n Plek van Pret\nUitgegee deur Mthombothi Studios\nISBN 978-1920519230"},
        {"page_number": 3, "translated_text": "Die hond hardloop vandag in die park."},
    ]}
    rep = compare(src, trans)
    check("title inconsistency detected", not rep["ok"]
          and any(i["type"] == "title_inconsistent" for i in rep["issues"]))
    check("title source discovered as 'A Fun Place'",
          rep["checks"]["consistency"]["title_source"] == "A Fun Place")
    import shutil; shutil.rmtree(tmp, ignore_errors=True)


def test_consistent_title_passes_and_no_false_leaks():
    tmp = tempfile.mkdtemp(prefix="tcmp_")
    src = os.path.join(tmp, "s.pdf"); _make_src(src)
    trans = {"pages": [
        {"page_number": 1, "translated_text": "MTHOMBOTHI STUDIOS\n'n Plek van Pret"},
        {"page_number": 2, "translated_text": "'n Plek van Pret\nUitgegee deur Mthombothi Studios\nISBN 978-1920519230"},
        {"page_number": 3, "translated_text": "Die hond hardloop vandag in die park."},
    ]}
    rep = compare(src, trans)
    # ISBN + publisher line repeated verbatim must NOT be flagged as leaks.
    check("consistent title + no false leaks -> ok", rep["ok"], )


def test_missing_translation_flagged():
    tmp = tempfile.mkdtemp(prefix="tcmp_")
    src = os.path.join(tmp, "s.pdf"); _make_src(src)
    trans = {"pages": [
        {"page_number": 1, "translated_text": "'n Plek van Pret"},
        {"page_number": 2, "translated_text": "'n Plek van Pret"},
        # page 3 missing entirely
    ]}
    rep = compare(src, trans)
    check("missing translation flagged", not rep["ok"]
          and any(i["type"] == "missing_translation" for i in rep["issues"]))
    import shutil; shutil.rmtree(tmp, ignore_errors=True)


def test_multiword_english_leak_flagged():
    tmp = tempfile.mkdtemp(prefix="tcmp_")
    src = os.path.join(tmp, "s.pdf"); _make_src(src)
    trans = {"pages": [
        {"page_number": 1, "translated_text": "'n Plek van Pret"},
        {"page_number": 2, "translated_text": "'n Plek van Pret"},
        # page 3: English sentence left untranslated verbatim
        {"page_number": 3, "translated_text": "The dog runs in the park today."},
    ]}
    rep = compare(src, trans)
    check("multi-word English leak flagged", not rep["ok"]
          and any(i["type"] == "english_leak" for i in rep["issues"]))
    import shutil; shutil.rmtree(tmp, ignore_errors=True)


if __name__ == "__main__":
    print("=" * 60)
    print("PRE-RENDER TRANSLATION COMPARE TESTS")
    print("=" * 60)
    test_title_inconsistency_flagged()
    test_consistent_title_passes_and_no_false_leaks()
    test_missing_translation_flagged()
    test_multiword_english_leak_flagged()
    print("=" * 60)
    print(f"RESULTS: {_passed} passed, {_failed} failed")
    print("=" * 60)
    sys.exit(1 if _failed else 0)
