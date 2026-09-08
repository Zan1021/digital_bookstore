# Spec — Manifest / Scene-Graph Unification (V8 structure fidelity in production)

**Author:** Naz
**Date:** 2026-09-01
**Status:** In progress
**Book under test:** Book 2 — Kolulu "A Fun Place", Afrikaans (translation id 4, 16 pages)

---

## 1. Problem statement

Translated editions render with broken structure on structured pages (the p15
WOORDE/phonics grid: merged headers scrambled — "WOORDE HOË FREKWENSIE" instead of
"HOËFREKWENSIE-WOORDE", "WOORDE FONETIEK", body words promoted into header slots,
overlapping columns).

This is **not** a defect in the compare/structure system. Proven on 2026-09-01:
- `document_model.build_document_scene()` run on the live source PDF **correctly**
  detects p15 merged headers: `WORDS` span=3, `HIGH FREQUENCY` span=2, `PHONICS`
  span=2, all centered, with `cell_box`.
- `test_table_structure.py` = **42/42 pass**, including merged-header horizontal-spill
  gate and mis-placed-header gate.

## 2. Root cause (proven, not assumed)

There are **two competing manifest/structure builders** that were never reconciled:

| Builder | File | Merged headers? | Used by |
|---|---|---|---|
| OLD | `scripts/page_manifest.py` (`build_document_manifest`, `build_vocabulary_manifest`) | **NO** — flat column clustering only | `PdfService::generateManifest()` on upload → writes `{id}_manifest.json` |
| NEW | `scripts/document_model.py` (`build_document_scene` → `to_translation_request`) | **YES** — full merged-cell, cell_box, align, peer groups | render fallback only |

Chain of failure:
1. On upload, `PdfService::generateManifest()` runs the **OLD** `page_manifest.py`,
   persisting a flat manifest with disconnected header cells (no `column_span`/`is_merged`).
2. `TranslationService::translateWithManifest()` reads that persisted manifest from disk
   and **never checks freshness or which builder produced it**.
3. The translated items (flat headers) are handed to the renderer as the contract,
   which wins over the scene-graph fallback. The correct merged-header structure in
   `document_model.py` is therefore never used in production.

Net: the good structure code exists and is tested, but the **production data path feeds
the renderer the old builder's flat structure**. Same class of bug as the earlier stale
inputs (trailing-comma URL, empty item_translations): engine correct, plumbing feeds bad input.

## 3. Goals

1. The **single source of truth** for page structure is the scene graph
   (`build_document_scene`). The persisted manifest MUST carry merged-header structure
   (`column_span`, `is_merged`, `cell_box`, `align_h`, `align_v`, `peer_group_id`).
2. Retranslate/render MUST NOT trust a stale or old-builder manifest. It must rebuild
   (or validate freshness + schema of) the manifest from the scene graph first.
3. The structure compare gate MUST run on every production render and its verdict
   (READY vs NEEDS_LAYOUT_REVIEW) must reflect the real rendered geometry — no silent pass.
4. Fix must be **book-agnostic** — no p15/Kolulu-specific constants. Works for any
   uploaded PDF (cover, copyright/info, story, vocabulary/table, back cover).
5. Prove end-to-end on Book 2 with a fresh render + screenshot before claiming done.

## 4. Non-goals

- Rewriting the compare/structure system (it works).
- Redesigning the reader UI (separate; the `?lang` sanitization is a nice-to-have logged
  separately).
- Changing the translation model/prompts.

## 5. Design

### 5.1 Unify the manifest builder on the scene graph
`scripts/page_manifest.py` `build_document_manifest` becomes a thin adapter that calls
`document_model.build_document_scene()` and serializes `to_translation_request()` into the
persisted manifest schema, INCLUDING per-item `column_span`, `is_merged`, `cell_box`,
`align_h`, `align_v`, `peer_group_id`, `semantic_role`, and region grouping. The old flat
vocabulary/column path is removed as the default (kept only behind an explicit
`--legacy` flag for emergency comparison).

Manifest schema gains `schema_version` bump + `builder: "scene_graph"` provenance tag.

### 5.2 Freshness / provenance guard in translateWithManifest (PHP)
Before trusting `$book->manifest_path`, `translateWithManifest()` must verify:
- the manifest file exists AND
- `builder == "scene_graph"` AND `schema_version >= REQUIRED` AND
- manifest mtime >= source PDF mtime.
If any check fails → regenerate the manifest (invoke the Python builder) before proceeding.
No silent fallback to a stale/flat manifest.

### 5.3 Render always drives from the contract carrying structure
`createTranslatedPdf` already prefers the contract; ensure the contract items include the
structure fields from §5.1 so the renderer places merged headers across their full
`cell_box`, centered, and never promotes body words into the header band.

### 5.4 Gate is authoritative
`createTranslatedPdf` must persist the render gate report every run and set
`render_status` from the real gate verdict. `NEEDS_LAYOUT_REVIEW` must be surfaced, not
buried. Fix C (blank + review, no English fallback) remains.

### 5.5 Regenerate existing book manifests
Existing books have stale manifests. Provide a `book:rebuild-manifest {book?}` command
(and run it for Book 2) so production data is corrected, not just new uploads.

## 6. Acceptance criteria

1. `book:rebuild-manifest 2` regenerates `2_manifest.json` with `builder=scene_graph`;
   p15 header region shows `WORDS` col_span=3, `HIGH FREQUENCY` span=2, `PHONICS` span=2,
   each `is_merged=true` with a `cell_box`.
2. `translateWithManifest` refuses a stale/flat manifest and rebuilds it automatically.
3. `book:retranslate 2 af` → fresh render; p15 renders merged headers correctly
   ("HOËFREKWENSIE-WOORDE" etc.), no body words in header band, columns not overlapping.
4. Render gate report persisted; `render_status` reflects real verdict.
5. All existing Python structure tests still pass (`test_table_structure.py`,
   `test_e2e_contract.py`, `test_manifest_flow.py`, `test_second_book.py`).
6. Verified on a second, structurally different page type (story p2, back cover p16) —
   no regression.
7. Fresh screenshot of p15 confirms correct headers before "done" is claimed.

## 7. Risk / rollback
- Manifest schema change: bump `schema_version`; guard reads for old versions by
  rebuilding. Old manifests are disposable (regenerated from source PDF).
- Keep the old flat builder behind `--legacy` for one release for comparison, then delete.
- All changes are in the bookstore repo; nothing committed/pushed without explicit checkout.
