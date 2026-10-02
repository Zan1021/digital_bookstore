# Tasks — World-Class Book-Agnostic Rendering Engine

Ordered, dependency-aware. Each task is a vertical slice: implement + test + verify on the
corpus before moving on. The p2 visible fix ships in Phase 1. Follow the brief's rule:
**fix deterministic identity/ownership/geometry/fonts BEFORE any new vision-model calls.**

Invariants on every task: I1 no special-casing · I2 single production path · I3 fail
closed · I4 derived not imposed · I5 immutable source.

---

## Phase 1 — Bounded containers + paragraphs + the gate that sees it (R1, R2, R8-partial)
*Ships the visible p2 fix.*

- [ ] 1.1 Add `Bounds` + `resolve_safe_box()` + `LayoutReviewRequired` to the geometry
  layer of `scripts/document_model.py`. Unit-test validate/intersect/inset and the
  fail-closed raise. (R1.1, R1.3)
- [ ] 1.2 Add `layout_container` + `safe_box` to `TextUnit`/`Region` (keep `bbox` as source
  ink box). Non-breaking defaults. (R1.1)
- [ ] 1.3 Implement non-table container resolution: cluster units into column regions by
  left-edge + reading order + style; bound each region by its own extent AND neighbouring
  regions/images (artwork exclusions); set `layout_container`; `safe_box = inset(padding)`.
  No page-wide fallback — unresolved ⇒ `LayoutReviewRequired`. (R1.2, R1.3, R1.6)
- [ ] 1.4 Replace `_merge_continuation_spans` with a **paragraph builder**: group by
  style+column+leading+reading-order into paragraphs with stable `paragraph_id`; keep
  publisher metadata lines separate; keep end-markers separate; never merge across
  columns/images/fields. Assert the My House bio becomes ONE paragraph (not s0015 /
  s0016 / s0024). (R2.1–R2.4, R2.6)
- [ ] 1.5 Infer alignment from the paragraph's own lines (plural); expose it as placement
  data only. (R1.5, R2.6)
- [ ] 1.6 Refactor `render_page_from_scene_generic`: for each paragraph, fit into its
  `safe_box` and `draw_paragraph_text` with derived alignment + hard clip. **Delete the
  center-expansion branch.** Alignment must not change box_left/box_right. (R1.4, R2.5)
- [ ] 1.7 Teach `render_gate.validate_structure` the missing defect classes: element
  outside its source column; rendered box wider than resolved container (grew via
  alignment); paragraph fragment at a stale anchor; neighbour/artwork overlap. (R8.1, R8.2)
- [ ] 1.8 Verify: re-render My House p2 (af). Assert bio in right container, copyright in
  left container, trailing fragment inside the bio, no overlap, and that the PRE-fix render
  is flagged by the gate. Capture before/after PDF.js images. Keep all existing suites
  green. (R11.2, R11.3)

## Phase 2 — Fit before erase (R3)
- [ ] 2.1 Add `prepare_region_layout(region, target, font_path, policy)` over
  `text_fit_solver.solve_text_fit`; raise `LayoutReviewRequired("TRANSLATED_TEXT_DOES_NOT_FIT")`
  on no-fit/overflow. (R3.1, R3.2)
- [ ] 2.2 Wire the native renderer to fit BEFORE redacting source glyphs; on no-fit, do not
  erase and do not draw — flag + block. (R3.2, R3.3)
- [ ] 2.3 Role-specific wrapping policy (paragraph vs label/title). (R3.4)
- [ ] 2.4 Ensure measure + draw share the resolved font + shaping (no HarfBuzz-measure /
  unshaped-draw mismatch); add a test on a known-wide string. (R3.5)
- [ ] 2.5 Verify: longer/shorter target paragraphs reflow within container, no cross-
  container, no growth of short text. (R11 matrix)

## Phase 3 — Fonts as explicit policy (R4)
- [ ] 3.1 Define `typography_policy` shape on `Book.metadata` (+ edition JSON field if
  needed); migration/casts; fonts referenced by validated asset ID (no absolute paths). (R4.3, R4.6)
- [ ] 3.2 `FontPolicyResolver` (PHP) with precedence unit→edition→book→publisher→source→
  fallback; emit a resolved render spec (hash/face/weight/style/script/direction/size/
  line-spacing/fit). (R4.1, R4.2)
- [ ] 3.3 Thin Python mirror so the engine selects the SAME font; make `illustration_text`
  use it (kill the independent `_pick_font` preference). Keep `STORY_BODY_FONT` as publisher
  default only. (R4.3, R4.4)
- [ ] 3.4 Missing-glyph detection ⇒ flag + fail closed. (R4.5)
- [ ] 3.5 Verify: same source with two approved fonts — both measured with the selected
  font, fit changes safely, container boundaries fixed. (R11 matrix)

