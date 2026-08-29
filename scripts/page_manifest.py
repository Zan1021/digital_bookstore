"""
Page Manifest Builder — Digital Bookstore V8
=============================================
Extracts a structured manifest from a PDF page that maps every text span
to a stable content ID with semantic role classification.

This manifest is used to:
1. Send structured translation requests (with IDs)
2. Validate 1:1 source→target coverage
3. Drive the V8 renderer (place translations at exact source coordinates)

Output format per page:
{
    "page_number": 15,
    "page_type": "vocabulary",
    "geometry": {"width": 538, "height": 751},
    "regions": [
        {
            "id": "p15-header-words",
            "semantic_role": "table_header",
            "translation_policy": "preserve",
            "spans": [...]
        },
        {
            "id": "p15-words-col1",
            "semantic_role": "word_list_column",
            "translation_policy": "translate_items",
            "items": [
                {"id": "p15-w-c1-001", "text": "house", "bbox": [...], "origin": [...]}
            ]
        }
    ]
}
"""

import json
import os
import re
import sys
from dataclasses import dataclass, field, asdict
from typing import Optional

import pymupdf


# =============================================================================
# PAGE CLASSIFICATION
# =============================================================================

def classify_page_from_spans(spans, page_num, total_pages):
    """Classify page type from extracted span data."""
    if page_num == 1:
        return 'cover'
    if page_num == 2:
        return 'copyright'
    if page_num == total_pages:
        return 'back_cover'

    small = sum(1 for s in spans if s["font_size"] < 15 and not s["is_page_number"])
    large = sum(1 for s in spans if s["font_size"] >= 15)
    if small > 30 and large < 5:
        return 'vocabulary'

    return 'story'


# =============================================================================
# COLUMN DETECTION (for vocabulary/table pages)
# =============================================================================

def detect_columns(spans, min_gap=15):
    """
    Detect column boundaries from X-coordinate clustering of spans.
    Returns list of column dicts: [{"center": x, "left": x, "right": x, "spans": [...]}]
    """
    if not spans:
        return []

    # Get unique X origins
    x_origins = sorted(set(round(s["origin"][0]) for s in spans))

    if not x_origins:
        return []

    # Cluster X positions
    clusters = []
    current_cluster = [x_origins[0]]
    for x in x_origins[1:]:
        if x - current_cluster[-1] < min_gap:
            current_cluster.append(x)
        else:
            clusters.append(current_cluster)
            current_cluster = [x]
    clusters.append(current_cluster)

    # Build column objects
    columns = []
    for i, cluster in enumerate(clusters):
        center = sum(cluster) / len(cluster)
        left = min(cluster)
        # Right edge: midpoint to next column, or page edge for last col
        if i < len(clusters) - 1:
            next_left = min(clusters[i + 1])
            right = (max(cluster) + next_left) / 2
        else:
            right = center + 120  # generous for last column

        # Find spans belonging to this column
        col_spans = []
        for s in spans:
            sx = round(s["origin"][0])
            if sx in cluster or (left - 5 <= s["origin"][0] <= right):
                col_spans.append(s)

        columns.append({
            "index": i,
            "center": center,
            "left": left,
            "right": right,
            "width": right - left,
            "spans": sorted(col_spans, key=lambda s: s["bbox"][1]),
        })

    return columns


# =============================================================================
# HEADER DETECTION (for vocabulary/table pages)
# =============================================================================

def detect_headers(spans, columns):
    """
    Detect header spans: ALL CAPS text in the topmost row.
    Returns (header_spans, content_spans).
    """
    if not spans:
        return [], []

    # Find the minimum Y position
    min_y = min(s["bbox"][1] for s in spans)
    header_y_threshold = min_y + 35  # Headers within top 35pts

    headers = []
    content = []

    for s in spans:
        text = s["text_stripped"]
        # Header criteria: in top row AND (all uppercase OR bold header text)
        is_in_header_row = s["bbox"][1] < header_y_threshold
        is_caps = text.isupper() and len(text) > 2
        is_header_text = is_in_header_row and (is_caps or s.get("is_bold", False))

        if is_header_text:
            headers.append(s)
        else:
            content.append(s)

    return headers, content


# =============================================================================
# MANIFEST BUILDER — VOCABULARY PAGE
# =============================================================================

