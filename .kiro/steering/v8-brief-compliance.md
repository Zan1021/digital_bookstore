---
inclusion: always
---

# Steering: V8 Universal PDF Translation Engine — Full Compliance (SINGLE SOURCE OF TRUTH)

## LIVE SYSTEM STATE (read FIRST — current truth, 2026-10-10)

> ⭐ **NEXT-SESSION STARTUP: read `.kiro/specs/engine-wiring-and-activation/START-HERE-NEXT-SESSION.md`
> FIRST.** It has the one-line truth (wired ≠ working), what-to-read order, how to test (browser on
> :8080 + gate), the confirmed-defect→task map, and the ordered task list (T-A done; **T-B dedup bug
> is next**). Do not re-audit from scratch — that doc + the RECONCILIATION doc tell you what's going on.

**This block exists so the engine's workings survive a cold session start — no re-briefing.**
It is the current, verified state. When it conflicts with older prose below, THIS wins.

> ### ►► RESUME POINTER (where to start next session) ◄◄
> ⚠️ **WIRED ≠ WORKING — READ THIS FIRST (corrected 2026-10-10).** Two specs track two different
> definitions of "done" and they were conflated:
>  - `engine-wiring-and-activation` = "are modules reachable from the live render path?" → **DONE.**
>  - `v8-brief-compliance` (Phases 1–3, THIS FILE below) = "does a rendered page actually LOOK right
>    — fit, typography, casing, no overflow/overlap?" → **NOT DONE. Phase 1–3 boxes all unchecked.**
> Captain Zan put real eyes on a browser render of #10001 (2026-10-10) and found defects a
> "COMPLETE/226-green" status had hidden: cover subtitle overflow, orphaned logo ®, mixed font
> sizes + overlapping words on the copyright page, inconsistent/wrong fonts (script face on the back
> cover vs sans interiors), and a context-blind title ("Speel saam met my" vs "Speel met my").
> ROOT CAUSE: `render_gate.py` only strictly checks elements with a precise `cell_box` (roles
> heading/table_header/merged_header/end_marker); cover/prose/artwork roles get PRESENCE+FONT only,
> so those defects passed the gate. Full analysis + defect→task map:
> `.kiro/specs/engine-wiring-and-activation/RECONCILIATION-wired-vs-working.md`.
> **DO NEXT (in order):** (1) Phase 1.2 — make render_gate cover cover/prose/back-cover page types so
> the defects are SEEN; (2) 1.1 clipping; (3) 2.4 font resolution/visual-size; (4) 2.6 hierarchy/peer;
> (5) the IllustrationTextService dedup bug (region bbox from items) + title-context assembly. VERIFY
> each the Section-6 way: render #10000 AND #10001, LOOK at the pages, run the gate — tick only when
> the user-visible outcome is right on ≥2 books (R4/R6). The VisualQaService vision gate ALREADY
> EXISTS — tighten it, do not rebuild it.
>
> **(historical) engine-wiring-and-activation COMPLETE (2026-10-10):**
> Every brief capability is wired+proven on a real render, documented-DORMANT behind a flag, or
> deleted — zero "built but inert" modules. All phases C1–C7 + C6 DONE. (Wiring only — NOT layout
> compliance; see the WIRED≠WORKING note above.)
> **FINAL STATE:** font-integrity preflight (C2), text-layer gate (C3), illustration trio
> reclassified-live (C4), accessibility /Lang + check (C4a), OCR fallback + caption tagging (C4b),
> CI wiring-audit gate + DORMANT docs (C5), the full LOST backlog — security, optical_calibration,
> quality_gates/PDF-A, audit_trail, inventory_layout, page_inventory, typography_fingerprint,
> scene_graph deleted (C7) — ALL done+proven.
> **C6 (R-W9) ACCEPTANCE MET — TWO books, full capability set, end-to-end:**
> #10000 "My House" (en→af, 16pp): non-generative 83s + generative gpt-image-1 701s renders; all
> gates fired; /Lang=af-ZA embedded; fail-closed NEEDS_LAYOUT_REVIEW. #10001 "Play with Me"
> (en→af, 16pp): 136 item_translations, 55.7 MB PDF, security/text_layer/accessibility/conformance/
> fit/target_mapping/visual passed, /Lang=af-ZA re-read from the saved catalog, correct no-ops
> (ocr/captions/font_integrity), fail-closed NEEDS_LAYOUT_REVIEW. Same book-agnostic behaviour on
> both → R1/R-W2/R4 satisfied.
> **ACCEPTED LIMITATIONS (not blockers):** both proof books fail-closed on the illustration/structure
> track (correct for picture books with baked-in artwork text — needs the per-page human review the
> gate demands, out of scope for *wiring*); generative inpaint stays mandatory-review + gated OFF by
> cost; PDF/A conformance is informational.
> **FIRST ACTION on resume:** this spec is closed — run `python scripts/wiring_audit.py` to confirm
> (totals: 24 import-live / 19 subproc-live / 6 test-only / 13 dead; CI baseline = 19 allowed-inert)
> and pick the NEXT spec. No open tasks remain here.


