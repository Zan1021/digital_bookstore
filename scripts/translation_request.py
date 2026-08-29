"""
Translation Request Builder — Digital Bookstore V8
====================================================
Builds structured translation requests from page manifests.
These requests are sent to the AI translation service (GPT-4)
and responses are validated for 1:1 coverage before rendering.

INPUT: Page manifest (from page_manifest.py)
OUTPUT: Structured translation request JSON + system prompt

The AI receives items with stable IDs and returns translations
keyed to those same IDs. No guessing structure from flat text.

Usage:
    python translation_request.py build --manifest manifest.json --language af --output request.json
    python translation_request.py validate --request request.json --response response.json
"""

import json
import os
import sys
from typing import Optional


# =============================================================================
# BUILD TRANSLATION REQUEST
# =============================================================================

def build_translation_request(manifest: dict, target_language: str, target_language_name: str = None) -> dict:
    """
    Build a structured translation request from a page manifest.
    
    Returns a dict with:
    - system_prompt: instruction for the AI translator
    - translation_units: list of items to translate (with IDs)
    - metadata: language info, item counts
    """
    if not target_language_name:
        language_names = {
            'af': 'Afrikaans', 'zu': 'isiZulu', 'xh': 'isiXhosa',
            'st': 'Sesotho', 'nso': 'Sepedi', 'tn': 'Setswana',
            'ts': 'Xitsonga', 've': 'Tshivenda', 'ss': 'siSwati',
            'nr': 'isiNdebele', 'fr': 'French', 'pt': 'Portuguese',
        }
        target_language_name = language_names.get(target_language, target_language)

    translation_units = []
    
    for page in manifest.get("pages", []):
        page_num = page["page_number"]
        page_type = page.get("page_type", "unknown")
        
        for region in page.get("regions", []):
            policy = region.get("translation_policy", "preserve")
            
            if policy == "preserve":
                continue  # Don't translate preserved items
            
            semantic_role = region.get("semantic_role", "unknown")
            
            for item in region.get("items", []):
                unit = {
                    "id": item["id"],
                    "page_number": page_num,
                    "page_type": page_type,
                    "semantic_role": semantic_role,
                    "translation_policy": policy,
                    "source_text": item["text"],
                }
                
                # Add context for educational adaptations
                if policy == "educational_adaptation":
                    unit["instruction"] = (
                        f"This is educational content ({semantic_role}). "
                        f"Create an equivalent {target_language_name} exercise "
                        f"at the same difficulty level. Do not translate literally."
                    )
                
                translation_units.append(unit)

    # Build system prompt
    system_prompt = _build_system_prompt(target_language_name, manifest)

    return {
        "target_language": target_language,
        "target_language_name": target_language_name,
        "source_language": "en",
        "total_units": len(translation_units),
        "system_prompt": system_prompt,
        "translation_units": translation_units,
    }


def _build_system_prompt(language_name: str, manifest: dict) -> str:
    """Build the system prompt for the AI translator."""
    page_count = manifest.get("page_count", 0)
    
    return f"""You are translating a children's picture book into {language_name}.

RULES:
1. You will receive a list of items, each with a stable "id" field.
2. Return ONLY a JSON array with objects: {{"id": "...", "translation": "..."}}
3. Every source ID must appear exactly once in your response.
4. Do NOT add, remove, or reorder items.
5. Do NOT include explanations or commentary — only the JSON array.
6. Keep translations age-appropriate (foundation phase, 5-8 years old).
7. For story_prose: translate the full paragraph naturally. Do not force line breaks.
8. For word_list items: translate to a single word equivalent.
9. For high_frequency_words: translate to the {language_name} equivalent high-frequency word.
10. For phonics (educational_adaptation): create equivalent {language_name} phonics examples at the same difficulty level. Use valid {language_name} spelling patterns.
11. For table_header (translate_headers): translate the header text.
12. For book_subtitle: translate the title naturally.
13. For publisher_information: translate factual info, keep names/URLs unchanged.
14. For character_biography: translate the paragraph naturally.
15. For series_title_list: translate each title.

IMPORTANT: Your response must be valid JSON. Nothing else. Start with [ and end with ]."""


