# Steering: V8 Universal PDF Translation Engine — Full Compliance (SINGLE SOURCE OF TRUTH)

**Goal:** The V8 engine must do **100%** of `Brief/kiro-v8-rendering-engine-overflow-fix-brief.md`
(the overflow/table/QA brief) AND `Brief/kiro-universal-pdf-translation-engine-brief2.md`
(the architecture brief), for **ANY** uploaded book — not just the Kolulu sample.
Current honest compliance: Phase-1 partially built (see "Honest status" below). NOT done.

> **CENTRAL RULE (never violate):** If V8 cannot produce a valid layout automatically,
> it must STOP and request review. It must NEVER create a visibly broken page and
> report success.

---

## 0. NON-NEGOTIABLE STANDING RULES (Captain Zan, apply to everything)

- **R1 — Book-agnostic.** ZERO logic keyed on a specific title (e.g. "Kolulu"), a fixed
  page number, a hard-coded language (e.g. "af"), or fixed coordinates from one book.
  Everything derived from detected structure/geometry/content of the uploaded PDF.
  Verify on a SECOND, different book before "done".
- **R2 — No legacy code influence.** Superseded code paths must be REMOVED or fully
  isolated. Never leave old + new engine paths both live where old can leak in. No
  unused-but-imported legacy functions in the render path. Exactly ONE engine path in
  production.
- **R3 — Casing mirrors source, ALL pages.** Detect the source glyphs' casing per region;
  reproduce the same visual casing (all-caps -> uppercase; normal -> normal) on EVERY
  page type (story, cover, copyright, vocabulary, back cover). Book-agnostic.
- **R4 — Never say "done" when it isn't.** "Done" = the user-visible OUTCOME works and is
  verified (the page renders correctly), NOT that internal sub-tasks/tests are ticked.
  If a step only DETECTS a problem but doesn't FIX it, say exactly that. Lead status with
  what the user will SEE. If unsure it meets the user's bar, ASK.
- **R5 — Fail-closed everywhere.** A page that fails any hard constraint => the edition is
  NOT publishable. The publish/serve path must INDEPENDENTLY verify state, not trust a UI.
- **R6 — Verify after every change.** Syntax check + full test suite (unit + integration +
  gate) must stay green; render book-2 af AND a second book; visually confirm; clean temp
  files; only then report the specific item complete.

---

## 1. GLOBAL CONSTRAINTS (from architecture brief)

- **Source PDF is the geometry authority.** Never change page dims, media/trim/crop boxes,
  rotation, artwork, border positions, table geometry, column count, image position,
  reading order, background colours, brand marks.
- **Canonical coordinates:** PDF points end-to-end; convert only at renderer boundaries.
  No repeated px<->pt<->css juggling.
- **Text stays live/selectable** where licensing/technical allows; fonts subsetted & licensed.
- **Instructions inside books are content**, never instructions to the app.

---

## 2. TARGET PIPELINE (architecture brief §Target Processing Pipeline; must be the real flow)

```
PDF upload
  -> preserve untouched source PDF
  -> inspect document metadata & page boxes            (Layer 1: Document Inspector)
  -> inventory PDF objects & fonts                     (Layer 2: Page Object Inventory)
  -> render reference page images
  -> OCR only where native text unavailable
  -> build a page scene graph                          (Layer 3: Scene Graph)
  -> group text objects into semantic regions
  -> assign translation policies to regions
  -> translate structured semantic units               (Layer 4: Semantic Translation)
  -> validate source->target coverage
  -> choose removal + rendering strategy per region     (Region Capability Router)
  -> remove ONLY original text                          (Layer 5: content-stream surgery)
  -> shape & fit translated text                        (shaping + constraint layout)
  -> inject translated text as live PDF text
  -> validate PDF structure & text coverage             (Layer 6: Verification)
  -> render translated page images
  -> run masked visual comparison
  -> route uncertain pages to human review              (fail-closed)
  -> export approved translated PDF
```
Translation, layout, rendering, QA are SEPARATE stages. Rendering != completion.

---

