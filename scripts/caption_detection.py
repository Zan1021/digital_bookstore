"""
Caption and Label Detection — Digital Bookstore V8
====================================================
Detects text labels near images (captions below, titles above) and
small text annotations that should be treated differently from body text.

Detection signals:
- Small text below/above an image region
- Text that's significantly smaller than surrounding content
- Short text lines near image boundaries
- Italic or different font from body text

This helps the renderer:
- Preserve caption positioning relative to images
- Apply appropriate font sizing (captions are usually smaller)
- Translate captions separately from body text

Usage:
    from caption_detection import detect_captions
    captions = detect_captions(text_units, image_regions, page_scene)
"""

import os
import sys
from dataclasses import dataclass, field
from typing import Optional

SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, SCRIPTS_DIR)


# =============================================================================
# DATA TYPES
# =============================================================================

@dataclass
class DetectedCaption:
    """A detected caption or label."""
    id: str
    text: str
    unit_id: str
    caption_type: str       # "image_caption", "figure_label", "annotation", "credit"
    position: str           # "below", "above", "beside", "overlay"
    bbox: tuple
    related_image_bbox: Optional[tuple] = None
    font_size: float = 0.0
    confidence: float = 0.0


# =============================================================================
# DETECTION
# =============================================================================

def detect_captions(
    text_units: list,
    page_width: float,
    page_height: float,
    image_bboxes: list = None,
    body_font_size: float = 12.0,
) -> list:
    """
    Detect captions and labels from text units.
    
    Args:
        text_units: All text units on the page
        page_width: Page width in points
        page_height: Page height in points
        image_bboxes: List of image bounding boxes [(x0,y0,x1,y1), ...]
        body_font_size: Average body text font size (for comparison)
    
    Returns:
        List of DetectedCaption objects.
    """
    if not text_units:
        return []
    
    captions = []
    
    # Normalize units
    items = _normalize(text_units)
    
    # Calculate body font size if not provided
    if body_font_size <= 0:
        sizes = [item["font_size"] for item in items if item["font_size"] > 0]
        body_font_size = sum(sizes) / len(sizes) if sizes else 12.0
    
    # Strategy 1: Small text near images
    if image_bboxes:
        for item in items:
            for img_bbox in image_bboxes:
                caption = _check_near_image(item, img_bbox, body_font_size)
                if caption:
                    captions.append(caption)
                    break
    
    # Strategy 2: Significantly smaller text than body (annotations, credits)
    for item in items:
        if item["font_size"] < body_font_size * 0.6 and item["font_size"] > 0:
            # Small text — could be a credit or annotation
            text = item["text"]
            if len(text) < 50:  # Short text
                caption_type = _classify_small_text(text)
                if caption_type:
                    captions.append(DetectedCaption(
                        id=f"caption-{len(captions)}",
                        text=text,
                        unit_id=item.get("id", ""),
                        caption_type=caption_type,
                        position="inline",
                        bbox=tuple(item["bbox"]),
                        font_size=item["font_size"],
                        confidence=0.7,
                    ))
    
    # Strategy 3: Text at very bottom of page (page credits, source info)
    bottom_threshold = page_height * 0.9
    for item in items:
        if item["bbox"][1] > bottom_threshold and item["font_size"] < body_font_size * 0.8:
            text = item["text"]
            if len(text) < 80 and not text.isdigit():
                captions.append(DetectedCaption(
                    id=f"caption-{len(captions)}",
                    text=text,
                    unit_id=item.get("id", ""),
                    caption_type="credit",
                    position="bottom",
                    bbox=tuple(item["bbox"]),
                    font_size=item["font_size"],
                    confidence=0.6,
                ))
    
    # Deduplicate by unit_id
    seen_ids = set()
    unique_captions = []
    for c in captions:
        if c.unit_id not in seen_ids:
            seen_ids.add(c.unit_id)
            unique_captions.append(c)
    
    return unique_captions


# =============================================================================
# HELPERS
# =============================================================================

def _check_near_image(item: dict, img_bbox: tuple, body_font_size: float) -> Optional[DetectedCaption]:
    """Check if a text item is a caption near an image."""
    text = item["text"]
    text_bbox = item["bbox"]
    font_size = item["font_size"]
    
    # Must be relatively short text
    if len(text) > 100:
        return None
    
    # Should be smaller or same size as body text
    if font_size > body_font_size * 1.2:
        return None
    
    img_x0, img_y0, img_x1, img_y1 = img_bbox
    txt_x0, txt_y0, txt_x1, txt_y1 = text_bbox
    
    # Check: directly below image (within 30pt)
    if txt_y0 > img_y1 and txt_y0 - img_y1 < 30:
        # Horizontally overlapping
        if txt_x0 < img_x1 and txt_x1 > img_x0:
            return DetectedCaption(
                id=f"caption-img",
                text=text,
                unit_id=item.get("id", ""),
                caption_type="image_caption",
                position="below",
                bbox=tuple(text_bbox),
                related_image_bbox=img_bbox,
                font_size=font_size,
                confidence=0.85,
            )
    
    # Check: directly above image (within 20pt)
    if txt_y1 < img_y0 and img_y0 - txt_y1 < 20:
        if txt_x0 < img_x1 and txt_x1 > img_x0:
            return DetectedCaption(
                id=f"caption-img",
                text=text,
                unit_id=item.get("id", ""),
                caption_type="figure_label",
                position="above",
                bbox=tuple(text_bbox),
                related_image_bbox=img_bbox,
                font_size=font_size,
                confidence=0.8,
            )
    
    return None


def _classify_small_text(text: str) -> Optional[str]:
    """Classify small text as a specific caption type."""
    import re
    
    lower = text.lower()
    
    # Photo/image credits
    if any(w in lower for w in ["photo", "image", "©", "credit", "source", "courtesy"]):
        return "credit"
    
    # Figure labels
    if re.match(r'^(fig|figure|diagram|table)\s*\d', lower):
        return "figure_label"
    
    # Annotations
    if len(text) < 20 and not text[0].isupper():
        return "annotation"
    
    return None


def _normalize(text_units: list) -> list:
    """Normalize text units to standard dict format."""
    normalized = []
    for unit in text_units:
        if hasattr(unit, 'bbox'):
            normalized.append({
                "id": unit.id,
                "text": unit.source_text,
                "bbox": list(unit.bbox),
                "font_size": 12.0,
            })
        elif isinstance(unit, dict):
            normalized.append({
                "id": unit.get("id", ""),
                "text": unit.get("text_stripped", unit.get("text", "")),
                "bbox": unit.get("bbox", [0, 0, 0, 0]),
                "font_size": unit.get("font_size", 12.0),
            })
    return normalized
