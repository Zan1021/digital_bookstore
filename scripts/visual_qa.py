"""
AI Visual QA Engine
===================
Uses GPT-4V (Vision) to compare original vs translated page images.
Evaluates layout quality, text positioning, font consistency, and overflow.

Returns structured JSON with approval status and specific issues.
Does NOT make rendering decisions — only evaluates and recommends.
"""

import base64
import json
import os
import sys
from pathlib import Path

import requests


def encode_image(image_path: str) -> str:
    """Encode an image file to base64 for GPT-4V."""
    with open(image_path, "rb") as f:
        return base64.b64encode(f.read()).decode("utf-8")


def visual_qa(
    original_image_path: str,
    translated_image_path: str,
    page_number: int,
    source_language: str = "English",
    target_language: str = "Afrikaans",
    api_key: str = None,
) -> dict:
    """
    Compare original page vs translated page using GPT-4V.
    
    Returns structured QA result:
    {
        "approved": true/false,
        "overall_score": 0-100,
        "text_position_correct": true/false,
        "text_size_correct": true/false,
        "font_style_correct": true/false,
        "line_spacing_correct": true/false,
        "alignment_correct": true/false,
        "overflow": true/false,
        "artwork_interference": true/false,
        "visual_hierarchy_preserved": true/false,
        "missing_text": true/false,
        "issues": ["list of specific issues"],
        "recommended_action": "description of fix if needed"
    }
    """
    if not api_key:
        api_key = os.environ.get("OPENAI_API_KEY", "")
    
    if not api_key:
        return {
            "approved": False,
            "overall_score": 0,
            "issues": ["No OpenAI API key provided"],
            "error": True,
        }

    # Encode both images
    original_b64 = encode_image(original_image_path)
    translated_b64 = encode_image(translated_image_path)

    # Build the QA prompt
    system_prompt = """You are the final visual quality-control system for a children's book translation.

Compare the ORIGINAL page (Image 1) with the TRANSLATED page (Image 2).

The translated page should look like the same book page but with text in a different language.

Evaluate:
1. TEXT POSITION: Is the translated text in the same position as the original?
2. TEXT SIZE: Is the font size consistent and matches the original?
3. FONT STYLE: Does the translated text use a similar font style (handwriting, serif, etc.)?
4. LINE SPACING: Are lines evenly spaced like the original?
5. ALIGNMENT: Is the text aligned the same way (left, center, etc.)?
6. OVERFLOW: Does any text extend beyond where it should be?
7. ARTWORK: Does the text cover any important artwork, characters, or illustrations?
8. HIERARCHY: Is the visual hierarchy preserved (big text stays big, small stays small)?
9. MISSING TEXT: Are any lines of text missing compared to the number of lines in the original?
10. READABILITY: Is the text clearly readable?

Scoring:
- 90-100: Excellent, approve immediately
- 70-89: Good but has minor issues
- 50-69: Noticeable problems, needs fixing
- 0-49: Serious issues, major rework needed

Return ONLY valid JSON (no markdown, no explanation outside the JSON):"""

    user_prompt = f"""Compare these two pages of a children's book.

Image 1 is the ORIGINAL ({source_language}) page {page_number}.
Image 2 is the TRANSLATED ({target_language}) page {page_number}.

Return your evaluation as JSON with this exact structure:
{{
    "approved": true or false,
    "overall_score": number 0-100,
    "text_position_correct": true or false,
    "text_size_correct": true or false,
    "font_style_correct": true or false,
    "line_spacing_correct": true or false,
    "alignment_correct": true or false,
    "overflow": true or false,
    "artwork_interference": true or false,
    "visual_hierarchy_preserved": true or false,
    "missing_text": true or false,
    "issues": ["list any specific issues found"],
    "recommended_action": "what to fix, or 'none' if approved"
}}"""

    # Call GPT-4V
    headers = {
        "Content-Type": "application/json",
        "Authorization": f"Bearer {api_key}",
    }

    payload = {
        "model": "gpt-4o",
        "messages": [
            {"role": "system", "content": system_prompt},
            {
                "role": "user",
                "content": [
                    {"type": "text", "text": user_prompt},
                    {
                        "type": "image_url",
                        "image_url": {
                            "url": f"data:image/png;base64,{original_b64}",
                            "detail": "high",
                        },
                    },
                    {
                        "type": "image_url",
                        "image_url": {
                            "url": f"data:image/png;base64,{translated_b64}",
                            "detail": "high",
                        },
                    },
                ],
            },
        ],
        "max_tokens": 1000,
        "temperature": 0.1,
    }

    try:
        response = requests.post(
            "https://api.openai.com/v1/chat/completions",
            headers=headers,
            json=payload,
            timeout=60,
        )

        if response.status_code != 200:
            return {
                "approved": False,
                "overall_score": 0,
                "issues": [f"API error: {response.status_code} - {response.text[:200]}"],
                "error": True,
            }

        result = response.json()
        content = result["choices"][0]["message"]["content"]

        # Parse the JSON response
        # Handle potential markdown wrapping
        if content.startswith("```"):
            content = content.split("```")[1]
            if content.startswith("json"):
                content = content[4:]

        qa_result = json.loads(content.strip())
        qa_result["error"] = False
        return qa_result

    except json.JSONDecodeError as e:
        return {
            "approved": False,
            "overall_score": 0,
            "issues": [f"Failed to parse QA response: {str(e)}"],
            "raw_response": content if 'content' in dir() else "no content",
            "error": True,
        }
    except Exception as e:
        return {
            "approved": False,
            "overall_score": 0,
            "issues": [f"QA failed: {str(e)}"],
            "error": True,
        }


