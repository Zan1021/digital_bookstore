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

## Phase C4 — Illustration-repair trio (R-W5, heaviest, last)

- [ ] **T10. Wire `crop_transform`** into the IllustrationTextService python half (coordinate
      transforms source→render→crop→model→patch; degenerate dim → review). PROOF: a known region's
      patch lands at the correct source coordinates on a real render (measured), not offset.
- [ ] **T11. Wire `artwork_repair`** (background-preserving raster repair; flat/gradient/textured by
      sampled type; protected-region reject → review). PROOF on a book with REAL baked-in text: the
      source label is removed without damaging surrounding artwork (PDF.js render + vision check).
- [ ] **T12. Wire `image_inpainting`** (background-only generative inpaint; ALWAYS mandatory-review).
      Stays behind `bookstore.illustration_text.enabled` (default off, vision API). PROOF: an
      illustration-text page → inpaint produces a clean background OR routes to review; never a
      guessed rectangle. Verified via render_pdfjs.mjs.

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
      illustration repair if applicable, visual_qa). This is the test that was previously meaningless.
      PROOF: both books render; the capabilities demonstrably executed (not just present); any genuine
      defect is caught fail-closed; suite green; temp files cleaned.
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
   Not dormant. → add to Phase C scope (tagged-PDF output gate).
3. **ocr_integration / caption_detection:** IN SCOPE for this spec (wire now), not a follow-up. →
   add to the illustration/text-extraction scope.
```
