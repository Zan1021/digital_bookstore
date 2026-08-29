# V8 Rendering-Engine Brief — Compliance Audit

Audited against: `Brief/kiro-v8-rendering-engine-overflow-fix-brief.md` (§1–§21)
Production path audited: `app/Services/PdfTranslationService.php` → `scripts/pdf_translate_v8.py` (`replace` subcommand)
Date: 2026 session audit. **No code was changed — audit only.**

---

## TL;DR verdict

**The system does NOT meet the brief. Rough compliance: ~25–30%, not 99%.**

The brief demands a *constrained document reconstruction pipeline* with a semantic region graph, real font shaping/measurement, per-region clipping, post-render glyph-geometry validation against hard constraints, a fail-closed edition-state machine, a per-region diagnostic manifest, and an admin layout-debug overlay. Most of those capabilities exist **as unwired standalone Python modules** (`scene_renderer.py`, `document_model.py`, `quality_gates.py`, `glyph_preflight.py`, `visual_qa.py`, `universal_containers.py`, `borderless_table.py`) but the **production render path never calls them**.

The live path is still the V8 "per-span redact & reinsert" approach: PHP sends a **flat `{page_number, translated_text}` string per page** (no region graph, no stable IDs), the engine classifies the page by heuristics, redacts spans and reinserts translated text, then does a light structural sanity check. There is **no clipping, no glyph-geometry validation, no hard-constraint gate, no edition-state enforcement, no diagnostic manifest persisted, and no admin overlay.**

Critically, the brief's central rule — *"If V8 cannot produce a valid layout automatically, it must stop and request review. It must never create a visibly broken page and report success"* — is **not enforced**. The engine always saves the PDF and returns success; the only fail-closed signal is `report["review_pages"]`, which `PdfTranslationService` **logs and ignores** (it still sets the translation `status = 'approved'`).

---

## Section-by-section

### §2 Constrained document reconstruction pipeline — ❌ NOT MET (in production)
The brief mandates the pipeline: geometry analysis → semantic region graph → translation mapped to regions → font resolution/shaping/measurement → constraint layout → clipped rendering → validation → PASS/FAIL gate.
- The full pipeline is implemented in `scene_renderer.py` (`render_from_scene`) + `document_model.py` (`build_document_scene`), which import `glyph_preflight`, `text_fit_solver`, `font_registry`.
- **But nothing calls `scene_renderer.render_from_scene`.** grep across `*.py` / `*.php`: only self-references in its own docstring. Production uses `replace_text_in_pdf()` in `pdf_translate_v8.py`, which is the redact-and-reinsert approach, not reconstruction.
- Result: the mandated architecture exists on disk but is bypassed. "Rendering the text is not completion" — the code treats rendering as completion.

### §4 Non-negotiable hard constraints — ❌ NOT MET
None of the eight hard invariants are actually checked against rendered glyph geometry in production:
- `renderedGlyphBounds ⊆ safeInnerBounds` — not computed. `_verify_rendered_page` only checks (a) back-cover line count and (b) vocab words whose x1 > `pageWidth − 4`. Nothing verifies containment within a *region's* safe inner bounds.
- `overflowX/overflowY = 0` — not measured per region. Vocab overflow flagging is explicitly disabled (comment in `render_vocabulary_page_v8`: *"per-item overflow flagging for vocab is intentionally NOT done here"*).
- `neighbourTextIntersections = 0` — no collision detection in the production path at all.
- `protectedGraphicIntersections = 0` / `tableBorderIntersections = 0` — no border/line intersection test. (`insert_translated_span` shrinks text to a column-width estimate but never tests glyph bounds against detected grid lines.)
- `pageBoundaryIntersections = 0` — partially approximated for vocab only (word x1 vs page width), not for other page types, and not against the trim box.
- `unresolvedFontFallbacks = 0` — not enforced; `_find_font_file` silently falls back to "first available font" and never fails.
- The required failure actions (mark region failed, page = layout review, edition = not publishable, retain diagnostics, show in admin UI) are **not implemented** beyond appending a page number to `report["review_pages"]`.

