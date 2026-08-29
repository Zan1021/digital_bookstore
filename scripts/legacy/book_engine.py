"""
Digital Bookstore — Book Engine
================================
The core engine for the 100% solution. Handles:
1. Structured Book Extraction (PDF → JSON)
2. Text Fit Engine (font metrics, overflow handling)
3. Page Renderer (artwork + text layers → image)
4. Visual QA integration point

Architecture principle:
"AI should understand, translate, critique and direct layout decisions.
Deterministic application code should perform the actual typography,
measurement and rendering."
"""

import json
import math
import os
import sys
from dataclasses import dataclass, field, asdict
from pathlib import Path
from typing import Optional

import pymupdf


# =============================================================================
# DATA MODELS
# =============================================================================

@dataclass
class Typography:
    """Typography properties for a text block."""
    font_family: str
    font_size: float
    font_weight: str = "normal"  # normal, bold
    font_style: str = "normal"   # normal, italic
    color: str = "#000000"
    alignment: str = "left"
    line_height: float = 0.0
    letter_spacing: float = 0.0

    def to_dict(self):
        return asdict(self)


@dataclass
class Geometry:
    """Geometry/position for a text block."""
    x: float
    y: float
    width: float
    height: float

    def to_dict(self):
        return asdict(self)


@dataclass
class TextBlock:
    """A single text block on a page with all metadata."""
    id: str
    page_number: int
    text: str
    geometry: Geometry
    typography: Typography
    importance: str = "normal"  # critical, high, normal, low
    layout_mode: str = "FLEXIBLE"  # EXACT, FLEXIBLE, INTELLIGENT
    is_page_number: bool = False
    reading_order: int = 0

    def to_dict(self):
        d = asdict(self)
        return d


@dataclass
class PageModel:
    """A single page with artwork and text blocks."""
    page_number: int
    width: float
    height: float
    text_blocks: list = field(default_factory=list)
    background_image_path: Optional[str] = None

    def to_dict(self):
        return {
            "page_number": self.page_number,
            "width": self.width,
            "height": self.height,
            "text_blocks": [tb.to_dict() for tb in self.text_blocks],
            "background_image_path": self.background_image_path,
        }


@dataclass
class BookModel:
    """The complete structured book representation."""
    title: str
    source_language: str
    page_count: int
    pages: list = field(default_factory=list)
    fonts_detected: list = field(default_factory=list)
    metadata: dict = field(default_factory=dict)

    def to_dict(self):
        return {
            "title": self.title,
            "source_language": self.source_language,
            "page_count": self.page_count,
            "fonts_detected": self.fonts_detected,
            "metadata": self.metadata,
            "pages": [p.to_dict() for p in self.pages],
        }

    def save_json(self, path: str):
        with open(path, 'w', encoding='utf-8') as f:
            json.dump(self.to_dict(), f, indent=2, ensure_ascii=False)

    @classmethod
    def load_json(cls, path: str) -> 'BookModel':
        with open(path, 'r', encoding='utf-8') as f:
            data = json.load(f)
        # Reconstruct from dict
        book = cls(
            title=data["title"],
            source_language=data["source_language"],
            page_count=data["page_count"],
            fonts_detected=data.get("fonts_detected", []),
            metadata=data.get("metadata", {}),
        )
        for page_data in data["pages"]:
            page = PageModel(
                page_number=page_data["page_number"],
                width=page_data["width"],
                height=page_data["height"],
                background_image_path=page_data.get("background_image_path"),
            )
            for tb_data in page_data["text_blocks"]:
                geom = Geometry(**tb_data["geometry"])
                typo = Typography(**tb_data["typography"])
                tb = TextBlock(
                    id=tb_data["id"],
                    page_number=tb_data["page_number"],
                    text=tb_data["text"],
                    geometry=geom,
                    typography=typo,
                    importance=tb_data.get("importance", "normal"),
                    layout_mode=tb_data.get("layout_mode", "FLEXIBLE"),
                    is_page_number=tb_data.get("is_page_number", False),
                    reading_order=tb_data.get("reading_order", 0),
                )
                page.text_blocks.append(tb)
            book.pages.append(page)
        return book


# =============================================================================
# PHASE 1: STRUCTURED BOOK EXTRACTION
# =============================================================================

