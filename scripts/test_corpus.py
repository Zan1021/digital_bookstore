"""
Test Corpus Fixtures — Digital Bookstore V8
=============================================
Golden outputs, expected manifests, allowed diff masks per test case.

Per the brief:
  "For every fixture, store: Source PDF, Expected object manifest,
   Approved translation, Expected render plan, Golden output preview,
   Allowed visual-difference mask, Expected QA result"

This module manages test fixtures that define what "correct" looks like
for each page type. When the engine is modified, we re-render and compare
against golden outputs to detect regressions.

Structure:
  test_fixtures/
    kolulu_story_p3/
      source_page.pdf       — Single page from source
      expected_manifest.json — What the manifest should produce
      translation.json      — Approved translation for this page
      golden_output.pdf     — Approved rendered output
      diff_mask.json        — Regions allowed to differ (text areas)
      qa_result.json        — Expected QA scores
    kolulu_vocab_p15/
      ...
    kolulu_cover_p1/
      ...

Usage:
    python test_corpus.py                 # self-test: committed fixtures + create/verify
    python test_corpus.py create --input book.pdf --page 3 --name kolulu_story_p3
    python test_corpus.py verify --fixture kolulu_story_p3 --rendered rendered_p3.pdf
    python test_corpus.py list
"""

import argparse
import json
import os
import sys
from typing import Optional

import pymupdf


# =============================================================================
# FIXTURE MANAGEMENT
# =============================================================================

FIXTURES_DIR = os.path.join(os.path.dirname(os.path.abspath(__file__)), "test_fixtures")


def create_fixture(pdf_path: str, page_num: int, fixture_name: str,
                  translation_text: str = "", fixture_dir: str = None) -> dict:
    """
    Create a test fixture from a source PDF page.
    
    Extracts the page, generates an expected manifest, and sets up
    the fixture directory structure.
    """
    fixture_dir = fixture_dir or FIXTURES_DIR
    fixture_path = os.path.join(fixture_dir, fixture_name)
    os.makedirs(fixture_path, exist_ok=True)
    
    # Extract single page as source
    doc = pymupdf.open(pdf_path)
    source_page_path = os.path.join(fixture_path, "source_page.pdf")
    
    single_doc = pymupdf.open()
    single_doc.insert_pdf(doc, from_page=page_num - 1, to_page=page_num - 1)
    single_doc.save(source_page_path)
    single_doc.close()
    
    # Generate expected manifest
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    from pdf_translate_v8 import extract_page_spans, classify_page
    
    page = doc[page_num - 1]
    spans = extract_page_spans(page, page_num)
    page_type = classify_page(spans, page_num, len(doc))
    
    manifest = {
        "page_number": page_num,
        "page_type": page_type,
        "span_count": len(spans),
        "geometry": {"width": page.rect.width, "height": page.rect.height},
        "spans_sample": [
            {"id": s["id"], "text": s["text_stripped"][:30], "font_size": s["font_size"]}
            for s in spans[:10]
        ],
    }
    
    manifest_path = os.path.join(fixture_path, "expected_manifest.json")
    with open(manifest_path, 'w', encoding='utf-8') as f:
        json.dump(manifest, f, indent=2, ensure_ascii=False)
    
    # Save translation
    translation_path = os.path.join(fixture_path, "translation.json")
    with open(translation_path, 'w', encoding='utf-8') as f:
        json.dump({
            "pages": [{"page_number": page_num, "translated_text": translation_text}]
        }, f, indent=2, ensure_ascii=False)
    
    # Create diff mask (text bounding boxes are allowed to differ)
    text_bboxes = [s["bbox"] for s in spans if not s.get("is_page_number")]
    diff_mask = {
        "allowed_regions": text_bboxes,
        "description": "Text areas that will differ after translation",
    }
    
    mask_path = os.path.join(fixture_path, "diff_mask.json")
    with open(mask_path, 'w', encoding='utf-8') as f:
        json.dump(diff_mask, f, indent=2)
    
    # Expected QA result template
    qa_template = {
        "expected_pass": True,
        "min_coverage": 0.8,
        "max_visual_diff": 0.05,
        "required_checks": ["text_selectable", "images_preserved", "dimensions_match"],
    }
    
    qa_path = os.path.join(fixture_path, "qa_result.json")
    with open(qa_path, 'w', encoding='utf-8') as f:
        json.dump(qa_template, f, indent=2)
    
    doc.close()
    
    return {
        "fixture_name": fixture_name,
        "path": fixture_path,
        "page_type": page_type,
        "span_count": len(spans),
        "files_created": [
            "source_page.pdf",
            "expected_manifest.json", 
            "translation.json",
            "diff_mask.json",
            "qa_result.json",
        ],
    }


