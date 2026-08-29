"""
Pre-render translation compare — Digital Bookstore V8
=====================================================
Runs BEFORE the Afrikaans (or any target) PDF is rendered and compares the SOURCE
(English) content against the TARGET translation to verify it is "100%" — i.e.
complete, consistent, and free of untranslated source leaking through.

This is the gate the render pipeline should call first: if the translation is not
sound, we DO NOT render (or we render but mark NEEDS_LANGUAGE_REVIEW). It catches
the class of defects the layout gates cannot see, because those only check geometry:

  1. COMPLETENESS  — every source content page has a non-empty translation; the
                     number of translated items is not wildly short of the source
                     item count (dropped/missing translations).
  2. CONSISTENCY   — the same source string (notably the book title) is translated
                     the SAME way on every page it appears (cover vs imprint vs
                     back-cover list).
  3. NO ENGLISH LEAK — the target text does not still contain source-language
                     strings verbatim (untranslated cells / spans).

Book-agnostic (R1): the title is discovered as the cover's largest display line;
no title text or page numbers are hardcoded. Language-agnostic: works for any
source→target pair (the "English leak" check compares target vs the actual source
strings of that book, not a fixed word list).

Returns a structured report:
  {
    "ok": bool,
    "checks": {completeness, consistency, english_leak},
    "review_pages": [...],
    "issues": [ {type, detail, pages} ]
  }
"""

import difflib
import re

import pymupdf


def _norm(s):
    return re.sub(r"\s+", " ", (s or "")).strip()


def _norm_key(s):
    return _norm(s).lower()


def _page_content_spans(page, page_num):
    from pdf_translate_v8 import extract_page_spans
    return [s for s in extract_page_spans(page, page_num) if not s.get("is_page_number")]


def _discover_title(cover_spans):
    """Book title = the largest-font display line on the cover (>2 letters, not a
    publisher/studio credit). Book-agnostic."""
    for s in sorted(cover_spans, key=lambda s: s.get("font_size", 0), reverse=True):
        t = s.get("text_stripped", "")
        letters = re.sub(r"[^A-Za-z]", "", t)
        if len(letters) > 2 and "studio" not in t.lower():
            return t
    return None


def _target_by_page(translations):
    """Return {page_number: target_text} from either the flat or ID-mapped format."""
    tmap = {}
    for p in translations.get("pages", []):
        tmap[p["page_number"]] = p.get("translated_text") or ""
    for it in translations.get("items", []):
        pn = it.get("page_number")
        if pn is not None:
            tmap[pn] = (tmap.get(pn, "") + "\n" + (it.get("translation") or "")).strip()
    return tmap


def _candidate_title(text):
    for ln in [l.strip() for l in (text or "").split("\n") if l.strip()]:
        low = ln.lower()
        if "studio" in low or "mthombothi" in low:
            continue
        if re.sub(r"[^A-Za-z]", "", ln):
            return ln
    return ""


