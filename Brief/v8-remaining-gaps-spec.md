# V8 Engine — Remaining-Gaps Spec Sheet (to reach genuine 100%)

**Date:** 2026-08-29
**Basis:** Honest audit of implemented work vs `.kiro/steering/v8-brief-compliance.md`
and `Brief/kiro-v8-rendering-engine-overflow-fix-brief.md`.
**Status legend:** ✅ done · ◑ partial · ❌ not done

This sheet lists ONLY what is still outstanding. Everything not listed here is done
and verified (Phase 1 fully; 2.3, 2.7; 3.2, 3.4, 3.5(core), 3.6, 3.7, 3.8, 3.9).

---

## GAP 1 — 2.1 Wire the region-graph pipeline into production  ◑
**Current:** `pdf_translate_v8.py` emits a scene *record* into the report, but
production does NOT route through `document_model.build_document_scene` +
`scene_renderer.render_from_scene`. Two renderers exist (live + dormant).
**Required:** ONE region-graph-driven path in production (either lift scene logic
into the live engine or wire scene_renderer with all Phase 1/2 fixes ported).
**Acceptance:** production render uses the region graph; `scene_renderer.py` is either
the live path or deleted (R2 — no dormant duplicate); all 90 tests still green; p15/p16
still correct.
**Est:** L (invasive; changes engine entry).

## GAP 2 — 2.2 Stable-ID translation contract from PHP  ◑
**Current:** PHP `buildTranslationsJson` sends flat `{page_number, translated_text}`.
Stable IDs are derived engine-side from geometry. Legacy re-split methods removed ✅.
**Required (§7/§8):** PHP emits manifest ITEMS with stable IDs
(page→region→item→translation→rendered object); engine consumes IDs directly; line/
paragraph/list boundaries preserved through the contract, not reconstructed.
**Acceptance:** translations JSON contains per-item IDs; engine maps by ID (no
string reconstruction); re-render of an edited item updates only that item.
**Est:** L (touches PHP translate flow + possibly re-translation format).

## GAP 3 — 2.4 Visual-size matching to spec + shaping + approved-font enforcement  ◑
**Current:** visual-size match uses ascender−descender proxy; no `text_shaping`;
fallback only fails when NO font exists.
**Required (§9.1/§9.2/§9.3):**
- Match apparent size via **cap-height / x-height / median glyph height** (not asc−desc).
- **Shape before measuring** (wire `text_shaping.py`): kerning/ligatures/combining marks.
- Maintain an **approved-font list**; FAIL (review) on any unapproved fallback; record
  `fontRequested`, `resolvedFamily`, `fontFileHash`, `fallbackUsed`.
**Acceptance:** unit test proves cap-height-based sizing keeps apparent size within
tolerance across two fonts; unapproved fallback => NEEDS_LAYOUT_REVIEW.
**Est:** M.

## GAP 4 — 2.5 Full controlled fitting ladder  ◑
**Current:** wrap → shrink → route-to-review.
**Required (§9.5) full order:** (1) preferred font+group size, (2) preserve semantic
breaks, (3) rewrap in item, (4) **tracking within approved range**, (5) **line-spacing
within approved range**, (6) shrink to min, (7) **approved metric-compatible alternate
font**, (8) **request shorter translation** (flag), (9) route to manual review.
Never paint outside region.
**Acceptance:** solver applies steps 4,5,7,8 before flagging; unit tests for each step.
**Est:** M.

## GAP 5 — 2.6 Minimum readability + typography hierarchy validation  ❌
**Current:** hard 7pt floor only; no hierarchy checks.
**Required (§10.1/§10.2):**
- Min sizes configurable by document type / market / output format.
- Validate `headingVisualSize > bodyVisualSize`, `tableHeader ≥ tableBody`,
  `peerRegionVariance ≤ tolerance`. Fail region if min size reached without valid fit.