## Phase 4 — Illustration inventory + region ownership + per-ID translation (R5)
- [x] 4.1 Source-only content-class classifier: native / outlined-vector / raster-baked, by
  comparing detected text to source PDF objects + OCR geometry. (R5.1, I5)
  — `scripts/illustration_classify.py` (3-class, text-layer→vector-paths→raster); wired into
  `tagRegions` (content_class + classified_by). Avoids the hidden-OCR trap. (deferred item 1 ✅)
- [x] 4.2 Replace whole-page `contractOwnedPages()` with region-level ownership; DELETE the
  no-op `filterNativeText()`; implement real overlap/object-ownership. (R5.2, R5.3)
- [x] 4.3 Per-page coverage result (scanned/no-candidate/deferred/unresolved); cost
  heuristic triages only, never decides "no artwork text." (R5.4, R5.5)
  — `process()` now records coverage for EVERY page (pdfPageCount); non-candidate distinct from
  scanned-clean; heuristic is triage-only. (deferred item 2 ✅)
- [x] 4.4 Emit the unified illustration manifest record with stable region IDs from source
  provenance; include artwork units in the translation request up front. (R5.6)
  — `inventoryArtworkRegions()` + manifest-translation merge into item_translations by stable
  artwork id, consumed by attachTargetsById at repair time. (deferred item 3 ✅)
- [x] 4.5 `attachTargetsById()` replaces whole-page/line-index fallbacks; missing target ⇒
  issue + block. (R5.6, R8)
- [x] 4.6 Verify: native paragraph + raster sign (both translated, paragraph not
  rasterized); dense page + small label (label inventoried); two signs distinct targets. (R11 matrix)
  — IllustrationDeferredItemsTest (5) + IllustrationRegionOwnershipTest (4) + classifier suite (5).

## Phase 5 — Artwork-preserving repair + coordinate transforms (R6, R7)
- [x] 5.1 Native text removal preserves images/line art (no white rectangle). (R6.1)
  — surgical path edits only the owning image XObject; native vector text/line-art untouched.
- [x] 5.2 Raster repair: edit correct image instance (isolate if reused) or local patch +
  letter-shaped mask; background method by sampled type (flat/gradient/textured). (R6.2, R6.3)
  — `artwork_repair.repair_page_surgical`; reused-xref isolation via page-local overlay (proven
  pixel-identical on the untouched page); letter-shaped PIL luminance mask; flat/gradient/textured fill.
- [x] 5.3 `CropTransform` with explicit source→render→crop→model→patch mapping after
  rotation/image-matrix normalization; use actual returned size; resize to source crop
  before composite; degenerate ⇒ `LayoutReviewRequired`. (R7.1–R7.4)
  — `scripts/crop_transform.py` (12 tests); uses image's ACTUAL embedded size, degenerate dims fail closed.
- [x] 5.4 Rotated/perspective ⇒ transformed path or review; full-page flatten only as
  reviewed fallback preserving boxes/rotation/labels/links. (R6.5, R7.5)
  — surgical repair is rotation-correct (edits image's own pixel space, xref re-embed re-applies
  page rotation; proven on a 90° page). Full-page flatten demoted to opt-in `--allow-flatten`,
  sets requires_review=True.
- [x] 5.5 Generative repair always a mandatory-review approximation. (R6.6)
  — generative composite forces `requires_review=True` in the (flatten) path that hosts it.
- [x] 5.6 Verify: flat/gradient/complex artwork; image reused across pages (only intended
  instance changes); rotated/CropBox/landscape/hi-res round-trip. (R11 matrix)
  — covered by test_artwork_repair (flat/gradient/mask/reused-isolation/rotated/landscape) +
  test_crop_transform (hi-res/actual-size/degenerate). Verified surgical repair on REAL My House
  p7 (xref 41, actual 1713x2028) preserving native text, not flattened.

## Phase 6 — Structured fail-closed QA + versioned staged commits (R8, R9)
- [x] 6.1 Machine-readable QA result (`status`, `issues[]` with code/stage, `checks` map,
  `requires_artwork_approval`); distinguish not-run from passed from failed. (R8.3)
  — `app/Services/Qa/QaReport.php`; required checks structure/fit/target_mapping. (12 unit tests)
- [x] 6.2 Monotonic merge across all passes; publish eligibility computed last; missing/
  invalid QA ⇒ not publishable. (R8.4)
  — QaReport::merge/setCheck never un-fail; empty report = not publishable; createTranslatedPdf
  now feeds every gate (engine/overflow/unresolved-spans/illustration/visual/pdfjs) into ONE
  QaReport and cross-checks it last (fail closed if it disagrees with the legacy flag).
