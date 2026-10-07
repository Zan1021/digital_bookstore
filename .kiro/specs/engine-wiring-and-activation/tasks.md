# Engine Wiring & Activation — Tasks

**Created:** 2026-10-07 · **Author:** Naz · Companion to `requirements.md` + `design.md`.

**Convention:** `[ ]` todo · `[~]` partial/caveat · `[x]` done + PROVEN ON A REAL RENDER (R4 — not
just "tests pass"). Every `[x]` names the observable proof (qa_report field / output diff / caught
defect) on a real book, plus suite-green + ≥2-book check (R6).

**Order is deliberate (design §Dependency order). Do not skip ahead — C1 must precede any wire-in.**

---

## Phase C1 — Reconcile DELETE? candidates FIRST (prevents wiring into a conflict, R2/R-W6)

- [ ] **T1. scene_graph** — grep repo (py/php/mjs/md). Confirm the live inline region graph in
      `pdf_translate_v8.py` (+ document_model) supersedes it. If unused → delete; else reclassify.
      Proof: audit still green; no live-path change; reconciliation note written.
- [ ] **T2. visual_qa.py (python)** — confirm the live PHP `VisualQaService` supersedes it; grep for
      any caller. Delete if unused. Proof: VisualQaService still runs on a real render (qa_report
      visual_qa present); python module gone; no regression.
- [ ] **T3. merged_cells / container_detection / list_detection** — confirm render_gate + table path
      + document_model generic-structure attach cover these inline. Delete the unused ones; keep any
      with a unique capability (reclassify). Proof: table/vocab page (My House p15) still renders +
      gates identically before/after removal.
- [ ] **T4. pdf_digital_twin / pikepdf_integration** — confirm document_model+page_manifest (twin)
      and PyMuPDF standardisation (pikepdf) supersede them. Delete if unused. Proof: full render
      unchanged; audit green.
- [ ] **T5. Update WIRING_TRIAGE.md + regenerate WIRING_AUDIT_MECHANICAL.md** to reflect deletions.
      Proof: `wiring_audit.py` DEAD count drops by exactly the deleted set; no module silently lost.

## Phase C2 — Font-asset-integrity preflight (R-W3, highest value)

- [ ] **T6. Build `font_integrity` entry reusing font_registry/glyph_preflight/typography_fingerprint**
      — given fonts dir + requested families (from manifest), return per-font {requested, file,
      internalName, matches, glyphGaps}. No new detection logic; reuse the dead modules. Unit test
      from the REAL counterfeit case (AdLibBT file whose internal name is "Bangers Regular").
- [ ] **T7. Wire it as a PHP pre-flight** in `createTranslatedPdf` before the render subprocess;
      record `qa_report['font_integrity']`; a mismatch/glyph-gap on a required font → edition
      NEEDS_LAYOUT_REVIEW with an actionable reason. Config `bookstore.font_integrity.enabled`
      (default true; cheap, no API). Default behaviour on mismatch = flag + use approved alias (never
      a silent unapproved file). PROOF: on a real render, plant a counterfeit → preflight flags it,
      qa_report records offending font, edition not publishable; remove plant → clean. ≥2 books.
- [ ] **T8. Regression test** built from the planted-counterfeit render (asserts flag + fail-closed).

## Phase C3 — Output text-layer verification (R-W4)

- [ ] **T9. Wire `text_verification` (or equivalent) as a post-render gate** (seam #3): extract text
      from the SAVED pdf, confirm translated strings present + searchable; record
      `qa_report['text_layer']`; corrupt/garbled layer fails closed. Cheap → default on.
      PROOF on a real render: My House af text layer verifies PASS (strings extractable); a
      deliberately ToUnicode-corrupted fixture FAILS closed. ≥2 books.

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

## Open decisions for Captain Zan (carried)
1. **Font-integrity on mismatch:** hard-BLOCK render, or flag+use-approved-alias (my default)? The
   latter keeps output flowing with the substitutes we chose today; the former is stricter.
2. **accessibility (tagged PDF):** wire now (R-W-scope) or mark DORMANT until accessibility is a
   stated requirement? Leaning DORMANT unless you need tagged output soon.
3. **ocr_integration / caption_detection:** in scope for THIS spec, or a follow-up? They matter only
   for scanned/baked-text books; My House doesn't need them. Leaning follow-up.
```
