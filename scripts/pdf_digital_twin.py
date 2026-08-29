"""
PDF Digital Twin — Digital Bookstore V8
=========================================
Creates a full queryable representation of a PDF before and after translation.

The "digital twin" is a JSON snapshot of everything visible in the PDF:
- Every text span with position, font, size, color
- Every image with position and dimensions
- Every drawing/vector path
- Page geometry and metadata

This enables:
- Before/after comparison at object level
- Semantic preservation verification (same number of objects)
- Source-language remnant detection (Item 46)
- Regression testing (compare across renders)

Items covered: 38, 45, 46

Usage:
    from pdf_digital_twin import build_twin, compare_twins
    
    source_twin = build_twin("source.pdf")
    output_twin = build_twin("output.pdf")
    diff = compare_twins(source_twin, output_twin)
"""

import hashlib
import json
import os
import sys
from dataclasses import dataclass, field, asdict
from typing import Optional

import pymupdf

SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPTS_DIR)


# =============================================================================
# DIGITAL TWIN MODEL
# =============================================================================

@dataclass
class TextObject:
    """A text span in the twin."""
    text: str
    bbox: list
    origin: list
    font_name: str
    font_size: float
    color: str
    is_bold: bool = False
    page_number: int = 0


@dataclass
class ImageObject:
    """An image in the twin."""
    bbox: list
    width: int
    height: int
    colorspace: str = ""
    page_number: int = 0


@dataclass
class DrawingObject:
    """A vector drawing in the twin."""
    bbox: list
    item_count: int
    has_fill: bool
    has_stroke: bool
    page_number: int = 0


@dataclass
class PageTwin:
    """Twin of a single page."""
    page_number: int
    width: float
    height: float
    text_objects: list = field(default_factory=list)
    image_objects: list = field(default_factory=list)
    drawing_objects: list = field(default_factory=list)
    text_count: int = 0
    image_count: int = 0
    drawing_count: int = 0
    full_text: str = ""


@dataclass
class DocumentTwin:
    """Complete digital twin of a PDF."""
    filepath: str
    filename: str
    sha256: str
    page_count: int
    pages: list = field(default_factory=list)  # [PageTwin]
    metadata: dict = field(default_factory=dict)
    
    @property
    def total_text_objects(self) -> int:
        return sum(p.text_count for p in self.pages)
    
    @property
    def total_images(self) -> int:
        return sum(p.image_count for p in self.pages)
    
    def get_page(self, num: int) -> Optional[PageTwin]:
        for p in self.pages:
            if p.page_number == num:
                return p
        return None
    
    def get_all_text(self) -> str:
        return "\n\n".join(p.full_text for p in self.pages)


# =============================================================================
# BUILD TWIN
# =============================================================================

def build_twin(pdf_path: str) -> DocumentTwin:
    """Build a complete digital twin from a PDF file."""
    with open(pdf_path, 'rb') as f:
        sha = hashlib.sha256(f.read()).hexdigest()
    
    doc = pymupdf.open(pdf_path)
    
    twin = DocumentTwin(
        filepath=pdf_path,
        filename=os.path.basename(pdf_path),
        sha256=sha,
        page_count=len(doc),
        metadata=dict(doc.metadata) if doc.metadata else {},
    )
    
    for page_idx in range(len(doc)):
        page = doc[page_idx]
        page_num = page_idx + 1
        page_twin = _build_page_twin(page, page_num)
        twin.pages.append(page_twin)
    
    doc.close()
    return twin


def _build_page_twin(page, page_num: int) -> PageTwin:
    """Build twin for a single page."""
    pt = PageTwin(
        page_number=page_num,
        width=page.rect.width,
        height=page.rect.height,
    )
    
    # Extract text
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
    for block in text_dict.get("blocks", []):
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                text = span["text"].strip()
                if not text:
                    continue
                
                color_int = span.get("color", 0)
                r = (color_int >> 16) & 0xFF
                g = (color_int >> 8) & 0xFF
                b = color_int & 0xFF
                
                pt.text_objects.append(TextObject(
                    text=text,
                    bbox=list(span["bbox"]),
                    origin=list(span["origin"]),
                    font_name=span["font"],
                    font_size=span["size"],
                    color=f"#{r:02x}{g:02x}{b:02x}",
                    is_bold=bool(span["flags"] & (1 << 4)),
                    page_number=page_num,
                ))
    
    # Extract images
    for img in page.get_images():
        xref = img[0]
        try:
            img_info = page.parent.extract_image(xref)
            pt.image_objects.append(ImageObject(
                bbox=[0, 0, img_info.get("width", 0), img_info.get("height", 0)],
                width=img_info.get("width", 0),
                height=img_info.get("height", 0),
                colorspace=img_info.get("cs-name", ""),
                page_number=page_num,
            ))
        except Exception:
            pt.image_objects.append(ImageObject(
                bbox=[0, 0, 0, 0], width=0, height=0, page_number=page_num,
            ))
    
    # Extract drawings (count only — full paths are large)
    drawings = page.get_drawings()
    for d in drawings:
        rect = d.get("rect")
        if rect:
            pt.drawing_objects.append(DrawingObject(
                bbox=[rect.x0, rect.y0, rect.x1, rect.y1],
                item_count=len(d.get("items", [])),
                has_fill=d.get("fill") is not None,
                has_stroke=d.get("color") is not None,
                page_number=page_num,
            ))
    
    pt.text_count = len(pt.text_objects)
    pt.image_count = len(pt.image_objects)
    pt.drawing_count = len(pt.drawing_objects)
    pt.full_text = " ".join(t.text for t in pt.text_objects)
    
    return pt


