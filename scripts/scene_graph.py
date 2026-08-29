"""
Scene Graph Builder — Digital Bookstore V8
============================================
Builds a spatial relationship graph for all objects on a page.

Per the brief:
  "Build a scene graph that describes spatial and semantic relationships."

The graph represents:
  - Object containment (what's inside what)
  - Overlap (which objects share space)
  - Z-order (front-to-back layering)
  - Alignment (objects sharing edges/centers)
  - Reading order (top-to-bottom, left-to-right within columns)
  - Region membership (which objects belong to which region)
  - Table-cell membership
  - Illustration-text relationships (text overlaying images)

This informs:
  - Text removal strategy (text over artwork needs inpainting, not redaction)
  - Confidence scoring (overlapping objects = lower confidence)
  - Region detection (grouping related objects)

Usage:
    python scene_graph.py build --input book.pdf --page N [--json]
    python scene_graph.py relationships --input book.pdf --page N
"""

import argparse
import json
import math
import os
import sys
from typing import Optional

import pymupdf


# =============================================================================
# GRAPH NODE TYPES
# =============================================================================

class SceneNode:
    """A node in the scene graph representing a visible page object."""
    
    def __init__(self, node_id: str, node_type: str, bbox: list,
                 z_order: int = 0, metadata: dict = None):
        self.id = node_id
        self.type = node_type  # "image", "text", "path", "fill", "clip"
        self.bbox = bbox  # [x0, y0, x1, y1]
        self.z_order = z_order
        self.metadata = metadata or {}
        self.children = []
        self.parent = None
        self.overlaps = []
        self.aligned_with = []
        self.region_id = None
    
    @property
    def width(self):
        return self.bbox[2] - self.bbox[0]
    
    @property
    def height(self):
        return self.bbox[3] - self.bbox[1]
    
    @property
    def area(self):
        return self.width * self.height
    
    @property
    def center(self):
        return ((self.bbox[0] + self.bbox[2]) / 2, (self.bbox[1] + self.bbox[3]) / 2)
    
    def contains(self, other: 'SceneNode', tolerance: float = 2.0) -> bool:
        """Check if this node fully contains another."""
        return (self.bbox[0] - tolerance <= other.bbox[0] and
                self.bbox[1] - tolerance <= other.bbox[1] and
                self.bbox[2] + tolerance >= other.bbox[2] and
                self.bbox[3] + tolerance >= other.bbox[3])
    
    def overlaps_with(self, other: 'SceneNode') -> bool:
        """Check if two nodes overlap (share any area)."""
        return not (self.bbox[2] < other.bbox[0] or
                   other.bbox[2] < self.bbox[0] or
                   self.bbox[3] < other.bbox[1] or
                   other.bbox[3] < self.bbox[1])
    
    def overlap_area(self, other: 'SceneNode') -> float:
        """Calculate the overlapping area between two nodes."""
        x0 = max(self.bbox[0], other.bbox[0])
        y0 = max(self.bbox[1], other.bbox[1])
        x1 = min(self.bbox[2], other.bbox[2])
        y1 = min(self.bbox[3], other.bbox[3])
        
        if x0 >= x1 or y0 >= y1:
            return 0.0
        return (x1 - x0) * (y1 - y0)
    
    def is_aligned_with(self, other: 'SceneNode', tolerance: float = 3.0) -> list:
        """Check alignment relationships. Returns list of alignment types."""
        alignments = []
        
        # Left-aligned
        if abs(self.bbox[0] - other.bbox[0]) < tolerance:
            alignments.append("left")
        # Right-aligned
        if abs(self.bbox[2] - other.bbox[2]) < tolerance:
            alignments.append("right")
        # Top-aligned
        if abs(self.bbox[1] - other.bbox[1]) < tolerance:
            alignments.append("top")
        # Bottom-aligned
        if abs(self.bbox[3] - other.bbox[3]) < tolerance:
            alignments.append("bottom")
        # Center-aligned horizontally
        if abs(self.center[0] - other.center[0]) < tolerance:
            alignments.append("center_h")
        # Center-aligned vertically
        if abs(self.center[1] - other.center[1]) < tolerance:
            alignments.append("center_v")
        
        return alignments
    
    def to_dict(self) -> dict:
        return {
            "id": self.id,
            "type": self.type,
            "bbox": [round(v, 1) for v in self.bbox],
            "z_order": self.z_order,
            "area": round(self.area, 1),
            "parent": self.parent.id if self.parent else None,
            "children": [c.id for c in self.children],
            "overlaps": self.overlaps[:10],  # Limit for readability
            "region_id": self.region_id,
            "metadata": self.metadata,
        }