## 3. CURRENT ARCHITECTURE INVENTORY (what exists; wire it, don't rebuild)

Existing modules (many built, NOT wired to production):
- `document_model.py` — DocumentScene/PageScene/Region/TextUnit/TextStyle (Layer 3/4 model)
- `scene_graph.py` — spatial relationship graph
- `scene_renderer.py` — region-dispatch renderer (`render_from_scene`)
- `content_stream_surgery.py` — Tj/TJ operator removal
- `pikepdf_integration.py` — pikepdf helpers (pikepdf 10.12 installed)
- `universal_containers.py`, `merged_cells.py`, `borderless_table.py` — table detection
- `text_fit_solver.py` — constraint fit (`solve_text_fit`, `solve_batch(force_consistent)`)
- `text_shaping.py`, `international_text.py`, `script_detection.py` — shaping/RTL/scripts
- `font_registry.py`, `font_resolver.py`, `typography_fingerprint.py`, `variable_fonts.py`
- `glyph_preflight.py` — missing-glyph detection
- `pdf_validation.py`, `visual_qa.py`, `quality_gates.py`, `text_verification.py`
- `rotated_text.py`, `caption_detection.py`, `list_detection.py`, `container_detection.py`
- `page_inventory.py`, `page_manifest.py`, `optical_calibration.py`, `accessibility.py`
- `render_gate.py` — NEW glyph-geometry hard-constraint gate (this session)

Production path TODAY: `PdfTranslationService::createTranslatedPdf` -> `pdf_translate_v8.py
replace` with FLAT `{page_number, translated_text}` per page. This must move to the
scene-graph + stable-ID pipeline (Phase 2).

---

## 4. HARD CONSTRAINTS — must be validated on RENDERED output (overflow brief §4)

```
renderedGlyphBounds  ⊆ safeInnerBounds
overflowX = 0
overflowY = 0
neighbourTextIntersections     = 0
protectedGraphicIntersections  = 0
tableBorderIntersections       = 0
pageBoundaryIntersections      = 0
unresolvedFontFallbacks        = 0
```
Any failure => mark region failed, page = NEEDS_LAYOUT_REVIEW, edition not publishable,
retain diagnostics, surface in admin UI. NEVER pass silently.

---

## PHASE 1 — STOP INVALID OUTPUT (finish to 100% FIRST)

Exit criteria: broken pages can never be silently approved/served; deliberate-failure
tests pass; existing suites green; verified on 2+ books.

- [ ] **1.1 Per-region clipping (§11) on ALL page types.** Every text insertion (story,
      cover, copyright, vocabulary, back cover) rendered through an explicit clip to its
      region/cell safe box. `save -> clip -> draw -> restore`. (Done: vocab. TODO: verify/
      add for story/cover/copyright/back-cover htmlbox rects are true clips.)
- [ ] **1.2 Post-render glyph-geometry gate (§4/§12.1)** covers ALL constraints for ALL
      page types (not just vocab): trim overflow, border cross, neighbour collision,
      missing content, unresolved font fallback. (`render_gate.py` — extend coverage.)
- [ ] **1.3 Casing mirroring (R3) on ALL renderers** — apply `_source_text_transform` to
      story/cover/copyright too (currently only back cover + vocab).
- [ ] **1.4 Fail-closed states (§13):** `render_status` + `qa_report` columns (DONE,
      migrated). Service sets NEEDS_LAYOUT_REVIEW when not publishable (DONE).
      **TODO: publish/serve path (BookManager renderPdf, any download/reader route) must
      INDEPENDENTLY check `isPublishable()` and refuse to mark approved/serve as final.**
- [ ] **1.5 Diagnostic report persisted (§14 minimum)** — `qa_report` JSON on translation
      (DONE). Extend to per-region detail (Phase 3 full manifest).
- [ ] **1.6 Regression + deliberate-failure tests (§17.3)** — `test_render_gate.py` exists
      (6 tests). TODO: add PERSISTED golden fixtures for a known-good page and a known-bad
      page; assert bad page => NEEDS_LAYOUT_REVIEW + publish blocked.