# =============================================================================
# BUILD REQUEST FOR A SINGLE PAGE (for incremental translation)
# =============================================================================

def build_page_request(page_manifest: dict, target_language: str, target_language_name: str = None) -> dict:
    """Build a translation request for a single page."""
    fake_manifest = {
        "page_count": 1,
        "pages": [page_manifest],
    }
    return build_translation_request(fake_manifest, target_language, target_language_name)


# =============================================================================
# VALIDATE TRANSLATION RESPONSE
# =============================================================================

def validate_response(request: dict, response: list) -> dict:
    """
    Validate that a translation response covers all requested items.
    
    Returns:
    {
        "valid": bool,
        "errors": [...],
        "warnings": [...],
        "coverage": {
            "expected": int,
            "received": int,
            "missing_ids": [...],
            "extra_ids": [...],
            "empty_translations": [...]
        }
    }
    """
    expected_ids = {u["id"] for u in request["translation_units"]}
    received_ids = set()
    empty_translations = []
    
    errors = []
    warnings = []
    
    if not isinstance(response, list):
        return {
            "valid": False,
            "errors": ["Response is not a JSON array"],
            "warnings": [],
            "coverage": {"expected": len(expected_ids), "received": 0},
        }
    
    for item in response:
        if not isinstance(item, dict):
            errors.append(f"Non-dict item in response: {item}")
            continue
        
        item_id = item.get("id")
        translation = item.get("translation", "")
        
        if not item_id:
            errors.append(f"Item missing 'id' field: {item}")
            continue
        
        if item_id in received_ids:
            errors.append(f"Duplicate ID in response: {item_id}")
        
        received_ids.add(item_id)
        
        if not translation or not translation.strip():
            empty_translations.append(item_id)
    
    missing_ids = expected_ids - received_ids
    extra_ids = received_ids - expected_ids
    
    if missing_ids:
        errors.append(f"Missing {len(missing_ids)} translations: {sorted(list(missing_ids))[:10]}...")
    
    if extra_ids:
        warnings.append(f"Extra {len(extra_ids)} IDs not in request: {sorted(list(extra_ids))[:10]}...")
    
    if empty_translations:
        warnings.append(f"{len(empty_translations)} empty translations: {empty_translations[:5]}...")
    
    valid = len(errors) == 0 and len(missing_ids) == 0
    
    return {
        "valid": valid,
        "errors": errors,
        "warnings": warnings,
        "coverage": {
            "expected": len(expected_ids),
            "received": len(received_ids),
            "missing_ids": sorted(list(missing_ids)),
            "extra_ids": sorted(list(extra_ids)),
            "empty_translations": empty_translations,
        }
    }


# =============================================================================
# CONVERT RESPONSE TO TRANSLATIONS MAP (for V8 renderer)
# =============================================================================

def response_to_translations_map(response: list, manifest: dict) -> dict:
    """
    Convert a validated translation response into the format expected by
    the V8 renderer (keyed by page_number → translated content).
    
    Returns: {page_number: {"items": {item_id: translation_text}}}
    """
    # Build ID → translation lookup
    id_to_translation = {}
    for item in response:
        if isinstance(item, dict) and "id" in item and "translation" in item:
            id_to_translation[item["id"]] = item["translation"]
    
    # Build page-level translation map
    page_translations = {}
    
    for page in manifest.get("pages", []):
        page_num = page["page_number"]
        page_items = {}
        
        for region in page.get("regions", []):
            for item in region.get("items", []):
                item_id = item["id"]
                if item_id in id_to_translation:
                    page_items[item_id] = id_to_translation[item_id]
        
        if page_items:
            page_translations[page_num] = {
                "page_type": page.get("page_type"),
                "items": page_items,
            }
    
    return page_translations


# =============================================================================
# LEGACY COMPATIBILITY — Convert old flat text translations to manifest format
# =============================================================================