def extract_book(pdf_path: str, output_dir: str) -> BookModel:
    """
    Extract a PDF into a structured BookModel.
    Separates artwork (page images) from text blocks with full metadata.
    """
    doc = pymupdf.open(pdf_path)
    os.makedirs(output_dir, exist_ok=True)
    images_dir = os.path.join(output_dir, "pages")
    os.makedirs(images_dir, exist_ok=True)

    # Detect all fonts in the document
    fonts_seen = set()
    for page in doc:
        for font_info in page.get_fonts(full=True):
            basefont = font_info[3]
            clean = basefont
            if len(basefont) > 7 and basefont[6] == '+':
                clean = basefont[7:]
            fonts_seen.add(clean)

    title = os.path.splitext(os.path.basename(pdf_path))[0]

    book = BookModel(
        title=title,
        source_language="en",
        page_count=len(doc),
        fonts_detected=sorted(fonts_seen),
    )

    for page_idx in range(len(doc)):
        page = doc[page_idx]
        page_num = page_idx + 1

        # Render page as background image (high DPI for quality)
        pix = page.get_pixmap(dpi=150)
        img_path = os.path.join(images_dir, f"page_{page_num:03d}.png")
        pix.save(img_path)

        page_model = PageModel(
            page_number=page_num,
            width=page.rect.width,
            height=page.rect.height,
            background_image_path=img_path,
        )

        # Extract all text blocks with full metadata
        text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
        block_counter = 0

        for block in text_dict.get("blocks", []):
            if block.get("type") != 0:  # Skip image blocks
                continue

            for line in block.get("lines", []):
                for span in line.get("spans", []):
                    text = span.get("text", "").strip()
                    if not text:
                        continue

                    block_counter += 1
                    bbox = span["bbox"]
                    origin = span["origin"]
                    flags = span["flags"]

                    # Determine font weight and style from flags
                    is_bold = bool(flags & (1 << 4))
                    is_italic = bool(flags & (1 << 1))

                    # Convert color int to hex
                    color_int = span.get("color", 0)
                    r = (color_int >> 16) & 0xFF
                    g = (color_int >> 8) & 0xFF
                    b = color_int & 0xFF
                    color_hex = f"#{r:02x}{g:02x}{b:02x}"

                    # Clean font name (remove subset prefix)
                    font_name = span["font"]
                    if len(font_name) > 7 and font_name[6] == '+':
                        font_name = font_name[7:]

                    # Detect if this is a page number
                    is_page_num = (
                        text.isdigit() and 
                        len(text) <= 3 and 
                        span["size"] < 20
                    )

                    # Determine importance
                    importance = "normal"
                    if span["size"] >= 40:
                        importance = "high"  # Title text
                    elif span["size"] < 10:
                        importance = "low"   # Fine print
                    elif is_page_num:
                        importance = "low"

                    # Determine layout mode
                    layout_mode = "FLEXIBLE"
                    if is_page_num or importance == "low":
                        layout_mode = "EXACT"
                    elif importance == "high":
                        layout_mode = "EXACT"

                    # Calculate geometry
                    geom = Geometry(
                        x=origin[0],
                        y=origin[1],
                        width=bbox[2] - bbox[0],
                        height=bbox[3] - bbox[1],
                    )

                    # Calculate line height (estimate from font size)
                    line_height = span["size"] * 1.15

                    typo = Typography(
                        font_family=font_name,
                        font_size=span["size"],
                        font_weight="bold" if is_bold else "normal",
                        font_style="italic" if is_italic else "normal",
                        color=color_hex,
                        alignment="left",
                        line_height=line_height,
                    )

                    tb = TextBlock(
                        id=f"p{page_num}_b{block_counter}",
                        page_number=page_num,
                        text=text,
                        geometry=geom,
                        typography=typo,
                        importance=importance,
                        layout_mode=layout_mode,
                        is_page_number=is_page_num,
                        reading_order=block_counter,
                    )

                    page_model.text_blocks.append(tb)

        book.pages.append(page_model)

    doc.close()
    return book


# =============================================================================
# PHASE 2: TEXT FIT ENGINE
# =============================================================================

