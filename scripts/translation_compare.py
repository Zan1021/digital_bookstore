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


def _pick_canonical(translations_by_page):
    """
    Choose ONE canonical translation from a {page_number: translation} map of the
    SAME source string translated on multiple pages. Book-agnostic, no config:

      1. Majority vote — the variant appearing on the most pages wins (greatest
         agreement across the book is the most trustworthy signal).
      2. Tie-break — prefer the variant on the EARLIEST page (the cover/front
         matter carries the authoritative title).

    Near-duplicates are grouped by fuzzy similarity so trivial spacing/case
    differences don't split the vote. Returns the chosen display string.
    """
    if not translations_by_page:
        return None
    # Group pages by fuzzy-equal translation.
    groups = []  # list of {"key": norm, "display": str, "pages": [..]}
    for pn in sorted(translations_by_page):
        disp = translations_by_page[pn]
        key = _norm_key(disp)
        placed = False
        for g in groups:
            if difflib.SequenceMatcher(None, key, g["key"]).ratio() >= 0.92:
                g["pages"].append(pn)
                placed = True
                break
        if not placed:
            groups.append({"key": key, "display": disp, "pages": [pn]})
    # Majority vote; tie-break by earliest page.
    groups.sort(key=lambda g: (-len(g["pages"]), min(g["pages"])))
    return groups[0]["display"]


def reconcile_repeated_translations(input_pdf, translations, source_language="en",
                                    target_language="af"):
    """
    BOOK-AGNOSTIC auto-fix for the "same source string translated N different ways"
    defect (e.g. a title rendered 'n Plek Vol Pret / 'n Plek van Pret / 'n Pretplek
    across cover, imprint and back-cover). Instead of asking a human to pick, we:

      - discover the title the same way the consistency check does (largest cover
        display line — no hardcoding),
      - find every page whose translation contains a divergent variant,
      - pick ONE canonical variant by majority vote (tie-break: earliest page),
      - rewrite the other pages' translated text so the variant is replaced by the
        canonical one.

    Works for ANY book: nothing here references a specific title, page or language.
    Returns (updated_page_text: {page_number: new_text}, changes: [...]). The caller
    persists updated_page_text back to translated_pages, then re-renders.
    """
    rep = compare(input_pdf, translations, source_language, target_language)
    tmap = _target_by_page(translations)
    updated = {}
    changes = []

    consistency = rep.get("checks", {}).get("consistency", {})
    tt = consistency.get("translations_by_page", {})
    # tt keys may be ints or strings depending on JSON round-tripping.
    tt = {int(k): v for k, v in tt.items()}
    if len(tt) < 2:
        return updated, changes  # nothing repeated to reconcile

    canonical = _pick_canonical(tt)
    if not canonical:
        return updated, changes

    canon_key = _norm_key(canonical)
    # The set of variant strings we treat as "the title translated differently".
    # Only SHORT, title-like variants qualify — a page where the title is merely one
    # entry inside a longer list (e.g. a back-cover series index) must NOT have its
    # whole text rewritten. We detect that by length: a genuine title variant is a
    # short line, not a multi-entry paragraph.
    def _is_title_like(v):
        # A title line is short (few words) and not a multi-item list.
        words = _norm(v).split()
        has_list = bool(re.search(r"\d+\s*[-–]\s*\S", v))  # "1 - X 2 - Y"
        return len(words) <= 6 and not has_list

    for pn, variant in tt.items():
        if _norm_key(variant) == canon_key:
            continue  # already canonical
        if not _is_title_like(variant):
            # Title appears only as a sub-string of a longer structure (series list).
            # Do a SURGICAL swap of the specific divergent title phrase, if we can
            # find one; never rewrite the whole page.
            page_text = tmap.get(pn, "")
            swapped, applied = _swap_title_phrase(page_text, canonical, tt, canon_key)
            if applied:
                updated[pn] = swapped
                changes.append({"page": pn, "from": applied, "to": canonical,
                                "context": "in-list"})
            continue
        page_text = tmap.get(pn, "")
        if page_text and variant in page_text:
            new_text = page_text.replace(variant, canonical)
        else:
            pattern = re.compile(re.escape(_norm(variant)), re.IGNORECASE)
            new_text, n = pattern.subn(canonical, _norm(page_text))
            if n == 0:
                continue
        updated[pn] = new_text
        changes.append({"page": pn, "from": variant, "to": canonical})

    return updated, changes


def _swap_title_phrase(page_text, canonical, translations_by_page, canon_key):
    """
    Replace a divergent title phrase that appears INSIDE a longer text (e.g. a
    back-cover series list) with the canonical title, WITHOUT touching the rest of
    the text. We look for any known short title-variant substring in the page and
    swap just that span. Returns (new_text, matched_variant_or_None).
    Book-agnostic: candidates come from the discovered per-page title variants.
    """
    if not page_text:
        return page_text, None
    # Build the set of short title variants seen anywhere (excluding canonical).
    variants = set()
    for v in translations_by_page.values():
        vv = _norm(v)
        if _norm_key(vv) == canon_key:
            continue
        if len(vv.split()) <= 6 and not re.search(r"\d+\s*[-–]\s*\S", vv):
            variants.add(vv)
    # Try the longest variants first so we match the fullest phrase.
    for v in sorted(variants, key=len, reverse=True):
        pattern = re.compile(re.escape(v), re.IGNORECASE)
        new_text, n = pattern.subn(canonical, page_text)
        if n > 0:
            return new_text, v
    return page_text, None


def main():
    import argparse, json, os, sys
    p = argparse.ArgumentParser(description="Pre-render translation compare (source vs target)")
    p.add_argument("--input", "-i", required=True, help="Source PDF")
    p.add_argument("--translations", "-t", required=True, help="Translations JSON (flat or ID-mapped)")
    p.add_argument("--source-language", default="en")
    p.add_argument("--target-language", "-l", default="af")
    p.add_argument("--reconcile", action="store_true",
                   help="Auto-fix repeated-string inconsistencies (e.g. title). Emits "
                        "{updated_pages, changes} JSON instead of the compare report.")
    a = p.parse_args()
    with open(a.translations, encoding="utf-8") as f:
        translations = json.load(f)
    if a.reconcile:
        updated, changes = reconcile_repeated_translations(
            a.input, translations, a.source_language, a.target_language)
        print(json.dumps({"updated_pages": updated, "changes": changes},
                         indent=2, ensure_ascii=False))
        sys.exit(0)
    rep = compare(a.input, translations, a.source_language, a.target_language)
    print(json.dumps(rep, indent=2, ensure_ascii=False))
    sys.exit(0 if rep["ok"] else 2)


if __name__ == "__main__":
    main()
