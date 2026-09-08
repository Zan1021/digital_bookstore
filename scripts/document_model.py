"""
Canonical Document Model — Digital Bookstore V8
=================================================
The canonical scene graph: typed dataclass model that is the single source of
truth for the entire pipeline. Translation and rendering operate on this model,
not on raw extracted text.

Per the brief (V8 Normative Spec):
  "The scene graph is the source of truth. Translation and rendering must
   operate on it rather than directly on extracted text lines."

This replaces ad-hoc dicts with proper typed structures:
  - PageScene: immutable page geometry + all objects
  - Region: semantic grouping with containers and policies
  - TextUnit: individual text object with stable ID
  - TextStyle: reusable typography style family
  - FontRef: font reference with embedding info

Usage:
    from document_model import DocumentScene, PageScene, Region, TextUnit
    
    # Build from PDF
    doc_scene = build_document_scene(pdf_path)
    
    # Get translation units
    units = doc_scene.get_translatable_units()
    
    # After translation, render
    for page in doc_scene.pages:
        for unit in page.text_units:
            ...
"""

from dataclasses import dataclass, field
from typing import Optional, Literal
from enum import Enum


# =============================================================================
# TYPE DEFINITIONS
# =============================================================================

BBox = tuple[float, float, float, float]  # (x0, y0, x1, y1)
Point = tuple[float, float]               # (x, y)
Polygon = list[Point]                      # List of vertices


class TranslationPolicy(str, Enum):
    TRANSLATE = "translate"
    PRESERVE = "preserve"
    TRANSLITERATE = "transliterate"
    EDUCATIONAL_ADAPTATION = "educational_adaptation"
    MANUAL_REVIEW = "manual_review"
    EXCLUDE = "exclude"


class RegionType(str, Enum):
    STORY_PROSE = "story_prose"
    COVER_TITLE = "cover_title"
    COVER_SUBTITLE = "cover_subtitle"
    PUBLISHER_INFO = "publisher_info"
    COPYRIGHT_TEXT = "copyright_text"
    CHARACTER_BIO = "character_bio"
    WORD_LIST = "word_list"
    HIGH_FREQUENCY_WORDS = "high_frequency_words"
    PHONICS = "phonics"
    TABLE_HEADER = "table_header"
    TABLE_CELL = "table_cell"
    TITLE_LIST = "title_list"
    LIST_HEADING = "list_heading"
    LIST_ITEM = "list_item"
    CAPTION = "caption"
    LABEL = "label"
    SPEECH_BUBBLE = "speech_bubble"
    PAGE_NUMBER = "page_number"
    BRAND_LOGO = "brand_logo"
    HEADING = "heading"
    UNKNOWN = "unknown"


class SemanticRole(str, Enum):
    BOOK_TITLE = "book_title"
    SERIES_NAME = "series_name"
    SUBTITLE = "subtitle"
    AUTHOR_NAME = "author_name"
    PUBLISHER = "publisher"
    PARAGRAPH = "paragraph"
    DIALOGUE = "dialogue"
    CAPTION = "caption"
    LABEL = "label"
    HEADING = "heading"
    TABLE_CELL = "table_cell"
    WORD_LIST_ITEM = "word_list_item"
    PAGE_NUMBER = "page_number"
    COPYRIGHT = "copyright"
    LIST_ITEM = "list_item"


# =============================================================================
# FONT AND STYLE
# =============================================================================

@dataclass(frozen=True)
class FontRef:
    """Reference to a specific font."""
    family: str
    postscript_name: str = ""
    weight: int = 400
    italic: bool = False
    embedded: bool = True
    source_resource: Optional[str] = None  # PDF font resource name (e.g., "F12")
    file_path: Optional[str] = None


@dataclass(frozen=True)
class TextStyle:
    """Reusable typography style family."""
    style_id: str
    font: FontRef
    nominal_size_pt: float
    fill_rgb: tuple[float, float, float] = (0.0, 0.0, 0.0)
    line_height_pt: Optional[float] = None
    tracking_em: float = 0.0
    alignment: str = "left"
    baseline_shift_pt: float = 0.0
    # Fitting constraints
    minimum_size_pt: float = 7.0
    maximum_size_pt: Optional[float] = None
    permitted_local_shrink: float = 0.15  # max 15% reduction


# =============================================================================
# TEXT UNIT — The atomic translatable element
# =============================================================================

@dataclass
class TextUnit:
    """
    A single text object with a stable ID.
    This is the atomic unit for translation and rendering.
    """
    id: str
    source_text: str
    bbox: BBox
    baseline: Point
    style_id: str
    reading_order: int
    semantic_role: str
    translation_policy: str = "translate"
    parent_region_id: str = ""
    # Optional structural info
    line_id: Optional[str] = None
    paragraph_id: Optional[str] = None
    row_index: Optional[int] = None
    column_index: Optional[int] = None
    row_span: int = 1
    column_span: int = 1
    # Geometry
    rotation_deg: float = 0.0
    text_matrix: Optional[list] = None
    # Structure-aware placement (spec: v8-structure-aware-engine, Req 1/3). All
    # optional so existing construction is unaffected; populated during scene build
    # for table/vocab pages and any element with a resolvable cell.
    cell_box: Optional[tuple] = None      # true render box (spans merged columns)
    align_h: Optional[str] = None         # "left" | "center" | "right" (from source)
    align_v: Optional[str] = None         # "top" | "middle" | "bottom" (from source)
    peer_group_id: Optional[str] = None   # elements that must share a size
    column_span: int = 1                  # >1 for merged/spanning header cells
    is_merged: bool = False               # True for a merged (spanning) header cell
    # Quality
    confidence: float = 1.0
    extraction_source: str = "native"  # "native", "ocr", "inferred"
    
    @property
    def width(self) -> float:
        return self.bbox[2] - self.bbox[0]
    
    @property
    def height(self) -> float:
        return self.bbox[3] - self.bbox[1]