def build_vocabulary_manifest(page, page_num, spans):
    """
    Build a structured manifest for a vocabulary/table page.
    
    Identifies:
    - Table headers (preserve)
    - Word columns (translate items 1:1)
    - High frequency words section
    - Phonics section
    """
    # Filter to vocabulary-relevant spans (small font, not page numbers)
    vocab_spans = [s for s in spans if not s["is_page_number"] and s["font_size"] < 20]

    # Detect columns
    columns = detect_columns(vocab_spans)

    # Detect headers vs content
    headers, content_spans = detect_headers(vocab_spans, columns)

    # Build regions
    regions = []

    # Header region
    if headers:
        header_items = []
        for i, h in enumerate(headers):
            item = {
                "id": f"p{page_num:02d}-header-{i:02d}",
                "text": h["text_stripped"],
                "bbox": h["bbox"],
                "origin": h["origin"],
                "font_size": h["font_size"],
            }
            # Include rotation metadata if present
            if h.get("is_rotated"):
                item["rotation_angle"] = h.get("rotation_angle", 0)
                item["rotation_class"] = h.get("rotation_class", "horizontal")
                item["text_direction"] = h.get("text_direction", (1.0, 0.0))
            header_items.append(item)

        regions.append({
            "id": f"p{page_num:02d}-headers",
            "semantic_role": "table_header",
            "translation_policy": "translate_headers",
            "items": header_items,
        })

    # Assign content spans to columns
    # Re-detect columns from content-only spans
    content_columns = detect_columns(content_spans)

    # Determine semantic role for each column based on position
    # Typical layout: WORDS (cols 0-2), HIGH FREQUENCY WORDS (col 3), PHONICS (col 4)
    # We detect by: number of items, text patterns (phonics has " - " patterns)
    for col in content_columns:
        col_spans = col["spans"]
        if not col_spans:
            continue

        # Detect if this is a phonics column (items contain " - " patterns)
        phonics_count = sum(1 for s in col_spans if re.search(r'\s+-\s+', s["text_stripped"]))
        is_phonics = phonics_count >= 3

        # Detect if high-frequency (short common words, fewer items than vocab cols)
        avg_word_len = sum(len(s["text_stripped"]) for s in col_spans) / max(len(col_spans), 1)
        is_hf = avg_word_len < 4 and len(col_spans) < 20 and not is_phonics

        # Determine role
        if is_phonics:
            role = "phonics"
            policy = "educational_adaptation"
        elif is_hf:
            role = "high_frequency_words"
            policy = "translate_items"
        else:
            role = "word_list"
            policy = "translate_items"

        # Build items with stable IDs
        items = []
        for j, s in enumerate(col_spans):
            item = {
                "id": f"p{page_num:02d}-c{col['index']:02d}-{j:03d}",
                "text": s["text_stripped"],
                "bbox": s["bbox"],
                "origin": s["origin"],
                "font_size": s["font_size"],
                "col_index": col["index"],
            }
            # Include rotation metadata if present
            if s.get("is_rotated"):
                item["rotation_angle"] = s.get("rotation_angle", 0)
                item["rotation_class"] = s.get("rotation_class", "horizontal")
                item["text_direction"] = s.get("text_direction", (1.0, 0.0))
            items.append(item)

        regions.append({
            "id": f"p{page_num:02d}-col-{col['index']:02d}",
            "semantic_role": role,
            "translation_policy": policy,
            "column_index": col["index"],
            "column_center": col["center"],
            "column_width": col["width"],
            "item_count": len(items),
            "items": items,
        })

    return {
        "page_number": page_num,
        "page_type": "vocabulary",
        "geometry": {
            "width": page.rect.width,
            "height": page.rect.height,
        },
        "column_count": len(content_columns),
        "total_content_items": sum(len(r["items"]) for r in regions if r["semantic_role"] != "table_header"),
        "regions": regions,
    }


# =============================================================================
# MANIFEST BUILDER — STORY PAGE
# =============================================================================

def build_story_manifest(page, page_num, spans):
    """Build manifest for a story page (illustration + prose)."""
    # Story spans: large font, not page numbers
    story_spans = [s for s in spans if not s["is_page_number"] and s["font_size"] >= 20]
    page_num_spans = [s for s in spans if s["is_page_number"]]

    if not story_spans:
        return {"page_number": page_num, "page_type": "story", "regions": []}

    # The story text is one logical paragraph (multiple spans)
    # Build bounding box
    min_x = min(s["bbox"][0] for s in story_spans)
    min_y = min(s["bbox"][1] for s in story_spans)
    max_x = max(s["bbox"][2] for s in story_spans)
    max_y = max(s["bbox"][3] for s in story_spans)

    # Reconstruct the full paragraph text from spans (in reading order)
    sorted_spans = sorted(story_spans, key=lambda s: (s["bbox"][1], s["bbox"][0]))
    full_text = ' '.join(s["text_stripped"] for s in sorted_spans)

    return {
        "page_number": page_num,
        "page_type": "story",
        "geometry": {
            "width": page.rect.width,
            "height": page.rect.height,
        },
        "regions": [{
            "id": f"p{page_num:02d}-story-prose",
            "semantic_role": "story_prose",
            "translation_policy": "translate",
            "bbox": [min_x, min_y, max_x, max_y],
            "source_text": full_text,
            "span_count": len(story_spans),
            "font_size": story_spans[0]["font_size"],
            "items": [{
                "id": f"p{page_num:02d}-para-001",
                "text": full_text,
                "bbox": [min_x, min_y, max_x, max_y],
            }]
        }],
    }