class TextFitEngine:
    """
    Deterministic text fitting engine.
    Measures text using actual font metrics and applies the fitting hierarchy:
    1. Natural line wrapping
    2. Slight line-height adjustment
    3. Slight letter-spacing adjustment
    4. Controlled text-box height adjustment
    5. Small font-size reduction (max 15%)
    6. Request shorter translation (returned as flag)
    """

    def __init__(self, fonts_dir: str):
        self.fonts_dir = fonts_dir
        self._font_cache = {}

    def get_font(self, font_family: str) -> pymupdf.Font:
        """Load a font from the fonts directory. Defaults to Playpen Sans."""
        if font_family in self._font_cache:
            return self._font_cache[font_family]

        # Try to find the font file
        candidates = [
            os.path.join(self.fonts_dir, f"{font_family}.ttf"),
            os.path.join(self.fonts_dir, f"{font_family.replace(' ', '')}.ttf"),
            os.path.join(self.fonts_dir, f"{font_family.replace('-', '')}.ttf"),
        ]

        for path in candidates:
            if os.path.isfile(path):
                font = pymupdf.Font(fontfile=path)
                self._font_cache[font_family] = font
                return font

        # Default to Playpen Sans Regular (the universal children's book font)
        playpen_path = os.path.join(self.fonts_dir, "PlaypenSans-Regular.ttf")
        if os.path.isfile(playpen_path):
            font = pymupdf.Font(fontfile=playpen_path)
            self._font_cache[font_family] = font
            return font

        # Last resort fallback to built-in
        font = pymupdf.Font("helv")
        self._font_cache[font_family] = font
        return font

    def measure_text(self, text: str, font_family: str, font_size: float) -> dict:
        """
        Measure text dimensions using actual font metrics.
        Returns: width, height, lines (if wrapped)
        """
        font = self.get_font(font_family)
        width = font.text_length(text, fontsize=font_size)
        # Approximate height based on font metrics
        ascender = font.ascender * font_size
        descender = abs(font.descender) * font_size
        line_height = ascender + descender
        
        return {
            "width": width,
            "line_height": line_height,
            "single_line_height": line_height,
            "font_size": font_size,
        }

    def fit_text(
        self, 
        text: str, 
        text_block: TextBlock, 
        available_width: float,
        page_width: float
    ) -> dict:
        """
        Fit translated text into the original text block's space.
        Returns fitting result with adjusted parameters.
        
        Priority hierarchy:
        1. Natural fit (no changes needed)
        2. Allow text to extend to page edge (generous width)
        3. Slight font-size reduction (max 15%)
        4. Flag for shorter translation
        """
        font_family = text_block.typography.font_family
        font_size = text_block.typography.font_size
        font = self.get_font(font_family)

        # Calculate the actual available width
        # From the text origin to the right edge minus margin
        origin_x = text_block.geometry.x
        right_margin = 30
        full_available = page_width - origin_x - right_margin

        # Use the more generous of: original width or full available
        effective_width = max(available_width, full_available)

        # Measure the text
        text_width = font.text_length(text, fontsize=font_size)

        result = {
            "text": text,
            "font_size": font_size,
            "line_height": text_block.typography.line_height,
            "letter_spacing": 0,
            "fits": True,
            "adjustments": [],
            "needs_shorter_translation": False,
        }

        # Step 1: Does it fit naturally?
        if text_width <= effective_width:
            return result

        # Step 2: For story text (FLEXIBLE mode), allow slight overflow
        # Children's books often have generous margins
        if text_block.layout_mode == "FLEXIBLE":
            overflow_ratio = text_width / effective_width
            if overflow_ratio <= 1.10:  # Up to 10% overflow is OK
                result["adjustments"].append("slight_overflow_accepted")
                return result

        # Step 3: Slight font-size reduction (max 15%)
        if text_block.layout_mode != "EXACT":
            shrink_ratio = effective_width / text_width
            if shrink_ratio >= 0.85:
                new_size = font_size * shrink_ratio
                result["font_size"] = round(new_size, 1)
                result["adjustments"].append(f"font_reduced_{100-round(shrink_ratio*100)}%")
                return result

        # Step 4: Flag for shorter translation
        result["fits"] = False
        result["needs_shorter_translation"] = True
        result["adjustments"].append("needs_shorter_translation")
        result["max_chars_estimate"] = int(len(text) * (effective_width / text_width))

        return result


# =============================================================================
# PHASE 3: PAGE RENDERER
# =============================================================================