# =============================================================================
# REGION — Semantic grouping of text units
# =============================================================================

@dataclass
class Region:
    """
    A semantic region on a page — groups related text units with
    a container and translation policy.
    """
    id: str
    region_type: str
    bbox: BBox
    safe_polygon: Optional[Polygon] = None  # For non-rectangular containers
    child_ids: list = field(default_factory=list)
    reading_order: int = 0
    page_family_id: Optional[str] = None
    confidence: float = 1.0
    translation_policy: str = "translate"
    # Container info
    design_container_bbox: Optional[BBox] = None  # May differ from ink bbox
    container_source: str = "glyph_union"  # "table_cell", "clip_path", "page_family", etc.
    # Metadata
    metadata: dict = field(default_factory=dict)
    
    @property
    def width(self) -> float:
        return self.bbox[2] - self.bbox[0]
    
    @property
    def height(self) -> float:
        return self.bbox[3] - self.bbox[1]
    
    def get_container(self) -> BBox:
        """Get the effective container (design container if available, else bbox)."""
        return self.design_container_bbox or self.bbox


# =============================================================================
# PAGE SCENE — Everything on one page
# =============================================================================

@dataclass
class PageScene:
    """Complete scene representation for one PDF page."""
    page_number: int
    width_pt: float
    height_pt: float
    rotation: int = 0
    media_box: BBox = (0, 0, 0, 0)
    crop_box: BBox = (0, 0, 0, 0)
    trim_box: Optional[BBox] = None
    bleed_box: Optional[BBox] = None
    # Content
    regions: list[Region] = field(default_factory=list)
    text_units: list[TextUnit] = field(default_factory=list)
    styles: dict[str, TextStyle] = field(default_factory=dict)
    # Classification
    page_type: str = "unknown"  # cover, story, vocabulary, etc.
    page_family_id: Optional[str] = None
    # Metadata
    has_images: bool = False
    has_vector_drawings: bool = False
    image_count: int = 0
    drawing_count: int = 0
    
    def get_translatable_units(self) -> list[TextUnit]:
        """Get all text units that need target-language text produced.

        Includes BOTH literal translation units and educational_adaptation units
        (phonics/sound-pattern exercises that are REGENERATED in the target language,
        not translated 1:1). Both require target text and a stable id in the contract;
        excluding adaptation units previously dropped phonics from the translation
        request and the required-id validation set. Book-agnostic: keyed on policy.
        """
        return [u for u in self.text_units
                if u.translation_policy in ("translate", "educational_adaptation")]

    def get_renderable_units(self) -> list[TextUnit]:
        """Units the renderer must place with target-language text (translate +
        educational_adaptation). Preserved units keep their source and are handled
        separately. Explicit name for the render path so intent is unambiguous."""
        return [u for u in self.text_units
                if u.translation_policy in ("translate", "educational_adaptation")]
    
    def get_preserved_units(self) -> list[TextUnit]:
        """Get all text units that should be preserved unchanged."""
        return [u for u in self.text_units 
                if u.translation_policy == "preserve"]
    
    def region_by_id(self, region_id: str) -> Optional[Region]:
        """Look up a region by ID."""
        for r in self.regions:
            if r.id == region_id:
                return r
        return None
    
    def unit_by_id(self, unit_id: str) -> Optional[TextUnit]:
        """Look up a text unit by ID."""
        for u in self.text_units:
            if u.id == unit_id:
                return u
        return None


# =============================================================================
# DOCUMENT SCENE — The entire book
# =============================================================================