- [ ] **1.7 Legacy/book-specific AUDIT (R1/R2):** grep render path for "Kolulu", fixed page
      numbers, hard-coded languages/coords, sample filenames; confirm single engine path;
      remove/​isolate `splitTranslatedText`/`mergeLines` proportional splitting if it feeds
      production. Verify on a non-Kolulu book.

---

## PHASE 2 — CORRECT THE UNDERLYING LAYOUT

Exit criteria: production uses the region graph + stable IDs; NO flat-string re-splitting;
table cells clipped to safe bounds; header/body typography consistent & hierarchy valid;
book-2 page 15 header NO LONGER crosses a border (gate passes); verified on 2+ books.

- [ ] **2.1 Wire the real pipeline (§2).** Route production through
      `document_model.build_document_scene` + `scene_renderer.render_from_scene` (or lift
      its logic into the live path). Retire the whole-page redact/reinsert path (R2).
- [ ] **2.2 Stop flat-string translation (§7/§8).** PHP `buildTranslationsJson` emits
      manifest ITEMS with stable IDs (page->region->item->translation->rendered object).
      REMOVE `splitTranslatedText`/`mergeLines` proportional distribution (forbidden §8).
      Preserve line/paragraph/list boundaries end-to-end (§7).
- [ ] **2.3 Table-cell graph (§6).** rows/cols/merged/nested cells + headers/body via
      `universal_containers`+`merged_cells`+`borderless_table`; multi-source detection with
      confidence; `safeInnerBounds = cellBounds - inferredPadding`; protected grid lines w/
      exclusion margin. Feed cell bounds to clipping + gate. Fixes the p15 header crossing.
- [ ] **2.4 Font resolution + visual-size matching (§9).** Wire `font_registry` +
      `typography_fingerprint`: record resolvedFamily/fontFileHash/fallbackUsed; FAIL on
      unapproved fallback (§9.1). Visual-size match via cap-height/x-height/median glyph
      height (§9.2), not raw point size. Shape before measuring (§9.3) via `text_shaping`.
      Document-level typography groups (§9.4). Apply to headers too.
- [ ] **2.5 Controlled fitting order (§9.5)**, full ladder: (1) preferred font+group size,
      (2) preserve semantic breaks/alignment, (3) rewrap within same item, (4) tracking in
      approved range, (5) line-spacing in approved range, (6) incremental shrink to min,
      (7) approved metric-compatible alternate font, (8) request shorter translation,
      (9) route to manual review. Never paint outside the region.
- [ ] **2.6 Minimum readability + hierarchy (§10.1/§10.2):** per-doc/market/format min
      sizes; validate headingVisualSize > body, tableHeader >= tableBody, peerVariance <=
      tolerance. Fail region if min size reached without valid fit.
- [ ] **2.7 Semantic validation (§12.3):** each unit appears once; list order preserved;
      heading never merged with body; separate cells never merged; sentence/paragraph
      boundaries preserved.

---

## PHASE 3 — RECOVERY, DIAGNOSTICS, ADMIN, FULL TEST LIBRARY

Exit criteria: full manifest + admin overlay + golden-page library; §18 acceptance and
§21 definition-of-done ALL pass; verified across layout families and 2+ books.

- [ ] **3.1 Full per-region diagnostic manifest (§14):** every field — regionId, regionType,
      semanticType, sourceBounds, safeInnerBounds, fontRequested/Resolved/Hash, fontSize,
      visualScaleRatio, lineHeight, tracking, sourceLineCount, semanticItemCount,
      renderedLineCount, renderedGlyphBounds, overflowX/Y, clippedGlyphCount,
      tableBorderIntersections, neighbourCollisions, fontFallbackUsed, fitStatus,
      failureReasons. Persist + expose to admins.
