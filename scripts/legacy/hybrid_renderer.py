"""
Hybrid Renderer — Digital Bookstore V8
========================================
Per-page rendering strategy selection. Combines V7 and V8 engines,
using the best approach for each page type.

Strategy matrix:
- story pages → V7 (htmlbox gives better paragraph wrapping + vertical centering)
- vocabulary/table pages → V8 (per-span preserves borders perfectly)
- cover pages → V8 (per-span with bg-color detection)
- copyright pages → V7 (two-column htmlbox layout)
- back cover → V8 (per-span with bg-color detection)

The strategy can also be overridden per-page by the manifest or admin.

Usage:
    python hybrid_renderer.py replace --input book.pdf --output book_af.pdf --translations translations.json --fonts-dir ./fonts
"""

import argparse
import json
import os
import sys
from pathlib import Path

import pymupdf

# Add scripts to path
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from pdf_translate import replace_text_in_pdf as v7_replace_text
from pdf_translate_v8 import (
    extract_page_spans,
    classify_page,
    render_cover_page_v8,
    render_vocabulary_page_v8,
    render_back_cover_v8,
    render_copyright_page_v8,
    render_story_page_v8,
    remove_span,
    insert_translated_span,
)


# =============================================================================
# STRATEGY ROUTING
# =============================================================================

# Default strategy per page type
DEFAULT_STRATEGIES = {
    'cover': 'v8',        # V8: per-span with bg detection
    'copyright': 'v7',    # V7: two-column htmlbox layout
    'story': 'v7',        # V7: htmlbox wrapping + vertical centering + auto font size
    'vocabulary': 'v8',   # V8: per-span preserves table borders
    'back_cover': 'v8',   # V8: per-span with bg detection
}


def get_page_strategy(page_type: str, page_num: int, overrides: dict = None) -> str:
    """
    Determine rendering strategy for a page.
    
    Priority:
    1. Per-page override (from manifest or admin)
    2. Default strategy for the page type
    """
    if overrides and str(page_num) in overrides:
        return overrides[str(page_num)]
    
    return DEFAULT_STRATEGIES.get(page_type, 'v7')


# =============================================================================
# HYBRID RENDERER
# =============================================================================

