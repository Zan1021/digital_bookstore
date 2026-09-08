# Tasks — Manifest / Scene-Graph Unification

Spec: ./spec.md

- [ ] T1. Confirm the persisted manifest schema fields the renderer consumes and add
      structure fields (column_span, is_merged, cell_box, align_h, align_v,
      peer_group_id, semantic_role) to the manifest serialization.
- [ ] T2. Rewrite `page_manifest.build_document_manifest` to delegate to
      `document_model.build_document_scene()` and serialize the scene's translation
      request into the manifest schema (with §5.1 fields + `builder`/`schema_version`).
      Keep old flat path behind `--legacy`.
- [ ] T3. Add `book:rebuild-manifest {book?}` artisan command that invokes the Python
      builder and updates `book->manifest_path`.
- [ ] T4. Freshness/provenance guard in `TranslationService::translateWithManifest`:
      rebuild manifest when missing/stale/old-builder before translating.
- [ ] T5. Ensure `createTranslatedPdf` contract carries the structure fields and persists
      the render gate report + sets render_status from the real verdict every run.
- [ ] T6. Run Python structure test suite; fix any regressions.
- [ ] T7. Execute end-to-end for Book 2: rebuild-manifest -> retranslate af -> render;
      verify p15 manifest has merged headers; extract rendered p15 text; screenshot.
- [ ] T8. Regression check p2 (story) + p16 (back cover) render text.
- [ ] T9. Update session note (on checkout only).
```