- **ONE live render engine:** `scripts/pdf_translate_v8.py` (invoked by `PdfTranslationService::
  createTranslatedPdf`) + `scripts/page_manifest.py` for extraction. There is NO second renderer;
  `v8_advanced.py` is only an admin FontManager helper, `scene_renderer.py` was removed.
- **Authoritative module map is GENERATED, not remembered:** run `python scripts/wiring_audit.py`.
  Latest totals (s13, after C7 — all LOST backlog wired): 24 import-live, 19 subprocess-live,
  6 test-only, 13 dead. The audit now propagates liveness TRANSITIVELY from LIVE-SUBPROC modules


- **ONE live render engine:** `scripts/pdf_translate_v8.py` (invoked by `PdfTranslationService::
  createTranslatedPdf`) + `scripts/page_manifest.py` for extraction. There is NO second renderer;
  `v8_advanced.py` is only an admin FontManager helper, `scene_renderer.py` was removed.
- **Authoritative module map is GENERATED, not remembered:** run `python scripts/wiring_audit.py`.
  Latest totals (s13, after C7 — all LOST backlog wired): 24 import-live, 19 subprocess-live,
  6 test-only, 13 dead. The audit now propagates liveness TRANSITIVELY from LIVE-SUBPROC modules
  through the Python import graph (earlier versions seeded only the 2 engine entrypoints and
  under-counted — the illustration-repair trio + font_registry/glyph_preflight/document_model/etc.
  were wrongly TEST-ONLY/DEAD). Reports:
  `.kiro/specs/unified-rendering-and-testing/WIRING_AUDIT_MECHANICAL.md` (the map) +
  `WIRING_TRIAGE.md` (what to wire/keep/delete). Re-run before trusting any claim about wiring.
  **CI GATE (R-W8/T13):** `tests/Feature/WiringAuditGateTest.php` runs `wiring_audit.py --check`
  against the committed baseline `scripts/.wiring_audit_baseline.json`; a NEW unwired production
  module fails the build. If you intentionally add a DORMANT module, add it to the baseline
  (`--write-baseline`) AND to the DORMANT list below.