@dataclass
class DocumentScene:
    """
    Complete scene graph for an entire PDF document.
    This is THE source of truth for translation and rendering.
    """
    document_id: str
    source_path: str
    source_sha256: str = ""
    source_language: str = "en"
    total_pages: int = 0
    # Content
    pages: list[PageScene] = field(default_factory=list)
    # Document-level style registry
    style_registry: dict[str, TextStyle] = field(default_factory=dict)
    # Page families (repeated template groups)
    page_families: dict[str, list[int]] = field(default_factory=dict)
    # Metadata
    pdf_version: str = ""
    is_tagged: bool = False
    has_encryption: bool = False
    document_language: Optional[str] = None
    
    def get_all_translatable_units(self) -> list[TextUnit]:
        """Get all translatable units across all pages."""
        units = []
        for page in self.pages:
            units.extend(page.get_translatable_units())
        return units
    
    def get_page(self, page_number: int) -> Optional[PageScene]:
        """Get a page by number (1-based)."""
        for p in self.pages:
            if p.page_number == page_number:
                return p
        return None
    
    def get_unit_count(self) -> dict:
        """Get counts of units by translation policy."""
        counts = {"translate": 0, "preserve": 0, "other": 0}
        for page in self.pages:
            for unit in page.text_units:
                if unit.translation_policy in counts:
                    counts[unit.translation_policy] += 1
                else:
                    counts["other"] += 1
        return counts
    
    def to_translation_request(self, target_language: str) -> dict:
        """
        Generate a structured translation request from the scene graph.
        This is the stable-ID JSON contract.
        """
        items = []
        for page in self.pages:
            for unit in page.get_translatable_units():
                region = page.region_by_id(unit.parent_region_id)
                item = {
                    "id": unit.id,
                    "source_text": unit.source_text,
                    "semantic_role": unit.semantic_role,
                    "page_number": page.page_number,
                    "context": {
                        "region_type": region.region_type if region else "unknown",
                        "page_type": page.page_type,
                    },
                    "constraints": {
                        "max_width_pt": unit.width if unit.width > 0 else None,
                        "style_family": unit.style_id,
                    },
                }
                # Structure-aware placement fields (spec Req 2.1/3): only emitted when
                # populated (table/vocab pages), so non-structured items stay compact.
                if getattr(unit, "cell_box", None) is not None:
                    item["cell_box"] = list(unit.cell_box)
                if getattr(unit, "align_h", None):
                    item["align_h"] = unit.align_h
                if getattr(unit, "align_v", None):
                    item["align_v"] = unit.align_v
                if getattr(unit, "peer_group_id", None):
                    item["peer_group_id"] = unit.peer_group_id
                if getattr(unit, "column_span", 1) and unit.column_span != 1:
                    item["column_span"] = unit.column_span
                if getattr(unit, "is_merged", False):
                    item["is_merged"] = True
                items.append(item)

        return {
            "document_id": self.document_id,
            "source_language": self.source_language,
            "target_language": target_language,
            "schema_version": "2.0",
            "items": items,
        }
    
    def validate_translation_response(self, response: dict) -> dict:
        """
        Validate a translation response against the scene graph.
        Ensures all required IDs are present, no duplicates, no unknowns.
        """
        errors = []
        warnings = []
        
        # Get all required IDs
        required_ids = set()
        for page in self.pages:
            for unit in page.get_translatable_units():
                required_ids.add(unit.id)
        
        # Get response IDs
        response_items = response.get("items", [])
        response_ids = set()
        response_map = {}
        
        for item in response_items:
            item_id = item.get("id")
            if not item_id:
                errors.append("Item without ID found in response")
                continue
            
            if item_id in response_ids:
                errors.append(f"Duplicate ID in response: {item_id}")
            response_ids.add(item_id)
            response_map[item_id] = item
        
        # Check for missing IDs
        missing = required_ids - response_ids
        if missing:
            errors.append(f"Missing translations for {len(missing)} units: {list(missing)[:5]}...")
        
        # Check for unknown IDs
        unknown = response_ids - required_ids
        if unknown:
            errors.append(f"Unknown IDs in response: {list(unknown)[:5]}...")
        
        # Check for empty translations
        for item_id in required_ids & response_ids:
            item = response_map.get(item_id, {})
            translation = item.get("translation", "")
            if not translation.strip():
                warnings.append(f"Empty translation for: {item_id}")
        
        return {
            "valid": len(errors) == 0,
            "errors": errors,
            "warnings": warnings,
            "coverage": {
                "required": len(required_ids),
                "provided": len(response_ids & required_ids),
                "missing": len(missing),
                "unknown": len(unknown),
            },
        }


# =============================================================================
# BUILDER — Construct DocumentScene from a PDF
# =============================================================================

def build_document_scene(pdf_path: str, document_id: str = None) -> DocumentScene:
    """
    Build a complete DocumentScene from a PDF file.
    
    This is the main entry point for the scene graph pipeline.
    It replaces the old extract_page_spans → classify_page → render approach
    with a proper structured model.
    """
    import hashlib
    import os
    import pymupdf
    
    if not document_id:
        document_id = os.path.basename(pdf_path).rsplit('.', 1)[0]
    
    # Hash source file
    with open(pdf_path, 'rb') as f:
        source_hash = hashlib.sha256(f.read()).hexdigest()
    
    doc = pymupdf.open(pdf_path)
    
    scene = DocumentScene(
        document_id=document_id,
        source_path=pdf_path,
        source_sha256=source_hash,
        total_pages=len(doc),
        pdf_version=f"{doc.metadata.get('format', 'PDF')}",
    )
    
    # Build each page
    for page_idx in range(len(doc)):
        page = doc[page_idx]
        page_num = page_idx + 1
        
        page_scene = _build_page_scene(page, page_num, len(doc))
        scene.pages.append(page_scene)
    
    doc.close()
    
    # Discover page families
    _discover_page_families(scene)
    
    # Apply page-family consensus containers
    _apply_consensus_containers(scene)
    
    return scene


