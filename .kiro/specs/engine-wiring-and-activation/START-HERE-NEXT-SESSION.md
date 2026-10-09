# ►► START HERE — Digital Bookstore engine, next session (set up 2026-10-10 by Naz for Captain Zan)

**If you read ONE thing at cold start, read this file, then the two docs in §1.**

---

## 0. THE ONE-LINE TRUTH (do not forget this again)
The V8 translation engine is **WIRED but NOT COMPLIANT.** Modules run; the output is NOT yet a
correctly typeset book. "226 tests green / spec COMPLETE" referred ONLY to the *wiring* spec, which
says nothing about whether a rendered page looks right. **Wired ≠ working.** We are now fixing the
layout/typography compliance, verified by LOOKING at real browser renders — not by trusting tests.

## 1. WHAT TO READ (in this order) TO KNOW WHAT THIS SYSTEM IS
1. `.kiro/specs/engine-wiring-and-activation/RECONCILIATION-wired-vs-working.md`
   — why green tests shipped visible defects; the defect→task map. **Read first.**
2. `.kiro/steering/v8-brief-compliance.md` — the SINGLE SOURCE OF TRUTH. Read:
   - the top "LIVE SYSTEM STATE / WIRED≠WORKING" block,
   - §0 standing rules (R1 book-agnostic … R6 verify-by-looking),
   - the PHASE 1 / 2 / 3 checklists (that IS the real remaining work).
3. Then run `python scripts/wiring_audit.py` only to confirm module reachability (NOT correctness).

## 2. WHAT THE SYSTEM IS (30-second model)
- A Laravel + Python engine that translates children's picture-book PDFs into other languages
  (en→af etc.) while preserving layout, artwork, fonts, and casing — **book-agnostic** (R1: no logic
  keyed to a title/page/language/coords).
- ONE live render path: `PdfTranslationService::createTranslatedPdf` → `scripts/pdf_translate_v8.py`
  (+ `page_manifest.py` for extraction). Stable-ID contract path, NOT flat per-page strings.
- Fail-closed (R5): a page that fails a hard constraint → `NEEDS_LAYOUT_REVIEW`, never silently shipped.
- Test books: **#10000 "My House"** and **#10001 "Play with Me"** (Kolulu S2, en→af, 16pp each).

## 3. HOW TO TEST (the ONLY definition of "done" that counts — R4/R6)
- Serve: `php artisan serve --port=8080` (⚠️ Docker Desktop squats on :8000 — use 8080). Launch it
  detached via `Start-Process` or it dies when the call returns.
- Reader URLs: `http://127.0.0.1:8080/read/10000?lang=af` and `/read/10001?lang=af`.
- VERIFY BY EYE in a real browser (PDF.js) + run `render_gate.py` on the output. A fix is only
  "done" when the user-visible page is right on BOTH books AND the gate passes it. Tests passing is
  necessary, NOT sufficient.
- Image rule: NEVER read_file a raw screenshot; run `python C:\Users\zande\.kiro\scripts\safe_image.py
  "<path>"` first and read the path it prints (API hangs >2000px).

## 4. CONFIRMED DEFECTS (found by Captain Zan's own eyes, 2026-10-10) → owning task
| # | Defect (on #10001)                                              | Task      | Status |
|---|------------------------------------------------------------------|-----------|--------|
| 1 | Copyright page: a word prints ON TOP of the line above ("self")  | 1.2       | ✅ FIXED+PROVEN |
| 2 | Cover subtitle "Speel saam met my" OVERFLOWS the panel           | dedup/1.1 | ☐ next |
| 3 | Logo ® orphaned / mis-placed (double-inventoried)                | dedup     | ☐ next |
| 4 | Copyright page: mixed font sizes in one block                    | 2.6       | ☐ |
| 5 | Back cover in a SCRIPT font; interiors in a SANS (inconsistent)  | 2.4       | ☐ |
| 6 | Title not context-aware ("Speel saam met my" vs "Speel met my")  | title-ctx | ☐ |

## 5. TASK LIST FOR NEXT SESSION (do in this order; verify each by LOOKING + gate, R6)

- [x] **T-A. Phase 1.2 — neighbour-collision on ALL page types.** DONE 2026-10-10. `render_gate.py`:
      collision now runs every page type; new `_words_overprint` (horizontal overlap + vertical
      overlap ≥45% of shorter word height) catches true stacks, ignores inter-line leading. PROVEN
      on real 10001_af.pdf (page 2 'hulle' overprints 'self.' now flagged → review_pages=[2]). Tests:
      gate 47/47, unit 64/64, integration 10/10, Laravel 226/226.

