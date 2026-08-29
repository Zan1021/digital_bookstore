"""
Full Audit Trail — Digital Bookstore V8
=========================================
Tracks source preservation, translation versions, engine state.

Per the brief:
  - "Full audit trail — source preserved, translation versions, glossary,
     engine version, checksums"

Records:
  - Source PDF checksum (immutable reference)
  - Every translation version with timestamp
  - Engine version used for each render
  - Glossary terms applied
  - Reviewer edits and approvals
  - Render checksums for reproducibility

Usage:
    python audit_trail.py init --book-id 2 --source book.pdf
    python audit_trail.py log-render --book-id 2 --language af --report report.json
    python audit_trail.py show --book-id 2
"""

import argparse
import hashlib
import json
import os
import sys
import time
from typing import Optional


# =============================================================================
# AUDIT TRAIL STORAGE
# =============================================================================

class AuditTrail:
    """
    Persistent audit trail for a book's translation lifecycle.
    
    Stored as a JSON file per book.
    """
    
    def __init__(self, storage_dir: str, book_id: str):
        self.storage_dir = storage_dir
        self.book_id = str(book_id)
        self.trail_path = os.path.join(storage_dir, f"audit_{self.book_id}.json")
        
        os.makedirs(storage_dir, exist_ok=True)
        self.data = self._load()
    
    def _load(self) -> dict:
        if os.path.isfile(self.trail_path):
            with open(self.trail_path, 'r', encoding='utf-8') as f:
                return json.load(f)
        return self._new_trail()
    
    def _save(self):
        with open(self.trail_path, 'w', encoding='utf-8') as f:
            json.dump(self.data, f, indent=2, ensure_ascii=False)
    
    def _new_trail(self) -> dict:
        return {
            "version": "v8-audit",
            "book_id": self.book_id,
            "created": time.strftime("%Y-%m-%dT%H:%M:%SZ"),
            "source": None,
            "translations": {},
            "renders": [],
            "reviews": [],
            "glossary_versions": [],
        }
    
    # Source tracking
    def set_source(self, pdf_path: str):
        """Record the source PDF with checksum."""
        file_size = os.path.getsize(pdf_path)
        
        with open(pdf_path, 'rb') as f:
            checksum = hashlib.sha256(f.read()).hexdigest()
        
        self.data["source"] = {
            "path": os.path.basename(pdf_path),
            "checksum_sha256": checksum,
            "file_size": file_size,
            "recorded_at": time.strftime("%Y-%m-%dT%H:%M:%SZ"),
        }
        self._save()
    
    # Translation versioning
    def log_translation(self, language: str, translation_data: dict, 
                       source: str = "ai"):
        """Record a translation version."""
        if language not in self.data["translations"]:
            self.data["translations"][language] = []
        
        # Hash the translation content
        content_str = json.dumps(translation_data, sort_keys=True, ensure_ascii=False)
        content_hash = hashlib.md5(content_str.encode('utf-8')).hexdigest()[:12]
        
        version = {
            "version": len(self.data["translations"][language]) + 1,
            "content_hash": content_hash,
            "source": source,  # "ai", "human_review", "glossary_update"
            "timestamp": time.strftime("%Y-%m-%dT%H:%M:%SZ"),
            "page_count": len(translation_data.get("pages", [])),
        }
        
        self.data["translations"][language].append(version)
        self._save()
        return version
    
    # Render logging
    def log_render(self, language: str, report: dict, engine_version: str = "v8"):
        """Record a render event."""
        entry = {
            "language": language,
            "engine_version": engine_version,
            "timestamp": time.strftime("%Y-%m-%dT%H:%M:%SZ"),
            "pages_processed": report.get("pages_processed", 0),
            "spans_replaced": report.get("spans_replaced", 0),
            "errors": len(report.get("errors", [])),
            "validation_passed": report.get("validation", {}).get("overall_valid", None),
        }
        
        self.data["renders"].append(entry)
        self._save()
        return entry
    
    # Review logging
    def log_review(self, reviewer: str, language: str, action: str, 
                  pages: list = None, notes: str = ""):
        """Record a review action (approve, reject, edit)."""
        entry = {
            "reviewer": reviewer,
            "language": language,
            "action": action,  # "approve", "reject", "edit"
            "pages": pages or [],
            "notes": notes,
            "timestamp": time.strftime("%Y-%m-%dT%H:%M:%SZ"),
        }
        
        self.data["reviews"].append(entry)
        self._save()
        return entry
    
    # Glossary tracking
    def log_glossary_update(self, language: str, terms_count: int, source: str = "manual"):
        """Record a glossary update."""
        entry = {
            "language": language,
            "terms_count": terms_count,
            "source": source,
            "timestamp": time.strftime("%Y-%m-%dT%H:%M:%SZ"),
        }
        
        self.data["glossary_versions"].append(entry)
        self._save()
        return entry
    
    # Query
    def get_summary(self) -> dict:
        """Get audit trail summary."""
        return {
            "book_id": self.book_id,
            "source_recorded": self.data["source"] is not None,
            "source_checksum": self.data["source"]["checksum_sha256"][:12] + "..." if self.data["source"] else None,
            "languages": list(self.data["translations"].keys()),
            "total_translation_versions": sum(len(v) for v in self.data["translations"].values()),
            "total_renders": len(self.data["renders"]),
            "total_reviews": len(self.data["reviews"]),
            "last_render": self.data["renders"][-1]["timestamp"] if self.data["renders"] else None,
            "last_review": self.data["reviews"][-1]["timestamp"] if self.data["reviews"] else None,
        }


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="Audit Trail — V8")
    subparsers = parser.add_subparsers(dest="command")
    
    # Init
    init_p = subparsers.add_parser("init", help="Initialize audit trail for a book")
    init_p.add_argument("--book-id", required=True)
    init_p.add_argument("--source", "-s", required=True, help="Source PDF path")
    init_p.add_argument("--storage", default=".audit", help="Audit storage directory")
    
    # Log render
    render_p = subparsers.add_parser("log-render", help="Log a render event")
    render_p.add_argument("--book-id", required=True)
    render_p.add_argument("--language", "-l", required=True)
    render_p.add_argument("--report", "-r", required=True, help="Render report JSON")
    render_p.add_argument("--storage", default=".audit")
    
    # Show
    show_p = subparsers.add_parser("show", help="Show audit trail")
    show_p.add_argument("--book-id", required=True)
    show_p.add_argument("--storage", default=".audit")
    
    args = parser.parse_args()
    
    if args.command == "init":
        trail = AuditTrail(args.storage, args.book_id)
        trail.set_source(args.source)
        
        print(f"Audit trail initialized for book {args.book_id}")
        print(f"  Source: {os.path.basename(args.source)}")
        print(f"  Checksum: {trail.data['source']['checksum_sha256'][:16]}...")
    
    elif args.command == "log-render":
        trail = AuditTrail(args.storage, args.book_id)
        
        with open(args.report, 'r') as f:
            report = json.load(f)
        
        entry = trail.log_render(args.language, report)
        print(f"Render logged: {entry['timestamp']}")
        print(f"  Language: {entry['language']}")
        print(f"  Pages: {entry['pages_processed']}")
        print(f"  Spans: {entry['spans_replaced']}")
    
    elif args.command == "show":
        trail = AuditTrail(args.storage, args.book_id)
        summary = trail.get_summary()
        
        print(f"Audit Trail — Book {summary['book_id']}:")
        print(f"  Source recorded: {summary['source_recorded']}")
        if summary['source_checksum']:
            print(f"  Source checksum: {summary['source_checksum']}")
        print(f"  Languages: {summary['languages'] or 'none yet'}")
        print(f"  Translation versions: {summary['total_translation_versions']}")
        print(f"  Renders: {summary['total_renders']}")
        print(f"  Reviews: {summary['total_reviews']}")
        if summary['last_render']:
            print(f"  Last render: {summary['last_render']}")
    
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
