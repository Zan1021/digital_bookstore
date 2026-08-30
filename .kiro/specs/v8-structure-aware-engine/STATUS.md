# V8 Structure-Aware, Compare-Driven Engine — Final Status (Task 13)

**Date:** 2026-08-29
**Spec:** `.kiro/specs/v8-structure-aware-engine/`
**Outcome:** All 13 tasks complete. The structure-aware, compare-driven engine is the
default path end-to-end (PHP contract → engine placement → structural gate → admin
overlay verdict). Honest status below — what is proven, and what is not.

## Task completion (1–13)
1. Table grid + merged-cell detection — done (detect_header_cells; header_row_box).
2. Element model (cell_box, align_h/v, peer_group_id, column_span, is_merged) — done.
3. End-marker + logical-unit roles (_mark_end_markers, structural) — done.
4. Per-element contract shape + engine consumption (items[] carries structure) — done.
5. Structure-preserving header placement (merged centered across span; multi-line) — done.
6. Word-column peer-size + end-marker as its own element — done.
7. StructuralComparison gate (validate_structure) wired fail-closed — done.
8. Gate defect-class tests — good render passes + all 8 defect classes flagged — done.
9. PHP emit + persist per-element contract as the LIVE render source — done.
10. End-to-end verification on 2+ books via the contract path — done.
11. Legacy flat mapper isolated (opt-in only; default fails closed) — done.
12. Diagnostics/overlay surface the per-element comparison verdict — done.
13. This status + final green sweep.

## Test status (final clean run — all 18 scripts, 0 failures)
Assertion suites (with counts):
- test_render_gate ................ 45
- test_table_structure ............ 42
- test_stable_id_contract ......... 13
- test_second_book ................ 14
- test_e2e_contract (NEW, T10) ..... 9
- test_typography .................. 8
- test_font_policy ................. 6
- test_fitting_ladder .............. 5
- test_translation_compare ......... 5
Pass/print suites (assert internally, exit 0):
- test_integration, test_scene_graph, test_unit, test_validation, test_rotated_text,
  test_manifest_flow, test_v8_render, test_surgery.

New tests added this spec: test_e2e_contract.py (T10); test_table_structure defect
matrix + legacy-isolation (T8/T11); test_render_gate structure-deviations surfacing (T12).

## "Nothing hand-tuned to one book" — audited (T13)
Grepped the production scripts (pdf_translate_v8, render_gate, document_model,
universal_containers, translation_compare) for book-specific literals:
- All "Kolulu"/"A Fun Place"/"Die Einde"/"WOORDE" occurrences are COMMENTS/docstrings
  used as examples — never logic.
- End-marker detection is structural (_mark_end_markers: last + short + isolated after a
  completed sentence), not a string match.
- `page_num == 1` / `page_num >= total_pages` are structural cover/back-cover heuristics,
  not per-book constants.
- The one calibrated threshold changed this spec — the header peer-size ratio (1.6→2.1)
  in T10 — was derived from the REAL geometry of TWO different books (2-line vs 1-line
  header), verified on both. Not tuned to one.

## Known caveats / not-yet-proven (honest)
- Content-word cells (vocab columns) are modelled at COLUMN granularity, not per-word
  rows. The structural gate therefore checks PRESENCE + FONT for content words, and
  strict in-box/peer-size only for headers + end-marker. Row-level content boxes are a
  future refinement (documented in validate_structure).
- The e2e contract test uses source_text as the translation stand-in (round-trip): it
  exercises geometry/placement faithfully but does not assert translation QUALITY (that
  is translation_compare's job, a separate gate).
- test_corpus.py is a CLI utility (prints argparse help without args), not an assertion
  suite; it is not counted above.
- Verification ran on Windows; some suites emit a ✓ glyph and need
  PYTHONIOENCODING=utf-8 to avoid a cp1252 console error (cosmetic, not a code fault).
- No PHP unit tests exist for PdfTranslationService; the PHP contract path was verified
  via `php -l` + an engine-level round-trip probe, not a PHPUnit test.

## Net effect
The p15 flat/merge mismatch that motivated the rewrite is resolved by construction: on
the contract path, book-2 renders with id_mapped=True, LEGACY_FLAT_MAPPING=[], and
structure_gate ok=True. The whack-a-mole visual-defect loop is replaced by a
source-vs-render structural comparison that fails closed and is surfaced per-element in
the admin overlay.