def legacy_text_to_manifest_items(translated_text: str, page_manifest: dict) -> list:
    """
    Convert legacy flat translated text into a list of {id, translation} items
    by mapping line-by-line to the manifest's item IDs.
    
    This is a bridge for existing translations that were generated before
    the manifest system. It maps by reading order (column by column).
    
    .. deprecated::
        Use DocumentScene.to_translation_request() + scene_renderer._map_legacy_to_ids()
        instead. This function will be removed once all stored translations are
        migrated to ID-mapped format. The scene graph path handles legacy text
        mapping natively via the `legacy_page_text` parameter.
    """
    import re
    import warnings
    warnings.warn(
        "legacy_text_to_manifest_items() is deprecated. "
        "Use scene_renderer.render_from_scene(legacy_page_text=...) instead.",
        DeprecationWarning,
        stacklevel=2,
    )
    
    page_type = page_manifest.get("page_type", "story")
    regions = page_manifest.get("regions", [])
    
    if page_type == "vocabulary":
        return _legacy_vocab_to_items(translated_text, regions)
    elif page_type == "story":
        return _legacy_story_to_items(translated_text, regions)
    elif page_type == "cover":
        return _legacy_cover_to_items(translated_text, regions)
    elif page_type == "back_cover":
        return _legacy_back_cover_to_items(translated_text, regions)
    elif page_type == "copyright":
        return _legacy_copyright_to_items(translated_text, regions)
    
    return []


def _legacy_vocab_to_items(text: str, regions: list) -> list:
    """Map legacy vocab text to manifest items."""
    import re
    
    lines = [l.strip() for l in text.split('\n') if l.strip()]
    
    # Remove preamble
    while lines and not re.match(r'^[A-Z\sËÖÜ]+$', lines[0]):
        lines.pop(0)
    
    # Separate ALL headers from content (headers can appear anywhere in the text)
    # Headers are ALL-CAPS lines shorter than 30 chars
    headers = []
    content_lines = []
    
    for line in lines:
        if re.match(r'^[A-Z\sËÖÜ\-]+$', line) and len(line) < 30 and len(line) > 2:
            headers.append(line)
        elif line.strip() in ['®', '©']:
            continue  # Skip symbols
        else:
            content_lines.append(line)
    
    # Get all content items from manifest (in column order)
    all_items = []
    header_items = []
    
    for region in regions:
        if region.get("semantic_role") == "table_header":
            header_items = region.get("items", [])
        else:
            all_items.extend(region.get("items", []))
    
    # Map translations to items by order
    result = []
    
    # Headers
    for i, h_item in enumerate(header_items):
        if i < len(headers):
            result.append({"id": h_item["id"], "translation": headers[i]})
    
    # Content items
    for i, item in enumerate(all_items):
        if i < len(content_lines):
            result.append({"id": item["id"], "translation": content_lines[i]})
    
    return result


def _legacy_story_to_items(text: str, regions: list) -> list:
    """Map legacy story text to manifest items."""
    import re
    
    clean = text.strip()
    clean = re.sub(r'^Hier is bladsy \d+.*?:\s*\n?', '', clean, flags=re.IGNORECASE)
    clean = re.sub(r'^Here is page \d+.*?:\s*\n?', '', clean, flags=re.IGNORECASE)
    clean = re.sub(r'^\d{1,2}\s*\\?\s*\n', '', clean)
    clean = re.sub(r'\s*\\\s*$', '', clean, flags=re.MULTILINE)
    lines = [l.strip() for l in clean.split('\n') if l.strip()]
    clean = ' '.join(lines)
    clean = re.sub(r'\s{2,}', ' ', clean).strip()
    
    result = []
    for region in regions:
        for item in region.get("items", []):
            result.append({"id": item["id"], "translation": clean})
            break  # Story pages have one paragraph item
    
    return result


def _legacy_cover_to_items(text: str, regions: list) -> list:
    """Map legacy cover text to manifest items."""
    import re
    
    clean = text.strip()
    lines = [l.strip() for l in clean.split('\n') if l.strip()]
    skip_patterns = [r'studios?', r'mthombothi', r'^[®©]$']
    filtered = [l for l in lines if not any(re.search(pat, l, re.IGNORECASE) for pat in skip_patterns)]
    subtitle = ' '.join(filtered) if filtered else clean
    
    result = []
    for region in regions:
        if region.get("semantic_role") == "book_subtitle":
            for item in region.get("items", []):
                result.append({"id": item["id"], "translation": subtitle})
    
    return result