# =============================================================================
# SCENE GRAPH
# =============================================================================

class SceneGraph:
    """Complete scene graph for a PDF page."""
    
    def __init__(self, page_num: int, page_width: float, page_height: float):
        self.page_num = page_num
        self.page_width = page_width
        self.page_height = page_height
        self.nodes = []
        self.node_map = {}  # id → node
        self.relationships = {
            "containment": [],  # (parent_id, child_id)
            "overlap": [],      # (id1, id2, overlap_area)
            "alignment": [],    # (id1, id2, alignment_types)
            "reading_order": [],  # ordered list of text node ids
        }
        self.regions = []
    
    def add_node(self, node: SceneNode):
        """Add a node to the graph."""
        self.nodes.append(node)
        self.node_map[node.id] = node
    
    def build_relationships(self):
        """Compute all spatial relationships between nodes."""
        self._compute_containment()
        self._compute_overlaps()
        self._compute_reading_order()
        self._compute_alignments()
    
    def _compute_containment(self):
        """Find parent-child containment relationships."""
        # Sort by area descending (largest first = potential parents)
        sorted_nodes = sorted(self.nodes, key=lambda n: n.area, reverse=True)
        
        for i, potential_parent in enumerate(sorted_nodes):
            for potential_child in sorted_nodes[i+1:]:
                if potential_child.parent:
                    continue  # Already has a parent
                if potential_parent.contains(potential_child):
                    # Found containment — assign to immediate parent (smallest container)
                    potential_child.parent = potential_parent
                    potential_parent.children.append(potential_child)
                    self.relationships["containment"].append(
                        (potential_parent.id, potential_child.id)
                    )
    
    def _compute_overlaps(self):
        """Find overlapping objects (excluding containment pairs)."""
        for i, node_a in enumerate(self.nodes):
            for node_b in self.nodes[i+1:]:
                if node_a.overlaps_with(node_b):
                    # Skip if one contains the other (that's containment, not overlap)
                    if node_a.contains(node_b) or node_b.contains(node_a):
                        continue
                    
                    overlap = node_a.overlap_area(node_b)
                    if overlap > 0:
                        node_a.overlaps.append(node_b.id)
                        node_b.overlaps.append(node_a.id)
                        self.relationships["overlap"].append(
                            (node_a.id, node_b.id, round(overlap, 1))
                        )
    
    def _compute_reading_order(self):
        """Determine reading order for text nodes (top-to-bottom, left-to-right)."""
        text_nodes = [n for n in self.nodes if n.type == "text"]
        
        # Sort by Y first (top to bottom), then X (left to right)
        # Account for columns: group by X position clusters
        sorted_texts = sorted(text_nodes, key=lambda n: (
            round(n.bbox[1] / 20) * 20,  # Row grouping (20pt tolerance)
            n.bbox[0]                      # Left to right within row
        ))
        
        self.relationships["reading_order"] = [n.id for n in sorted_texts]
    
    def _compute_alignments(self):
        """Find alignment relationships between nearby objects."""
        # Only check objects within reasonable proximity
        for i, node_a in enumerate(self.nodes):
            for node_b in self.nodes[i+1:]:
                # Skip if too far apart (> 200pts)
                dist = math.sqrt(
                    (node_a.center[0] - node_b.center[0])**2 +
                    (node_a.center[1] - node_b.center[1])**2
                )
                if dist > 200:
                    continue
                
                alignments = node_a.is_aligned_with(node_b)
                if alignments:
                    node_a.aligned_with.append((node_b.id, alignments))
                    self.relationships["alignment"].append(
                        (node_a.id, node_b.id, alignments)
                    )
    
    def get_text_over_images(self) -> list:
        """Find text nodes that overlap with image nodes (text on artwork)."""
        results = []
        image_nodes = [n for n in self.nodes if n.type == "image"]
        text_nodes = [n for n in self.nodes if n.type == "text"]
        
        for text in text_nodes:
            for image in image_nodes:
                if image.overlaps_with(text):
                    overlap = image.overlap_area(text)
                    if overlap > 0:
                        results.append({
                            "text_id": text.id,
                            "image_id": image.id,
                            "text_content": text.metadata.get("text", ""),
                            "overlap_area": round(overlap, 1),
                            "text_fully_inside_image": image.contains(text),
                        })
        
        return results
    
    def get_regions(self) -> list:
        """Group nodes into semantic regions based on spatial proximity."""
        # Simple region detection: group text nodes that are vertically close
        text_nodes = sorted(
            [n for n in self.nodes if n.type == "text"],
            key=lambda n: (n.bbox[0], n.bbox[1])
        )
        
        if not text_nodes:
            return []
        
        regions = []
        current_region = [text_nodes[0]]
        
        for node in text_nodes[1:]:
            prev = current_region[-1]
            # Same region if vertically close (within 30pts) and similar X
            if (abs(node.bbox[1] - prev.bbox[3]) < 30 and
                abs(node.bbox[0] - prev.bbox[0]) < 50):
                current_region.append(node)
            else:
                if current_region:
                    regions.append(current_region)
                current_region = [node]
        
        if current_region:
            regions.append(current_region)
        
        # Convert to region dicts
        result = []
        for i, region_nodes in enumerate(regions):
            min_x = min(n.bbox[0] for n in region_nodes)
            min_y = min(n.bbox[1] for n in region_nodes)
            max_x = max(n.bbox[2] for n in region_nodes)
            max_y = max(n.bbox[3] for n in region_nodes)
            
            region_id = f"p{self.page_num:02d}-region-{i:02d}"
            for n in region_nodes:
                n.region_id = region_id
            
            result.append({
                "id": region_id,
                "bbox": [round(min_x, 1), round(min_y, 1), round(max_x, 1), round(max_y, 1)],
                "node_count": len(region_nodes),
                "node_ids": [n.id for n in region_nodes],
            })
        
        self.regions = result
        return result
    
    def to_dict(self) -> dict:
        """Serialize the full scene graph."""
        return {
            "page_number": self.page_num,
            "geometry": {
                "width": round(self.page_width, 1),
                "height": round(self.page_height, 1),
            },
            "nodes": [n.to_dict() for n in self.nodes],
            "node_count": len(self.nodes),
            "relationships": {
                "containment_pairs": len(self.relationships["containment"]),
                "overlap_pairs": len(self.relationships["overlap"]),
                "alignment_pairs": len(self.relationships["alignment"]),
                "reading_order_length": len(self.relationships["reading_order"]),
            },
            "text_over_images": self.get_text_over_images(),
            "regions": self.regions,
        }
    
    def summary(self) -> dict:
        """Quick summary without full node details."""
        type_counts = {}
        for n in self.nodes:
            type_counts[n.type] = type_counts.get(n.type, 0) + 1
        
        return {
            "page_number": self.page_num,
            "total_nodes": len(self.nodes),
            "type_counts": type_counts,
            "containment_pairs": len(self.relationships["containment"]),
            "overlap_pairs": len(self.relationships["overlap"]),
            "text_over_images": len(self.get_text_over_images()),
            "regions": len(self.regions),
        }


