"""
Content-Addressed Caching — Digital Bookstore V8
==================================================
Identical source pages are not reprocessed.

Per the brief:
  - "Content-addressed caching — identical source pages not reprocessed"

How it works:
  1. Hash the content of each source page (text + image refs + drawings)
  2. Store rendered results keyed by content hash
  3. On re-render: if hash matches cache → skip, return cached result
  4. Cache is per-book (different books can share identical pages)

Cache levels:
  - Page content hash → manifest (skip manifest extraction)
  - Content hash + translation hash → rendered page (skip rendering)
  - Font measurement cache (text + font + size → width)

This speeds up:
  - Re-renders after minor translation edits (only changed pages re-render)
  - Series books with identical structures (vocab page templates)
  - Repeated full renders during testing

Usage:
    python content_cache.py hash --input book.pdf --page N
    python content_cache.py status --cache-dir ./cache
    python content_cache.py clear --cache-dir ./cache
"""

import argparse
import hashlib
import json
import os
import sys
import time
from typing import Optional

import pymupdf


# =============================================================================
# CONTENT HASHING
# =============================================================================

def hash_page_content(page) -> str:
    """
    Generate a stable content hash for a page.
    
    The hash includes:
    - All text content (sorted by position for stability)
    - Image references (xrefs)
    - Page dimensions
    - Drawing count and approximate structure
    
    Does NOT include:
    - Annotation state (those can change independently)
    - Metadata
    """
    hasher = hashlib.sha256()
    
    # Page geometry
    hasher.update(f"geo:{page.rect.width:.1f}x{page.rect.height:.1f}:{page.rotation}".encode())
    
    # Text content (sorted by position for determinism)
    text_dict = page.get_text("dict", flags=pymupdf.TEXT_PRESERVE_WHITESPACE)
    text_entries = []
    
    for block in text_dict.get("blocks", []):
        if block.get("type") != 0:
            continue
        for line in block.get("lines", []):
            for span in line.get("spans", []):
                text = span["text"].strip()
                if text:
                    # Include position for stability
                    origin = span["origin"]
                    text_entries.append(f"{origin[0]:.0f},{origin[1]:.0f}:{text}")
    
    text_entries.sort()
    for entry in text_entries:
        hasher.update(entry.encode('utf-8'))
    
    # Image references
    images = page.get_images()
    for img in sorted(images, key=lambda x: x[0]):
        hasher.update(f"img:{img[0]}:{img[2]}x{img[3]}".encode())
    
    # Drawing structure (count + approximate fingerprint)
    try:
        drawings = page.get_drawings()
        hasher.update(f"draw:{len(drawings)}".encode())
        # Hash first and last drawing for fingerprinting
        if drawings:
            d0 = drawings[0]
            hasher.update(f"d0:{d0.get('rect', '')}".encode())
            dl = drawings[-1]
            hasher.update(f"dl:{dl.get('rect', '')}".encode())
    except Exception:
        hasher.update(b"draw:0")
    
    return hasher.hexdigest()[:16]  # 16 hex chars = 64 bits (enough for uniqueness)


def hash_translation(text: str) -> str:
    """Hash a translation text for cache key."""
    return hashlib.md5(text.encode('utf-8')).hexdigest()[:12]


def get_cache_key(page_hash: str, translation_hash: str) -> str:
    """Build a cache key from page content hash and translation hash."""
    return f"{page_hash}_{translation_hash}"


# =============================================================================
# CACHE STORAGE
# =============================================================================