# =============================================================================
# MANIFEST BUILDER — COVER PAGE
# =============================================================================

def build_cover_manifest(page, page_num, spans):
    """Build manifest for a cover page."""
    regions = []

    # Find subtitle (large text >= 40px, more than 2 chars)
    subtitle_spans = [s for s in spans if s["font_size"] >= 40 and len(s["text_stripped"]) > 2]
    # Find small text (publisher info)
    small_spans = [s for s in spans if s["font_size"] < 20 and not s["is_page_number"]]

    if subtitle_spans:
        subtitle_text = ' '.join(s["text_stripped"] for s in subtitle_spans)
        regions.append({
            "id": f"p{page_num:02d}-subtitle",
            "semantic_role": "book_subtitle",
            "translation_policy": "translate",
            "items": [{
                "id": f"p{page_num:02d}-subtitle-001",
                "text": subtitle_text,
                "bbox": [
                    min(s["bbox"][0] for s in subtitle_spans),
                    min(s["bbox"][1] for s in subtitle_spans),
                    max(s["bbox"][2] for s in subtitle_spans),
                    max(s["bbox"][3] for s in subtitle_spans),
                ],
                "font_size": subtitle_spans[0]["font_size"],
            }],
        })

    # Publisher/brand marks — preserve
    if small_spans:
        regions.append({
            "id": f"p{page_num:02d}-publisher",
            "semantic_role": "publisher_brand",
            "translation_policy": "preserve",
            "items": [{
                "id": f"p{page_num:02d}-pub-{i:03d}",
                "text": s["text_stripped"],
                "bbox": s["bbox"],
            } for i, s in enumerate(small_spans)],
        })

    return {
        "page_number": page_num,
        "page_type": "cover",
        "geometry": {"width": page.rect.width, "height": page.rect.height},
        "regions": regions,
    }


# =============================================================================
# MANIFEST BUILDER — BACK COVER
# =============================================================================

def build_back_cover_manifest(page, page_num, spans):
    """Build manifest for a back cover (typically title list)."""
    content_spans = [s for s in spans if not s["is_page_number"] and s["font_size"] >= 10]

    if not content_spans:
        return {"page_number": page_num, "page_type": "back_cover", "regions": []}

    sorted_spans = sorted(content_spans, key=lambda s: s["bbox"][1])
    full_text = '\n'.join(s["text_stripped"] for s in sorted_spans)

    return {
        "page_number": page_num,
        "page_type": "back_cover",
        "geometry": {"width": page.rect.width, "height": page.rect.height},
        "regions": [{
            "id": f"p{page_num:02d}-title-list",
            "semantic_role": "series_title_list",
            "translation_policy": "translate",
            "items": [{
                "id": f"p{page_num:02d}-title-{i:03d}",
                "text": s["text_stripped"],
                "bbox": s["bbox"],
                "origin": s["origin"],
                "font_size": s["font_size"],
            } for i, s in enumerate(sorted_spans)],
        }],
    }


# =============================================================================
# MANIFEST BUILDER — COPYRIGHT PAGE
# =============================================================================

def build_copyright_manifest(page, page_num, spans):
    """Build manifest for a copyright/title page."""
    subtitle_spans = [s for s in spans if s["font_size"] >= 40 and len(s["text_stripped"]) > 2]
    info_spans = [s for s in spans if s["font_size"] < 15 and not s["is_page_number"]]

    regions = []

    if subtitle_spans:
        text = ' '.join(s["text_stripped"] for s in subtitle_spans)
        regions.append({
            "id": f"p{page_num:02d}-subtitle",
            "semantic_role": "book_subtitle",
            "translation_policy": "translate",
            "items": [{"id": f"p{page_num:02d}-sub-001", "text": text}],
        })

    # Split info spans by column (left < 300, right >= 300)
    left_spans = sorted([s for s in info_spans if s["bbox"][0] < 300], key=lambda s: s["bbox"][1])
    right_spans = sorted([s for s in info_spans if s["bbox"][0] >= 300], key=lambda s: s["bbox"][1])

    if left_spans:
        regions.append({
            "id": f"p{page_num:02d}-publisher-info",
            "semantic_role": "publisher_information",
            "translation_policy": "translate",
            "items": [{
                "id": f"p{page_num:02d}-info-{i:03d}",
                "text": s["text_stripped"],
                "bbox": s["bbox"],
                "origin": s["origin"],
            } for i, s in enumerate(left_spans)],
        })

    if right_spans:
        full_text = ' '.join(s["text_stripped"] for s in right_spans)
        regions.append({
            "id": f"p{page_num:02d}-character-bio",
            "semantic_role": "character_biography",
            "translation_policy": "translate",
            "items": [{"id": f"p{page_num:02d}-bio-001", "text": full_text}],
        })

    return {
        "page_number": page_num,
        "page_type": "copyright",
        "geometry": {"width": page.rect.width, "height": page.rect.height},
        "regions": regions,
    }