class PageRenderer:
    """
    Deterministic page renderer.
    Takes a page background + translated text blocks → rendered page.
    Separates artwork layer from text layer.
    """

    def __init__(self, fonts_dir: str):
        self.fonts_dir = fonts_dir

    def render_page(
        self,
        original_pdf_path: str,
        page_number: int,
        text_blocks: list,
        output_path: str,
        dpi: int = 150,
    ) -> str:
        """
        Render a translated page:
        1. Import original page as artwork background
        2. White out original text areas
        3. Place translated text at exact positions
        4. Save as image for QA
        """
        doc = pymupdf.open(original_pdf_path)
        page = doc[page_number - 1]

        # Redact all original text areas that are being replaced
        for tb in text_blocks:
            if tb.get("is_page_number", False):
                continue  # Don't touch page numbers
            
            # Create redaction rect from the original geometry
            geom = tb["original_geometry"]
            rect = pymupdf.Rect(
                geom["x"] - 2,
                geom["y"] - tb["original_typography"]["font_size"],
                geom["x"] + geom["width"] + 2,
                geom["y"] + 5
            )
            page.add_redact_annot(rect, text="", fill=(1, 1, 1))

        page.apply_redactions()

        # Insert translated text
        for tb in text_blocks:
            if tb.get("is_page_number", False):
                continue

            text = tb["translated_text"]
            origin = pymupdf.Point(tb["original_geometry"]["x"], tb["original_geometry"]["y"])
            font_size = tb.get("fitted_font_size", tb["original_typography"]["font_size"])
            font_family = tb["original_typography"]["font_family"]

            # Find font file
            font_file = self._find_font_file(font_family)

            # Parse color
            color_hex = tb["original_typography"].get("color", "#000000")
            r = int(color_hex[1:3], 16) / 255
            g = int(color_hex[3:5], 16) / 255
            b = int(color_hex[5:7], 16) / 255

            try:
                if font_file:
                    page.insert_text(
                        point=origin,
                        text=text,
                        fontsize=font_size,
                        fontname="F0",
                        fontfile=font_file,
                        color=(r, g, b),
                    )
                else:
                    page.insert_text(
                        point=origin,
                        text=text,
                        fontsize=font_size,
                        fontname="helv",
                        color=(r, g, b),
                    )
            except Exception as e:
                print(f"Warning: Failed to insert text on page {page_number}: {e}", file=sys.stderr)

        # Save as image for Visual QA
        pix = page.get_pixmap(dpi=dpi)
        pix.save(output_path)

        doc.close()
        return output_path

    def _find_font_file(self, font_family: str) -> str | None:
        """Find a font file in the fonts directory."""
        candidates = [
            os.path.join(self.fonts_dir, f"{font_family}.ttf"),
            os.path.join(self.fonts_dir, f"{font_family.replace(' ', '')}.ttf"),
            os.path.join(self.fonts_dir, f"{font_family.replace('-', '')}.ttf"),
        ]
        for path in candidates:
            if os.path.isfile(path):
                return path
        return None


# =============================================================================
# CLI
# =============================================================================

def main():
    import argparse

    parser = argparse.ArgumentParser(description="Digital Bookstore — Book Engine")
    subparsers = parser.add_subparsers(dest="command")

    # Extract command
    extract_p = subparsers.add_parser("extract", help="Extract PDF into structured book model")
    extract_p.add_argument("--input", "-i", required=True, help="Input PDF")
    extract_p.add_argument("--output", "-o", required=True, help="Output directory")

    # Measure command (test text fitting)
    measure_p = subparsers.add_parser("measure", help="Measure text width for a font")
    measure_p.add_argument("--text", "-t", required=True, help="Text to measure")
    measure_p.add_argument("--font", "-f", required=True, help="Font family name")
    measure_p.add_argument("--size", "-s", type=float, required=True, help="Font size in pt")
    measure_p.add_argument("--fonts-dir", required=True, help="Fonts directory")

    args = parser.parse_args()

    if args.command == "extract":
        print(f"Extracting: {args.input}", file=sys.stderr)
        book = extract_book(args.input, args.output)
        
        # Save the structured model
        json_path = os.path.join(args.output, "book.json")
        book.save_json(json_path)
        
        print(f"Extraction complete:", file=sys.stderr)
        print(f"  Pages: {book.page_count}", file=sys.stderr)
        print(f"  Fonts: {', '.join(book.fonts_detected)}", file=sys.stderr)
        
        total_blocks = sum(len(p.text_blocks) for p in book.pages)
        print(f"  Text blocks: {total_blocks}", file=sys.stderr)
        print(f"  Output: {json_path}", file=sys.stderr)
        
        # Output JSON path to stdout
        print(json_path)

    elif args.command == "measure":
        engine = TextFitEngine(args.fonts_dir)
        result = engine.measure_text(args.text, args.font, args.size)
        print(json.dumps(result, indent=2))

    else:
        parser.print_help()


if __name__ == "__main__":
    main()