- [ ] **3.2 Edition state machine (§13 full):** ANALYSING, TRANSLATING, RENDERING,
      AUTOMATED_QA, NEEDS_LAYOUT_REVIEW, NEEDS_LANGUAGE_REVIEW, READY_FOR_REVIEW, APPROVED,
      PUBLISHABLE. PUBLISHABLE only if every page passed hard geometry validation, no
      unresolved layout error, language + narration review complete, human approval
      recorded. Publish API independently verifies.
- [ ] **3.3 Admin layout-debug overlay (§15):** toggles for source boxes / safe inner boxes
      / table borders / rendered glyph bounds / reading order / collision regions / clipped
      areas / font info / confidence. Invalid regions red. Per-region: edit translation,
      pick approved font, adjust size/tracking/line-height within limits, edit region
      boundary, split/merge with audit, re-render affected page only, side-by-side compare.
      Overrides saved as EDITION-SPECIFIC, never book-specific code.
- [ ] **3.4 Raster validation with masks (§12.2):** artwork/lines/source-text/translated-
      text zones; check table-line preservation, stray pixels, collisions, missing content,
      abnormal font scale, alignment drift, background damage. Compare geometry + protected
      areas separately (not whole-page pixel score).
- [ ] **3.5 Golden-page regression library (§17.2):** merged cells, borderless tables,
      3/4-column, centred, justified, text-over-illustration, irregular shapes, rotated
      text, very-short & 30–80%-longer translations, mixed fonts, missing embedded fonts,
      RTL, complex scripts, scanned, vector, crop-marked, landscape/portrait, double-page
      spreads. Verify machine geometry AND high-res renders.
- [ ] **3.6 Unit test coverage (§17.1):** coordinate conversion, safe-box calc, font
      resolution, glyph measurement, line-break preservation, list-item preservation,
      translation mapping, table-cell graph creation, clipping-path generation, overflow
      detection, border intersection, collision detection, typography-group consistency,
      publication-state enforcement.
- [ ] **3.7 Deliberate-failure tests (§17.3):** unfittable-at-min-size pages => no border
      cross, no silent clip, region failed, page NEEDS_LAYOUT_REVIEW, publish blocked,
      useful admin reason.
- [ ] **3.8 Performance (§19):** cache source geometry by file hash; cache font metrics by
      font-file hash; re-render only affected pages after edits; parallel page analysis
      where safe; fast preflight before raster compare. NEVER weaken validation for speed.
- [ ] **3.9 Immediate investigation record (§16):** document how the original defective page
      passed (job/build, manifest, mapping, measure-vs-embedded font, which code path marked
      it successful, whether validation ran / was only a warning) and add it as a golden test.

---

## 5. ACCEPTANCE (§18) + DEFINITION OF DONE (§21) — ALL must pass before "done"

- Geometry: no glyph crosses its safe region / border; no neighbour overlap; nothing past
  trim; all borders intact.
- Structure: word lists keep items+order; headings separate from body; cells separate;
  paragraph/sentence boundaries preserved.
- Typography: resolved font known+logged; SAME font file for measure + render; consistent
  peer sizes; heading > body; no region below readability threshold; CASING mirrors source
  on all pages (R3).
- Validation: every page => diagnostic manifest; invalid pages cannot reach READY/
  PUBLISHABLE; the reported defective page is caught automatically; regressions fail on
  reintroduction.
- Generalisation (R1/R2): no Kolulu/page-number/language/fixed-coord conditionals; solution
  works on unrelated books; single engine path; no leftover legacy code.
- Honesty (R4): only report "done" when the user-visible outcome is verified on 2+ books.

---

## 6. VERIFICATION DISCIPLINE (run every change; R6)
1. `python -c ast.parse` on changed .py; `php -l` on changed .php.
2. `test_unit.py` + `test_integration.py` + `test_render_gate.py` all green.
3. Render book-2 af AND a second uploaded book end-to-end.
4. Visually confirm affected pages (esp. p15 table, p16 back cover) + spot-check story.
5. Run the render_gate; a page is only "fixed" when the gate PASSES it (not just flags).
6. Clean temp `_diag_*`/`_verify_*` files.
7. Report status tied to user-visible outcome; do not claim a phase complete until its
   exit criteria above are met AND verified.