class ContentCache:
    """
    File-based content-addressed cache for rendered pages.
    
    Structure:
    cache_dir/
      index.json       — Cache index (key → metadata)
      manifests/       — Cached page manifests
      renders/         — Cached render results (metadata only, not full pages)
      measurements/    — Font measurement cache
    """
    
    def __init__(self, cache_dir: str):
        self.cache_dir = cache_dir
        self.index_path = os.path.join(cache_dir, "index.json")
        self.manifests_dir = os.path.join(cache_dir, "manifests")
        self.renders_dir = os.path.join(cache_dir, "renders")
        self.measurements_dir = os.path.join(cache_dir, "measurements")
        
        # Create directories
        for d in [self.manifests_dir, self.renders_dir, self.measurements_dir]:
            os.makedirs(d, exist_ok=True)
        
        # Load index
        self.index = self._load_index()
    
    def _load_index(self) -> dict:
        if os.path.isfile(self.index_path):
            try:
                with open(self.index_path, 'r') as f:
                    return json.load(f)
            except Exception:
                pass
        return {"version": "v8-cache", "entries": {}, "stats": {"hits": 0, "misses": 0}}
    
    def _save_index(self):
        with open(self.index_path, 'w') as f:
            json.dump(self.index, f, indent=2)
    
    # Manifest cache
    def get_manifest(self, page_hash: str) -> Optional[dict]:
        """Get cached manifest for a page content hash."""
        path = os.path.join(self.manifests_dir, f"{page_hash}.json")
        if os.path.isfile(path):
            self.index["stats"]["hits"] = self.index["stats"].get("hits", 0) + 1
            with open(path, 'r', encoding='utf-8') as f:
                return json.load(f)
        self.index["stats"]["misses"] = self.index["stats"].get("misses", 0) + 1
        return None
    
    def set_manifest(self, page_hash: str, manifest: dict):
        """Cache a manifest."""
        path = os.path.join(self.manifests_dir, f"{page_hash}.json")
        with open(path, 'w', encoding='utf-8') as f:
            json.dump(manifest, f, indent=2, ensure_ascii=False)
        
        self.index["entries"][f"manifest:{page_hash}"] = {
            "type": "manifest",
            "created": time.strftime("%Y-%m-%d %H:%M:%S"),
        }
        self._save_index()
    
    # Render result cache
    def get_render_result(self, cache_key: str) -> Optional[dict]:
        """Get cached render result."""
        path = os.path.join(self.renders_dir, f"{cache_key}.json")
        if os.path.isfile(path):
            self.index["stats"]["hits"] = self.index["stats"].get("hits", 0) + 1
            with open(path, 'r', encoding='utf-8') as f:
                return json.load(f)
        self.index["stats"]["misses"] = self.index["stats"].get("misses", 0) + 1
        return None
    
    def set_render_result(self, cache_key: str, result: dict):
        """Cache a render result."""
        path = os.path.join(self.renders_dir, f"{cache_key}.json")
        with open(path, 'w', encoding='utf-8') as f:
            json.dump(result, f, indent=2, ensure_ascii=False)
        
        self.index["entries"][f"render:{cache_key}"] = {
            "type": "render",
            "created": time.strftime("%Y-%m-%d %H:%M:%S"),
        }
        self._save_index()
    
    # Font measurement cache
    def get_measurement(self, text: str, font_path: str, font_size: float) -> Optional[float]:
        """Get cached text width measurement."""
        key = hashlib.md5(f"{text}:{font_path}:{font_size}".encode()).hexdigest()[:12]
        path = os.path.join(self.measurements_dir, f"{key}.txt")
        if os.path.isfile(path):
            try:
                with open(path, 'r') as f:
                    return float(f.read().strip())
            except Exception:
                pass
        return None
    
    def set_measurement(self, text: str, font_path: str, font_size: float, width: float):
        """Cache a text width measurement."""
        key = hashlib.md5(f"{text}:{font_path}:{font_size}".encode()).hexdigest()[:12]
        path = os.path.join(self.measurements_dir, f"{key}.txt")
        with open(path, 'w') as f:
            f.write(str(round(width, 4)))
    
    # Status
    def get_status(self) -> dict:
        """Get cache status."""
        manifest_count = len([f for f in os.listdir(self.manifests_dir) if f.endswith('.json')])
        render_count = len([f for f in os.listdir(self.renders_dir) if f.endswith('.json')])
        measurement_count = len(os.listdir(self.measurements_dir))
        
        return {
            "cache_dir": self.cache_dir,
            "manifests_cached": manifest_count,
            "renders_cached": render_count,
            "measurements_cached": measurement_count,
            "total_entries": manifest_count + render_count + measurement_count,
            "hits": self.index["stats"].get("hits", 0),
            "misses": self.index["stats"].get("misses", 0),
            "hit_rate": round(
                self.index["stats"].get("hits", 0) / 
                max(self.index["stats"].get("hits", 0) + self.index["stats"].get("misses", 0), 1), 3
            ),
        }
    
    def clear(self):
        """Clear all cached data."""
        import shutil
        for d in [self.manifests_dir, self.renders_dir, self.measurements_dir]:
            shutil.rmtree(d, ignore_errors=True)
            os.makedirs(d, exist_ok=True)
        
        self.index = {"version": "v8-cache", "entries": {}, "stats": {"hits": 0, "misses": 0}}
        self._save_index()


# =============================================================================
# INTEGRATION — Cache-aware page processing
# =============================================================================

def should_process_page(cache: ContentCache, page, translation_text: str) -> tuple:
    """
    Check if a page needs processing or can use cached results.
    
    Returns (needs_processing: bool, cache_key: str, cached_data: dict or None)
    """
    page_hash = hash_page_content(page)
    trans_hash = hash_translation(translation_text)
    cache_key = get_cache_key(page_hash, trans_hash)
    
    # Check render cache
    cached = cache.get_render_result(cache_key)
    if cached:
        return False, cache_key, cached
    
    return True, cache_key, None


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="Content-Addressed Cache — V8")
    subparsers = parser.add_subparsers(dest="command")
    
    # Hash a page
    hash_p = subparsers.add_parser("hash", help="Hash a page's content")
    hash_p.add_argument("--input", "-i", required=True)
    hash_p.add_argument("--page", "-p", type=int, required=True)
    
    # Cache status
    status_p = subparsers.add_parser("status", help="Show cache status")
    status_p.add_argument("--cache-dir", "-c", default=".cache")
    
    # Clear cache
    clear_p = subparsers.add_parser("clear", help="Clear all cache")
    clear_p.add_argument("--cache-dir", "-c", default=".cache")
    
    args = parser.parse_args()
    
    if args.command == "hash":
        doc = pymupdf.open(args.input)
        page = doc[args.page - 1]
        page_hash = hash_page_content(page)
        doc.close()
        
        print(f"Page {args.page} content hash: {page_hash}")
    
    elif args.command == "status":
        cache = ContentCache(args.cache_dir)
        status = cache.get_status()
        
        print(f"Cache Status: {status['cache_dir']}")
        print(f"  Manifests: {status['manifests_cached']}")
        print(f"  Renders: {status['renders_cached']}")
        print(f"  Measurements: {status['measurements_cached']}")
        print(f"  Total: {status['total_entries']}")
        print(f"  Hit rate: {status['hit_rate']:.0%} "
              f"({status['hits']} hits / {status['hits'] + status['misses']} total)")
    
    elif args.command == "clear":
        cache = ContentCache(args.cache_dir)
        cache.clear()
        print(f"Cache cleared: {args.cache_dir}")
    
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
