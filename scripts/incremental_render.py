"""
Incremental Re-rendering — Digital Bookstore V8
=================================================
Change one translation → re-render only that page.

Instead of re-processing the entire PDF when a reviewer edits a single
translation, this module:
  1. Tracks which pages have been rendered (render state cache)
  2. On a change, re-renders only the affected page(s)
  3. Merges the re-rendered page back into the existing output PDF
  4. Saves with incremental update (fast, small file delta)

This dramatically speeds up the review-edit-render cycle:
  Full render: 16 pages × ~2s = 32s
  Incremental: 1 page × ~2s = 2s

Usage:
    python incremental_render.py render-page --input source.pdf --output translated.pdf --page 5 --translations trans.json --fonts-dir ./fonts
    python incremental_render.py status --output translated.pdf
"""

import argparse
import json
import os
import sys
import time
import hashlib
from typing import Optional

import pymupdf


# =============================================================================
# RENDER STATE — Tracks what's been rendered per page
# =============================================================================

class RenderState:
    """
    Tracks render state per page: what translation was used, when it was rendered,
    and a hash for change detection.
    """
    
    def __init__(self, state_path: str = None):
        self.state_path = state_path
        self.pages = {}
        
        if state_path and os.path.isfile(state_path):
            self._load()
    
    def _load(self):
        """Load state from JSON file."""
        try:
            with open(self.state_path, 'r', encoding='utf-8') as f:
                data = json.load(f)
            self.pages = data.get("pages", {})
        except Exception:
            self.pages = {}
    
    def save(self):
        """Persist state to JSON file."""
        if not self.state_path:
            return
        
        data = {
            "version": "v8-incremental",
            "pages": self.pages,
            "last_updated": time.strftime("%Y-%m-%d %H:%M:%S"),
        }
        
        os.makedirs(os.path.dirname(self.state_path) or '.', exist_ok=True)
        with open(self.state_path, 'w', encoding='utf-8') as f:
            json.dump(data, f, indent=2)
    
    def set_page_rendered(self, page_num: int, translation_text: str, 
                         render_time: float, page_type: str):
        """Record that a page has been rendered with specific translation."""
        text_hash = hashlib.md5(translation_text.encode('utf-8')).hexdigest()[:12]
        
        self.pages[str(page_num)] = {
            "translation_hash": text_hash,
            "rendered_at": time.strftime("%Y-%m-%d %H:%M:%S"),
            "render_time_ms": round(render_time * 1000),
            "page_type": page_type,
        }
    
    def needs_render(self, page_num: int, translation_text: str) -> bool:
        """Check if a page needs re-rendering (translation changed)."""
        key = str(page_num)
        if key not in self.pages:
            return True
        
        current_hash = hashlib.md5(translation_text.encode('utf-8')).hexdigest()[:12]
        return self.pages[key]["translation_hash"] != current_hash
    
    def get_changed_pages(self, translations: dict) -> list:
        """
        Given a translations dict, return list of page numbers that need re-rendering.
        
        translations format: {"pages": [{"page_number": N, "translated_text": "..."}]}
        """
        changed = []
        for page_data in translations.get("pages", []):
            page_num = page_data["page_number"]
            text = page_data.get("translated_text", "")
            if self.needs_render(page_num, text):
                changed.append(page_num)
        return changed
    
    def get_status(self) -> dict:
        """Get overall render status."""
        return {
            "total_pages_rendered": len(self.pages),
            "pages": self.pages,
        }


# =============================================================================
# INCREMENTAL PAGE RENDERING
# =============================================================================