# =============================================================================
# FULL DOCUMENT MANIFEST
# =============================================================================

def build_document_manifest(pdf_path: str) -> dict:
    """
    Build a complete structured manifest for an entire PDF.
    Every text span gets a stable ID and semantic classification.
    """
    doc = pymupdf.open(pdf_path)
    total_pages = len(doc)

    manifest = {
        "schema_version": "1.0",
        "source_file": os.path.basename(pdf_path),
        "page_count": total_pages,
        "pages": [],
    }

    for page_idx in range(total_pages):
        page_num = page_idx + 1
        page = doc[page_idx]

        # Extract all spans
        spans = _extract_spans(page, page_num)

        # Classify page
        page_type = classify_page_from_spans(spans, page_num, total_pages)

        # Build page manifest based on type
        if page_type == 'vocabulary':
            page_manifest = build_vocabulary_manifest(page, page_num, spans)
        elif page_type == 'story':
            page_manifest = build_story_manifest(page, page_num, spans)
        elif page_type == 'cover':
            page_manifest = build_cover_manifest(page, page_num, spans)
        elif page_type == 'back_cover':
            page_manifest = build_back_cover_manifest(page, page_num, spans)
        elif page_type == 'copyright':
            page_manifest = build_copyright_manifest(page, page_num, spans)
        else:
            page_manifest = {"page_number": page_num, "page_type": page_type, "regions": []}

        manifest["pages"].append(page_manifest)

    doc.close()
    return manifest


def _extract_spans(page, page_num):
    """Extract all text spans from a page with metadata."""
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
    spans = []
    span_idx = 0

    for block in text_dict.get("blocks", []):
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                text = span["text"].strip()
                if not text:
                    continue
                span_idx += 1
                bbox = span["bbox"]
                flags = span["flags"]

                is_page_num = (
                    text.isdigit() and len(text) <= 3 and
                    span["size"] < 20 and bbox[1] > 600
                )

                color_int = span.get("color", 0)
                r = (color_int >> 16) & 0xFF
                g = (color_int >> 8) & 0xFF
                b = color_int & 0xFF

                font_name = span["font"]
                if len(font_name) > 7 and font_name[6] == '+':
                    font_name = font_name[7:]

                spans.append({
                    "id": f"p{page_num:02d}_s{span_idx:04d}",
                    "text": span["text"],
                    "text_stripped": text,
                    "origin": list(span["origin"]),
                    "bbox": list(bbox),
                    "font_size": span["size"],
                    "font_name": font_name,
                    "color": f"#{r:02x}{g:02x}{b:02x}",
                    "is_bold": bool(flags & (1 << 4)),
                    "is_italic": bool(flags & (1 << 1)),
                    "is_page_number": is_page_num,
                })

    return spans


# =============================================================================
# CLI
# =============================================================================

def main():
    import argparse

    parser = argparse.ArgumentParser(description="Page Manifest Builder — Extract structured book manifest")
    parser.add_argument("pdf_path", help="Input PDF file")
    parser.add_argument("--output", "-o", help="Output JSON file (default: stdout)")
    parser.add_argument("--page", "-p", type=int, help="Extract single page only")

    args = parser.parse_args()

    manifest = build_document_manifest(args.pdf_path)

    if args.page:
        # Filter to single page
        page_data = next((p for p in manifest["pages"] if p["page_number"] == args.page), None)
        if page_data:
            output = json.dumps(page_data, indent=2, ensure_ascii=False)
        else:
            print(f"Page {args.page} not found", file=sys.stderr)
            sys.exit(1)
    else:
        output = json.dumps(manifest, indent=2, ensure_ascii=False)

    if args.output:
        with open(args.output, 'w', encoding='utf-8') as f:
            f.write(output)
        print(f"Manifest saved to: {args.output}", file=sys.stderr)
    else:
        print(output)


if __name__ == "__main__":
    main()
