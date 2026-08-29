"""
pikepdf Integration — Digital Bookstore V8
============================================
Reliable content-stream parsing using pikepdf's proper PDF object model.

Replaces fragile regex parsing in content_stream_surgery.py with proper
PDF token-level parsing via pikepdf.

Per the brief:
  - "For born-digital PDF text, remove only the relevant PDF text-showing operations."
  - pikepdf provides proper PDF object parsing without regex fragility

Key advantages over regex:
  1. Handles escaped parentheses in strings correctly
  2. Handles hex strings <4F6B> properly
  3. Doesn't break on binary content in streams
  4. Properly handles nested arrays in TJ operators
  5. Can modify specific operators without corrupting stream structure

PDF text-showing operators:
  Tj  — Show a text string
  TJ  — Show text with individual glyph positioning  
  '   — Move to next line and show text
  "   — Set spacing, move to next line, show text

Usage:
    python pikepdf_integration.py analyze --input book.pdf --page N
    python pikepdf_integration.py remove --input book.pdf --output cleaned.pdf --page N --targets targets.json
"""

import argparse
import json
import os
import sys
from typing import Optional

import pikepdf
from pikepdf import Pdf, Page, Name, String, Array, Object


# =============================================================================
# CONTENT STREAM PARSING
# =============================================================================

def parse_content_stream(page: Page) -> list:
    """
    Parse a page's content stream into a list of (operands, operator) tuples.
    
    Uses pikepdf's parse_content_stream which properly tokenizes all PDF
    operators including text-showing ones.
    
    Returns list of:
    [
        (operands: list, operator: pikepdf.Operator),
        ...
    ]
    """
    try:
        commands = pikepdf.parse_content_stream(page)
        return commands
    except Exception as e:
        print(f"[pikepdf] Error parsing content stream: {e}", file=sys.stderr)
        return []


def extract_text_from_operand(operand) -> str:
    """
    Extract readable text from a PDF operand (String or Array).
    
    Handles:
    - pikepdf.String → decode to text
    - pikepdf.Array (for TJ) → concatenate string elements
    """
    if isinstance(operand, String):
        try:
            return str(operand)
        except Exception:
            return operand.decode('latin-1', errors='replace') if hasattr(operand, 'decode') else ""
    elif isinstance(operand, bytes):
        try:
            return operand.decode('latin-1', errors='replace')
        except Exception:
            return ""
    return ""


def extract_text_from_tj_array(array_operand) -> str:
    """
    Extract combined text from a TJ array operand.
    
    TJ arrays contain alternating strings and kerning values:
    [(H) 10 (ello) -20 ( W) 5 (orld)]
    
    We extract only the string parts and concatenate them.
    """
    parts = []
    if isinstance(array_operand, Array):
        for item in array_operand:
            if isinstance(item, String):
                try:
                    parts.append(str(item))
                except Exception:
                    pass
            elif isinstance(item, bytes):
                try:
                    parts.append(item.decode('latin-1', errors='replace'))
                except Exception:
                    pass
    return ''.join(parts)


# =============================================================================
# TEXT OPERATION ANALYSIS
# =============================================================================

def analyze_text_operations(page: Page) -> dict:
    """
    Analyze all text-showing operations on a page.
    
    Returns:
    {
        "total_operations": int,
        "text_operations": int,
        "text_entries": [
            {"operator": "Tj"|"TJ"|"'"|'"', "text": str, "index": int}
        ],
        "non_text_operations": int,
    }
    """
    commands = parse_content_stream(page)
    
    result = {
        "total_operations": len(commands),
        "text_operations": 0,
        "text_entries": [],
        "non_text_operations": 0,
    }
    
    text_operators = {
        pikepdf.Operator("Tj"),
        pikepdf.Operator("TJ"),
        pikepdf.Operator("'"),
        pikepdf.Operator('"'),
    }
    
    for i, (operands, operator) in enumerate(commands):
        if operator in text_operators:
            result["text_operations"] += 1
            
            # Extract text based on operator type
            text = ""
            op_name = str(operator)
            
            if op_name in ("Tj", "'"):
                # Single string operand
                if operands:
                    text = extract_text_from_operand(operands[0])
            elif op_name == "TJ":
                # Array operand
                if operands:
                    text = extract_text_from_tj_array(operands[0])
            elif op_name == '"':
                # Three operands: aw ac string
                if len(operands) >= 3:
                    text = extract_text_from_operand(operands[2])
            
            result["text_entries"].append({
                "operator": op_name,
                "text": text,
                "index": i,
            })
        else:
            result["non_text_operations"] += 1
    
    return result