- [ ] **T-B. IllustrationTextService dedup bug (fixes defects #2 + #3) — DO FIRST next session.**
      ROOT CAUSE (confirmed): `contractOwnedRegions()` only treats a manifest region as "owned" if it
      has a 4-number region-level `bbox`; but geometry lives on the ITEMS inside each region (page-1
      regions report `bbox=(no bbox)`). So the ownership dedup returns empty, and the cover subtitle +
      ® get inventoried BOTH as native spans (p01_s0002/s0003) AND as artwork regions (p01_art02/art03)
      → double-placed → overflow + orphaned ®.
      FIX: in `app/Services/IllustrationTextService.php::contractOwnedRegions()`, when a region has no
      bbox, derive it by UNION of its items' bboxes; THEN the overlap dedup in
      `filterContractOwnedRegions()` works. ADD a text/id safety-net dedup (drop an artwork region
      whose source_text == an already-owned native span on the same page) in case geometry is absent.
      VERIFY: re-render #10001 (costs vision+translation budget — GET CAPTAIN ZAN'S OK FIRST), then
      confirm in browser the ® reattaches to the logo and "Speel ... my" fits the panel; gate passes.
      Add a regression test asserting the cover subtitle appears in item_translations ONCE.

- [ ] **T-C. Phase 1.1 — per-region clipping on ALL page types** (story/cover/copyright/back-cover):
      explicit save→clip→draw→restore to each region's safe box so nothing can paint outside its box.
      Verify a deliberately-too-long subtitle clips/ routes to review, not bleeds.

- [ ] **T-D. Phase 2.4 — font resolution + visual-size matching (fixes defect #5).** Why is the back
      cover a SCRIPT font while interiors are SANS? Investigate role→font mapping in `font_policy.py`
      + the `_RETIRED_FONT_ALIASES` substitutes (real licensed AdLibBT/Calibri/Edu-Aid/OzHandicraft
      were counterfeits, deleted; substitutes stand in). DECISION NEEDED FROM CAPTAIN ZAN: install the
      genuine licensed fonts, or formally accept specific substitutes? Then enforce ONE consistent
      family per role across all pages; visual-size match via cap-height, not raw pt.

- [ ] **T-E. Phase 2.6 — typography hierarchy + peer-size (fixes defect #4).** `validate_typography`
      exists; wire/verify it so mixed sizes in one block fail the gate. heading>body, peer variance
      within tolerance. Confirm it flags the real copyright page.

- [ ] **T-F. Title context (fixes defect #6).** `TranslationService::translateManifestPage` sends the
      model isolated strings with role `book_subtitle` and NO book/series context, so "Play with me"
      → "Speel saam met my" instead of "Speel met my". FIX: assemble context (book title, series,
      page_type=cover, sibling titles) into the subtitle translation prompt. Capability EXISTS
      (glossary + per-role prompt) — it's incomplete, not missing. No new module.

- [ ] **T-G. Re-verify BOTH books end-to-end** once T-B..T-F land: render #10000 + #10001, eyeball in
      browser, gate must pass or fail-closed correctly. Only then update the compliance Phase
      checkboxes. Then prove on a 3rd, different book (R1 book-agnostic).

## 6. GOTCHAS (don't relearn — cost hours)
- PowerShell has NO `&&` (use `;` or the tool's `cwd`); set `$env:PYTHONIOENCODING="utf-8"` for suites.
- Read engine stderr JSON via a tiny .py/.php runner (PS `2>` mangles UTF-16).
- Translation column is `language_code` not `language`. Re-rendering reuses stored
  item_translations (no translation-API spend) UNLESS you re-run `book:retranslate` (that DOES spend).
- tinker scripts: end with `exit;` or the REPL hangs the call.
- `cover_retypeset.enabled` + `illustration_text.enabled` were set TRUE at runtime for the C6 renders
  (defaults are false). The illustration pass is what double-places cover text (see T-B).
- The VisualQaService vision gate ALREADY EXISTS (don't rebuild). Its coverage of cover/prose
  typography is weak — tighten, same as the gate work.

## 7. STATE OF THE TREE (2026-10-10, uncommitted — checkout-gated, SEPARATE repo from .kiro)
Changed this session (bookstore repo, NOT committed):
- `scripts/render_gate.py` — all-page collision + `_words_overprint`.
- `scripts/test_render_gate.py` — 2 new regression tests (now 47 total).
- `.kiro/steering/v8-brief-compliance.md` — corrected LIVE SYSTEM STATE header + 1.2 progress.
- `.kiro/specs/engine-wiring-and-activation/RECONCILIATION-wired-vs-working.md` (new).
- `.kiro/specs/engine-wiring-and-activation/START-HERE-NEXT-SESSION.md` (this file).
DB side-effect: #10001 af has 136 item_translations + rendered PDF (dev DB only).
