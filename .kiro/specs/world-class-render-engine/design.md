# Design — World-Class Book-Agnostic Rendering Engine

## Overview

Extend the existing V8 scene-graph + stable-ID-contract architecture (do NOT replace it)
so that:

1. every placeable element owns a **resolved layout container** (not a per-unit guess);
2. **paragraphs** are first-class (identity + grouping), so a translated block flows as one
   unit in its column;
3. the engine **fits before it erases**, so overflowing text is never drawn and a region
   is never cleared without a proven replacement;
4. **fonts** are an explicit per-book/edition/role policy shared by every render path;
5. **illustration (baked-in) text** is owned at region level, translated via the same
   contract, and repaired without harming surrounding artwork;
6. QA is **structured, self-verifying, and fail-closed**, merging monotonically across all
   passes;
7. artifacts are **versioned and staged**, with approvals tied to a render fingerprint.

The guiding frame: the scene graph is the source of truth (unchanged), but today it is
truthful only for *table* geometry. This design makes it equally truthful for
*non-table* geometry (containers + paragraphs) and adds a comparison gate that actually
detects the defects a human sees.

## Current-state anchor (verified on My House p2)

```
copyright_debug[2] = {has_scene: true, has_contract: true}   # generic placer DOES run
scene_generic[2]   = {units:15, placed:15, overflow:[], unresolved:[]}  # false success
structure_gate.ok  = true                                     # gate is blind to the bleed
every p2 unit: cell_box = none, align_h = -                   # NO container on this page
bio (x=321..477) fragmented into 3 units: s0015 / s0016(over-merged) / s0024("self.")
```

Three root causes, mapped to requirements:
- **No container** on non-table pages → R1. The generic placer rebuilds a box from raw
  bbox and the center branch does `box_left = x0-(x1-x0)`, `box_right = x1+(x1-x0)` →
  page-wide bleed. Fix: resolve containers; alignment positions, never resizes.
- **Paragraph fragmentation** → R2. `_merge_continuation_spans` is a line heuristic with no
  paragraph concept; it over-merges and strands. Fix: real paragraph model.
- **Blind gate** → R8. Fix: comparison gate checks containment + overlap + no-growth.

## Architecture

```
                          ┌───────────────────────── IMMUTABLE SOURCE PDF ─────────────────────────┐
                          │                                                                         │
        PdfService.import │                                                                         │ (I5)
                          ▼                                                                         │
      ┌─────────────────────────────────────┐        ┌──────────────────────────────────────┐     │
      │  StructureAnalyzer (document_model)  │        │  IllustrationInventory (source only) │     │
      │  • spans → paragraphs (R2)           │        │  • content class: native/vector/raster│    │
      │  • regions → layout containers (R1)  │        │  • region-level ownership (R5)        │     │
      │  • ink box / container / safe box    │        │  • stable region IDs, coverage result │     │
      └───────────────┬──────────────────────┘        └──────────────────┬───────────────────┘     │
                      │   DocumentScene (one source of truth)             │                         │
                      └───────────────┬───────────────────────────────────┘                         │
                                      ▼                                                              │
                       ┌────────────────────────────────┐   stable-ID contract (native + artwork)   │
                       │  TranslationService             │◄──────────────────────────────────────────┘
                       │  • translate ALL units by ID    │
                       └───────────────┬─────────────────┘
                                       ▼
            ┌──────────────────────────────────────────────────────────────────┐
            │  PdfTranslationService (orchestrator)                              │
            │  • FontPolicyResolver (R4) ── one policy, both paths               │
            │  • fit-before-erase (R3) via text_fit_solver                       │
            │  • render → UNIQUE STAGING pdf (R9)                                │
            │  • merge QA monotonically (R8)                                     │
            └───────────────┬───────────────────────────────┬──────────────────┘
                            ▼                                 ▼
         ┌───────────────────────────────┐    ┌────────────────────────────────────┐
         │ pdf_translate_v8 (native path)│    │ illustration_text (artwork path)     │
         │ • render_page_from_container  │    │ • fit → local repair → overlay (R6/7)│
         │   (bounded, paragraph-aware)  │    │ • coordinate transforms (R7)         │
         │ • draw_paragraph_text (prim.) │    │ • same FontPolicy (R4)               │
         └───────────────┬───────────────┘    └──────────────────┬───────────────────┘
                         ▼                                        ▼
            ┌──────────────────────────────────────────────────────────────────┐
            │  QA / Comparison gates (render_gate + visual) — FAIL CLOSED (R8)   │
            │  present · in-safe-box · no-overlap · align-didn't-grow · font ·   │
            │  residue · target-match · artwork-unchanged-outside-mask           │
            └───────────────┬───────────────────────────────────────────────────┘
                            ▼
            publishable? → commit staging→public coherently + fingerprint (R9)
                            │ else keep prior edition, route to ReviewQueue (R10)
                            ▼
                      Admin review overlays (R10)
```

Everything still funnels through the one placement primitive `draw_paragraph_text` (I2).

## Components

### 1. StructureAnalyzer — containers + paragraphs (R1, R2)
Extend `scripts/document_model.py`.