def _merge_continuation_spans(spans, page_type):
    """
    Merge consecutive CONTINUATION lines into one logical span so a multi-line entry
    (phonics rule, wrapped sentence) becomes ONE unit — not one-per-line. Book-agnostic.

    A span B is a continuation of the preceding span A (in reading order) when ALL of:
      - both are body text (not page numbers, not ALL-CAPS headings/display),
      - same column: A and B left edges align within a tolerance derived from A's height,
      - vertically consecutive downward: 0 < top(B) - bottom(A) <= ~0.9 * lineHeight
        (tight leading = same block; a big blank gap = new entry),
      - A does NOT already look "terminated": A does not end a self-contained short
        list word. We treat A as continuing when A ends without sentence-final
        punctuation AND (A has >1 word OR ends with ':' or '-' or a comma), which is
        the shape of a wrapped rule/sentence rather than a single vocabulary word.

    Single-word vocabulary columns (one token per line, each its own entry) are left
    untouched because a lone word is self-contained (fails the ">1 word / open
    punctuation" test), so we never over-merge them.
    """
    import re as _re
    if not spans:
        return spans

    def _is_body(s):
        if s.get("is_page_number"):
            return False
        t = s.get("text_stripped", "")
        letters = [c for c in t if c.isalpha()]
        if letters and all(c.isupper() for c in letters) and len(t) < 30:
            return False  # ALL-CAPS heading/display line
        return bool(t)

    def _continues_into(a_text, b_text):
        """Conservative, book-agnostic continuation test. B continues A only when the
        join is UNAMBIGUOUS prose wrap, not two stacked short entries:
          - A does not end with terminal/entry punctuation (. ! ? , : ; -) — a comma
            or colon ends a list entry, so it is NOT a continuation; and
          - A is multi-word AND B is multi-word — a wrapped clause has running words on
            BOTH lines. Two single words stacked (a vocabulary column) are NOT merged; and
          - B starts lowercase (a wrapped clause continues in lower case).
        Merges true wraps ("3 letter consonant blends at the" + "beginning of words:")
        while keeping single-word columns and distinct rule lines separate."""
        a = (a_text or "").strip()
        b = (b_text or "").strip()
        if not a or not b:
            return False
        if a[-1] in ".!?,:;-–—":
            return False
        if len(a.split()) < 2 or len(b.split()) < 2:
            return False
        # A line that itself contains an internal " - "/" – " delimiter is a
        # self-contained "<pattern> - <examples>" entry (e.g. "ai - plain, rain"),
        # NOT a continuation. Neither A nor B may be such an entry to merge.
        import re as _re2
        delim = _re2.compile(r"\s[-–—]\s")
        if delim.search(a) or delim.search(b):
            return False
        return b.lstrip()[:1].islower()

    # Process column-by-column: cluster body spans by left-x so a continuation line
    # is compared against the line directly ABOVE IT IN THE SAME COLUMN (not against
    # an interleaved neighbour column at a similar y). Non-body spans pass through.
    body = [s for s in spans if _is_body(s)]
    non_body = [s for s in spans if not _is_body(s)]

    # Cluster by left edge (column). Tolerance scales with typical text height.
    cols = []
    for s in sorted(body, key=lambda z: z["bbox"][0]):
        placed = False
        for c in cols:
            if abs(s["bbox"][0] - c["x"]) <= max(6.0, (s["bbox"][3] - s["bbox"][1]) * 0.9):
                c["spans"].append(s)
                c["x"] = (c["x"] * (len(c["spans"]) - 1) + s["bbox"][0]) / len(c["spans"])
                placed = True
                break
        if not placed:
            cols.append({"x": s["bbox"][0], "spans": [s]})

    merged_body = []
    for c in cols:
        col_spans = sorted(c["spans"], key=lambda z: z["bbox"][1])  # top -> bottom
        acc = None
        for s in col_spans:
            if acc is not None:
                a_h = max(1.0, acc["bbox"][3] - acc["bbox"][1])
                vgap = s["bbox"][1] - acc["bbox"][3]
                line_h = max(a_h, s["bbox"][3] - s["bbox"][1])
                consecutive = -0.3 * line_h <= vgap <= 0.9 * line_h
                if consecutive and _continues_into(acc["text_stripped"], s["text_stripped"]):
                    acc["text_stripped"] = (acc["text_stripped"].rstrip() + " " +
                                            s["text_stripped"].lstrip()).strip()
                    acc["text"] = acc["text_stripped"]
                    acc["bbox"] = [min(acc["bbox"][0], s["bbox"][0]),
                                   min(acc["bbox"][1], s["bbox"][1]),
                                   max(acc["bbox"][2], s["bbox"][2]),
                                   max(acc["bbox"][3], s["bbox"][3])]
                    # Keep the FIRST line's origin (the merged unit's anchor).
                    continue
                merged_body.append(acc)
            acc = s
        if acc is not None:
            merged_body.append(acc)

    # Restore reading order by (y, x) so downstream reading_order is sensible.
    out = sorted(non_body + merged_body, key=lambda z: (round(z["bbox"][1] / 2), z["bbox"][0]))
    return out


def _infer_align_h(source_box, cell_box, tol_frac=0.12):
    """Infer horizontal alignment of source text within its cell from glyph geometry
    (spec Req 3.2: mirror the source, don't impose). Book-agnostic."""
    if not source_box or not cell_box:
        return "left"
    left_gap = max(0.0, source_box[0] - cell_box[0])
    right_gap = max(0.0, cell_box[2] - source_box[2])
    total = left_gap + right_gap
    if total <= 1.0:
        return "center"
    diff = abs(left_gap - right_gap) / max(1.0, cell_box[2] - cell_box[0])
    if diff < tol_frac:
        return "center"
    return "left" if left_gap < right_gap else "right"


def _infer_align_v(source_box, cell_box, tol_frac=0.15):
    """Infer vertical alignment of source text within its cell from glyph geometry.
    Book-agnostic: compares the top gap vs bottom gap inside the cell box."""
    if not source_box or not cell_box:
        return "top"
    top_gap = max(0.0, source_box[1] - cell_box[1])
    bot_gap = max(0.0, cell_box[3] - source_box[3])
    total = top_gap + bot_gap
    if total <= 1.0:
        return "middle"
    diff = abs(top_gap - bot_gap) / max(1.0, cell_box[3] - cell_box[1])
    if diff < tol_frac:
        return "middle"
    return "top" if top_gap < bot_gap else "bottom"


def _attach_table_structure(page, page_num, spans, text_units):
    """
    Populate cell_box / align_h / align_v / column_span / is_merged / peer_group_id on
    the TextUnits of a table/vocabulary page, using the detected grid + merged header
    cells (spec Req 1/3). Book-agnostic: everything derived from grid geometry + source
    glyph positions. Silently no-ops if no grid is present.
    """
    try:
        from universal_containers import detect_table_grid, detect_header_cells
    except Exception:
        return
    grid = detect_table_grid(page)
    if grid is None or not getattr(grid, "columns", None):
        return

    # Header cells (incl. merged) mapped from the header source spans.
    header_spans = [s for s in spans
                    if s.get("text_stripped", "") and _classify_span_role(s, "vocabulary") in
                    ("heading", "table_header")]
    hdr = detect_header_cells(grid, header_spans)
    header_cells = hdr.get("cells", [])
    header_row_box = hdr.get("header_row_box")

    def _unit_center(u):
        b = u.bbox
        return ((b[0] + b[2]) / 2, (b[1] + b[3]) / 2)

    for u in text_units:
        cx, cy = _unit_center(u)
        role = u.semantic_role or ""
        is_header_like = role in ("heading", "table_header") or (
            header_row_box and header_row_box[1] - 2 <= cy <= header_row_box[3] + 2)

        if is_header_like and header_cells:
            # Assign to the header cell whose box contains this unit's center-x.
            cell = None
            for c in header_cells:
                cb = c["cell_box"]
                if cb[0] - 2 <= cx <= cb[2] + 2:
                    cell = c
                    break
            if cell:
                u.cell_box = tuple(cell["cell_box"])
                u.column_span = cell["column_span"]
                u.is_merged = cell["column_span"] > 1
                u.align_h = "center"   # table headers are centered in their cell
                u.align_v = "middle"
                u.peer_group_id = f"p{page_num}-headers"
                continue

        # Content word cell: bound by the column that contains the unit center-x.
        col = None
        for i, (cs, ce) in enumerate(grid.columns):
            if cs - 2 <= cx <= ce + 2:
                col = (i, cs, ce)
                break
        if col is not None:
            i, cs, ce = col
            # Vertical extent: the row band containing the unit (fallback: unit height).
            cy0, cy1 = u.bbox[1], u.bbox[3]
            for (rs, re) in grid.rows:
                if rs - 2 <= cy <= re + 2:
                    cy0, cy1 = rs, re
                    break
            u.cell_box = (cs, cy0, ce, cy1)
            u.column_span = 1
            u.align_h = _infer_align_h(u.bbox, u.cell_box)
            u.align_v = _infer_align_v(u.bbox, u.cell_box) if (cy1 - cy0) > u.bbox[3] - u.bbox[1] + 2 else "top"
            u.peer_group_id = f"p{page_num}-col{i}"