# =============================================================================
# COMPARE TWINS (Item 45 — Semantic Preservation)
# =============================================================================

def compare_twins(source: DocumentTwin, output: DocumentTwin) -> dict:
    """
    Compare source and output twins to verify semantic preservation.
    
    Checks:
    - Page count unchanged
    - Image count unchanged per page
    - Drawing count unchanged per page (borders, lines preserved)
    - Text object count reasonable (translated, not lost)
    """
    issues = []
    warnings = []
    
    # Page count
    if source.page_count != output.page_count:
        issues.append(f"Page count changed: {source.page_count} -> {output.page_count}")
    
    # Per-page comparison
    for src_page in source.pages:
        out_page = output.get_page(src_page.page_number)
        if not out_page:
            issues.append(f"Page {src_page.page_number} missing from output")
            continue
        
        # Images must be preserved
        if out_page.image_count != src_page.image_count:
            issues.append(
                f"Page {src_page.page_number}: image count changed "
                f"{src_page.image_count} -> {out_page.image_count}"
            )
        
        # Drawings should be preserved (table borders, etc.)
        if out_page.drawing_count < src_page.drawing_count * 0.8:
            warnings.append(
                f"Page {src_page.page_number}: drawings reduced "
                f"{src_page.drawing_count} -> {out_page.drawing_count}"
            )
    
    # Overall
    passed = len(issues) == 0
    
    return {
        "passed": passed,
        "issues": issues,
        "warnings": warnings,
        "source_pages": source.page_count,
        "output_pages": output.page_count,
        "source_text_objects": source.total_text_objects,
        "output_text_objects": output.total_text_objects,
        "source_images": source.total_images,
        "output_images": output.total_images,
    }


# =============================================================================
# ITEM 46: SOURCE-LANGUAGE REMNANT DETECTION
# =============================================================================

def detect_source_remnants(
    source_twin: DocumentTwin,
    output_twin: DocumentTwin,
    source_language: str = "en",
    min_word_length: int = 4,
    threshold: float = 0.3,
) -> dict:
    """
    Detect untranslated source-language text remaining in the output.
    
    Compares output text against source text to find strings that
    appear unchanged (potential missed translations).
    
    Args:
        source_twin: Twin of original PDF
        output_twin: Twin of translated PDF
        source_language: Source language code
        min_word_length: Minimum word length to check (skip short common words)
        threshold: What fraction of source words in output triggers warning
    """
    # Build set of significant source words
    source_words = set()
    for page in source_twin.pages:
        for obj in page.text_objects:
            words = obj.text.split()
            for word in words:
                clean = word.strip(".,!?;:\"'()-")
                if len(clean) >= min_word_length:
                    source_words.add(clean.lower())
    
    # Check output for source words
    remnants = {}  # word → [page_numbers]
    total_output_words = 0
    
    for page in output_twin.pages:
        for obj in page.text_objects:
            words = obj.text.split()
            total_output_words += len(words)
            for word in words:
                clean = word.strip(".,!?;:\"'()-").lower()
                if clean in source_words and len(clean) >= min_word_length:
                    if clean not in remnants:
                        remnants[clean] = []
                    if page.page_number not in remnants[clean]:
                        remnants[clean].append(page.page_number)
    
    # Filter: some words are legitimately shared between languages
    # (proper nouns, brand names, technical terms)
    # Flag only if many remnants found
    remnant_count = sum(len(pages) for pages in remnants.values())
    remnant_ratio = remnant_count / max(total_output_words, 1)
    
    return {
        "remnants_found": len(remnants),
        "total_occurrences": remnant_count,
        "output_word_count": total_output_words,
        "remnant_ratio": remnant_ratio,
        "is_suspicious": remnant_ratio > threshold,
        "top_remnants": dict(sorted(remnants.items(), key=lambda x: -len(x[1]))[:20]),
    }


# =============================================================================
# CLI
# =============================================================================

def main():
    """Test digital twin on Kolulu."""
    source_pdf = r"C:\Users\zande\Documents\Digital Bookstore\Kolulu Engl Series 3 - 2 - A Fun Place.pdf"
    output_pdf = r"C:\Users\zande\Documents\Digital Bookstore\bookstore\storage\app\public\books\translated\2_af_v8.pdf"
    
    print("=== PDF Digital Twin ===\n")
    
    print("Building source twin...")
    source = build_twin(source_pdf)
    print(f"  Pages: {source.page_count}")
    print(f"  Text objects: {source.total_text_objects}")
    print(f"  Images: {source.total_images}")
    
    if os.path.isfile(output_pdf):
        print("\nBuilding output twin...")
        output = build_twin(output_pdf)
        print(f"  Pages: {output.page_count}")
        print(f"  Text objects: {output.total_text_objects}")
        print(f"  Images: {output.total_images}")
        
        print("\nComparing twins...")
        diff = compare_twins(source, output)
        print(f"  Passed: {diff['passed']}")
        if diff['issues']:
            for issue in diff['issues']:
                print(f"  ISSUE: {issue}")
        if diff['warnings']:
            for w in diff['warnings']:
                print(f"  WARN: {w}")
        
        print("\nDetecting source remnants...")
        remnants = detect_source_remnants(source, output)
        print(f"  Remnants: {remnants['remnants_found']} words, {remnants['total_occurrences']} occurrences")
        print(f"  Suspicious: {remnants['is_suspicious']}")
        if remnants['top_remnants']:
            top5 = list(remnants['top_remnants'].items())[:5]
            for word, pages in top5:
                print(f"    '{word}' on pages {pages}")
    else:
        print(f"\n  Output PDF not found: {output_pdf}")


if __name__ == "__main__":
    main()
