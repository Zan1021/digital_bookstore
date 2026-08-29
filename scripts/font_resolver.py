"""
Auto Font Resolver
==================
Given a font name from a PDF, automatically find and download the best matching
font from Google Fonts. Uses category matching and fuzzy name search.

Usage:
    python font_resolver.py resolve "Edu-Aid" --output ./fonts/
    python font_resolver.py resolve "ComicSansMS" --output ./fonts/
    python font_resolver.py list --category handwriting
"""

import argparse
import json
import os
import re
import sys
from pathlib import Path

import requests

# Google Fonts API (no key needed for basic list)
GOOGLE_FONTS_API = "https://www.googleapis.com/webfonts/v1/webfonts"
GOOGLE_FONTS_CSS = "https://fonts.googleapis.com/css2"

# Font category mapping: PDF font keywords -> Google Fonts categories
CATEGORY_HINTS = {
    "handwriting": ["hand", "script", "edu", "comic", "cursive", "write", "kid", "child", "craft"],
    "serif": ["serif", "roman", "times", "garamond", "georgia", "book", "text"],
    "sans-serif": ["sans", "arial", "helvetica", "gothic", "grotesk", "calibri"],
    "display": ["display", "poster", "bold", "black", "impact", "ad", "lib"],
    "monospace": ["mono", "courier", "code", "console", "fixed"],
}

# Known font name -> resolved font family mapping
# PRIMARY FONT: Playpen Sans (replaces Comic Sans, Edu-Aid, and all handwriting fonts)
# EARLY READER: Grade1Font (for grade 1 / beginner readers)
# These are LOCAL fonts — no Google download needed for matched fonts.
KNOWN_MAPPINGS = {
    # Handwriting / children's book fonts → Playpen Sans
    "edu-aid": "PlaypenSans-Regular",
    "edu sa beginner": "PlaypenSans-Regular",
    "comicsansms": "PlaypenSans-Regular",
    "comic sans ms": "PlaypenSans-Regular",
    "comic sans": "PlaypenSans-Regular",
    "comic neue": "PlaypenSans-Regular",
    "patrick hand": "PlaypenSans-Regular",
    "indie flower": "PlaypenSans-Regular",
    "caveat": "PlaypenSans-Regular",
    "kalam": "PlaypenSans-Regular",
    "playwriteza": "PlaypenSans-Regular",
    # Display / title fonts → Playpen Sans Bold
    "adlibbt-regular": "PlaypenSans-Bold",
    "adlib": "PlaypenSans-Bold",
    "bangers": "PlaypenSans-Bold",
    "fredoka": "PlaypenSans-Bold",
    # Craft / decorative → Playpen Sans Medium
    "ozhandicraftbt-roman": "PlaypenSans-Medium",
    "ozhandicraft": "PlaypenSans-Medium",
    # Standard sans-serif → Playpen Sans (children's book context)
    "calibri": "PlaypenSans-Regular",
    "arial": "PlaypenSans-Regular",
    "helvetica": "PlaypenSans-Regular",
    "verdana": "PlaypenSans-Regular",
    # Serif fonts → Playpen Sans (keeping the friendly look)
    "times new roman": "PlaypenSans-Regular",
    "times": "PlaypenSans-Regular",
    "georgia": "PlaypenSans-Regular",
    "papyrus": "PlaypenSans-Medium",
    # Early reader font
    "grade 1 font": "Grade1Font",
    "grade1font": "Grade1Font",
    "grade1": "Grade1Font",
}


def get_google_fonts_list(api_key: str = None) -> list:
    """Fetch the full list of Google Fonts with metadata."""
    params = {"sort": "popularity"}
    if api_key:
        params["key"] = api_key
    
    r = requests.get(GOOGLE_FONTS_API, params=params)
    if r.status_code != 200:
        print(f"Warning: Google Fonts API returned {r.status_code}", file=sys.stderr)
        return []
    
    data = r.json()
    return data.get("items", [])


def guess_category(font_name: str) -> str:
    """Guess the font category from the PDF font name."""
    name_lower = font_name.lower().replace("-", "").replace(" ", "")
    
    for category, keywords in CATEGORY_HINTS.items():
        for keyword in keywords:
            if keyword in name_lower:
                return category
    
    return "handwriting"  # Default for children's books