def _infer_align_h_public(source_box, cell_box):
    return _infer_align_h(source_box, cell_box)


def _build_page_scene(page, page_num: int, total_pages: int) -> PageScene:
    """Build a PageScene from a pymupdf page."""
    import pymupdf
    
    # Import the V8 classifier and extractor
    import sys, os
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
    from pdf_translate_v8 import extract_page_spans, classify_page
    from rotated_text import get_rotation_angle, is_rotated as _is_rotated
    
    # Extract spans
    spans = extract_page_spans(page, page_num)

    # Classify page type (needed before the logical-unit merge decision).
    page_type = classify_page(spans, page_num, total_pages)

    # LOGICAL-UNIT MERGE (§8): the PDF stores each physical LINE as its own span, so a
    # multi-line logical entry (e.g. a phonics rule "3 letter consonant blends at the
    # beginning of words: str - str-eam", or a sentence that wraps) arrives as several
    # spans and would become several units — splitting one entry into many and mis-
    # grouping neighbours. Merge consecutive CONTINUATION spans within a column into a
    # single logical span so a unit reflects the logical entry, not the line break.
    # Book-agnostic: pure geometry + content-shape heuristics, no per-title constants.
    spans = _merge_continuation_spans(spans, page_type)
    page_type = classify_page(spans, page_num, total_pages)

    # Flag a document end-marker (e.g. "The End"/"Die Einde") as its own element so it
    # is not glued to the last sentence (spec Req 1.5). Book-agnostic.
    spans = _mark_end_markers(spans, page_type)
    
    # Page geometry
    page_scene = PageScene(
        page_number=page_num,
        width_pt=page.rect.width,
        height_pt=page.rect.height,
        rotation=page.rotation,
        media_box=tuple(page.mediabox),
        crop_box=tuple(page.cropbox),
        trim_box=tuple(page.trimbox) if page.trimbox else None,
        bleed_box=tuple(page.bleedbox) if page.bleedbox else None,
        page_type=page_type,
        has_images=len(page.get_images()) > 0,
        image_count=len(page.get_images()),
    )
    
    # Build text units and regions from spans
    reading_order = 0
    region_spans = {}  # Group spans by region
    
    for span in spans:
        reading_order += 1
        
        # Determine semantic role
        role = _classify_span_role(span, page_type)
        
        # Determine translation policy
        policy = "translate"
        if span.get("is_page_number"):
            policy = "preserve"
        elif role == "brand_logo":
            policy = "preserve"
        elif role == "phonics":
            # Phonics exercises are regenerated for the target language, not
            # translated literally (English "wh"/"str" don't exist elsewhere).
            policy = "educational_adaptation"
        
        # Build style ID from span characteristics
        style_id = _build_style_id(span, page_type)
        
        # Register style if not already present
        if style_id not in page_scene.styles:
            font_name = span.get("font_name", "unknown")
            is_bold = span.get("is_bold", False)
            color_hex = span.get("color", "#000000")
            # Parse color hex to RGB floats
            cr = int(color_hex[1:3], 16) / 255.0 if len(color_hex) >= 7 else 0.0
            cg = int(color_hex[3:5], 16) / 255.0 if len(color_hex) >= 7 else 0.0
            cb = int(color_hex[5:7], 16) / 255.0 if len(color_hex) >= 7 else 0.0
            
            page_scene.styles[style_id] = TextStyle(
                style_id=style_id,
                font=FontRef(
                    family=font_name,
                    weight=700 if is_bold else 400,
                    italic=span.get("is_italic", False),
                ),
                nominal_size_pt=span.get("font_size", 12.0),
                fill_rgb=(cr, cg, cb),
            )
        
        # Create text unit
        unit = TextUnit(
            id=span["id"],
            source_text=span["text_stripped"],
            bbox=tuple(span["bbox"]),
            baseline=(span["origin"][0], span["origin"][1]),
            style_id=style_id,
            reading_order=reading_order,
            semantic_role=role,
            translation_policy=policy,
            rotation_deg=span.get("rotation_angle", 0.0),
            confidence=1.0,
        )
        
        # Group by region
        region_key = _get_region_key(span, page_type, page_num)
        if region_key not in region_spans:
            region_spans[region_key] = []
        region_spans[region_key].append((unit, span))
        
        page_scene.text_units.append(unit)
    
    # Build regions from grouped spans
    for region_key, unit_spans in region_spans.items():
        units = [us[0] for us in unit_spans]
        spans_data = [us[1] for us in unit_spans]
        
        # Calculate region bbox
        all_bboxes = [u.bbox for u in units]
        region_bbox = (
            min(b[0] for b in all_bboxes),
            min(b[1] for b in all_bboxes),
            max(b[2] for b in all_bboxes),
            max(b[3] for b in all_bboxes),
        )
        
        region = Region(
            id=f"p{page_num:02d}-{region_key}",
            region_type=_region_type_from_key(region_key),
            bbox=region_bbox,
            child_ids=[u.id for u in units],
            reading_order=units[0].reading_order,
            translation_policy=units[0].translation_policy,
        )
        
        # Set parent region on units
        for unit in units:
            unit.parent_region_id = region.id
        
        page_scene.regions.append(region)
    
    # Detect containers and assign design_container_bbox to regions
    _assign_containers(page, page_scene)

    # Structure-aware placement fields (spec Req 1/3): attach cell_box / alignment /
    # peer groups / merged-cell info to units on table/vocabulary pages.
    if page_type == "vocabulary":
        try:
            _attach_table_structure(page, page_num, spans, page_scene.text_units)
        except Exception:
            pass

    return page_scene