def render_single_page(input_pdf: str, output_pdf: str, page_num: int,
                      translations: dict, fonts_dir: str = None,
                      render_state: RenderState = None) -> dict:
    """
    Re-render a single page in an existing output PDF.
    
    Strategy:
    1. Open the SOURCE PDF to get a fresh copy of the page
    2. Apply the V8 renderer to that single page
    3. Replace the corresponding page in the output PDF
    
    This preserves all other pages untouched while updating only the target.
    """
    # Import V8 renderer
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    from pdf_translate_v8 import (
        extract_page_spans, classify_page, 
        render_story_page_v8, render_vocabulary_page_v8,
        render_cover_page_v8, render_back_cover_v8,
        render_copyright_page_v8,
    )
    
    start_time = time.time()
    
    report = {
        "page": page_num,
        "success": False,
        "page_type": None,
        "render_time_ms": 0,
        "error": None,
    }
    
    # Build translations map for this page
    translations_map = {}
    for p in translations.get("pages", []):
        if p["page_number"] == page_num:
            translations_map[page_num] = p.get("translated_text", "")
            break
    
    if page_num not in translations_map:
        report["error"] = f"No translation found for page {page_num}"
        return report
    
    # Open source PDF (fresh page)
    source_doc = pymupdf.open(input_pdf)
    total_pages = len(source_doc)
    
    # Get fresh page from source
    source_page = source_doc[page_num - 1]
    
    # Extract spans and classify
    page_spans = extract_page_spans(source_page, page_num)
    page_type = classify_page(page_spans, page_num, total_pages)
    report["page_type"] = page_type
    
    # Create a temporary doc with just this page
    temp_doc = pymupdf.open()
    temp_doc.insert_pdf(source_doc, from_page=page_num - 1, to_page=page_num - 1)
    temp_page = temp_doc[0]
    
    # Re-extract spans from temp page (it's a copy)
    temp_spans = extract_page_spans(temp_page, page_num)
    
    # Render
    render_report = {"spans_replaced": 0, "errors": [], "overflow_warnings": []}
    
    try:
        if page_type == 'cover':
            render_cover_page_v8(temp_page, temp_spans, translations_map, fonts_dir, page_num, render_report)
        elif page_type == 'story':
            render_story_page_v8(temp_page, temp_spans, translations_map, fonts_dir, page_num, render_report)
        elif page_type == 'vocabulary':
            render_vocabulary_page_v8(temp_page, temp_spans, translations_map, fonts_dir, page_num, render_report)
        elif page_type == 'back_cover':
            render_back_cover_v8(temp_page, temp_spans, translations_map, fonts_dir, page_num, render_report)
        elif page_type == 'copyright':
            render_copyright_page_v8(temp_page, temp_spans, translations_map, fonts_dir, page_num, render_report)
        
        report["success"] = True
        report["spans_replaced"] = render_report["spans_replaced"]
    except Exception as e:
        report["error"] = str(e)
        source_doc.close()
        temp_doc.close()
        return report
    
    # Now merge this rendered page into the output PDF
    if os.path.isfile(output_pdf):
        # Output exists — replace just this page
        output_doc = pymupdf.open(output_pdf)
        
        # Delete the old page and insert the new one
        if page_num - 1 < len(output_doc):
            output_doc.delete_page(page_num - 1)
            output_doc.insert_pdf(temp_doc, from_page=0, to_page=0, start_at=page_num - 1)
        else:
            # Page doesn't exist yet in output — append
            output_doc.insert_pdf(temp_doc, from_page=0, to_page=0)
        
        output_doc.save(output_pdf, garbage=4, deflate=True, incremental=False)
        output_doc.close()
    else:
        # Output doesn't exist — create with all source pages, render only target
        full_doc = pymupdf.open(input_pdf)
        
        # Replace target page with rendered version
        full_doc.delete_page(page_num - 1)
        full_doc.insert_pdf(temp_doc, from_page=0, to_page=0, start_at=page_num - 1)
        
        full_doc.save(output_pdf, garbage=4, deflate=True)
        full_doc.close()
    
    source_doc.close()
    temp_doc.close()
    
    elapsed = time.time() - start_time
    report["render_time_ms"] = round(elapsed * 1000)
    
    # Update render state
    if render_state:
        render_state.set_page_rendered(
            page_num, translations_map[page_num], elapsed, page_type
        )
        render_state.save()
    
    return report