def find_best_match(pdf_font_name: str, google_fonts: list) -> dict | None:
    """
    Find the best matching Google Font for a PDF font name.
    Strategy:
    1. Check known mappings first
    2. Try exact/fuzzy name match
    3. Fall back to category-based selection
    """
    clean_name = pdf_font_name.lower().replace("-", " ").replace("+", "").strip()
    # Remove subset prefix (e.g., "OYTDCQ+Edu-Aid" -> "edu aid")
    if len(clean_name) > 7 and clean_name[6] == " ":
        maybe_prefix = clean_name[:6]
        if maybe_prefix.isalpha() and maybe_prefix.isupper():
            clean_name = clean_name[7:]
    clean_name = re.sub(r'^[a-z]{6}\+', '', pdf_font_name.lower()).replace("-", " ").strip()
    
    # 1. Check known mappings
    for key, mapped_family in KNOWN_MAPPINGS.items():
        if key in clean_name or clean_name in key:
            # Find this family in Google Fonts
            for font in google_fonts:
                if font["family"].lower() == mapped_family.lower():
                    return font
            # If not in the list, return a synthetic entry
            return {"family": mapped_family, "category": "handwriting", "variants": ["regular"]}
    
    # 2. Try fuzzy name matching against Google Fonts
    best_score = 0
    best_match = None
    
    for font in google_fonts:
        family_lower = font["family"].lower().replace(" ", "")
        
        # Exact match
        if clean_name.replace(" ", "") == family_lower:
            return font
        
        # Partial match scoring
        score = 0
        for word in clean_name.split():
            if word in family_lower:
                score += len(word)
        
        if score > best_score:
            best_score = score
            best_match = font
    
    # 3. Category-based fallback
    if best_score < 3:
        category = guess_category(pdf_font_name)
        # Get top fonts in this category
        category_fonts = [f for f in google_fonts if f.get("category") == category]
        
        # For children's books, prefer these specific fonts
        preferred = {
            "handwriting": ["Patrick Hand", "Caveat", "Comic Neue", "Indie Flower", "Kalam"],
            "display": ["Bangers", "Pacifico", "Lobster", "Fredoka"],
            "serif": ["Merriweather", "Lora", "Playfair Display"],
            "sans-serif": ["Nunito", "Poppins", "Open Sans"],
        }
        
        for pref_name in preferred.get(category, []):
            for font in category_fonts:
                if font["family"] == pref_name:
                    return font
        
        # Return the most popular in category
        if category_fonts:
            return category_fonts[0]
    
    return best_match


def download_font(family_name: str, output_dir: str, target_filename: str = None) -> str | None:
    """
    Download a font family from Google Fonts.
    Returns the path to the downloaded TTF file.
    """
    os.makedirs(output_dir, exist_ok=True)
    
    # Get the CSS to find the TTF URL
    css_url = f"{GOOGLE_FONTS_CSS}?family={family_name.replace(' ', '+')}&display=swap"
    headers = {"User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36"}
    
    r = requests.get(css_url, headers=headers)
    if r.status_code != 200:
        print(f"Failed to get font CSS for '{family_name}': {r.status_code}", file=sys.stderr)
        return None
    
    # Extract TTF URL from CSS
    ttf_match = re.search(r'url\((https://fonts\.gstatic\.com/[^)]+\.ttf)\)', r.text)
    if not ttf_match:
        print(f"No TTF URL found in CSS for '{family_name}'", file=sys.stderr)
        return None
    
    ttf_url = ttf_match.group(1)
    
    # Download the TTF
    r = requests.get(ttf_url)
    if r.status_code != 200 or len(r.content) < 1000:
        print(f"Failed to download TTF: {r.status_code}", file=sys.stderr)
        return None
    
    # Save it
    filename = target_filename or f"{family_name.replace(' ', '')}.ttf"
    output_path = os.path.join(output_dir, filename)
    
    with open(output_path, 'wb') as f:
        f.write(r.content)
    
    return output_path