### §5 Source PDF as geometry authority / canonical coordinates / region schema — ⚠️ PARTIAL
- Source geometry is read from the PDF (spans, bboxes, origins) so the source remains authoritative for placement. Good.
- The full per-region JSON schema (`regionId`, `semanticType`, `safeInnerBounds`, `parentRegionId`, `neighbourRegionIds`, `protectedEdges`, `preserveLineBreaks`, `readingOrder`, alignment, rotation) is produced by `document_model.py` / `page_manifest.build_vocabulary_manifest` — but **only vocabulary pages** build a manifest, and it is not persisted or emitted in the required shape. Story/cover/back-cover/copyright pages have **no region graph**.
- Coordinates: mostly PDF points end-to-end, but story/back-cover paths mix in CSS px via `insert_htmlbox` CSS. No single canonical coordinate object is enforced.

### §6 Table recognition & reconstruction — ⚠️ PARTIAL (vocab only), core gap remains
- §6.1 table graph: `universal_containers.py` (grid detection) + `merged_cells.py` + `borderless_table.py` exist and `document_model.detect_containers` builds a grid → **but only reachable via `scene_renderer`, which production doesn't use.** In production, `render_vocabulary_page_v8` builds a *column-clustered* manifest (`build_vocabulary_manifest`), not a full table graph with rows/merged cells/nested regions. Header clustering (`_cluster_header_spans_by_column`) is a heuristic, not a grid graph.
- §6.2 multi-source detection & confidence: implemented in the standalone modules; not in the production path.
- §6.3 cell padding / safe inner box: `insert_translated_span` uses a flat 5pt column padding, not inferred per-cell `safeInnerBounds = cellBounds − calculatedPadding`.
- §6.4 protected grid lines / exclusion margin: **not enforced** — no glyph-vs-gridline test anywhere in production. (V8's premise is "we never erase borders," but the brief additionally requires that *translated glyphs must not intersect them* — untested.)

### §7 Preserve line / item / paragraph structure — ❌ MOSTLY NOT MET
- The brief forbids passing a page as one undifferentiated string. **Production does exactly that**: `PdfTranslationService::buildTranslationsJson` sends one `translated_text` blob per page; `splitTranslatedText` re-splits it by *proportional word distribution* and `mergeLines` — i.e. exactly the "string similarity / re-inference" the brief prohibits (§8).
- Story pages collapse all text to one flowing paragraph (`_clean_story_text` joins all lines). Blank-line/paragraph boundaries are **not preserved**.
- Back-cover title lists are *reconstructed* from a flat string via regex (`_split_title_list`) — a recovery hack that concedes the line breaks were already destroyed upstream.
- Semantic item model with per-item `{id, source, translation}` (the §7 JSON) is only present for vocab via `translation_request`/manifest; other page types have no item structure.

### §8 Translation-to-region mapping with stable IDs — ❌ NOT MET (in production)
- Required chain `page → region → item → translation → rendered object` with a *stable source identifier* exists only for vocabulary pages (`legacy_text_to_manifest_items` maps flat text to manifest IDs — and even that is a legacy converter, i.e. mapping a flat string back onto IDs, which is the fragile approach the brief warns against).
- The production PHP layer sends **no IDs at all**. `translateWithManifest` in `TranslationService.php` exists and mentions manifest-based translation, but `PdfTranslationService::createTranslatedPdf` calls `buildTranslationsJson` (flat text), not the manifest path.
- The four pre-render validations (exactly one translation per unit, no cross-region assignment, no orphans, no swallowed units, compatible structure) are **not run** before rendering. Only a post-hoc header-count mismatch check exists for vocab headers.

### §9 Font resolution & visual-size matching — ⚠️ PARTIAL
- §9.1 same font file for measurement & final render: `insert_translated_span` measures with `pymupdf.Font(fontfile=...)` and renders with the same `fontfile` — consistent for that path. But `_find_font_file` **silently substitutes** and never fails on unapproved fallback (violates "If an unapproved fallback is used, validation must fail"). No `fontFileHash`, `resolvedFamily`, `fallbackUsed` record is produced in production (that lives in `font_registry.py`, unused here).
- §9.2 visual-size matching: `_visual_size_match` exists and is applied for vocabulary columns (ascender−descender proxy). Not applied to story/cover/back-cover/copyright. Uses a crude proxy, not cap-height/x-height/median-glyph-height as specified.
- §9.3 shape before measuring: `text_shaping.py` exists; production relies on `Font.text_length` only — no kerning/ligature/bidi/combining-mark-aware shaping in the live path.
- §9.4 typography groups: implemented for vocab columns (`solve_batch(force_consistent=True)`). No document-level typography groups across page types.
- §9.5 controlled fitting order: only steps 6 (shrink) and a partial 3 (rewrap, and `_insert_wrapped_span` is *unused after revert*) exist. Steps 4 (tracking), 5 (line-spacing), 7 (alternate metric-compatible font), 8 (request shorter translation), 9 (route to manual review) are not implemented in production.

### §10 Constraint-based layout — ⚠️ PARTIAL (vocab only)
- `text_fit_solver.solve_batch` provides a real constraint fit for vocabulary columns (min size, shrink ratio, consistent size). Good, but it's the **only** page type using it.
- §10.1 minimum readability by doc type/market/format: solver has a `min_font_size` (7.0 hard-coded for vocab), not configurable by document type/market/output format. Failing the region on min-size-without-fit is **not** wired to a page/edition failure.
- §10.2 hierarchy validation (`heading > body`, `tableHeader ≥ tableBody`, `peerVariance ≤ tolerance`) — **not validated** anywhere in production.

### §11 Rendering containment (clipping) — ❌ NOT MET
- The brief requires *every* text region to render through an explicit clip path (`save state → clip → draw → restore`). **There is no clipping anywhere** in `pdf_translate_v8.py` (no `set_clip`, no clip-path parameter; grep for `clip` returns nothing). This is a Phase-1 "stop invalid output" item and it is entirely absent.
- Consequence: a failed region *can* damage neighbours, exactly the failure mode §11 exists to prevent.
- Live/selectable text: `insert_text`/`insert_htmlbox` do produce live text — ✅ that sub-point holds. Font subsetting/licensing is handled by `doc.save(garbage=4)` but not verified against licensing.

### §12 Post-render validation — ❌ MOSTLY NOT MET
- §12.1 geometry validation on actual rendered output: **not done.** `pdf_validation.validate_render_output` (called at the end of `replace_text_in_pdf`) only checks page count, page boxes, rotation, PDF integrity, font *accessibility*, and file size — **not** glyph-in-region, border crossings, neighbour intersection, clipping, trim-area, expected-object-count, or "resolved font == approved font."
- `_verify_rendered_page` adds two shallow heuristics (back-cover line-count collapse; vocab word past page edge). This is nowhere near the §12.1 checklist.
- §12.2 raster validation with masks (artwork/lines/source-text/translated-text zones, table-line preservation, stray pixels, collisions, alignment drift, background damage): implemented in `visual_qa.py` / `quality_gates.gate_visual_qa` — **not invoked** by the production path.
- §12.3 semantic validation (each unit once, list order, heading/body, sentence/paragraph boundaries, cell assignment): **not run** in production.

### §13 Fail-closed publication gate — ❌ NOT MET
- Required edition states (`ANALYSING … NEEDS_LAYOUT_REVIEW … READY_FOR_REVIEW … PUBLISHABLE`) **do not exist.** `Translation.status` is a free-string column defaulting to `pending` with values `pending/processing/draft/approved` (migration `..._create_translations_table.php`).
- No gate prevents `READY_FOR_REVIEW`/`PUBLISHABLE` on failed QA. Worse: `generateAllTranslatedPdfs()` sets `status = 'approved'` on success of the *subprocess*, regardless of `report["review_pages"]`. Flagged pages do not block approval.
- No independent publish-API state verification exists.

### §14 Diagnostic manifest — ❌ NOT MET
- The per-region diagnostic manifest (regionId, fonts, visualScaleRatio, overflowX/Y, clippedGlyphCount, tableBorderIntersections, neighbourCollisions, fitStatus, failureReasons, etc.) is **not produced.** The `report` dict returned to PHP has coarse fields (`page_types`, `coverage`, `errors`, `review_pages`, `verification`, `validation`) and is only written to the Laravel log — **not persisted with the edition** and not exposed to admins. Most required per-region fields are never computed.

### §15 Admin review interface (layout-debug overlay) — ❌ NOT MET
- No layout-debug overlay exists. The only "overlay" in blade views is the reader's word-highlight/narration overlay (`flipbook.blade.php` `.text-overlay`), unrelated to QA.
- None of the required toggles (source boxes, safe inner boxes, table borders, glyph bounds, reading order, collisions, clipped areas, font info, confidence) exist. No red-highlight of invalid regions, no per-region manifest inspector, no in-place edit/re-render/side-by-side, no edition-specific override store.

### §16 Immediate investigation tasks — ⚠️ UNVERIFIED / LIKELY NOT MET
- No evidence in the repo that the specific defective-page root-cause investigation (which job/build, retrieved manifest, mapping inspection, measurement-vs-embedded-font comparison, "which code path marked it successful", regression test added *before* the fix) was performed and recorded. The reported page is not present as a golden regression test (see §17.2).

### §17 Required automated tests
- §17.1 unit tests — ⚠️ PARTIAL. `test_unit.py` covers coordinate conversion, font subsetting, table detection, overflow detection, classification, span extraction, translation mapping, reading order, validation *structure*, etc. Missing/weak vs the mandated list: safe-box calculation, **clipping-path generation** (no clipping to test), **border intersection detection**, **collision detection**, typography-group consistency, and **publication-state enforcement** (no state machine to test).
- §17.2 golden-page regression library — ❌ NOT MET. `test_integration.py` has 10 generic render/pipeline tests. There is **no versioned golden-page library** covering merged cells, borderless tables, 3/4-column pages, centred/justified text, text-over-illustration, irregular shapes, rotated text, very-short and 30–80%-longer translations, mixed fonts, missing embedded fonts, RTL, complex scripts, scanned vs vector vs crop-marked PDFs, landscape/portrait, double-page spreads. The reported word-table page is not enshrined as a golden test.
- §17.3 deliberate-failure tests — ❌ NOT MET. No test creates an unfittable page and asserts: no border crossing, no silent clip, region failed, page `NEEDS_LAYOUT_REVIEW`, publication blocked, useful admin reason. This is the acceptance test for the central rule and it does not exist.

### §18 Acceptance criteria — ❌ NOT MET (most)
- Geometry (no glyph crosses region/border, no neighbour overlap, no trim overflow, borders intact): **not verified** → cannot be asserted.
- Structure (word-list items/order, heading≠body, cells separate, paragraph boundaries): only partially for vocab; story/back-cover structure is reconstructed from flat text → **not met**.
- Typography (resolved font known/logged, same file for measure+render, consistent peer sizes, heading>body, min readability): partial for vocab, logging incomplete, hierarchy unvalidated → **not met**.
- Validation (every page → manifest; invalid pages can't reach READY/PUBLISHABLE; reported page caught automatically; regressions fail on reintroduction): **not met** (no manifest, no state gate, no golden/failure tests).
- Generalisation (no Kolulu/page-number/Afrikaans/fixed-coord conditionals): ✅ **largely met** — `classify_page` is content/geometry-driven; helpers are book-agnostic. Minor residue: `merged_cells.py` and other modules contain hard-coded Kolulu test paths in `__main__` blocks (test-only, not in the render path).

### §19 Performance — ⚠️ PARTIAL
- Content/font caching exists (`content_cache.py`, font metrics by hash in `font_registry.py`) and incremental re-render (`incremental_render.py`) — but these serve the unused scene pipeline. The production path does not demonstrably cache geometry by source hash or re-render only affected pages. "Do not weaken validation for speed" is moot because strong validation isn't present.

### §20 Recommended implementation sequence — ❌ Phase 1 incomplete
Phase 1 ("stop invalid output") items are the priority and are largely missing:
1. Per-region clipping — **absent.**
2. Post-render overflow & collision detection — **absent** (only shallow heuristics).
3. Fail-closed page/publication states — **absent.**
4. Diagnostic logging — partial (coarse report to log only).
5. Add the defective page as a regression test — **not present.**

### §21 Definition of done — ❌ NOT MET
The central rule is not enforced: the engine renders and reports success even when pages are flagged, and `PdfTranslationService` approves translations regardless of `review_pages`. Points 1–8 (valid render verified, structure preserved, consistent typography, validator catches deliberate failures, invalid pages blocked, works on unrelated books, diagnostics explain every failed region, regression coverage) are not satisfied.

---

## Highest-priority gaps to reach 100% (concrete)

1. **Wire the real pipeline into production.** `PdfTranslationService::createTranslatedPdf` + `pdf_translate_v8.py` must route through `scene_renderer.render_from_scene` (with `document_model.build_document_scene`), or the required capabilities must be added to the live `replace` path. Today those modules are dead code in production. (`app/Services/PdfTranslationService.php`, `scripts/pdf_translate_v8.py::replace_text_in_pdf`)
2. **Stop sending flat page strings.** Send manifest items with stable IDs from PHP (`buildTranslationsJson` → manifest-based), remove `splitTranslatedText`/`mergeLines` proportional re-splitting (§7/§8).
3. **Add per-region clipping** (`save/clip/draw/restore`) to every insertion (§11) — currently zero clipping.
4. **Add post-render glyph-geometry validation** against the §4 hard constraints (region containment, border intersection, neighbour collision, trim overflow, expected-object count, approved-font check) and make it the gate, not `pdf_validation.py`'s box/rotation checks (§12.1).
5. **Introduce the edition-state machine** and enforce it in the publish API and in `generateAllTranslatedPdfs` (stop auto-`approved`) (§13).
6. **Persist a per-region diagnostic manifest** in the required schema and expose it to admins (§14).
7. **Build the admin layout-debug overlay** with the nine toggles, red invalid-region highlighting, per-region editing/override store, and single-page re-render (§15).
8. **Create the golden-page library and deliberate-failure tests**, and add clipping/border-intersection/collision/typography-consistency/publication-state unit tests (§17).
9. **Fail on unapproved font fallback** and record `fontFileHash`/`fallbackUsed` (§9.1).

## What IS solid (credit where due)
- Book-agnostic classification (`classify_page`, `_looks_like_title_list`, `_is_prose_page`, imprint-marker copyright detection) — no page-number/title hard-coding in the render path (§18 generalisation).
- Vocabulary path is the closest to spec: manifest + `text_fit_solver.solve_batch(force_consistent=True)` consistent per-column sizing + `_visual_size_match` + header coverage-mismatch flagging.
- The supporting modules needed for full compliance largely *exist* (`scene_renderer`, `document_model`, `universal_containers`, `borderless_table`, `merged_cells`, `glyph_preflight`, `quality_gates`, `visual_qa`, `font_registry`, `text_shaping`) — the work is substantially about **wiring, validation, gating, and tests**, not building every capability from zero.
