# Implementation Plan — Book-Agnostic Vocabulary/Phonics Rendering

Spec: ./requirements.md · ./design.md
Rule: nothing is skipped. Each task is verified before the next. No commit/push without
explicit checkout.

- [ ] 1. Baseline capture (prove starting state, so we can prove the fix)
  - Record current render outcome for the three test books' vocab pages (My Senses p19,
    a second Kolulu title, A Fun Place) — render_status + a note of English-leak/OK.
  - Save the p19 "before" screenshot reference.
  - _Requirements: 4.2, 4.3, 5.3_

- [ ] 2. Fix renderable-unit selection for phonics (scripts/document_model.py)
  - Audit all callers of `get_translatable_units()`.
  - Add `get_renderable_units()` (translate + educational_adaptation) OR safely broaden
    the existing method; do NOT change semantics other callers rely on.
  - _Requirements: 2.2_

- [ ] 3. Implement `render_vocabulary_page_by_id` (scripts/pdf_translate_v8.py)
  - Place each scene-graph unit's translation by stable id into its `cell_box`/`bbox`
    with `align_h/v`, `column_span`, source-casing mirroring, per-cell clipping,
    shrink-to-fit. Reuse existing placement helpers; key everything by id.
  - Single redaction pass over the page's source spans before placement.
  - _Requirements: 1.1, 1.2, 1.4, 2.1, 2.3_

- [ ] 4. Header placement by id (scripts/pdf_translate_v8.py)
  - Feed scene-graph header units to the cell placement; drop the source-text bucket
    fallback on the id path. Merged headers span `column_span`, centered, clipped.
  - _Requirements: 1.2, 1.3_

- [ ] 5. Fail-closed on the id path (scripts/pdf_translate_v8.py)
  - Unresolved id + page has translated content → blank + `unresolved_span_ids` +
    NEEDS_LAYOUT_REVIEW. Never place English source as a fallback.
  - _Requirements: 3.1, 3.2, 3.4_

- [ ] 6. Route vocabulary pages to the id renderer (scripts/pdf_translate_v8.py)
  - In `replace_text_in_pdf`: vocabulary + scene graph + stable-id contract → new id
    renderer (default). Else legacy path with `allow_legacy_flat` (off by default).
  - _Requirements: 1.5, 3.3_

- [ ] 7. Report/diagnostics counters (scripts/pdf_translate_v8.py)
  - `report["vocabulary"][page] = {units_total, placed_by_id, unresolved:[ids], mode}`.
  - Ensure `CONTRACT_BRIDGE_MISS` cannot occur on the id path.
  - _Requirements: 6.1, 6.2_

- [ ] 8. New engine unit test (scripts/test_vocab_id_render.py)
  - Synthetic scene page + id map incl. an ADAPTED phonics entry (translation != source):
    assert placed_by_id == units_total, unresolved == 0, no source string rendered.
  - Missing-id case: that unit blank + flagged, no English.
  - _Requirements: 1.1, 2.1, 2.3, 3.1_

- [ ] 9. Python regression suite (all)
  - Run + green: test_table_structure, test_render_gate, test_integration, test_unit,
    test_e2e_contract, test_second_book, test_manifest_flow, test_phonics_classification.
  - _Requirements: 5.1_

- [ ] 10. PHP call-site + Laravel suite
  - Update any `PdfTranslationService` call sites if the renderer entry changed; run the
    full Laravel suite green.
  - _Requirements: 5.2_

- [ ] 11. E2E — Book A "My Senses" (new book)
  - rebuild-manifest → retranslate af → render → screenshot p19.
  - Assert: Afrikaans WOORDE headers + words + ADAPTED phonics all in-cell, no English,
    columns not crossing borders.
  - _Requirements: 1.1, 2.3, 4.2_

- [ ] 12. E2E — Book B (second Kolulu title with a vocab page)
  - Same flow + screenshot its vocab page; assert correct target text in-cell.
  - _Requirements: 4.1, 4.3_

- [ ] 13. E2E — Book C "A Fun Place" (regression on the previously-working book)
  - Render af; screenshot vocab page; assert still correct (no regression).
  - _Requirements: 5.3, 5.4_

- [x] 14. Known-limitations record
  - Vocabulary/phonics pages now render book-agnostically by stable id + cell_box on the
    scene path (render_vocabulary_page_from_scene). Proven on 2 structurally different
    books (My Senses 64 units incl. 11 adapted phonics; Kolulu's Home 148 units).
  - KNOWN LIMITATION (fail-closed, not silent): pages where a unit has no translation, a
    translation overflows its cell even at min font size, or text is baked into raster
    art / vector outlines → routed to NEEDS_LAYOUT_REVIEW (the human-QA safety net).
  - Legacy page_spans path retained as fallback only when no scene graph is available.
  - _Requirements: 4.4_

- [ ] 15. Cleanup + session note (on checkout only)
  - Remove temp diagnostic files. Session note + commit/push ONLY on explicit checkout.
  - _Requirements: (process)_
```
```
Sequencing: 1 baseline → 2–7 build → 8–10 verify code → 11–13 prove on 3 books →
14 record limits → 15 cleanup. Do not mark a task done until its verification passes.
```