- **New boxes on `TextUnit`/`Region`:** keep `bbox` as the source ink box; ADD
  `layout_container` and `safe_box`. A new `Paragraph` grouping (or `paragraph_id` +
  `para_role`) ties continuation units together.
- **Container resolution (non-table):** generalize the existing table-grid path. For a
  generic/copyright page, cluster units into regions by column (left-edge + reading order
  + style), bound each region by its own units' extent AND by neighbouring regions /
  images (artwork exclusions), and set `layout_container` to that bounded region — never
  the page. `safe_box = layout_container.inset(padding)`.
- **`Bounds` helper (from the brief):** `Bounds.validate / intersect / inset` +
  `resolve_safe_box(container, page_box, pad_x, pad_y)` raising `LayoutReviewRequired`.
  Put it in the geometry layer of `document_model.py` (NOT a new competing module, I2).
- **Paragraph grouping replaces the brittle merge:** `_merge_continuation_spans` becomes a
  *paragraph builder* — group by style + column + leading + reading order; keep publisher
  metadata lines as separate fields; keep an end-marker separate. The over-merge + strand
  on p2 (s0015/s0016/s0024) must become ONE bio paragraph.
- **Alignment inference** uses the paragraph's own lines (plural), never one merged bbox,
  and feeds only placement — never box size.

### 2. Renderer — bounded, paragraph-aware placement (R1, R2, R3)
Refactor `render_page_from_scene_generic` in `scripts/pdf_translate_v8.py`.

- Replace the per-unit box reconstruction + center-expansion branch with: for each
  paragraph, take its resolved `safe_box`, run fit (R3), then `draw_paragraph_text` with
  the derived alignment and a hard clip to the safe box. Alignment positions within the
  box; it may not change `box_left`/`box_right`.
- A genuinely centered title still centers — but within its own container only.
- The copyright page then uses the SAME container+paragraph path the vocab page uses →
  one code path, book-agnostic.

### 3. FitSolver adapter — fit before erase (R3)
Use the existing `scripts/text_fit_solver.py` (`FitConstraints`, `solve_text_fit`). Add
`prepare_region_layout(region, target, font_path, policy)` that resolves the safe box,
solves the fit, and raises `LayoutReviewRequired("TRANSLATED_TEXT_DOES_NOT_FIT")` on
no-fit. The orchestrator calls this BEFORE any redaction/overlay. Measurement + draw use
the same resolved font + shaping (no HarfBuzz-measure / unshaped-draw mismatch).

### 4. FontPolicyResolver — explicit fonts (R4)
New resolver (PHP side in `PdfTranslationService` + a thin Python mirror for the engine's
font selection). Precedence: unit → edition role → book role → publisher default → source
font → approved fallback. Config lives in `Book.metadata.typography_policy` (+ an edition
JSON field if needed), fonts referenced by **validated asset ID**. The resolved render
spec (font hash, face/weight/style, script, direction, size, line spacing, fit policy) is
persisted and shared by BOTH the native renderer and `illustration_text` (one `_pick_font`
source of truth). The env `STORY_BODY_FONT` stays only as a publisher default.

### 5. IllustrationInventory + ownership (R5)
Rework `IllustrationTextService` + `scripts/illustration_text.py`.

- Classify each region into native / outlined-vector / raster-baked from the SOURCE,
  comparing detected text to source PDF text objects + OCR geometry (I5).
- Replace whole-page `contractOwnedPages()` with **region-level ownership**; delete the
  misleading no-op `filterNativeText()`; implement real overlap/object-ownership
  classification.
- Every page records a coverage result (scanned / no-candidate / deferred / unresolved);
  the cost heuristic only triages, never decides "no artwork text."
- Emit the unified manifest record (stable region ID, source_kind, boxes, mask asset,
  image ref, policy, confidence, review_status) and include artwork units in the
  translation request up front (R5.6) via `attachTargetsById` (replace whole-page/line-
  index fallbacks).

### 6. Artwork-preserving repair + transforms (R6, R7)
In `scripts/illustration_text.py` / `illustration_genmask.py` / `illustration_genvalidate.py`:

- Native text: object-level removal preserving images/line art (no white rectangle).
- Raster text: edit the correct image instance (isolate if reused) or composite a
  validated local patch with a letter-shaped mask; background method chosen by sampled
  background type (flat / gradient / textured) — no median-strip assumption.
- Explicit `CropTransform` (source pts → render px → crop px → model px → source patch px)
  after rotation/image-matrix normalization; use the model's ACTUAL returned size; resize
  to source crop before masked composite; degenerate transform → `LayoutReviewRequired`.
- Full-page flatten is a reviewed fallback only, preserving boxes/rotation/labels/links.
- Generative repair is always a reviewed approximation (mandatory artwork review state).

### 7. Self-verifying, fail-closed QA (R8)
Extend `render_gate.py` + `VisualQaService`.

- Comparison gate adds the defect classes the current gate misses: **element outside its
  source column**, **container grew via alignment** (rendered box wider than resolved
  container), **paragraph fragment at a stale anchor**, **neighbour/artwork overlap**. The
  p2 render must now FLAG (not report `ok=true`).