def batch_qa(
    original_dir: str,
    translated_dir: str,
    api_key: str = None,
    source_language: str = "English",
    target_language: str = "Afrikaans",
) -> list:
    """
    Run Visual QA on all pages in a translated book.
    Compares each original page image with its translated counterpart.
    """
    results = []

    # Find all translated pages
    translated_files = sorted(Path(translated_dir).glob("page_*.png"))

    for trans_file in translated_files:
        # Extract page number from filename
        page_num = int(trans_file.stem.split("_")[1])

        # Find corresponding original
        orig_file = Path(original_dir) / f"page_{page_num:03d}.png"
        if not orig_file.exists():
            results.append({
                "page": page_num,
                "approved": False,
                "issues": ["Original page image not found"],
                "error": True,
            })
            continue

        print(f"  QA page {page_num}...", end=" ", flush=True)

        result = visual_qa(
            original_image_path=str(orig_file),
            translated_image_path=str(trans_file),
            page_number=page_num,
            source_language=source_language,
            target_language=target_language,
            api_key=api_key,
        )

        result["page"] = page_num
        results.append(result)

        status = "PASS" if result.get("approved") else "FAIL"
        score = result.get("overall_score", 0)
        print(f"{status} (score: {score})")

    return results


# CLI
if __name__ == "__main__":
    import argparse

    parser = argparse.ArgumentParser(description="AI Visual QA for translated book pages")
    parser.add_argument("--original", "-o", required=True, help="Original pages directory")
    parser.add_argument("--translated", "-t", required=True, help="Translated pages directory")
    parser.add_argument("--page", "-p", type=int, help="Single page number to check (optional)")
    parser.add_argument("--api-key", help="OpenAI API key (or set OPENAI_API_KEY env)")
    parser.add_argument("--source-lang", default="English")
    parser.add_argument("--target-lang", default="Afrikaans")

    args = parser.parse_args()

    api_key = args.api_key or os.environ.get("OPENAI_API_KEY", "")

    if args.page:
        # Single page QA
        orig = os.path.join(args.original, f"page_{args.page:03d}.png")
        trans = os.path.join(args.translated, f"page_{args.page:03d}_af.png")

        if not os.path.exists(trans):
            trans = os.path.join(args.translated, f"page_{args.page:03d}.png")

        result = visual_qa(orig, trans, args.page, args.source_lang, args.target_lang, api_key)
        print(json.dumps(result, indent=2))
    else:
        # Batch QA
        results = batch_qa(
            args.original, args.translated, api_key,
            args.source_lang, args.target_lang
        )
        print(json.dumps(results, indent=2))
