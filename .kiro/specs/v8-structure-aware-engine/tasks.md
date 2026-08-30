# Implementation Plan — V8 Structure-Aware, Compare-Driven Engine

- [x] 1. Table grid + merged-cell detection
  - Add `detect_table_grid(page)` returning horizontal/vertical grid lines and CELL boxes.
  - Detect merged/column-spanning header cells (one cell, `column_span > 1`) and the
    `header_row_box` (top of table → first content row).
  - Book-agnostic: from detected vector lines + span geometry only.
  - _Requirements: 1.2, 1.3_

- [x] 2. Extend the element model
  - Add `cell_box`, `align_h`, `align_v`, `peer_group_id`, `column_span`, `is_merged` to
    `TextUnit`/`Region` (document_model.py); populate from the grid + source geometry.
  - Derive `align_h`/`align_v` from where the source glyphs sit in the cell (reuse
    `_infer_source_alignment`; add vertical equivalent).
  - _Requirements: 1.1, 3.2, 3.3_

- [x] 3. End-marker + logical-unit roles
  - Add `end_marker` role classification (short trailing phrase isolated after the last
    sentence — position + brevity + separation, book-agnostic).
  - Confirm `_merge_continuation_spans` results feed roles correctly (wrapped entry = one
    element; single words / `pattern - examples` = own elements).
  - Test: "Die Einde" becomes its own element, not sentence tail.
  - _Requirements: 1.4, 1.5_

- [x] 4. Per-element contract shape + engine consumption
  - Extend contract item with `role`, `cell_box`, `column_span`, `align_h/v`,
    `peer_group_id` (document_model.to_translation_request).
  - Engine consumes `id_to_translation` directly for ALL page types; flat mapper runs
    only when no contract and sets `LEGACY_FLAT_MAPPING` flag.
  - _Requirements: 2.1, 2.2, 2.3_

- [x] 5. Structure-preserving placement — headers
  - Render each header into its cell_box; merged header centered across FULL span;
    multi-line header `valign=middle` in `header_row_box`; clip to cell.
  - One shared size across the header peer group.
  - Verify on p15: WOORDE / HOË FREKWENSIE WOORDE / FONIES centered, no overflow.
  - _Requirements: 3.1, 3.3, 3.4, 3.5_

- [x] 6. Structure-preserving placement — word columns & story
  - Vocab word items: one shared size per column peer group (fix "kampioenskap" smaller).
  - Story/end-marker: place `end_marker` as its own centered element separate from prose.
  - _Requirements: 3.1, 3.2, 3.5, 3.6_

- [x] 7. StructuralComparison gate
  - New `render_gate.validate_structure(source_structure, rendered_pdf)`: per-element
    present / in-box / align match / peer-size consistent / approved font; region element
    count matches source.
  - Replace raw insert-call "coverage" with logical-element accounting.
  - Wire verdicts into review_pages + publishable (fail closed).
  - _Requirements: 4.1, 4.2, 4.3, 4.4_

- [x] 8. Gate tests (pass + flag each defect class)
  - Synthetic fixtures: good render passes; each of {mis-placed header, merged-header
    off-center, multi-line overflow, size mismatch, fallback font, dropped element,
    invented element, end-marker glued to sentence} is FLAGGED.
  - _Requirements: 4.5_

- [x] 9. PHP: emit + persist per-element contract (item 2.2)
  - PdfTranslationService builds + persists the per-element contract for an edition;
    render_book/live render use it as the source (not flat translated_pages).
  - _Requirements: 2.4_

- [x] 10. End-to-end verification on 2+ books
  - Render two different books via the contract path; assert structural comparison passes
    and the p15 header + "Die Einde" cases render correctly.
  - _Requirements: 5.1_

- [x] 11. Remove the legacy flat mapper (R2)
  - Once the contract path is default and green, delete/isolate `legacy_text_to_manifest_
    items` line-position mapping so no competing path remains.
  - _Requirements: 5.2_

- [x] 12. Diagnostics/overlay surface the comparison result
  - Admin overlay shows per-element comparison verdict (which element deviated, why).
  - _Requirements: 5.4_

- [x] 13. Full suite green + regressions
  - All existing suites pass; new unit/gate/e2e tests added; nothing hand-tuned to one
    book; honest status recorded.
  - _Requirements: 5.3_
