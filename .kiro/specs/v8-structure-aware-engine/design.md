# Design — V8 Structure-Aware, Compare-Driven Engine

## Overview

Reshape the V8 render path around a single source of truth — a **DocumentStructure**
model — and add a **StructuralComparison** gate that verifies the rendered output against
that model. The translation is attached per structural element via the stable-ID
contract. Heuristic flat-text mapping becomes a flagged fallback only.

This design builds on what already exists (document_model.PageScene/TextUnit/Region, the
stable-ID contract, draw_paragraph_text, render_gate font/size checks, the
_merge_continuation_spans logical-unit merge) and closes the gaps: merged/spanning cells,
header-row boxes, end-marker roles, per-element live translation, and the comparison gate.

## Architecture

```
PDF ──▶ StructureAnalyzer ──▶ DocumentStructure ──┐
                                                  ├─▶ Renderer (per-element placement)
Contract (id→translation) ────────────────────────┘            │
                                                                ▼
                                                        Rendered PDF
                                                                │
DocumentStructure ──────────────▶ StructuralComparison ◀────────┘
                                          │
                                          ▼
                              per-element verdicts → review_pages / publishable
```

### Components

1. **StructureAnalyzer** (extend `document_model.py`)
   - Input: source PDF page. Output: `PageStructure` = regions → elements.
   - Reuses `extract_page_spans` + `_merge_continuation_spans`.
   - NEW: `detect_table_grid()` returns horizontal + vertical grid lines and CELL boxes,
     including MERGED cells (a header cell whose span crosses multiple content columns is
     one cell with `column_span > 1`).
   - NEW: `header_row_box` per table (top of table to first content row) for vertical
     centering.
   - NEW: role classifier additions — `end_marker` (short trailing phrase like
     "The End"/"Die Einde" isolated after the last sentence, detected by position +
     brevity + separation, book-agnostic), `merged_header`.

2. **ElementModel** (extend `TextUnit`/`Region`)
   - Add fields: `cell_box` (true render box incl. spanning), `align_h`, `align_v`
     (derived from source), `peer_group_id`, `column_span`, `is_merged`.

3. **Renderer** (refactor `pdf_translate_v8.py` renderers)
   - One placement primitive: `draw_paragraph_text` (already handles font/weight/casing,
     h-align, v-align, clip). Each element placed into its `cell_box` with its derived
     alignment. Merged header → box spans full width. Multi-line header → `valign=middle`
     inside `header_row_box`.
   - Peer-group size solve: one shared size per peer group (already done for back-cover;
     generalize to vocab columns + headers).
   - Driven by `id_to_translation` (contract). Legacy flat mapping only when no contract,
     and flags `LEGACY_FLAT_MAPPING`.

4. **StructuralComparison gate** (extend `render_gate.py`)
   - Input: source `DocumentStructure` + rendered PDF.
   - Per element: present? inside `cell_box`? align_h/align_v match source (tolerance)?
     size consistent within `peer_group`? drawn font approved? 
   - Region-level: element count matches source (no drop/invent).
   - Output: per-element verdict list; any failure → page flagged + not publishable.
   - Replaces the raw insert-call "coverage" metric with logical-element accounting.

## Data model (contract item, extended)
```json
{
  "id": "p15_s0004",
  "role": "merged_header",
  "page_number": 15,
  "source_text": "HIGH FREQUENCY WORDS",
  "translation": "HOË FREKWENSIE WOORDE",
  "cell_box": [280, 40, 373, 118],
  "column_span": 1,
  "align_h": "center",
  "align_v": "middle",
  "peer_group_id": "p15-headers"
}
```

## Key decisions
- **Single source of truth:** DocumentStructure drives BOTH render and comparison, so
  they can never diverge (R2).
- **Comparison, not just constraints:** the gate compares OUTPUT⇄SOURCE element-by-
  element (the missing capability), instead of only checking abstract geometry rules.
- **Alignment/size are derived, never hand-set** (Captain Zan's rule).
- **insert_text primitive** for correct font + text layer (PyMuPDF 1.28.2 constraint).
- **End-marker** is a role, so "Die Einde" is a separate placed element, not sentence tail.

## Error handling / fail-closed
- Unresolved structure, missing element, out-of-box placement, align/size/font mismatch,
  or element-count mismatch → NEEDS_LAYOUT_REVIEW, not publishable, with a specific reason.

## Testing strategy
- Unit: grid+merged-cell detection; header-row box; end_marker classification; peer-group
  size solve; alignment derivation.
- Gate: PASS on a good synthetic render; FLAG each defect class (mis-placed/merged header,
  multi-line overflow, size mismatch, fallback font, dropped/added element, end-marker
  glued to sentence).
- End-to-end: render >=2 books via the contract path; assert structural comparison passes
  and the p15 header + "Die Einde" cases are correct.
- Regression: keep all existing suites green.

## Rollout
1. Land StructureAnalyzer + model behind the existing scene build (non-breaking).
2. Switch renderers to element placement; keep flat fallback flagged.
3. Add comparison gate; replace coverage metric.
4. PHP: emit + persist per-element contract; make it the live render source.
5. Remove the legacy flat mapper once the contract path is the default (R2).