def _legacy_back_cover_to_items(text: str, regions: list) -> list:
    """Map legacy back cover text to manifest items."""
    import re
    
    clean = text.strip()
    clean = re.sub(r'\s*\\\s*$', '', clean, flags=re.MULTILINE)
    lines = [l.strip() for l in clean.split('\n') if l.strip()]
    
    result = []
    for region in regions:
        for i, item in enumerate(region.get("items", [])):
            if i < len(lines):
                result.append({"id": item["id"], "translation": lines[i]})
    
    return result


def _legacy_copyright_to_items(text: str, regions: list) -> list:
    """Map legacy copyright text to manifest items."""
    import re
    
    lines = [l.strip() for l in text.split('\n') if l.strip()]
    lines = [l for l in lines if l not in ['®', '©']]
    
    if not lines:
        return []
    
    result = []
    
    # First line is subtitle
    subtitle = lines[0]
    remaining = lines[1:]
    
    for region in regions:
        role = region.get("semantic_role")
        
        if role == "book_subtitle":
            for item in region.get("items", []):
                result.append({"id": item["id"], "translation": subtitle})
        
        elif role == "publisher_information":
            items = region.get("items", [])
            # Split remaining into publisher info (before bio marker)
            pub_lines = []
            for line in remaining:
                if any(m in line.lower() for m in ['die naam', 'the name', 'karakter', 'is uitgevind']):
                    break
                pub_lines.append(line)
            
            for i, item in enumerate(items):
                if i < len(pub_lines):
                    result.append({"id": item["id"], "translation": pub_lines[i]})
        
        elif role == "character_biography":
            # Bio is everything after the publisher info
            bio_lines = []
            in_bio = False
            for line in remaining:
                if not in_bio and any(m in line.lower() for m in ['die naam', 'the name', 'karakter', 'is uitgevind']):
                    in_bio = True
                if in_bio:
                    bio_lines.append(line)
            
            bio_text = ' '.join(bio_lines)
            for item in region.get("items", []):
                result.append({"id": item["id"], "translation": bio_text})
    
    return result


# =============================================================================
# CLI
# =============================================================================

def main():
    import argparse
    
    parser = argparse.ArgumentParser(description="Translation Request Builder")
    subparsers = parser.add_subparsers(dest="command")
    
    # Build command
    build_p = subparsers.add_parser("build", help="Build translation request from manifest")
    build_p.add_argument("--manifest", "-m", required=True, help="Manifest JSON file")
    build_p.add_argument("--language", "-l", required=True, help="Target language code")
    build_p.add_argument("--output", "-o", help="Output JSON file")
    
    # Validate command
    val_p = subparsers.add_parser("validate", help="Validate translation response")
    val_p.add_argument("--request", "-r", required=True, help="Request JSON file")
    val_p.add_argument("--response", "-s", required=True, help="Response JSON file")
    
    args = parser.parse_args()
    
    if args.command == "build":
        with open(args.manifest, 'r', encoding='utf-8') as f:
            manifest = json.load(f)
        
        request = build_translation_request(manifest, args.language)
        output = json.dumps(request, indent=2, ensure_ascii=False)
        
        if args.output:
            with open(args.output, 'w', encoding='utf-8') as f:
                f.write(output)
            print(f"Request saved to: {args.output}", file=sys.stderr)
            print(f"Total translation units: {request['total_units']}", file=sys.stderr)
        else:
            print(output)
    
    elif args.command == "validate":
        with open(args.request, 'r', encoding='utf-8') as f:
            request = json.load(f)
        with open(args.response, 'r', encoding='utf-8') as f:
            response = json.load(f)
        
        result = validate_response(request, response)
        print(json.dumps(result, indent=2))
    
    else:
        parser.print_help()


if __name__ == "__main__":
    main()