def _assign_containers(page, page_scene: 'PageScene'):
    """
    Detect actual containers on a page and assign them to regions.
    Uses universal_containers module for table grid and vector rectangle detection.
    Falls back to borderless table inference when no grid lines found.
    """
    from universal_containers import detect_containers
    
    container_data = detect_containers(page, page_scene.page_number, page_scene.page_type)
    
    if container_data["has_table"] and container_data["column_containers"]:
        # Table page: assign column containers to word_list regions
        col_containers = container_data["column_containers"]
        
        for region in page_scene.regions:
            if region.region_type in ("word_list", "high_frequency_words", "phonics"):
                # Match region to column by x-position overlap
                region_center_x = (region.bbox[0] + region.bbox[2]) / 2
                best_col = None
                best_overlap = 0
                
                for col_idx, container in col_containers.items():
                    if container.bbox[0] <= region_center_x <= container.bbox[2]:
                        overlap = min(region.bbox[2], container.bbox[2]) - max(region.bbox[0], container.bbox[0])
                        if overlap > best_overlap:
                            best_overlap = overlap
                            best_col = container
                
                if best_col:
                    region.design_container_bbox = best_col.bbox
                    region.container_source = "table_cell"
                    # Also set column_index on child units
                    for uid in region.child_ids:
                        unit = page_scene.unit_by_id(uid)
                        if unit:
                            unit.column_index = best_col.column_index
    
    elif page_scene.page_type in ("vocabulary",) and len(page_scene.text_units) > 20:
        # No grid lines found but page looks like vocabulary → try borderless inference
        try:
            from borderless_table import infer_table_from_text
            
            # Build text unit dicts for inference
            unit_dicts = []
            for unit in page_scene.text_units:
                if unit.translation_policy == "translate":
                    unit_dicts.append({
                        "bbox": list(unit.bbox),
                        "origin": list(unit.baseline),
                        "font_size": _get_style_size(unit, page_scene),
                        "text_stripped": unit.source_text,
                        "id": unit.id,
                    })
            
            inferred = infer_table_from_text(
                unit_dicts, page_scene.width_pt, page_scene.height_pt
            )
            
            if inferred.detected and inferred.confidence > 0.7:
                # Assign inferred column containers to regions
                for region in page_scene.regions:
                    if region.region_type in ("word_list", "high_frequency_words", "phonics"):
                        region_center_x = (region.bbox[0] + region.bbox[2]) / 2
                        for col in inferred.columns:
                            if col.x_start <= region_center_x <= col.x_end:
                                region.design_container_bbox = (
                                    col.x_start, region.bbox[1],
                                    col.x_end, region.bbox[3],
                                )
                                region.container_source = "borderless_inference"
                                # Set column_index on child units
                                for uid in region.child_ids:
                                    unit = page_scene.unit_by_id(uid)
                                    if unit:
                                        unit.column_index = col.index
                                break
        except ImportError:
            pass
    
    # For non-table pages: expand regions to TrimBox margins if available
    elif page_scene.trim_box:
        trim = page_scene.trim_box
        for region in page_scene.regions:
            if region.region_type in ("story_prose", "title_list"):
                # Expand horizontally to trim margins, keep vertical tight
                region.design_container_bbox = (
                    trim[0] + 20,  # left margin
                    region.bbox[1],
                    trim[2] - 20,  # right margin
                    region.bbox[3],
                )
                region.container_source = "trim_box"


def _get_style_size(unit, page_scene) -> float:
    """Get font size for a unit from its style (helper for container assignment)."""
    style = page_scene.styles.get(unit.style_id)
    return style.nominal_size_pt if style else 12.0