def resolve_font(pdf_font_name: str, output_dir: str) -> dict:
    """
    Main resolver: given a PDF font name, find the best match.
    Priority order:
    1. Exact local file match
    2. Known mapping to local Playpen Sans / Grade1Font
    3. Fallback to Playpen Sans Regular (default for all children's books)
    
    Returns a dict with: family, category, path, source
    """
    result = {
        "pdf_font": pdf_font_name,
        "matched_family": None,
        "category": None,
        "path": None,
        "source": None,
    }
    
    # Strip subset prefix (e.g., "OYTDCQ+Edu-Aid" -> "Edu-Aid")
    clean_name = re.sub(r'^[A-Z]{6}\+', '', pdf_font_name)
    
    # Step 1: Check if we already have this exact font locally
    local_candidates = [
        os.path.join(output_dir, f"{clean_name}.ttf"),
        os.path.join(output_dir, f"{clean_name.replace(' ', '')}.ttf"),
        os.path.join(output_dir, f"{clean_name.replace('-', '')}.ttf"),
    ]
    
    for candidate in local_candidates:
        if os.path.isfile(candidate) and os.path.getsize(candidate) > 5000:
            result["path"] = candidate
            result["source"] = "local_exact"
            result["matched_family"] = clean_name
            result["category"] = "handwriting"
            return result
    
    # Step 2: Check known mappings → resolve to local Playpen Sans / Grade1Font
    clean_lower = clean_name.lower().replace("-", " ").replace("+", "").strip()
    for key, mapped_family in KNOWN_MAPPINGS.items():
        if key in clean_lower or clean_lower in key:
            # Look for the mapped font in the output directory
            mapped_path = os.path.join(output_dir, f"{mapped_family}.ttf")
            if os.path.isfile(mapped_path):
                result["matched_family"] = mapped_family
                result["category"] = "handwriting"
                result["path"] = mapped_path
                result["source"] = "local_known_mapping"
                print(f"Mapped '{pdf_font_name}' → '{mapped_family}' (local)", file=sys.stderr)
                return result
    
    # Step 3: Default to Playpen Sans Regular for ALL unresolved fonts
    # In a children's book context, this is always the right choice.
    default_font = "PlaypenSans-Regular"
    default_path = os.path.join(output_dir, f"{default_font}.ttf")
    
    if os.path.isfile(default_path):
        result["matched_family"] = default_font
        result["category"] = "handwriting"
        result["path"] = default_path
        result["source"] = "local_default_playpen"
        print(f"Defaulting '{pdf_font_name}' → Playpen Sans Regular", file=sys.stderr)
        return result
    
    # Step 4: Last resort — try Google Fonts (shouldn't normally reach here)
    print(f"Warning: No local font match for '{pdf_font_name}', trying Google Fonts...", file=sys.stderr)
    google_fonts = get_google_fonts_list()
    
    if google_fonts:
        match = find_best_match(pdf_font_name, google_fonts)
        if match:
            family = match["family"]
            result["matched_family"] = family
            result["category"] = match.get("category", "unknown")
            target_filename = f"{clean_name}.ttf"
            path = download_font(family, output_dir, target_filename)
            if path:
                result["path"] = path
                result["source"] = "google_fonts_download"
    
    return result


def resolve_all_fonts(pdf_path: str, output_dir: str) -> list:
    """
    Extract all fonts from a PDF and resolve each one.
    """
    import pymupdf
    
    doc = pymupdf.open(pdf_path)
    seen_fonts = set()
    results = []
    
    for page in doc:
        fonts = page.get_fonts(full=True)
        for font_info in fonts:
            basefont = font_info[3]
            clean_name = re.sub(r'^[A-Z]{6}\+', '', basefont)
            
            if clean_name in seen_fonts:
                continue
            seen_fonts.add(clean_name)
            
            result = resolve_font(basefont, output_dir)
            results.append(result)
    
    doc.close()
    return results


def main():
    parser = argparse.ArgumentParser(description="Auto Font Resolver for PDF Translation")
    subparsers = parser.add_subparsers(dest="command")
    
    # Resolve command
    resolve_parser = subparsers.add_parser("resolve", help="Resolve a single font")
    resolve_parser.add_argument("font_name", help="PDF font name to resolve")
    resolve_parser.add_argument("--output", "-o", default="./fonts", help="Output directory")
    
    # Resolve-all command
    all_parser = subparsers.add_parser("resolve-all", help="Resolve all fonts from a PDF")
    all_parser.add_argument("pdf_path", help="Path to PDF file")
    all_parser.add_argument("--output", "-o", default="./fonts", help="Output directory")
    
    args = parser.parse_args()
    
    if args.command == "resolve":
        result = resolve_font(args.font_name, args.output)
        print(json.dumps(result, indent=2))
    
    elif args.command == "resolve-all":
        results = resolve_all_fonts(args.pdf_path, args.output)
        print(json.dumps(results, indent=2))
    
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
