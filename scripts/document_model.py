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
        """Get all text units that need translation."""
        return [u for u in self.text_units 
                if u.translation_policy == "translate"]
    
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
                items.append({
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
                })
        
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
    
    # Classify page type
    page_type = classify_page(spans, page_num, total_pages)
    
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


def _classify_span_role(span: dict, page_type: str) -> str:
    """Classify the semantic role of a span based on its characteristics."""
    if span.get("is_page_number"):
        return "page_number"
    
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