def hybrid_replace_text_in_pdf(
    input_pdf: str,
    output_pdf: str,
    translations: dict,
    fonts_dir: str = None,
    strategy_overrides: dict = None,
) -> dict:
    """
    Hybrid renderer: uses V7 or V8 per page based on page type.
    
    translations format (same as both engines):
    {
        "pages": [
            {"page_number": 1, "translated_text": "..."},
            ...
        ]
    }
    """
    doc = pymupdf.open(input_pdf)
    total_pages = len(doc)

    report = {
        "version": "hybrid",
        "pages_processed": 0,
        "spans_replaced": 0,
        "page_types": {},
        "page_strategies": {},
        "overflow_warnings": [],
        "errors": [],
        "coverage": {
            "total_source_spans": 0,
            "total_translated": 0,
            "pages_with_gaps": [],
        },
    }

    translations_map = {p["page_number"]: p.get("translated_text", "")
                       for p in translations.get("pages", [])}

    # =========================================================================
    # PASS 1: Classify all pages and determine strategies
    # =========================================================================
    page_classifications = {}
    story_pages_for_v7 = []

    for page_idx in range(total_pages):
        page_num = page_idx + 1
        page = doc[page_idx]

        if page_num not in translations_map:
            continue
        if not translations_map[page_num].strip():
            continue

        page_spans = extract_page_spans(page, page_num)
        page_type = classify_page(page_spans, page_num, total_pages)
        page_classifications[page_num] = page_type

        strategy = get_page_strategy(page_type, page_num, strategy_overrides)
        report["page_types"][str(page_num)] = page_type
        report["page_strategies"][str(page_num)] = strategy

        if strategy == 'v7' and page_type == 'story':
            story_pages_for_v7.append((page_idx, page_num))

    # =========================================================================
    # PASS 1.5: Calculate optimal font size for V7 story pages
    # (Only if there are story pages using V7)
    # =========================================================================
    optimal_font_size = 26
    v7_containers = {}

    if story_pages_for_v7:
        from pdf_translate import (
            calculate_optimal_font_size,
            clean_translated_text,
        )
        story_translations = {}
        for _, page_num in story_pages_for_v7:
            story_translations[page_num] = translations_map.get(page_num, "")

        optimal_font_size, v7_containers = calculate_optimal_font_size(
            doc, story_pages_for_v7, story_translations, fonts_dir
        )
        report["v7_auto_font_size"] = optimal_font_size

    # =========================================================================
    # PASS 2: Render each page with its assigned strategy
    # =========================================================================
    for page_idx in range(total_pages):
        page_num = page_idx + 1
        page = doc[page_idx]

        if page_num not in translations_map:
            continue
        if not translations_map[page_num].strip():
            continue

        page_type = page_classifications.get(page_num)
        if not page_type:
            continue

        strategy = report["page_strategies"].get(str(page_num), 'v7')
        report["pages_processed"] += 1

        # Extract spans for V8 pages
        page_spans = extract_page_spans(page, page_num)
        content_spans = [s for s in page_spans if not s["is_page_number"]]
        report["coverage"]["total_source_spans"] += len(content_spans)

        pre_replaced = report["spans_replaced"]

        try:
            if strategy == 'v8':
                # Use V8 per-span replacement
                if page_type == 'cover':
                    render_cover_page_v8(page, page_spans, translations_map, fonts_dir, page_num, report)
                elif page_type == 'vocabulary':
                    render_vocabulary_page_v8(page, page_spans, translations_map, fonts_dir, page_num, report)
                elif page_type == 'back_cover':
                    render_back_cover_v8(page, page_spans, translations_map, fonts_dir, page_num, report)
                elif page_type == 'story':
                    render_story_page_v8(page, page_spans, translations_map, fonts_dir, page_num, report)
                elif page_type == 'copyright':
                    render_copyright_page_v8(page, page_spans, translations_map, fonts_dir, page_num, report)

            elif strategy == 'v7':
                # Use V7 zone-erase + rebuild
                from pdf_translate import (
                    render_story_page,
                    render_cover_page,
                    render_back_cover,
                    render_copyright_page,
                    render_vocabulary_page,
                    find_story_text_zone,
                    detect_story_container,
                    clean_translated_text,
                )

                translated_text = translations_map[page_num]
                text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
                blocks = text_dict.get("blocks", [])

                if page_type == 'story':
                    container = v7_containers.get(page_num)
                    render_story_page(page, translated_text, fonts_dir, page_num, report,
                                    font_size=optimal_font_size, container=container)
                elif page_type == 'cover':
                    render_cover_page(page, translated_text, fonts_dir, page_num, report)
                elif page_type == 'back_cover':
                    render_back_cover(page, translated_text, fonts_dir, page_num, report)
                elif page_type == 'copyright':
                    render_copyright_page(page, translated_text, fonts_dir, page_num, report)
                elif page_type == 'vocabulary':
                    render_vocabulary_page(page, translated_text, fonts_dir, page_num, report)

        except Exception as e:
            report["errors"].append({
                "page": page_num,
                "strategy": strategy,
                "error": str(e),
            })

        # Track coverage
        page_replaced = report["spans_replaced"] - pre_replaced
        report["coverage"]["total_translated"] += page_replaced

    # Save
    output_dir = os.path.dirname(output_pdf)
    if output_dir and not os.path.isdir(output_dir):
        os.makedirs(output_dir, exist_ok=True)

    doc.save(output_pdf, garbage=4, deflate=True)
    doc.close()

    return report


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="Hybrid PDF Translation Renderer (V7+V8 per page)")
    subparsers = parser.add_subparsers(dest="command")

    replace_p = subparsers.add_parser("replace", help="Replace text using hybrid strategy")
    replace_p.add_argument("--input", "-i", required=True)
    replace_p.add_argument("--output", "-o", required=True)
    replace_p.add_argument("--translations", "-t", required=True)
    replace_p.add_argument("--fonts-dir", "-f")
    replace_p.add_argument("--strategy", "-s", help="JSON file with per-page strategy overrides")

    args = parser.parse_args()

    if args.command == "replace":
        with open(args.translations, 'r', encoding='utf-8') as f:
            translations = json.load(f)

        overrides = None
        if args.strategy:
            with open(args.strategy, 'r', encoding='utf-8') as f:
                overrides = json.load(f)

        report = hybrid_replace_text_in_pdf(
            input_pdf=args.input,
            output_pdf=args.output,
            translations=translations,
            fonts_dir=args.fonts_dir,
            strategy_overrides=overrides,
        )
        print(json.dumps(report, indent=2, ensure_ascii=False), file=sys.stderr)
        print(args.output)
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