def _mark_end_markers(spans, page_type):
    """
    Flag a document/story END-MARKER (e.g. "The End" / "Die Einde") so it becomes its
    OWN element instead of being glued to the final sentence. Book-agnostic and
    language-agnostic: detected purely by structure —
      - only on story/prose-type pages,
      - the LAST content line (by y) on the page,
      - SHORT (<= 4 words) and not ending with sentence-continuation punctuation,
      - SEPARATED from the preceding text by a vertical gap noticeably larger than the
        page's typical line spacing (an isolated closing line).
    Sets span['is_end_marker'] = True on the matching span. No hardcoded phrases.
    """
    if page_type not in ("story",):
        return spans
    body = [s for s in spans if not s.get("is_page_number")
            and s.get("text_stripped", "").strip()]
    if len(body) < 2:
        return spans
    body_sorted = sorted(body, key=lambda s: s["bbox"][1])
    last = body_sorted[-1]
    prev = body_sorted[-2]

    words = last["text_stripped"].split()
    if len(words) > 4:
        return spans
    prev_text = prev["text_stripped"].rstrip()
    # Primary signal (robust, book-agnostic): the previous line ENDS a sentence
    # (terminal punctuation) and this trailing line is a short, fresh phrase — the
    # shape of a closing marker ("...morning." / "The End").
    prev_ends_sentence = bool(prev_text) and prev_text[-1] in ".!?"
    # Secondary signal: this line is visually separated from the text above.
    gaps = []
    for a, b in zip(body_sorted, body_sorted[1:]):
        gaps.append(b["bbox"][1] - a["bbox"][3])
    gaps_sorted = sorted(g for g in gaps if g > -50)
    typical = gaps_sorted[len(gaps_sorted) // 2] if gaps_sorted else 0.0
    last_gap = last["bbox"][1] - prev["bbox"][3]
    separated = last_gap > typical + 3.0
    # A short trailing line after a completed sentence is an end-marker; separation
    # reinforces but is not required (large-leading books have tiny inter-line gaps).
    if prev_ends_sentence and (separated or len(words) <= 3):
        last["is_end_marker"] = True
    return spans


# Phonics / sound-pattern detection.
#
# A phonics exercise teaches a LANGUAGE-SPECIFIC sound-to-spelling pattern
# (English "oa", "ai", "wh", "str", ...). These must NOT be translated word for
# word — the target language has its own sounds — so they are tagged with the
# educational_adaptation policy, which instructs the model to regenerate an
# equivalent exercise in the target language instead of translating the English.
#
# Detection is by CONTENT SHAPE only, so it is book-agnostic and source-language
# agnostic:
#   1. "pattern - example(s)"  e.g. "oa - float", "str - str-eam", "ai - plain, rain"
#      (a short leading token, then a dash, then example words), OR
#   2. an instruction to recognise/identify a pattern at the start/end of words,
#      e.g. "Recognise wh- at the beginning of words:".
import re as _re

# A short leading token (letters, optionally with an internal/trailing hyphen used
# as a phonics cue, e.g. "wh-") followed by a dash separator and at least one
# example. Tolerant of the extra spaces PDF extraction often introduces.
_PHONICS_PATTERN_RE = _re.compile(
    r"^[a-z][a-z\-]{0,4}\s*[-–—]\s*\S", _re.IGNORECASE
)
# Instructional phonics lead-ins, kept language-neutral where practical. The
# English/Afrikaans forms cover the current corpus; the pattern rule above catches
# the actual exercise rows regardless of the instruction language.
_PHONICS_INSTRUCTION_RE = _re.compile(
    r"\b(recognise|recognize|identify|herken|sound|klank)\b.*\b(begin|beginning|end|einde|word|woord)",
    _re.IGNORECASE,
)


def _is_phonics_span(text: str) -> bool:
    """True when a span looks like a phonics/sound-pattern exercise (shape-based)."""
    t = (text or "").strip()
    if len(t) < 3:
        return False
    # A single ordinary word (no dash separator, no instruction) is NOT phonics.
    if _PHONICS_INSTRUCTION_RE.search(t):
        return True
    if _PHONICS_PATTERN_RE.match(t):
        # Guard: require a dash that separates a SHORT pattern token from examples,
        # so ordinary hyphenated words ("mother-in-law") don't match — the leading
        # token before the dash must be at most 4 chars.
        lead = _re.split(r"[-–—]", t, 1)[0].strip()
        if len(lead) <= 4:
            return True
    return False


def _classify_span_role(span: dict, page_type: str) -> str:
    """Classify the semantic role of a span based on its characteristics."""
    if span.get("is_page_number"):
        return "page_number"
    # End-marker (e.g. "The End" / "Die Einde") is flagged by a page-level pass
    # (_mark_end_markers) because it needs page context (last, short, isolated).
    if span.get("is_end_marker"):
        return "end_marker"

    font_size = span.get("font_size", 12)
    text = span.get("text_stripped", "")
    
    if page_type == "cover":
        if font_size >= 40:
            return "subtitle"
        elif font_size >= 20:
            return "book_title"
        else:
            return "publisher"
    elif page_type == "copyright":
        if font_size >= 40:
            return "subtitle"
        else:
            return "copyright"
    elif page_type == "vocabulary":
        if text.isupper() and len(text) > 2:
            return "heading"
        elif _is_phonics_span(text):
            return "phonics"
        else:
            return "word_list_item"
    elif page_type == "back_cover":
        return "list_item"
    else:  # story
        return "paragraph"


def _build_style_id(span: dict, page_type: str) -> str:
    """Build a style family ID from span characteristics."""
    size_bucket = round(span.get("font_size", 12))
    bold = "b" if span.get("is_bold") else ""
    return f"{page_type}-{size_bucket}{bold}"


def _get_region_key(span: dict, page_type: str, page_num: int) -> str:
    """Determine which region a span belongs to."""
    if span.get("is_page_number"):
        return "page-number"
    
    font_size = span.get("font_size", 12)
    
    if page_type == "cover":
        if font_size >= 40:
            return "subtitle"
        else:
            return "publisher"
    elif page_type == "copyright":
        if font_size >= 40:
            return "title"
        elif span["bbox"][0] < 300:
            return "info-left"
        else:
            return "info-right"
    elif page_type == "vocabulary":
        text = span.get("text_stripped", "")
        if text.isupper() and len(text) > 2:
            return "headers"
        elif _is_phonics_span(text):
            # Keep all phonics rows in ONE region so the exercise is adapted as a
            # coherent unit, not scattered across x-position columns.
            return "phonics"
        else:
            # Group by column (x position)
            col = int(span["origin"][0] / 120)
            return f"col-{col}"
    elif page_type == "back_cover":
        return "title-list"
    else:  # story
        return "prose"


def _region_type_from_key(key: str) -> str:
    """Map region keys to region types."""
    mapping = {
        "page-number": "page_number",
        "subtitle": "cover_subtitle",
        "publisher": "publisher_info",
        "title": "cover_title",
        "info-left": "publisher_info",
        "info-right": "character_bio",
        "headers": "table_header",
        "title-list": "title_list",
        "prose": "story_prose",
        "phonics": "phonics",
    }
    if key.startswith("col-"):
        return "word_list"
    return mapping.get(key, "unknown")


def _discover_page_families(scene: DocumentScene):
    """Discover repeated page templates across the document."""
    # Group pages by similar characteristics
    families = {}
    
    for page in scene.pages:
        # Build a simple fingerprint: page_type + region_count + has_images
        fingerprint = f"{page.page_type}_{len(page.regions)}_{page.has_images}"
        
        if fingerprint not in families:
            families[fingerprint] = []
        families[fingerprint].append(page.page_number)
    
    # Only keep families with 2+ members
    family_idx = 0
    for fingerprint, page_numbers in families.items():
        if len(page_numbers) >= 2:
            family_id = f"family-{family_idx:02d}-{fingerprint.split('_')[0]}"
            scene.page_families[family_id] = page_numbers
            
            # Tag pages with their family
            for pn in page_numbers:
                page = scene.get_page(pn)
                if page:
                    page.page_family_id = family_id
            
            family_idx += 1
    
    # Build book-wide style families after page families are known
    _build_style_families(scene)


def _build_style_families(scene: DocumentScene):
    """
    Build book-wide style families: ensure consistent typography across
    pages that share the same page family.
    
    For each page family, finds the most common (mode) font size per style_id
    and normalizes all pages in that family to use it. This prevents visual
    inconsistency where page 5 uses 24px and page 7 uses 26px for the same
    type of content (e.g., story prose).
    
    The normalized styles are stored in scene.style_registry (book-level)
    and each page's styles are updated to match.
    """
    from collections import Counter
    
    for family_id, page_numbers in scene.page_families.items():
        # Collect all style occurrences across family pages
        # style_id → list of (nominal_size_pt, font_family, weight, color)
        style_samples = {}
        
        for pn in page_numbers:
            page = scene.get_page(pn)
            if not page:
                continue
            for style_id, style in page.styles.items():
                if style_id not in style_samples:
                    style_samples[style_id] = []
                style_samples[style_id].append(style)
        
        # For each style, pick the mode (most common size) across the family
        for style_id, samples in style_samples.items():
            if len(samples) < 2:
                continue
            
            # Find mode size
            sizes = [round(s.nominal_size_pt, 1) for s in samples]
            size_counts = Counter(sizes)
            mode_size = size_counts.most_common(1)[0][0]
            
            # Find mode font (in case there are minor variations)
            fonts = [s.font.family for s in samples]
            font_counts = Counter(fonts)
            mode_font = font_counts.most_common(1)[0][0]
            
            # Find mode color
            colors = [s.fill_rgb for s in samples]
            color_counts = Counter(colors)
            mode_color = color_counts.most_common(1)[0][0]
            
            # Find mode weight
            weights = [s.font.weight for s in samples]
            weight_counts = Counter(weights)
            mode_weight = weight_counts.most_common(1)[0][0]
            
            # Create the canonical style
            canonical = TextStyle(
                style_id=style_id,
                font=FontRef(
                    family=mode_font,
                    weight=mode_weight,
                    italic=samples[0].font.italic,
                ),
                nominal_size_pt=mode_size,
                fill_rgb=mode_color,
            )
            
            # Store in document-level registry
            scene.style_registry[style_id] = canonical
            
            # Update all pages in this family to use the canonical style
            for pn in page_numbers:
                page = scene.get_page(pn)
                if page and style_id in page.styles:
                    page.styles[style_id] = canonical


def _apply_consensus_containers(scene: DocumentScene):
    """
    Apply page-family consensus containers to regions that don't already
    have a design_container_bbox from table detection.
    
    For pages in the same family, the consensus container is the UNION
    of all text positions across those pages, expanded by a small margin.
    This gives translated text more room than the tight glyph bbox of any
    single page.
    """
    from universal_containers import compute_family_consensus_containers
    
    for family_id, page_numbers in scene.page_families.items():
        # Build pages_data for consensus computation
        pages_data = []
        for pn in page_numbers:
            page = scene.get_page(pn)
            if not page:
                continue
            regions_data = []
            for region in page.regions:
                regions_data.append({
                    "region_type": region.region_type,
                    "bbox": region.bbox,
                })
            pages_data.append({
                "page_number": pn,
                "regions": regions_data,
            })
        
        if len(pages_data) < 2:
            continue
        
        # Compute consensus containers
        consensus = compute_family_consensus_containers(pages_data)
        
        # Apply to regions that don't already have a design container
        for pn in page_numbers:
            page = scene.get_page(pn)
            if not page:
                continue
            for region in page.regions:
                if region.design_container_bbox is not None:
                    continue  # Already has a container (from table detection)
                if region.region_type in consensus:
                    container = consensus[region.region_type]
                    region.design_container_bbox = container.bbox
                    region.container_source = "page_family"


# =============================================================================
# CONVENIENCE: Build and summarize
# =============================================================================

def summarize_document_scene(scene: DocumentScene) -> dict:
    """Quick summary of a document scene for display."""
    return {
        "document_id": scene.document_id,
        "total_pages": scene.total_pages,
        "total_units": sum(len(p.text_units) for p in scene.pages),
        "translatable_units": len(scene.get_all_translatable_units()),
        "page_types": {p.page_number: p.page_type for p in scene.pages},
        "page_families": scene.page_families,
        "unit_counts": scene.get_unit_count(),
    }


if __name__ == "__main__":
    import sys
    import json
    
    if len(sys.argv) < 2:
        print("Usage: python document_model.py <pdf_path>")
        sys.exit(1)
    
    scene = build_document_scene(sys.argv[1])
    summary = summarize_document_scene(scene)
    print(json.dumps(summary, indent=2, ensure_ascii=False))
