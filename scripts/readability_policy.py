"""
Readability + typography hierarchy policy — Digital Bookstore V8 (brief §10.1/§10.2)
=====================================================================================
Configurable minimum readable sizes (by document type / market / output format) and
the typography-hierarchy rules the render gate enforces:

  - minimum readable size reached without a valid fit  -> region fails
  - heading visual size must exceed body visual size
  - table header size must be >= table body size
  - peer regions (same role on a page) must not vary beyond a tolerance

Book-agnostic: thresholds are policy, not per-book constants. A project may override
them via a JSON file (fonts_dir/../config/readability.json or env READABILITY_POLICY).
"""

import json
import os

# Default minimum readable point sizes by (document_type, market, output_format).
# Falls back progressively: exact -> document_type default -> global default.
_DEFAULT_MIN_SIZES = {
    "default": 7.0,
    "by_document_type": {
        "childrens_book": 9.0,   # larger floor for early readers
        "picture_book": 9.0,
        "textbook": 8.0,
        "novel": 7.0,
    },
    "by_market": {
        # e.g. large-print markets demand a higher floor
        "large_print": 14.0,
    },
    "by_output_format": {
        "print": 7.0,
        "ebook": 8.0,
        "web": 9.0,
    },
}

# Roles considered "heading-like" vs "body-like" for the hierarchy check.
HEADING_ROLES = {"heading", "table_header", "book_title", "subtitle", "list_heading",
                 "cover_title", "cover_subtitle"}
BODY_ROLES = {"paragraph", "word_list_item", "table_cell", "list_item", "caption",
              "word_item", "copyright", "imprint"}

# Peer-region size variance tolerance (relative).
PEER_VARIANCE_TOLERANCE = 0.15


def load_policy(config_path=None):
    """Load the readability policy, overlaying an optional JSON override file."""
    policy = json.loads(json.dumps(_DEFAULT_MIN_SIZES))  # deep copy
    path = config_path or os.environ.get("READABILITY_POLICY")
    if path and os.path.isfile(path):
        try:
            with open(path, "r", encoding="utf-8") as fh:
                override = json.load(fh)
            for k, v in override.items():
                if isinstance(v, dict) and isinstance(policy.get(k), dict):
                    policy[k].update(v)
                else:
                    policy[k] = v
        except Exception:
            pass
    return policy


def min_readable_size(document_type=None, market=None, output_format="print", policy=None):
    """
    Resolve the minimum readable point size for the given context. The STRICTEST
    (largest) applicable floor wins, so a large-print market is never undercut by
    a document-type default.
    """
    policy = policy or load_policy()
    candidates = [policy.get("default", 7.0)]
    if document_type:
        candidates.append(policy.get("by_document_type", {}).get(document_type,
                          policy.get("default", 7.0)))
    if market:
        candidates.append(policy.get("by_market", {}).get(market, 0))
    if output_format:
        candidates.append(policy.get("by_output_format", {}).get(output_format, 0))
    return max(c for c in candidates if c)
