# Engine Wiring & Activation — Tasks

**Created:** 2026-10-07 · **Author:** Naz · Companion to `requirements.md` + `design.md`.

**Convention:** `[ ]` todo · `[~]` partial/caveat · `[x]` done + PROVEN ON A REAL RENDER (R4 — not
just "tests pass"). Every `[x]` names the observable proof (qa_report field / output diff / caught
defect) on a real book, plus suite-green + ≥2-book check (R6).

**Order is deliberate (design §Dependency order). Do not skip ahead — C1 must precede any wire-in.**

---

## Phase C1 — Reconcile DELETE? candidates FIRST (prevents wiring into a conflict, R2/R-W6)

- [x] **T1. scene_graph** — RECLASSIFIED (not deleted). Live inline region graph in
      `pdf_translate_v8.py` (+ document_model) supersedes the standalone file, BUT `scene_graph.py` is
      test-coupled (test_integration/test_unit) so it is HELD as TEST-ONLY, not dead. Proof: audit
      shows scene_graph under TEST-ONLY; no live-path change. (Session 7, commit 2d7cef9.)
- [x] **T2. visual_qa.py (python)** — DELETED. Live PHP `VisualQaService` supersedes it; no caller
      found. Proof: file absent from scripts/; VisualQaService still live (visual_qa gate turned on
      session 5, qa_report records it); no regression. (Session 7.)
- [x] **T3. merged_cells / container_detection / list_detection** — DELETED. render_gate + table
      path + document_model generic-structure attach cover these inline. Proof: all three absent from
      scripts/; table path suites green (table_structure 39). (Session 7.)
- [x] **T4. pdf_digital_twin / pikepdf_integration** — DELETED. document_model+page_manifest (twin)
      and PyMuPDF standardisation (pikepdf) supersede them. Proof: both absent from scripts/; audit
      green. (Session 7.)
- [x] **T5. Update WIRING_TRIAGE.md + regenerate WIRING_AUDIT_MECHANICAL.md** — done session 7 (C1
      note appended). Current audit: LIVE-IMPORT=15, LIVE-SUBPROC=11, TEST-ONLY=13, DEAD=23. Proof:
      the 6 deleted modules absent from every tier; scene_graph correctly in TEST-ONLY.

## Phase C2 — Font-asset-integrity preflight (R-W3, highest value)

- [x] **T6. Build `font_integrity` entry reusing font_registry/glyph_preflight/typography_fingerprint**
      — DONE. `scripts/font_integrity.py`: given fonts dir + requested families, returns per-font
      {requested, file, internalName, matches, glyphGaps, aliasApplied, status, uploadPrompt}. The
      ONE new piece is the internal-name-vs-requested-family compare (via pymupdf.Font().name — the
      counterfeit detector that was dead); reuses font_policy._norm/_RETIRED_FONT_ALIASES and
      glyph_preflight.preflight_single_text. PROOF: `scripts/test_font_integrity.py` 19/19 green,
      built hermetically from the real counterfeit case (a genuine font under a lying filename;
      embedded name ≠ claimed family → caught).
- [x] **T7. Wire it as a PHP pre-flight** in `createTranslatedPdfInner` before the render subprocess
      — DONE. `runFontIntegrityPreflight(Book,$language)` runs `scripts/font_integrity.py` over
      `Book::requestedFontFamilies()`; verdict folded into the monotonic QaReport as check
      `font_integrity`; a required-font mismatch/glyph-gap/missing → `publishable=false`,
      `render_status=NEEDS_LAYOUT_REVIEW`, `qa_report['font_integrity']` records offending + upload
      prompts; `report['flags']['FONT_INTEGRITY_MISMATCH']`. Config `bookstore.font_integrity.enabled`
      (default true, no API). Fail-SAFE: a preflight that cannot run records ran=false and never
      sinks the render. PROOF: wiring_audit now classifies font_integrity LIVE-SUBPROC (was DEAD);
      Feature test below drives it end-to-end.
- [x] **T7b. Prompt-and-upload resolution loop (R-W3.1 — restored BookOnboarding design).** DONE.
      On a flagged family the verdict carries an actionable "upload the correct TTF/OTF for `<family>`"
      prompt (per-font + rolled up in `uploadPrompts`) for the existing FontManager path; an approved
      retired-font alias (font_policy) is recorded as a deliberate substitution, never a counterfeit;
      an all-honest set returns publishable (the cleared state after a correct upload). PROOF: Feature
      test asserts counterfeit → flag+prompt, honest set → publishable, alias ≠ counterfeit.
- [x] **T8. Regression test** — DONE. `scripts/test_font_integrity.py` (unit, 19 checks) + Laravel
      `tests/Feature/FontIntegrityPreflightTest.php` (5 tests/18 assertions): requested-family
      extraction, no-policy no-op, counterfeit fails closed + upload prompt, honest set publishable,
      config-disable no-op. Full Laravel suite 207 passed; Python font/gate suites green.