- [x] 6.3 Persist via Laravel array-cast (`['qa_report'=>$report]`) + decoder for existing
  double-encoded reports. (R8.5)
  — fixed BOTH json_encode persistence sites; Translation::decodeQaReport() recovers legacy
  double-encoded rows; canBePublished routed through it. (4 feature tests, no diagnostic loss)
- [x] 6.4 Independent PDF.js visual check on final output for covers/masks. (R8.6)
  — render_pdfjs.mjs extended with blank/washed detection (near-white frac + variance);
  pdfJsVisualCheck() runs on cover + flagged pages, feeds QaReport. Config-gated; not_run when
  node unavailable (never a false pass). (4 node tests: blank flagged, content cleared)
- [x] 6.5 Render to UNIQUE staging path; keep prior public edition until publishable;
  coherent PDF+DB commit with recovery; persist final actual path before resolve. (R9.1, R9.5)
  — renders to books/staging/<id>_<lang>_<rand>.pdf; promotes staging→public only on success;
  promotion failure keeps prior edition + routes to review; final path persisted before resolve.
- [x] 6.6 Render fingerprint (source/manifest ver, target IDs/values, font hashes, fit
  policies, repair revisions, engine ver); tie approvals to it; invalidate on input change;
  text change invalidates narration; idempotent + per-edition locking. (R9.2–R9.4)
  — `app/Services/Qa/RenderFingerprint.php` (deterministic, order-independent); migration adds
  render_fingerprint + narration_fingerprint; fp change clears page_approvals; narration sub-hash
  independent of layout; TranslateEditionJob wrapped in Cache::lock per edition.
- [x] 6.7 Verify: detector/API/repair/verify failure keeps prior edition + blocks; missing
  target/overflow blocks; rerun identical inputs ⇒ no drift. (R11 matrix)
  — RenderVersioningTest (5) + QaReportTest (12) + QaReportPersistenceTest (4). NOTE: full LIVE
  engine round-trip (real translate+render with illustration enabled) is the remaining
  integration proof — deterministic units all green; see completion note.

## Phase 7 — Admin review experience (R10)
- [x] 7.1 Extend the `overlay-data` engine command to emit the new boxes (source ink /
  container / mask / glyph bounds / protected artwork). (R10.1)
  — build_overlay_data now emits layoutContainer (defaults to safeInnerBounds), targetGlyphBounds,
  eraseMask, protectedArtwork, contentClass. (test_overlay_boxes 3/3)
- [x] 7.2 ReviewQueue: original/translated previews + toggleable overlays; show effective
  font/size/alignment/target/diagnostic reason/policy origin. (R10.1, R10.2)
  — DONE: interactive overlay layer (Alpine `overlayLayer`) draws source-ink/container/mask/
  glyph/protected-artwork boxes over the translated PNG with per-type toggles; selected-region
  info panel shows type/content-class/visual-scale/line-height/clipped/issues. loadOverlay()
  pulls overlay-data on demand. (ReviewQueueOverlayTest)
- [x] 7.3 Per-region edits (container, paragraph grouping, policy, mask, font role, text)
  write to canonical manifest/overrides (no parallel store); edits invalidate the
  fingerprint/approvals. (R10.3, R10.5)
  — ReviewQueue::updateRegion writes to layout_overrides[regionId] (the canonical store the
  contract resolver + attachTargetsById already consume), re-renders via the single path,
  invalidates layout+artwork tracks (+language+narration on a text change).
- [x] 7.4 One-time background approval reusable across languages; separate language/layout/
  artwork approval tracks; `FontManager` persists + previews policy. (R10.4)
  — Translation approval TRACKS (language/layout/artwork) each tied to render_fingerprint;
  isTrackApproved returns false when stale; invalidateStaleApprovalTracks sweep; migration
  2026_10_02_000002. FontManager policy persistence done in Phase 3. (ApprovalTracksTest 5/5)
  Cross-language cleaned-artwork reuse (R10.4 "one-time background reusable across languages")
  implemented as XL1 (book-level shared_artwork store + seed on render).

## Phase 8 — Verification corpus + evidence (R11)
- [~] 8.1 Assemble a multi-publisher / multi-size / multi-layout corpus (incl. a
  complex-script fixture + a rotated/landscape fixture). (R11.1)
  — scripts/corpus_fixtures.py generates deterministic SYNTHETIC fixtures (landscape, rotated,
  RTL, multi-size A4/A5/square, reused-image, gradient) + uses the real Kolulu books (2 sizes)
  + Math Fun (Letter). HONEST GAP: real source is ONE publisher — true MULTI-PUBLISHER corpus
  needs third-party PDFs from Captain Zan (flagged in COMPLETION_REPORT + Remaining).