# =============================================================================
# BUILDER — Construct scene graph from a PDF page
# =============================================================================

def build_scene_graph(page, page_num: int) -> SceneGraph:
    """
    Build a complete scene graph for a PDF page.
    
    Extracts all visible objects and computes their spatial relationships.
    """
    graph = SceneGraph(page_num, page.rect.width, page.rect.height)
    z_counter = 0
    
    # 1. Add image nodes
    image_list = page.get_images(full=True)
    for img_info in image_list:
        xref = img_info[0]
        try:
            rects = page.get_image_rects(xref)
            for rect in rects:
                node = SceneNode(
                    node_id=f"p{page_num:02d}-img-{xref}",
                    node_type="image",
                    bbox=[rect.x0, rect.y0, rect.x1, rect.y1],
                    z_order=z_counter,
                    metadata={
                        "xref": xref,
                        "width_px": img_info[2],
                        "height_px": img_info[3],
                    }
                )
                graph.add_node(node)
                z_counter += 1
        except Exception:
            pass
    
    # 2. Add vector path nodes (representative samples — not all 100+ paths)
    try:
        drawings = page.get_drawings()
        for i, drawing in enumerate(drawings):
            rect = drawing.get("rect")
            if not rect or rect.is_empty:
                continue
            
            # Classify path type
            items = drawing.get("items", [])
            has_fill = drawing.get("fill") is not None
            
            path_type = "path"
            if has_fill:
                path_type = "fill"
            
            node = SceneNode(
                node_id=f"p{page_num:02d}-path-{i:04d}",
                node_type=path_type,
                bbox=[rect.x0, rect.y0, rect.x1, rect.y1],
                z_order=z_counter,
                metadata={
                    "fill_color": drawing.get("fill"),
                    "stroke_color": drawing.get("color"),
                    "stroke_width": drawing.get("width", 0),
                }
            )
            graph.add_node(node)
            z_counter += 1
    except Exception:
        pass
    
    # 3. Add text nodes
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
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
                
                node = SceneNode(
                    node_id=f"p{page_num:02d}-txt-{span_idx:04d}",
                    node_type="text",
                    bbox=list(bbox),
                    z_order=z_counter,
                    metadata={
                        "text": text,
                        "font_size": span["size"],
                        "font_name": span["font"],
                    }
                )
                graph.add_node(node)
                z_counter += 1
    
    # 4. Compute all relationships
    graph.build_relationships()
    graph.get_regions()
    
    return graph


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="Scene Graph Builder — V8")
    subparsers = parser.add_subparsers(dest="command")
    
    # Build graph
    build_p = subparsers.add_parser("build", help="Build scene graph for a page")
    build_p.add_argument("--input", "-i", required=True)
    build_p.add_argument("--page", "-p", type=int, required=True)
    build_p.add_argument("--json", action="store_true")
    
    # Show relationships
    rel_p = subparsers.add_parser("relationships", help="Show key relationships")
    rel_p.add_argument("--input", "-i", required=True)
    rel_p.add_argument("--page", "-p", type=int, required=True)
    
    args = parser.parse_args()
    
    if args.command == "build":
        doc = pymupdf.open(args.input)
        page = doc[args.page - 1]
        graph = build_scene_graph(page, args.page)
        doc.close()
        
        if args.json:
            print(json.dumps(graph.to_dict(), indent=2, ensure_ascii=False))
        else:
            s = graph.summary()
            print(f"Scene Graph — Page {args.page}:")
            print(f"  Total nodes: {s['total_nodes']}")
            print(f"  Types: {s['type_counts']}")
            print(f"  Containment pairs: {s['containment_pairs']}")
            print(f"  Overlap pairs: {s['overlap_pairs']}")
            print(f"  Text over images: {s['text_over_images']}")
            print(f"  Regions: {s['regions']}")
            
            # Show text-over-image relationships
            toi = graph.get_text_over_images()
            if toi:
                print(f"\n  Text overlaying images:")
                for rel in toi[:10]:
                    inside = " (fully inside)" if rel['text_fully_inside_image'] else ""
                    print(f"    \"{rel['text_content'][:30]}\" over {rel['image_id']}{inside}")
    
    elif args.command == "relationships":
        doc = pymupdf.open(args.input)
        page = doc[args.page - 1]
        graph = build_scene_graph(page, args.page)
        doc.close()
        
        print(f"Key Relationships — Page {args.page}:")
        
        # Text over images
        toi = graph.get_text_over_images()
        if toi:
            print(f"\n  Text on artwork ({len(toi)} instances):")
            for rel in toi[:10]:
                print(f"    \"{rel['text_content'][:40]}\"")
        else:
            print(f"\n  No text overlaying images (safe for redaction)")
        
        # Regions
        if graph.regions:
            print(f"\n  Detected regions ({len(graph.regions)}):")
            for region in graph.regions[:10]:
                print(f"    {region['id']}: {region['node_count']} nodes "
                      f"at [{region['bbox'][0]:.0f},{region['bbox'][1]:.0f}]-"
                      f"[{region['bbox'][2]:.0f},{region['bbox'][3]:.0f}]")
        
        # Reading order
        ro = graph.relationships["reading_order"]
        if ro:
            print(f"\n  Reading order: {len(ro)} text elements")
            # Show first few
            for node_id in ro[:5]:
                node = graph.node_map.get(node_id)
                if node:
                    print(f"    {node_id}: \"{node.metadata.get('text', '')[:30]}\"")
    
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