## Phase C3 — Output text-layer verification (R-W4)

- [x] **T9. Wire `text_verification` (or equivalent) as a post-render gate** (seam #3) — DONE.
      Added a payload-shape-agnostic `verify_text_layer()` + `text-layer` CLI (JSON out) to
      `scripts/text_verification.py`; wired `runTextLayerGate()` in `createTranslatedPdfInner`
      AFTER the cover-flatten + illustration passes (operates on the staging artifact). Extracts
      text from the SAVED pdf, confirms it is searchable + encoding-clean + matches the translation
      (significant-word match rate ≥ `bookstore.text_layer.min_match_rate`, default 0.6); records
      `qa_report['text_layer']`; a no-text-layer / garbled / low-match result fails closed
      (NEEDS_LAYOUT_REVIEW, check `text_layer`). Cheap, no API, default on; fail-safe when it cannot
      run. PROOF: `scripts/test_text_layer_gate.py` 17/17 (good passes; IMAGE-ONLY — the
      ToUnicode/painted-pixels class — fails closed searchable=False; wrong-content fails on match
      rate; U+FFFD detector unit-proven). Laravel `tests/Feature/TextLayerGateTest.php` 5/5 drives
      the wired gate (good/image-only/wrong-content/disabled/missing-inputs). wiring_audit now shows
      text_verification LIVE-SUBPROC (was DEAD). Full Laravel suite 212 passed; Python suites green.
      NOTE: full-book proof on My House deferred to C6 (needs real source PDF + translations fixture).

## Phase C4 — Illustration-repair trio (R-W5) — ALREADY WIRED (reclassified 2026-10-07)

> **FINDING (session 11):** the trio was NOT dead. `scripts/illustration_text.py` (LIVE-SUBPROC via
> `IllustrationTextService::repair()`) imports `image_inpainting` (top-level) and `artwork_repair`
> (`repair_page_surgical`, inside the live repair path), and `artwork_repair` imports+uses
> `crop_transform.CropTransform`. The chain `illustration_text → artwork_repair → crop_transform +
> image_inpainting` is live, fail-closed (surgical-preferred; flatten is review-only), and DORMANT
> behind `bookstore.illustration_text.enabled` (default false, vision API). The wiring_audit MISREAD
> them (TEST-ONLY/DEAD) because it did not propagate liveness TRANSITIVELY from a LIVE-SUBPROC module
> through the Python import graph — fixed in T10 below. So C4 is NOT "build the wiring" (doing so
> would create a second repair path — R2 violation); it is "fix the audit's blind spot + PROVE the
> existing path on a real render."

- [x] **T10. crop_transform — RECLASSIFIED LIVE (not wired anew).** Confirmed `crop_transform.
      CropTransform` is imported+used by `artwork_repair.repair_page_surgical` (line ~43/281), which
      is called in the live `illustration_text.render_page_repair()` path. Writing new wiring would
      duplicate this (R2). INSTEAD fixed the AUDIT BLIND SPOT: `scripts/wiring_audit.py` now seeds its
      import-liveness BFS from LIVE-SUBPROC modules too (not just the 2 engine entrypoints) and
      propagates transitively, so a module reached only through a live subprocess module is correctly
      LIVE-IMPORT. PROOF: audit totals 15/13/13/22 → 18/15/11/19; crop_transform now
      `LIVE-IMPORT (transitively imported by a live module)`; `test_crop_transform.py` 12/12 green.
      (Also corrected the under-count of font_registry/glyph_preflight/document_model/render_gate/
      text_fit_solver/text_shaping/etc., which were live all along.)
- [x] **T11. artwork_repair — RECLASSIFIED LIVE (not wired anew).** `repair_page_surgical` /
      `repair_image_region` are the live surgical repair invoked by `illustration_text.py` line ~257
      (`from artwork_repair import repair_page_surgical`). Background-preserving raster repair with
      fail-closed on unowned regions (no silent flatten; flatten is `--allow-flatten` + review-only).
      PROOF: audit now `LIVE-IMPORT`; `test_artwork_repair.py` 10/10 green (asserts baked text pixels
      removed, surrounding artwork intact, reused xref isolated).
- [x] **T12. image_inpainting — RECLASSIFIED LIVE (not wired anew).** `create_text_mask` /
      `inpaint_region` imported at the top of `illustration_text.py` (line ~59) and used by its
      halo-free inpaint. Generative background stays opt-in + mandatory-review
      (`bookstore.illustration_text.generative`, default false). PROOF: audit now `LIVE-IMPORT`.
- [ ] **T12b. PROVE the trio on a real render (the actual remaining R-W5/R4 work).** With
      `ILLUSTRATION_TEXT_ENABLED=true` on a book that HAS baked-in artwork text (not My House if it
      has none — pick a book with a real baked label; the Kolulu corpus fixture has them), run
      `createTranslatedPdf` and show: the surgical path repairs the region (translated label lands at
      correct source coords via the CropTransform chain — measured, not eyeballed) OR fails closed to
      NEEDS_LAYOUT_REVIEW; `qa_report` records the illustration coverage ledger. Verify via
      `render_pdfjs.mjs`, not PyMuPDF pixmap. This is the C6-adjacent proof; it needs a vision-API
      budget decision from Captain Zan (the trio is gated OFF precisely because it costs vision calls).

## Phase C4a — Tagged-PDF / accessibility (R-W10, Decision 2 — wire now)

- [x] **T17. Wire `accessibility` language-stamp + check as a post-render pass** (seam #3, beside the
      text-layer gate) — DONE. Added a `pass` subcommand to `scripts/accessibility.py` (one JSON
      in/out, mirrors font_integrity/text_verification): stamps `/Lang` (BCP-47 via LANGUAGE_MAP) IN
      PLACE on the output, re-checks accessibility, emits alt-text placeholders. Wired PHP
      `runAccessibilityPass(Book,$language,$outputPath)` in `createTranslatedPdfInner` AFTER the
      text-layer gate (so the metadata write never perturbs the gate's read). Verdict folded into
      QaReport check `accessibility` + `report['accessibility']`. FAIL-CLOSED only when the language
      write fails (`pass=false` → publishable=false, NEEDS_LAYOUT_REVIEW, flag
      `ACCESSIBILITY_LANG_UNSET`); a missing structure tree / alt text is a recorded recommendation,
      NOT a block (PyMuPDF can't synthesize a StructTreeRoot). Config `bookstore.accessibility.enabled`
      (env `ACCESSIBILITY_PASS_ENABLED`, default true, no API). Fail-SAFE: cannot-run → ran=false, no
      sink. FIXED a real bug found by testing: PyMuPDF refuses a full save over the same open file
      ("save to original must be incremental") — `set_document_language` now writes to a temp file +
      atomic replace on same-path. PROOF (R4): on a built PDF the output catalog `/Lang` reads `af-ZA`
      — verified by RE-READING the catalog from disk, not just a return value; qa_report carries the
      score; wiring_audit reclassifies accessibility DEAD→LIVE-SUBPROC (gated:accessibility.enabled),
      totals 18/15/11/19 → 18/16/11/18.
- [x] **T18. Regression test for the accessibility pass** — DONE. `scripts/test_accessibility.py`
      6/6 (hermetic PyMuPDF docs: `/Lang` stamped in-place and re-read from the catalog; BCP-47
      mapping; missing structure = recommendation not block; fail-closed on lang-write failure;
      alt-text placeholders for an embedded image). Laravel `tests/Feature/AccessibilityPassTest.php`
      5/5 (reflection-driven: normal pass stamps `/Lang`, missing-structure not blocked, lang-write
      failure fails closed, config-disable + missing-input no-ops). Full Laravel suite 217 passed
      (was 212). ≥2-book check deferred to C6 full render.

## Phase C4b — Scanned-page OCR fallback + caption/label classification (R-W11, Decision 3 — wire now)

- [x] **T19. C1-reconcile FIRST (R2 guard)** — DONE. Grepped the live engine for inline OCR
      (`get_textpage_ocr`/`is_scanned`/`pytesseract`) and inline caption logic (`caption`/
      `detect_captions`/`figure_label`). FINDINGS: (a) NO inline OCR anywhere — those symbols appear
      only in `ocr_integration.py` itself + `test_integration.py` (which is why the audit had it
      TEST-ONLY). No competing path. (b) `document_model` DEFINES `CAPTION`/`LABEL` as semantic
      roles/content-types and font_policy/readability_policy bucket a `caption` role, BUT there is NO
      proximity-based caption DETECTION in the live engine — only the enum values. So
      `caption_detection.py` (small-text-near-image heuristic) fills a genuine gap, complementary to
      the structural role, not competing. Safe to wire both.
- [x] **T20. Wire `ocr_integration` scanned-page fallback into the manifest stage** — DONE. The
      single, R2-clean seam = inside `pdf_translate_v8.extract_page_spans`: when a page yields ZERO
      text spans, `_maybe_ocr_fallback` classifies it (`is_scanned_page`); a scanned page, when OCR
      is enabled+available, is OCR'd via `ocr_for_manifest` and its spans (canonical schema +
      ocr_confidence/ocr_backend) flow through the normal translate+render path. Every caller (cached
      or direct) benefits — ONE place. FAIL-CLOSED on the right thing: a scanned page that is
      disabled/unavailable/empty/low-confidence/errored is recorded in a module `_OCR_LEDGER`
      (cleared per render) → folded into `report['ocr'].pages` + its pages appended to
      `report['review_pages']` → engine `publishable=false` NEEDS_LAYOUT_REVIEW. Born-digital book =
      pure no-op. Gate via env `OCR_FALLBACK_ENABLED` (+ `OCR_MIN_CONFIDENCE`), set by
      `PdfTranslationService` from `bookstore.ocr.enabled` (default true). PROOF (R4): scanned
      image-only page → ledger records scanned + fails closed when OCR off/unavailable (never a
      silent empty-clean); Kolulu (born-digital) OCR no-op confirmed by test_integration
      test_05_scanned_page_detection still green. wiring_audit reclassifies ocr_integration
      DEAD/TEST-ONLY→LIVE-IMPORT.
- [x] **T21. Wire `caption_detection` into the manifest stage** — DONE. Added
      `page_manifest.annotate_captions(spans, page, page_num)` called in the manifest driver after
      span extraction: runs `detect_captions` over spans + the page's image bboxes (body font size =
      median span size, book-agnostic), tags matched spans in place with
      `is_caption`/`caption_type`/`caption_position`, and attaches a per-page `captions` summary
      (count + by_type) to the page manifest. INFORMATIONAL ONLY — never gates publishability;
      fail-safe (any error leaves spans untouched); no-op on pages with no image-adjacent small text.
      PROOF (R4): a page with an image + small caption below tags that span `image_caption`/`figure_
      label` while the large body line stays untagged; a plain page tags nothing. wiring_audit
      reclassifies caption_detection DEAD→LIVE-IMPORT.
- [x] **T22. Regression tests for OCR + captions** — DONE. `scripts/test_ocr_captions.py` 6/6
      (hermetic PyMuPDF: born-digital no-op; scanned page disabled→ocr_disabled; scanned page
      enabled-no-backend→ocr_unavailable/empty, never silent clean; ledger clears; caption tagged +
      body untouched; no-caption no-op). Engine suites green (integration 10/10 incl. scanned-page
      detection, unit 64/64). Full Laravel suite 217 passed. wiring_audit totals 18/16/11/18 →
      20/16/10/17 (ocr_integration + caption_detection now LIVE-IMPORT). ≥2-book render check
      deferred to C6.

## Phase C5 — Keep it honest (R-W7/R-W8)

- [ ] **T13. Add `python scripts/wiring_audit.py` to the test/CI run** — assert no NEW dead
      production module vs a committed baseline (catches "built but never wired" at creation).
- [ ] **T14. Document every DORMANT module** in steering LIVE SYSTEM STATE (one line each: what,
      which flag/condition activates it, why off now): content_cache, incremental_render,
      variable_fonts, raster_fallback, translation_variants, script_detection, content_stream_surgery,
      optical_calibration (if left dormant), accessibility (decide wire vs dormant).

## Phase C6 — The now-MEANINGFUL full-engine book test (R-W9)

- [ ] **T15. End-to-end render of My House #10000 (af) AND a second, different book** with the wired
      capability set; record in qa_report WHICH capabilities fired (font_integrity, text_layer,
      accessibility, ocr, captions, illustration repair if applicable, visual_qa). This is the test
      that was previously meaningless. PROOF: both books render; the capabilities demonstrably
      executed (not just present); any genuine defect is caught fail-closed; suite green; temp files
      cleaned.
- [ ] **T16. Completion report** — list what got wired, what was deleted, what stays dormant (+flags),
      which capabilities the final book test exercised, and the honest remaining gaps. Update the
      steering LIVE SYSTEM STATE + regenerate the audit so the next cold session starts from truth.

---

## Decisions — RESOLVED by Captain Zan (2026-10-07)
1. **Font-integrity on mismatch:** flag + fail-closed on publishability + apply approved alias as a
   TEMPORARY preview stand-in + emit an actionable "upload the correct TTF" prompt wired to the
   existing FontManager/BookOnboarding path (restores the original wizard design). On a matching
   re-upload the flag clears. NOT a silent substitute; NOT a full hard-block-no-render. → R-W3.1, T7/T7b.
2. **accessibility (tagged PDF):** WIRE NOW (Captain Zan: "if it's functionality we need, do it").
   Not dormant. → PLANNED: Phase C4a, tasks T17–T18 (R-W10). Tagged-PDF output gate + `/Lang` stamp.
3. **ocr_integration / caption_detection:** IN SCOPE for this spec (wire now), not a follow-up. →
   PLANNED: Phase C4b, tasks T19–T22 (R-W11). Scanned-page OCR fallback + caption classification at
   the manifest stage.
```