**Acceptance:** gate flags a page where a heading rendered ≤ body size, or peer columns
vary beyond tolerance; unit tests cover it.
**Est:** M.

## GAP 6 — 3.1 Diagnostic manifest: remaining §14 fields  ◑
**Current:** has page/type/status/units/overflowX/borderIntersections/collisions/
fitStatus/failureReasons/font hash.
**Missing fields:** `regionId`, `semanticType`, `sourceBounds`, `safeInnerBounds`,
`visualScaleRatio`, `lineHeight`, `tracking`, `sourceLineCount`, `semanticItemCount`,
`renderedLineCount`, `renderedGlyphBounds`, `clippedGlyphCount`.
**Acceptance:** manifest contains every §14 field per region; admin panel can show them.
**Est:** S–M.

## GAP 7 — 3.3 Interactive admin layout-debug overlay  ◑ (biggest UX gap)
**Current:** a diagnostic TABLE (status/reasons/font) on the reviewer.
**Required (§15):** visual overlay on the rendered page image with TOGGLES for source
boxes / safe inner boxes / table borders / rendered glyph bounds / reading order /
collision regions / clipped areas / font info / confidence; invalid regions in red.
Per-region actions: edit translation, pick approved font, adjust size/tracking/
line-height within limits, edit region boundary, split/merge with audit, **re-render
affected page only**, side-by-side source vs translation. Overrides stored EDITION-
specific (not code).
**Acceptance:** reviewer can toggle overlays on a page image, click a red region, edit
it, and re-render just that page; overrides persist per edition.
**Est:** L (frontend + per-region override storage + single-page render endpoint).

## GAP 8 — 3.5 Golden-page library: full layout-family coverage  ◑
**Current:** centred, multi-column, long-word fixtures.
**Missing families (§17.2):** merged cells, borderless tables, 4-column, justified,
text-over-illustration, irregular shapes, rotated text, 30–80%-longer translations,
mixed font families, missing embedded fonts, RTL, complex scripts, scanned, vector,
crop-marked, landscape, double-page spread.
**Acceptance:** a fixture + assertion per family; each verifies geometry AND a rendered
image; suite green.
**Est:** M (many fixtures).

## GAP 9 — Verification on a 2nd book end-to-end  ❌ (Definition of Done blocker)
**Current:** only ONE translated book (book 2) in the DB. Book-agnostic classification
proven on 4 source books, but no full translate→render→gate on a different book.
**Required (§5 DoD / R1):** upload/translate a second, structurally different book and
render it end-to-end; confirm gate passes / flags correctly; no book-specific coupling.
**Acceptance:** a 2nd book renders with correct per-page classification, passes/flag as
appropriate, no regressions.
**Est:** S (needs a 2nd book's translated_pages — may need translation run).

---

## Priority order (recommended)
1. GAP 5 (2.6 hierarchy) — pure validation, low risk, closes a real quality hole.
2. GAP 3 (2.4 cap-height + approved font) — visible typography fidelity.
3. GAP 4 (2.5 ladder) — fewer needless review flags.
4. GAP 6 (3.1 fields) — cheap, feeds the overlay.
5. GAP 8 (3.5 fixtures) — regression safety.
6. GAP 9 (2nd book) — DoD requirement.
7. GAP 7 (3.3 interactive overlay) — largest; UX.
8. GAP 1 + GAP 2 (2.1/2.2 pipeline + stable-ID contract) — most invasive; do last with full re-verify.

## Global guardrails (every gap)
- Book-agnostic (R1): no title/page/language/coordinate hardcoding.
- Single engine path, no leftover legacy (R2).
- Casing mirrors source on all pages (R3).
- Only mark a gap done when the user-visible outcome is verified (R4), ideally on 2+ books.
- Fail-closed (R5); verify after every change (R6): ast/php -l, 90+ tests green, render
  book-2 + 2nd book, visual confirm, clean temp files.