- **DORMANT MODULES (R-W7/T14 — built, inert, intentionally OFF; what / flag / why off):**
  - `optical_calibration` — measures rendered ink vs source metrics for fine font-size calibration.
    No flag yet (not wired). OFF: current per-region font fitting (text_fit_solver) is sufficient;
    revisit only if measured ink drift becomes a visible problem.
  - `raster_fallback` — renders a page to high-DPI raster + paints over text when vector editing
    would damage content. OFF: the surgical/illustration paths + fail-closed-to-review cover this;
    a blind raster fallback would mask defects (anti-R5). Enable only as a deliberate last resort.
  - `translation_variants` — primary vs compact translation variants for overflow. OFF: overflow is
    currently handled by fit-before-erase + review, not variant swapping. Activate if/when the
    translation layer supplies compact variants.
  - `typography_fingerprint` — visual font signature for automatic matching. OFF as a standalone
    path; its IDEA is now served by the LIVE `font_integrity` preflight (embedded-name compare).
    Keep for a future similarity-based matcher; not needed for the counterfeit guard.
  - `variable_fonts` — OpenType variable-font axes (wght/wdth/opsz). OFF: no current book ships a
    variable font; the house/approved fonts are static. Activate when a variable font asset appears.
  - `content_cache` — content-addressed caching so identical source pages skip reprocessing. OFF:
    the in-process `_GEOM_CACHE` already memoises per render; a persistent cache is a perf-only
    optimisation, enable if batch throughput demands it.
  - `content_stream_surgery` — removes text operators directly from the PDF content stream (cleanest
    possible removal). OFF: the live engine uses tight per-span redaction (safer across malformed
    streams); keep as an alternative removal strategy, not the default.
  - `incremental_render` — re-render only the changed page on a single-translation edit. OFF: no
    flag wired; the current path re-renders the edition (cheap, reuses stored translations). Enable
    for a faster single-page edit loop later.
  - `script_detection` — RTL/complex-script (Arabic/Hebrew/Indic) detection. OFF: current catalogue
    is LTR (African languages + European); activate when an RTL/complex-script edition is onboarded
    (also the gate for the dormant RTL render path).
  - `security` — PDF-processing hardening (no JS, size/time limits). OFF as a wired gate; uploads are
    currently trusted (publisher-supplied). WIRE THIS before accepting untrusted public uploads.
- **The big open problem:** the ChatGPT brief was built module-by-module but MOST modules were
  never wired into the live engine. Capability exists as code yet does nothing at runtime. The
  active plan to fix this is spec `.kiro/specs/engine-wiring-and-activation/`.
- **Font truth:** source books reference AdLibBT/Calibri/Edu-Aid/OzHandicraft; the shipped files for
  those were COUNTERFEITS (Google-Font lookalikes renamed). The counterfeits were deleted and
  `font_policy.py::_RETIRED_FONT_ALIASES` now maps those source names to approved substitutes
  (AdLibBT->PlaypenSans-Bold, Edu-Aid->PlaywriteZA, Calibri->PlaypenSans, OzHandicraft->PatrickHand).
  The font-asset-integrity preflight (`scripts/font_integrity.py`, s9) is now WIRED and LIVE — it
  compares each font file's embedded internal name vs the requested family and fails closed on a
  counterfeit before render (the guard that would have caught the Bangers-as-AdLibBT fiasco). The
  rest of the old detection subsystem (font_registry/glyph_preflight/typography_fingerprint/
  optical_calibration) is reused BY it or still dormant.
- **AI QA gate is ON** (PHP `VisualQaService`, `VISUAL_QA_ENABLED=true` in `.env`, scope=structured,
  runs on queue/console path). It compares source vs translated pages. The python `visual_qa.py` is
  the dead old version — ignore it.
- **Gotchas that cost hours (do not relearn):** PowerShell has NO `&&` (use `;` or `cwd`); set
  `$env:PYTHONIOENCODING="utf-8"` for ✓-glyph test suites; read the engine's stderr JSON by running
  it IN-PROCESS from a tiny .py/.php runner (PS `2>` mangles UTF-16); the Translation language column
  is `language_code` not `language`; re-rendering an existing translation reuses stored
  item_translations (NO translation-API spend); `.env` had a DUPLICATE key once (dotenv last-wins) —
  check for dupes when a flag "won't take".
- **Test book:** My House #10000, translation #8 (af). Re-render via a bootstrap .php calling
  `createTranslatedPdf`. Still lands NEEDS_LAYOUT_REVIEW for non-font reasons (grid-cross geometry,
  vocab consistency) — that's the fail-closed gate working, not a crash.

---

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