- Machine-readable result with per-region `{page_number, region_id, code, stage}` + a
  `checks` map (not_run / passed / failed). Merge monotonically across every pass; compute
  publish eligibility last; missing/invalid QA ⇒ not publishable.
- Persist via Laravel array-cast (`['qa_report' => $report]`) + a decoder for existing
  double-encoded reports.
- Independent PDF.js visual check on final output for covers/masks.

### 8. Versioned, staged commits (R9)
In `PdfTranslationService` + `Translation` model: render to a unique staging path; keep the
current public edition until QA is complete and publishable; persist a render fingerprint
(source/manifest version, target text IDs/values, font hashes, fit policies, repair
revisions, engine version); tie approvals to the fingerprint; invalidate approvals (and
narration on text change) when inputs change; idempotent regeneration with per-edition job
locking; coherent PDF+DB commit with recovery.

### 9. Admin review (R10)
Extend `app/Livewire/Admin/ReviewQueue.php` + `FontManager.php`: original/translated
previews; toggleable overlays (source ink / container / mask / glyph bounds / protected
artwork) via the existing `overlay-data` engine command (extend it with the new boxes);
per-region edits (container, paragraph grouping, policy, mask, font role, text) writing to
the canonical manifest/overrides; one-time background approval reusable across languages;
separate language / layout / artwork approval tracks.

## Key decisions

- **Extend, don't replace.** All work lands on the existing scene graph, contract, gate,
  and the single `draw_paragraph_text` primitive (I2). No second renderer.
- **Alignment positions, never resizes** — the one-line conceptual fix behind the whole p2
  bug (R1.4). Everything else in R1/R2 makes that correct and general.
- **Containers + paragraphs are derived** from geometry/style/reading order (I4), bounded
  by neighbours + artwork, failing closed when unresolved (I3).
- **One font policy** feeds both the native and artwork paths (R4.4) — the current split
  (`STORY_BODY_FONT` vs illustration `_pick_font`) is the bug.
- **The gate must see the defect.** A world-class engine's defining property here is
  self-verification: the p2 page must flag itself (R8.2). This is the difference between
  "renders and hopes" and "renders and proves."
- **Deterministic first.** Per the brief's sequencing: fix identity, ownership, geometry,
  and fonts BEFORE adding any more vision-model calls.

## Error handling / fail-closed
`LayoutReviewRequired` is the single fail-closed signal from the geometry/fit layer; the
orchestrator catches it, preserves the prior edition, records the per-region issue, and
routes the page to review. Unresolved container, no-fit, degenerate transform, missing
target, unsupported glyph, overlap, or any not-run required stage ⇒ NOT publishable.

## Acceptance matrix (meaningful-output tests, R11)

| Fixture / scenario | Required outcome |
| --- | --- |
| My House p2 (af) | Bio stays in right container; copyright in own left container; trailing fragment belongs to bio; NO overlap; gate FLAGS the pre-fix render. |
| Same source, 2 approved fonts | Both measured with the selected font; fit changes safely; container boundaries fixed. |
| Longer / shorter target paragraphs | Reflow within container; no cross-container, no arbitrary growth of short text. |
| Native paragraph + raster sign | Both translated; ordinary paragraph NOT rasterized. |
| Dense native page + small label | Label inventoried despite cost heuristic. |
| Two signs, distinct translations | Each gets its own target by stable ID; no page-string repeat. |
| Flat / gradient / complex artwork | Correct repair method; uncertain structure ⇒ review. |
| Image reused on multiple pages | Only intended instance changes. |
| Rotated / CropBox / landscape / hi-res image | Coordinate round-trip + patch placement correct. |
| Multi-line title vs single-line label | Role-specific wrapping respected. |
| Arabic / complex script | Correct shaping/direction + glyph coverage, or explicit review. |
| Outlined lettering / hidden OCR layer | Correct content class; no "delete text layer removes visible lettering" assumption. |
| Detector/API/repair/verify failure | Prior stable edition retained; new edition NOT publishable. |
| Missing target / overflow | Region not erased; issue persisted; edition blocked. |
| Generative return at different resolution | Mask + patch transformed correctly; mandatory artwork review. |
| Font/text/policy change after approval | Affected approvals invalidated; text change invalidates narration. |
| Rerun identical inputs | No drift, duplicate units, or compounded inpainting. |

## Rollout (maps to tasks.md)
1. Container + paragraph model + bounded generic renderer; reproduce + fix p2; teach the
   gate to flag it. (R1, R2, R8-partial) — **ships the visible fix first.**
2. Fit-before-erase adapter wired into the native path. (R3)
3. Shared font policy across both paths. (R4)
4. Illustration inventory + region ownership + per-ID artwork translation. (R5)
5. Artwork-preserving repair + coordinate transforms. (R6, R7)
6. Structured fail-closed QA merge + versioned staged commits. (R8, R9)
7. Admin overlays + policy editing. (R10)
8. Verification corpus + evidence. (R11)