# =============================================================================
# TEXT REMOVAL — Proper operator-level surgery
# =============================================================================

def remove_text_operations(page: Page, target_texts: list, 
                          match_mode: str = "exact") -> dict:
    """
    Remove specific text-showing operations from a page's content stream.
    
    Uses pikepdf's proper parsing to identify and neutralize text operators
    without corrupting the stream structure.
    
    Args:
        page: pikepdf Page object
        target_texts: List of text strings to remove
        match_mode: "exact" (full match), "contains" (substring), "startswith"
    
    Returns:
        {"removed": int, "preserved": int, "modified": bool}
    """
    commands = parse_content_stream(page)
    
    if not commands:
        return {"removed": 0, "preserved": 0, "modified": False}
    
    # Normalize targets
    targets = set(t.strip().lower() for t in target_texts if t.strip())
    
    text_operators = {
        pikepdf.Operator("Tj"),
        pikepdf.Operator("TJ"),
        pikepdf.Operator("'"),
        pikepdf.Operator('"'),
    }
    
    new_commands = []
    removed = 0
    preserved = 0
    modified = False
    
    for operands, operator in commands:
        if operator in text_operators:
            # Extract text from this operation
            op_name = str(operator)
            text = ""
            
            if op_name in ("Tj", "'"):
                if operands:
                    text = extract_text_from_operand(operands[0])
            elif op_name == "TJ":
                if operands:
                    text = extract_text_from_tj_array(operands[0])
            elif op_name == '"':
                if len(operands) >= 3:
                    text = extract_text_from_operand(operands[2])
            
            text_lower = text.strip().lower()
            
            # Check if this text should be removed
            should_remove = False
            if match_mode == "exact":
                should_remove = text_lower in targets
            elif match_mode == "contains":
                should_remove = any(t in text_lower for t in targets)
            elif match_mode == "startswith":
                should_remove = any(text_lower.startswith(t) for t in targets)
            
            if should_remove:
                # Neutralize: replace with empty text operation (preserves BT/ET structure)
                if op_name == "TJ":
                    new_commands.append(([Array([String(b"")])], operator))
                elif op_name in ("Tj", "'"):
                    new_commands.append(([String(b"")], operator))
                elif op_name == '"':
                    new_commands.append(([operands[0], operands[1], String(b"")], operator))
                removed += 1
                modified = True
            else:
                new_commands.append((operands, operator))
                preserved += 1
        else:
            # Non-text operator — always preserve
            new_commands.append((operands, operator))
    
    # Write modified stream back to page
    if modified:
        new_stream = pikepdf.unparse_content_stream(new_commands)
        page.Contents = page.obj.make_stream(new_stream)
    
    return {"removed": removed, "preserved": preserved, "modified": modified}


def remove_all_text_from_page(page: Page, keep_patterns: list = None) -> dict:
    """
    Remove ALL text-showing operations from a page.
    Optionally keep text matching certain patterns (e.g., page numbers).
    
    Args:
        page: pikepdf Page object
        keep_patterns: List of text strings to preserve (e.g., ["1", "2", ...])
    
    Returns:
        {"removed": int, "kept": int}
    """
    commands = parse_content_stream(page)
    
    if not commands:
        return {"removed": 0, "kept": 0}
    
    keep_set = set(t.strip().lower() for t in (keep_patterns or []))
    
    text_operators = {
        pikepdf.Operator("Tj"),
        pikepdf.Operator("TJ"),
        pikepdf.Operator("'"),
        pikepdf.Operator('"'),
    }
    
    new_commands = []
    removed = 0
    kept = 0
    
    for operands, operator in commands:
        if operator in text_operators:
            # Extract text
            op_name = str(operator)
            text = ""
            if op_name in ("Tj", "'") and operands:
                text = extract_text_from_operand(operands[0])
            elif op_name == "TJ" and operands:
                text = extract_text_from_tj_array(operands[0])
            elif op_name == '"' and len(operands) >= 3:
                text = extract_text_from_operand(operands[2])
            
            # Check if we should keep this text
            text_lower = text.strip().lower()
            if keep_set and text_lower in keep_set:
                new_commands.append((operands, operator))
                kept += 1
            else:
                # Neutralize
                if op_name == "TJ":
                    new_commands.append(([Array([String(b"")])], operator))
                elif op_name in ("Tj", "'"):
                    new_commands.append(([String(b"")], operator))
                elif op_name == '"':
                    new_commands.append(([operands[0], operands[1], String(b"")], operator))
                removed += 1
        else:
            new_commands.append((operands, operator))
    
    # Write back
    if removed > 0:
        new_stream = pikepdf.unparse_content_stream(new_commands)
        page.Contents = page.obj.make_stream(new_stream)
    
    return {"removed": removed, "kept": kept}