> ⚠️ **DO NOT trust a hand-typed module list here — it rots.** The AUTHORITATIVE, always-current
> map of which modules are LIVE vs TEST-ONLY vs DEAD is GENERATED by `scripts/wiring_audit.py`
> (writes `.kiro/specs/unified-rendering-and-testing/WIRING_AUDIT_MECHANICAL.md`). Re-run it at the
> start of any engine work. The triage of what to wire/delete is `WIRING_TRIAGE.md` beside it.
> See also the `## LIVE SYSTEM STATE` section at the top of this file.

Existing modules (many built, NOT wired to production — confirm against the audit, not this list):
- `document_model.py` — DocumentScene/PageScene/Region/TextUnit/TextStyle (Layer 3/4 model) — LIVE
- `scene_graph.py` — spatial relationship graph — TEST-ONLY (the live engine uses its OWN inline
  region graph; the old standalone `scene_renderer.py` was REMOVED, its logic now lives inside
  `pdf_translate_v8.py`. Do not reintroduce a second scene renderer.)
- `content_stream_surgery.py` — Tj/TJ operator removal — TEST-ONLY
- `pikepdf_integration.py` — pikepdf helpers — DEAD (engine standardised on PyMuPDF)
- `universal_containers.py`, `borderless_table.py` — LIVE; `merged_cells.py` — DEAD (merged-header
  handling is inline in render_gate/table path)
- `text_fit_solver.py`, `text_shaping.py`, `international_text.py` — LIVE
- `script_detection.py` — TEST-ONLY (dormant until an RTL target language ships)
- `font_registry.py`, `font_resolver.py`, `typography_fingerprint.py`, `variable_fonts.py`,
  `glyph_preflight.py`, `optical_calibration.py` — the FONT subsystem; `font_resolver` is LIVE
  (upload-time Google-Fonts downloader — it CREATED the counterfeit fonts, see gotchas), the rest
  are DEAD/unwired. `font_policy.py` is the LIVE resolver.
- `pdf_validation.py`, `render_gate.py` — LIVE; `visual_qa.py` (python) — DEAD, superseded by the
  LIVE PHP `VisualQaService`; `quality_gates.py`, `text_verification.py`, `accessibility.py` — DEAD
- `rotated_text.py` — LIVE; `caption_detection.py`, `list_detection.py`, `container_detection.py` — DEAD
- `page_inventory.py` — TEST-ONLY; `page_manifest.py` — LIVE (engine entrypoint, PHP subprocess)
- `cover_retypeset.py`, `illustration_*` — LIVE-SUBPROC, config-gated; `visual_coverage(_cli).py` — LIVE-SUBPROC, gated

Production path TODAY: `PdfTranslationService::createTranslatedPdf` -> `pdf_translate_v8.py replace`
via the STABLE-ID CONTRACT path (not flat per-page strings — that's a deprecated fallback).

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
- [~] **1.2 Post-render glyph-geometry gate (§4/§12.1)** covers ALL constraints for ALL
      page types (not just vocab): trim overflow, border cross, neighbour collision,
      missing content, unresolved font fallback. (`render_gate.py`.)
      PROGRESS (2026-10-10): NEIGHBOUR-COLLISION now runs on ALL page types (was
      vocabulary-only — the bug that let the #10001 copyright-page "self" overprint pass as
      visual=passed). Added `_words_overprint` (flags a genuine stack: horizontal overlap +
      vertical overlap >= 45% of the shorter word's height) so normal inter-line leading does
      NOT false-positive. VERIFIED (R4): enhanced gate run on the REAL 10001_af.pdf now flags
      page 2 `neighbourTextIntersections: 'hulle' overprints 'self.' (copyright)` +
      `tableBorderIntersections` → review_pages=[2], fail-closed. Regression tests added
      (test_render_gate.py: overprint-on-copyright flagged; normal-two-line-prose NOT flagged);
      gate 47/47, unit 64/64, integration 10/10, Laravel 226/226 all green.
      STILL TODO under 1.2: trim-overflow + border-cross are already all-pages; confirm
      font-fallback coverage on cover/back-cover; peer-size/hierarchy is 2.6.
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