def compare(input_pdf, translations, source_language="en", target_language="af"):
    """
    Compare source PDF content against the target translation. Returns the report
    described in the module docstring. Does not render anything.
    """
    report = {
        "ok": True,
        "source_language": source_language,
        "target_language": target_language,
        "checks": {},
        "review_pages": [],
        "issues": [],
    }

    doc = pymupdf.open(input_pdf)
    total = len(doc)
    tmap = _target_by_page(translations)

    # Per-page source spans + joined source string.
    src_pages = {}
    for pi in range(total):
        spans = _page_content_spans(doc[pi], pi + 1)
        src_pages[pi + 1] = {
            "spans": spans,
            "joined": _norm(" ".join(s.get("text_stripped", "") for s in spans)),
        }

    def _flag(pages):
        for pn in pages:
            if pn not in report["review_pages"]:
                report["review_pages"].append(pn)

    # ---- 1. COMPLETENESS ----
    missing_pages = []
    short_pages = []
    for pn, info in src_pages.items():
        if not info["joined"]:
            continue  # source page has no translatable content (e.g. full-bleed art)
        tgt = _norm(tmap.get(pn, ""))
        if not tgt:
            missing_pages.append(pn)
            continue
        # Heuristic completeness: target word count should be at least ~40% of source
        # (translations vary in length; this only catches gross drops, not style).
        s_words = len(info["joined"].split())
        t_words = len(tgt.split())
        if s_words >= 8 and t_words < max(3, int(s_words * 0.4)):
            short_pages.append({"page": pn, "source_words": s_words, "target_words": t_words})
    if missing_pages:
        report["issues"].append({"type": "missing_translation", "pages": missing_pages,
                                  "detail": f"{len(missing_pages)} source page(s) have no translation"})
        _flag(missing_pages)
    if short_pages:
        report["issues"].append({"type": "short_translation",
                                  "pages": [x["page"] for x in short_pages],
                                  "detail": short_pages})
        _flag([x["page"] for x in short_pages])
    report["checks"]["completeness"] = {"ok": not (missing_pages or short_pages),
                                        "missing": missing_pages, "short": short_pages}

    # ---- 2. CONSISTENCY (book title across pages) ----
    title_src = _discover_title(src_pages.get(1, {}).get("spans", [])) if total else None
    consistency = {"ok": True, "title_source": title_src, "translations_by_page": {}}
    if title_src:
        nt = _norm_key(title_src)
        title_pages = [pn for pn, info in src_pages.items() if nt and nt in info["joined"].lower()]
        tt = {}
        for pn in title_pages:
            cand = _candidate_title(tmap.get(pn, ""))
            if cand:
                tt[pn] = cand
        consistency["translations_by_page"] = tt
        distinct = []
        for t in tt.values():
            tl = _norm_key(t)
            # Stricter similarity for short titles: a one-word difference in a short
            # title (e.g. "plek vol pret" vs "plek van pret") must count as distinct.
            if not any(difflib.SequenceMatcher(None, tl, d).ratio() >= 0.92 for d in distinct):
                distinct.append(tl)
        if len(distinct) > 1:
            consistency["ok"] = False
            report["issues"].append({
                "type": "title_inconsistent",
                "detail": f"title '{title_src}' translated {len(distinct)} different ways",
                "pages": sorted(tt.keys()),
                "translations_by_page": tt,
            })
            _flag(list(tt.keys()))
    report["checks"]["consistency"] = consistency

    # ---- 3. NO ENGLISH (source) LEAK ----
    # A target page should not still contain an untranslated source PHRASE verbatim.
    # We only flag MULTI-WORD phrases (>=3 words) that appear verbatim in the target,
    # because single words are frequently legitimate cognates (sport, water, tennis,
    # ping-pong) or proper nouns/URLs/ISBNs that correctly stay identical across
    # languages. A 3+word English phrase surviving into the Afrikaans is a real leak.
    leaks = []
    for pn, info in src_pages.items():
        tgt = _norm(tmap.get(pn, ""))
        if not tgt:
            continue
        tgt_low = tgt.lower()
        for s in info["spans"]:
            src_t = _norm(s.get("text_stripped", ""))
            words = src_t.split()
            if len(words) < 3:
                continue  # only multi-word phrases
            letters = re.sub(r"[^A-Za-z]", "", src_t)
            if len(letters) < 6:
                continue
            low = src_t.lower()
            if any(m in low for m in ("studio", "mthombothi", "isbn", "www.", "http",
                                      "@", "(pty)", "printed by", "published by",
                                      "copyright", "all rights")):
                continue
            if low in tgt_low:
                leaks.append({"page": pn, "source_text": src_t[:60]})
    if leaks:
        # De-dup per page.
        seen = set()
        uniq = []
        for l in leaks:
            k = (l["page"], l["source_text"])
            if k not in seen:
                seen.add(k); uniq.append(l)
        report["issues"].append({"type": "english_leak", "pages": sorted({l["page"] for l in uniq}),
                                 "detail": uniq[:20]})
        _flag([l["page"] for l in uniq])
        report["checks"]["english_leak"] = {"ok": False, "leaks": uniq[:20]}
    else:
        report["checks"]["english_leak"] = {"ok": True, "leaks": []}

    doc.close()
    report["ok"] = not report["review_pages"]
    return report


def _looks_like_proper_noun(s):
    """Heuristic: a single Capitalized token is likely a name that stays the same
    across languages (skip it as a 'leak')."""
    toks = s.split()
    return len(toks) == 1 and toks[0][:1].isupper()


def main():
    import argparse, json, os, sys
    p = argparse.ArgumentParser(description="Pre-render translation compare (source vs target)")
    p.add_argument("--input", "-i", required=True, help="Source PDF")
    p.add_argument("--translations", "-t", required=True, help="Translations JSON (flat or ID-mapped)")
    p.add_argument("--source-language", default="en")
    p.add_argument("--target-language", "-l", default="af")
    a = p.parse_args()
    with open(a.translations, encoding="utf-8") as f:
        translations = json.load(f)
    rep = compare(a.input, translations, a.source_language, a.target_language)
    print(json.dumps(rep, indent=2, ensure_ascii=False))
    sys.exit(0 if rep["ok"] else 2)


if __name__ == "__main__":
    main()