- [x] 8.2 Turn every acceptance-matrix row into a meaningful-output test (geometry/
  containment/fidelity, not "helper called"). (R11.2, R11.3)
  — scripts/test_acceptance_matrix.py (11): flat/gradient repair, reused-image isolation,
  rotated/landscape round-trip, hi-res actual-size (real My House p7), degenerate→review,
  unowned→fail-closed, multi-size, RTL, real-p2 presence.
- [x] 8.3 Retain before/after PDF-renderer + PDF.js evidence for covers, masks, p2. (R11.4)
  — scripts/capture_evidence.py captures both PyMuPDF + PDF.js PNGs (with blank/variance
  signal) for any pages; verified on My House cover/p2/story.
- [x] 8.4 Completion report: changed files, behavior, pass/fail, visual samples, remaining
  unsupported cases, new deps — no claim of universal automatic fidelity. (R11.5)
  — COMPLETION_REPORT.md: phase table, invariants, full test tally, HONEST corpus scope,
  deps, and explicit Remaining (multi-publisher corpus + LV1–LV6 live pass).

---

## ⚠️ DEFERRED LIVE VERIFICATION — DO THIS AFTER ALL CODING, BEFORE SHIPPING
Captain Zan's directive (2026-10-02): finish ALL coding across the phases first, THEN run
the live, API-burning end-to-end tests as ONE deliberate pass. These require real OpenAI
calls and a real book, so they are intentionally NOT run per-phase. This list MUST NOT be
dropped — it is the integration proof the deterministic unit/feature tests cannot give.

- [ ] LV1 — Full live engine round-trip: real `translateWithManifest` + `createTranslatedPdf`
  on a real book (e.g. My House #10000, af) with `bookstore.illustration_text.enabled=true`,
  exercising the WHOLE Phase-6 chain end to end: staged render → staging→public promotion →
  QaReport monotonic merge → render fingerprint → approval invalidation. Confirm a failed
  render keeps the prior public edition. (Phase 6.7 remaining integration proof.)
- [ ] LV2 — Up-front artwork inventory (Phase-4 item 3) LIVE: with illustration enabled,
  confirm artwork region ids land in `item_translations` from the translation pass and are
  consumed by `attachTargetsById` at repair time (end to end, not just unit-wired).
- [ ] LV3 — Illustration surgical repair LIVE on a book that genuinely has baked-in artwork
  text (GPT-4o detect → classify → surgical repair → overlay), verifying no page flatten,
  native text preserved, reused-image isolation on real data.
- [ ] LV4 — PDF.js visual check (6.4) LIVE on real covers/masks with `pdfjs_check.enabled`.
- [ ] LV5 — Rerun-identical-inputs drift check LIVE (R9.4): same inputs twice ⇒ identical
  fingerprint, no duplicate units, no compounded inpaint.
- [ ] LV6 — Browser-drive the ReviewQueue overlay LIVE (Dusk/Playwright): toggle each box
  layer, drag a container box, confirm updateRegionContainer persists the px→pt override and
  the re-render reflects it. (The Alpine drag interaction can't be unit-tested server-side.)

## FOLLOW-ON UI / CROSS-LANGUAGE — DONE
- [x] UI1 — Interactive toggleable overlay LAYER in ReviewQueue (Alpine overlayLayer): boxes
  over the page PNG with per-type toggles + effective region info panel. (ReviewQueueOverlayTest)
- [x] UI2 — Reviewer drag-to-edit container: overlayLayer drag → updateRegionContainer()
  (px→pt) → updateRegion() container override → re-render. Degenerate drags ignored.
- [x] XL1 — One-time cleaned-artwork reuse ACROSS languages: reuseArtworkAcrossLanguages()
  promotes language-independent artwork overrides (container/mask/cleaned_bg/content_class, NOT
  per-language text) to book.metadata['shared_artwork']; createTranslatedPdf seeds them into a
  new edition's layout_overrides. Requires artwork track approved first. (ReviewQueueOverlayTest)

---

## Sequencing notes
- Phases 1–3 harden the NATIVE text path (the thing broken in your screenshot) and are the
  highest value for the least risk. Phase 1 alone fixes the p2 defect and makes the gate
  honest.
- Phases 4–5 add the ILLUSTRATION (baked-in) text capability — a genuinely new feature,
  heavier, and dependent on the font policy (Phase 3) and fit/transform machinery.
- Phases 6–7 are the publishing-safety + reviewer layer that make it production-grade.
- Phase 8 is the proof. Do not claim "world-class" before the corpus passes.
- Any phase can ship independently; each leaves the engine green and the single production
  path intact.
```