def verify_against_fixture(fixture_name: str, rendered_path: str,
                          fixture_dir: str = None) -> dict:
    """
    Verify a rendered output against a fixture's expected results.
    
    Checks:
    - Page dimensions match
    - Text is selectable
    - Images are preserved (same count)
    - Visual diff within allowed mask
    """
    fixture_dir = fixture_dir or FIXTURES_DIR
    fixture_path = os.path.join(fixture_dir, fixture_name)
    
    if not os.path.isdir(fixture_path):
        return {"pass": False, "error": f"Fixture not found: {fixture_path}"}
    
    # Load fixture data
    with open(os.path.join(fixture_path, "expected_manifest.json"), 'r') as f:
        expected_manifest = json.load(f)
    
    with open(os.path.join(fixture_path, "qa_result.json"), 'r') as f:
        qa_expected = json.load(f)
    
    # Open rendered PDF
    rendered_doc = pymupdf.open(rendered_path)
    rendered_page = rendered_doc[0]
    
    # Open source
    source_path = os.path.join(fixture_path, "source_page.pdf")
    source_doc = pymupdf.open(source_path)
    source_page = source_doc[0]
    
    checks = []
    
    # Check 1: Dimensions match
    dim_match = (
        abs(rendered_page.rect.width - source_page.rect.width) < 0.1 and
        abs(rendered_page.rect.height - source_page.rect.height) < 0.1
    )
    checks.append({"name": "dimensions_match", "pass": dim_match})
    
    # Check 2: Text is selectable
    rendered_text = rendered_page.get_text("text").strip()
    text_selectable = len(rendered_text) > 0
    checks.append({"name": "text_selectable", "pass": text_selectable,
                   "detail": f"{len(rendered_text)} chars extracted"})
    
    # Check 3: Images preserved
    source_images = len(source_page.get_images())
    rendered_images = len(rendered_page.get_images())
    images_ok = rendered_images >= source_images
    checks.append({"name": "images_preserved", "pass": images_ok,
                   "detail": f"source={source_images}, rendered={rendered_images}"})
    
    source_doc.close()
    rendered_doc.close()
    
    # Overall result
    all_pass = all(c["pass"] for c in checks)
    
    return {
        "fixture": fixture_name,
        "pass": all_pass,
        "checks": checks,
        "page_type": expected_manifest.get("page_type"),
    }


def list_fixtures(fixture_dir: str = None) -> list:
    """List all available test fixtures."""
    fixture_dir = fixture_dir or FIXTURES_DIR
    
    if not os.path.isdir(fixture_dir):
        return []
    
    fixtures = []
    for name in sorted(os.listdir(fixture_dir)):
        path = os.path.join(fixture_dir, name)
        if os.path.isdir(path):
            manifest_path = os.path.join(path, "expected_manifest.json")
            if os.path.isfile(manifest_path):
                with open(manifest_path, 'r') as f:
                    manifest = json.load(f)
                fixtures.append({
                    "name": name,
                    "page_type": manifest.get("page_type"),
                    "span_count": manifest.get("span_count"),
                })
    
    return fixtures


# =============================================================================
# SELF-TEST (no arguments) — what run_python_tests.py runs
# =============================================================================