def render_changed_pages(input_pdf: str, output_pdf: str, translations: dict,
                        fonts_dir: str = None, state_path: str = None) -> dict:
    """
    Render only the pages whose translations have changed.
    
    Uses RenderState to detect changes and only re-renders affected pages.
    
    Returns:
    {
        "total_pages": int,
        "pages_rendered": int,
        "pages_skipped": int,
        "page_reports": [...],
        "total_time_ms": int,
    }
    """
    start_time = time.time()
    
    # Load or create render state
    if state_path is None:
        state_path = output_pdf + ".state.json"
    render_state = RenderState(state_path)
    
    # Determine which pages need rendering
    changed_pages = render_state.get_changed_pages(translations)
    all_pages = [p["page_number"] for p in translations.get("pages", [])]
    skipped_pages = [p for p in all_pages if p not in changed_pages]
    
    result = {
        "total_pages": len(all_pages),
        "pages_rendered": 0,
        "pages_skipped": len(skipped_pages),
        "changed_pages": changed_pages,
        "skipped_pages": skipped_pages,
        "page_reports": [],
        "total_time_ms": 0,
    }
    
    if not changed_pages:
        result["total_time_ms"] = round((time.time() - start_time) * 1000)
        return result
    
    # If output doesn't exist, we need a full render first
    if not os.path.isfile(output_pdf):
        # Create output from source with all pages untranslated
        # Then render changed pages into it
        pass  # render_single_page handles this case
    
    # Render each changed page
    for page_num in sorted(changed_pages):
        page_report = render_single_page(
            input_pdf, output_pdf, page_num,
            translations, fonts_dir, render_state
        )
        result["page_reports"].append(page_report)
        if page_report["success"]:
            result["pages_rendered"] += 1
    
    result["total_time_ms"] = round((time.time() - start_time) * 1000)
    return result


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="Incremental Re-rendering — V8")
    subparsers = parser.add_subparsers(dest="command")
    
    # Render single page
    render_p = subparsers.add_parser("render-page", help="Re-render a single page")
    render_p.add_argument("--input", "-i", required=True, help="Source PDF")
    render_p.add_argument("--output", "-o", required=True, help="Output PDF")
    render_p.add_argument("--page", "-p", type=int, required=True)
    render_p.add_argument("--translations", "-t", required=True, help="Translations JSON")
    render_p.add_argument("--fonts-dir", "-f")
    
    # Render only changed pages
    changed_p = subparsers.add_parser("render-changed", help="Render only changed pages")
    changed_p.add_argument("--input", "-i", required=True)
    changed_p.add_argument("--output", "-o", required=True)
    changed_p.add_argument("--translations", "-t", required=True)
    changed_p.add_argument("--fonts-dir", "-f")
    changed_p.add_argument("--state", help="State file path (default: output.state.json)")
    
    # Check status
    status_p = subparsers.add_parser("status", help="Show render state")
    status_p.add_argument("--output", "-o", required=True, help="Output PDF (reads .state.json)")
    
    args = parser.parse_args()
    
    if args.command == "render-page":
        with open(args.translations, 'r', encoding='utf-8') as f:
            translations = json.load(f)
        
        report = render_single_page(
            args.input, args.output, args.page,
            translations, args.fonts_dir
        )
        
        if report["success"]:
            print(f"Page {args.page} rendered successfully")
            print(f"  Type: {report['page_type']}")
            print(f"  Time: {report['render_time_ms']}ms")
            print(f"  Spans replaced: {report.get('spans_replaced', 0)}")
        else:
            print(f"Render failed: {report['error']}")
    
    elif args.command == "render-changed":
        with open(args.translations, 'r', encoding='utf-8') as f:
            translations = json.load(f)
        
        result = render_changed_pages(
            args.input, args.output, translations,
            args.fonts_dir, args.state
        )
        
        print(f"Incremental render complete:")
        print(f"  Total pages: {result['total_pages']}")
        print(f"  Pages rendered: {result['pages_rendered']}")
        print(f"  Pages skipped (unchanged): {result['pages_skipped']}")
        print(f"  Total time: {result['total_time_ms']}ms")
        
        if result['page_reports']:
            print(f"\n  Rendered pages:")
            for r in result['page_reports']:
                status = 'OK' if r['success'] else f"FAIL: {r['error']}"
                print(f"    Page {r['page']} [{r['page_type']}]: {status} ({r['render_time_ms']}ms)")
    
    elif args.command == "status":
        state_path = args.output + ".state.json"
        state = RenderState(state_path)
        status = state.get_status()
        
        print(f"Render State: {state_path}")
        print(f"  Pages rendered: {status['total_pages_rendered']}")
        
        if status['pages']:
            print(f"\n  Page details:")
            for page_num, info in sorted(status['pages'].items(), key=lambda x: int(x[0])):
                print(f"    Page {page_num}: {info['page_type']} "
                      f"(rendered {info['rendered_at']}, {info['render_time_ms']}ms)")
    
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