# =============================================================================
# INTEGRATION WITH V8 ENGINE
# =============================================================================

def surgery_remove_spans(pdf_path: str, page_num: int, 
                        spans_to_remove: list, output_path: str = None) -> dict:
    """
    High-level function for V8 engine integration.
    
    Opens the PDF with pikepdf, removes specified text spans from the content
    stream, and saves. Returns a report.
    
    Args:
        pdf_path: Input PDF path
        page_num: 1-based page number
        spans_to_remove: List of span dicts with 'text_stripped' key
        output_path: Output path (optional, modifies in place if None)
    
    Returns:
        {"success": bool, "removed": int, "preserved": int, "method": "pikepdf"}
    """
    try:
        pdf = Pdf.open(pdf_path, allow_overwriting_input=True)
        page = pdf.pages[page_num - 1]
        
        target_texts = [s["text_stripped"] for s in spans_to_remove if s.get("text_stripped")]
        
        if not target_texts:
            pdf.close()
            return {"success": True, "removed": 0, "preserved": 0, "method": "pikepdf"}
        
        result = remove_text_operations(page, target_texts, match_mode="exact")
        
        save_path = output_path or pdf_path
        pdf.save(save_path)
        pdf.close()
        
        return {
            "success": True,
            "removed": result["removed"],
            "preserved": result["preserved"],
            "modified": result["modified"],
            "method": "pikepdf",
        }
    except Exception as e:
        return {
            "success": False,
            "error": str(e),
            "method": "pikepdf",
            "removed": 0,
            "preserved": 0,
        }


# =============================================================================
# CLI
# =============================================================================

def main():
    parser = argparse.ArgumentParser(description="pikepdf Content-Stream Surgery — V8")
    subparsers = parser.add_subparsers(dest="command")
    
    # Analyze
    analyze_p = subparsers.add_parser("analyze", help="Analyze text operations on a page")
    analyze_p.add_argument("--input", "-i", required=True)
    analyze_p.add_argument("--page", "-p", type=int, required=True)
    analyze_p.add_argument("--json", action="store_true")
    
    # Remove
    remove_p = subparsers.add_parser("remove", help="Remove text via content-stream surgery")
    remove_p.add_argument("--input", "-i", required=True)
    remove_p.add_argument("--output", "-o", required=True)
    remove_p.add_argument("--page", "-p", type=int, required=True)
    remove_p.add_argument("--targets", "-t", required=True, help="JSON file with target texts")
    
    args = parser.parse_args()
    
    if args.command == "analyze":
        pdf = Pdf.open(args.input)
        page = pdf.pages[args.page - 1]
        
        result = analyze_text_operations(page)
        pdf.close()
        
        if args.json:
            print(json.dumps(result, indent=2, ensure_ascii=False))
        else:
            print(f"Page {args.page} Content Stream Analysis:")
            print(f"  Total operations: {result['total_operations']}")
            print(f"  Text operations: {result['text_operations']}")
            print(f"  Non-text operations: {result['non_text_operations']}")
            if result['text_entries']:
                print(f"\n  Text entries:")
                for entry in result['text_entries'][:20]:
                    text_preview = entry['text'][:50]
                    print(f"    [{entry['operator']}] \"{text_preview}\"")
                if len(result['text_entries']) > 20:
                    print(f"    ... and {len(result['text_entries']) - 20} more")
    
    elif args.command == "remove":
        with open(args.targets, 'r', encoding='utf-8') as f:
            targets = json.load(f)
        
        pdf = Pdf.open(args.input)
        page = pdf.pages[args.page - 1]
        
        result = remove_text_operations(page, targets)
        pdf.save(args.output)
        pdf.close()
        
        print(f"Surgery complete:")
        print(f"  Removed: {result['removed']}")
        print(f"  Preserved: {result['preserved']}")
        print(f"  Modified: {result['modified']}")
        print(f"  Output: {args.output}")
    
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
