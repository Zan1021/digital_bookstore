# Requirements — V8 Structure-Aware, Compare-Driven Engine

## Introduction

The V8 PDF translation engine currently extracts text spans, translates a flat per-page
text blob, and re-renders by geometric heuristics. Because it holds no model of the
source's intended structure and never compares its output back to that structure, it
produces recurring layout defects that only a human catches by eye:

- logical text units split or merged wrongly (a wrapped phonics rule fragmented; a
  standalone "The End" glued to the preceding sentence);
- table headers not centered horizontally/vertically, and merged (column-spanning)
  headers mis-placed; multi-line headers overflowing their cell;
- inconsistent text sizes within a peer group;
- fallback fonts substituted silently.

This feature makes the engine **structure-aware** (it models the source's real layout:
roles, boxes, merged cells, header rows, end-markers) and **compare-driven** (after
rendering it verifies each element against its source counterpart and fails closed on
any deviation). It is book-agnostic and applies to any uploaded PDF.

## Glossary
- **Structural element**: an atomic source item with a stable ID and role (e.g.
  `header-of-column`, `word_list_item`, `story_sentence`, `end_marker`, `title`).
- **Region**: a semantic grouping of elements (a table column, header row, story block).
- **Cell box**: the true geometric box an element must render within, derived from grid
  lines (including merged/spanning cells).
- **Comparison gate**: the post-render check that the output structurally matches the
  source.

## Requirements

### Requirement 1 — Source structure model
**User story:** As the engine, I want a faithful model of the source document's
structure, so that I know what each element is and where it belongs before rendering.

#### Acceptance Criteria
1. WHEN a PDF is analysed THEN the engine SHALL produce, per page, a set of structural
   elements each with a stable ID, a semantic role, a source bounding box, a baseline,
   and the region it belongs to.
2. WHEN a table page has grid lines THEN the engine SHALL derive cell boxes from the
   grid, INCLUDING merged/column-spanning header cells (a header covering N columns is
   ONE element whose box spans those N columns).
3. WHEN a header row is taller than one line THEN the engine SHALL record the full
   header-row box so multi-line headers can be vertically centered within it.
4. WHEN consecutive lines form one logical entry (a wrapped sentence/rule) THEN the
   engine SHALL represent them as ONE element; WHEN lines are distinct entries (single
   words, `pattern - examples` rules, an end-marker) THEN each SHALL be its own element.
5. WHEN an element is a document end-marker (e.g. "The End") THEN the engine SHALL
   classify it as `end_marker`, distinct from the preceding sentence.
6. The model SHALL be book-agnostic: derived from detected geometry/content only.

### Requirement 2 — Per-element translation contract (live)
**User story:** As the engine, I want the translation attached to each structural
element by ID, so that rendering never re-segments flat text by guessing.

#### Acceptance Criteria
1. WHEN rendering a translated edition THEN the engine SHALL consume a stable-ID
   contract mapping element ID -> translated text (with source_text + role + page).
2. WHEN the contract is present THEN the flat-text line-position mapper SHALL NOT run.
3. WHEN only flat per-page text exists (legacy data) THEN the engine SHALL derive a
   best-effort per-element mapping via source-text/geometry matching AND flag the page
   as `LEGACY_FLAT_MAPPING` for review.
4. The PHP translation flow SHALL be able to produce and persist the per-element
   contract for an edition (item 2.2), replacing flat `translated_pages` as the render
   source.

### Requirement 3 — Structure-preserving placement
**User story:** As a reader, I want each translated element placed exactly where and how
the source had it, so the translated book matches the original design.

#### Acceptance Criteria
1. WHEN placing an element THEN the engine SHALL render it inside its source cell box,
   clipped so no glyph crosses a border.
2. WHEN the source element is centered in its cell (h and/or v) THEN the translation
   SHALL be centered the same way; WHEN left/right aligned, mirror that. Alignment is
   DERIVED from the source, never hand-set.
3. WHEN a header spans multiple columns THEN the translation SHALL be centered across
   the FULL spanned box, not a sub-column.
4. WHEN a multi-line header is placed THEN it SHALL be vertically centered within the
   header-row box and SHALL NOT overflow into the content area.
5. WHEN elements form a peer group (same role in a region) THEN they SHALL render at ONE
   consistent size; per-cell fitting is allowed only where the source itself varies.
6. Text SHALL render in the source-matched/house font (correct weight, casing) with a
   real searchable text layer (no fallback fonts, no corrupt ToUnicode).

### Requirement 4 — Structural comparison gate
**User story:** As Captain Zan, I want the engine to catch layout mistakes itself, so I
don't have to find them by eye.

#### Acceptance Criteria
1. AFTER rendering THEN the engine SHALL compare the output to the source structure and
   verify, per element: it is present; it lies within its source cell box; its alignment
   (h+v) matches the source within tolerance; its size is consistent with its peers; its
   drawn font is approved (not a fallback).
2. WHEN any element fails THEN its page SHALL be flagged NEEDS_LAYOUT_REVIEW with a
   specific, per-element reason, and the edition SHALL NOT be publishable (fail closed).
3. WHEN the source had N elements in a region THEN the output SHALL have N (no dropped or
   invented elements); a mismatch SHALL flag the page.
4. The gate's element/coverage accounting SHALL count logical elements (not raw
   insert-calls), so a correctly placed multi-line element is not a false "gap".
5. The gate SHALL be book-agnostic and covered by tests proving it both PASSES a good
   render and FLAGS each defect class (mis-placed header, overflow, size mismatch,
   fallback font, dropped/added element, end-marker glued to sentence).

### Requirement 5 — Verification & rollout
**User story:** As the team, I want confidence the new engine is correct and that the old
heuristic path is gone.

#### Acceptance Criteria
1. The engine SHALL be verified end-to-end on at least TWO different books.
2. WHEN the structure-driven path replaces a heuristic path THEN the heuristic path SHALL
   be removed or provably isolated (no competing renderers/mappers live at once, R2).
3. The full test suite SHALL pass, with new tests for the structure model, placement,
   and the comparison gate.
4. Diagnostics/admin overlay SHALL expose the per-element comparison result so a reviewer
   can see exactly which element deviated and why.