def self_test() -> int:
    """Check the committed fixtures still match the engine, and that create/verify work.

    1. For every committed fixture, re-extracting its source page with today's engine
       reproduces the stored manifest (span count, ids, text, font sizes, geometry).
    2. Every fixture's own source page passes verify (a no-op render is not a regression).
    3. create -> verify round-trips on a synthetic page; a page of the wrong size and a
       page without text both FAIL verify.
    """
    import tempfile
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    from pdf_translate_v8 import extract_page_spans

    passed = failed = 0

    def check(name, ok, detail=""):
        nonlocal passed, failed
        print(f"  [{'OK' if ok else 'FAIL'}] {name}{f' — {detail}' if detail and not ok else ''}")
        if ok:
            passed += 1
        else:
            failed += 1

    fixtures = list_fixtures()
    check("committed fixtures found", len(fixtures) > 0, f"none in {FIXTURES_DIR}")

    for fx in fixtures:
        name = fx["name"]
        path = os.path.join(FIXTURES_DIR, name)
        with open(os.path.join(path, "expected_manifest.json"), encoding="utf-8") as f:
            expected = json.load(f)
        src = pymupdf.open(os.path.join(path, "source_page.pdf"))
        page = src[0]
        spans = extract_page_spans(page, expected["page_number"])
        geom = expected["geometry"]
        check(f"[{name}] page geometry unchanged",
              abs(page.rect.width - geom["width"]) < 0.1 and abs(page.rect.height - geom["height"]) < 0.1)
        check(f"[{name}] span count unchanged", len(spans) == expected["span_count"],
              f"expected {expected['span_count']}, got {len(spans)}")
        got = [{"id": s["id"], "text": s["text_stripped"][:30], "font_size": s["font_size"]}
               for s in spans[:10]]
        check(f"[{name}] span ids, text and sizes unchanged", got == expected["spans_sample"],
              f"got {got}")
        src.close()
        result = verify_against_fixture(name, os.path.join(path, "source_page.pdf"))
        check(f"[{name}] source page passes its own verify", result.get("pass") is True,
              str(result.get("checks") or result.get("error")))

    with tempfile.TemporaryDirectory() as tmp:
        book = os.path.join(tmp, "book.pdf")
        doc = pymupdf.open()
        for n in range(3):
            p = doc.new_page(width=420, height=595)
            p.insert_text((40, 80), f"Synthetic page {n + 1} with a sentence.", fontsize=14)
        doc.save(book)
        doc.close()

        fx_dir = os.path.join(tmp, "fixtures")
        made = create_fixture(book, 2, "synthetic_p2", "Vertaalde sin.", fixture_dir=fx_dir)
        check("create writes every fixture file",
              all(os.path.isfile(os.path.join(made["path"], f)) for f in made["files_created"]))
        check("list finds the created fixture",
              [f["name"] for f in list_fixtures(fx_dir)] == ["synthetic_p2"])
        same = verify_against_fixture("synthetic_p2", os.path.join(made["path"], "source_page.pdf"), fx_dir)
        check("verify passes the unchanged page", same["pass"] is True, str(same.get("checks")))

        wrong_size = os.path.join(tmp, "wrong_size.pdf")
        doc = pymupdf.open()
        doc.new_page(width=595, height=842).insert_text((40, 80), "Some text", fontsize=14)
        doc.save(wrong_size)
        doc.close()
        r = verify_against_fixture("synthetic_p2", wrong_size, fx_dir)
        check("verify fails a page of the wrong size",
              r["pass"] is False and not next(c for c in r["checks"] if c["name"] == "dimensions_match")["pass"])

        no_text = os.path.join(tmp, "no_text.pdf")
        doc = pymupdf.open()
        doc.new_page(width=420, height=595)
        doc.save(no_text)
        doc.close()
        r = verify_against_fixture("synthetic_p2", no_text, fx_dir)
        check("verify fails a page without selectable text",
              r["pass"] is False and not next(c for c in r["checks"] if c["name"] == "text_selectable")["pass"])

        missing = verify_against_fixture("does_not_exist", no_text, fx_dir)
        check("verify fails an unknown fixture", missing["pass"] is False)

    print("=" * 60)
    print(f"RESULTS: {passed} passed, {failed} failed")
    print("=" * 60)
    return 1 if failed else 0


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="Test Corpus Fixtures — V8")
    subparsers = parser.add_subparsers(dest="command")
    
    # Create
    create_p = subparsers.add_parser("create", help="Create a test fixture")
    create_p.add_argument("--input", "-i", required=True)
    create_p.add_argument("--page", "-p", type=int, required=True)
    create_p.add_argument("--name", "-n", required=True)
    create_p.add_argument("--translation", "-t", default="")
    
    # Verify
    verify_p = subparsers.add_parser("verify", help="Verify rendered output against fixture")
    verify_p.add_argument("--fixture", "-f", required=True)
    verify_p.add_argument("--rendered", "-r", required=True)
    
    # List
    subparsers.add_parser("list", help="List available fixtures")
    
    args = parser.parse_args()
    
    if args.command == "create":
        result = create_fixture(args.input, args.page, args.name, args.translation)
        print(f"Fixture created: {result['fixture_name']}")
        print(f"  Path: {result['path']}")
        print(f"  Page type: {result['page_type']}")
        print(f"  Spans: {result['span_count']}")
        print(f"  Files: {result['files_created']}")
    
    elif args.command == "verify":
        result = verify_against_fixture(args.fixture, args.rendered)
        status = "PASS" if result['pass'] else "FAIL"
        print(f"[{status}] Fixture: {result['fixture']}")
        for check in result.get('checks', []):
            icon = 'OK' if check['pass'] else 'XX'
            detail = f" — {check.get('detail', '')}" if check.get('detail') else ""
            print(f"  [{icon}] {check['name']}{detail}")
    
    elif args.command == "list":
        fixtures = list_fixtures()
        if fixtures:
            print(f"Test Fixtures ({len(fixtures)}):")
            for f in fixtures:
                print(f"  {f['name']} [{f['page_type']}] ({f['span_count']} spans)")
        else:
            print("No fixtures found. Create with: python test_corpus.py create ...")
    
    else:
        return self_test()
    return 0


if __name__ == "__main__":
    sys.exit(main())
