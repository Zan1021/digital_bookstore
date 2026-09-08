# Design — Book-Agnostic Vocabulary/Phonics Rendering

## Overview

Replace the vocabulary renderer's **source-text bridge** with **stable-id + geometry
placement** driven directly by the scene graph. The scene graph already carries, per
vocabulary page, the exact structure and ids the renderer needs; today the renderer
throws that away and rebuilds its own manifest with a different id scheme, forcing a
fragile English-string join. We make the renderer consume the scene graph's units by id.

```
BEFORE (fragile):
  contract {p19_s0006: "hoor"}          scene graph units (p19_s0006, cell_box, ...)
        │                                         │
        └── render_vocabulary_page_v8 builds OWN manifest (p19-w-c1-001)
                 └── bridge by NORMALIZED SOURCE TEXT  ← breaks on adapted/differing text
                        └── CONTRACT_BRIDGE_MISS → English remains

AFTER (book-agnostic):
  contract {p19_s0006: "hoor"}   +   scene graph units (p19_s0006, cell_box, align, span)
        └────────────── join by STABLE ID ──────────────┘
                 └── place each unit's translation in its cell_box/bbox by id
                        └── unresolved id → blank + review (fail closed)
```

## Why this is the correct, book-agnostic fix

- Source-text matching is inherently **not** book-agnostic: it assumes the translation
  resembles the source. Adaptation (phonics), re-segmentation, or any wording change
  breaks it. It happened to work only because the calibration book's vocab page was a
  literal word list.
- Id/geometry matching is book-agnostic **by construction**: "this translation belongs to
  the physical span with this id at this cell," regardless of the words or how they were
  produced (translate vs educational_adaptation), or the language.

## Current state (verified in code this session)

- `page_manifest.build_document_manifest` already delegates to the scene graph
  (`builder=scene_graph`, schema 2.0) and emits regions/items with `id`, `bbox`,
  `cell_box`, `align_h/v`, `column_span`, `is_merged`, `semantic_role`,
  `translation_policy`. ✅ (manifest-scene-graph-unification T1/T2 done.)
- `replace_text_in_pdf` builds the `DocumentScene` once and exposes
  `document_scene.get_page(page_num)` at render time. ✅
- `render_vocabulary_page_v8` still calls `build_vocabulary_manifest(page,…)` (its own
  `p19-w-c1-001` scheme) and bridges by source text. ❌ ← the thing we fix.
- `id_to_translation` reaching the renderer is the RICH dict `{id: {translation,
  source_text, page_number, …structure}}` when the contract carries `source_text`
  (it does). ✅ So per-id translations are already in hand at render time.

## Data model (existing, reused — no new persistence)

`PageScene.text_units: list[TextUnit]`, each with:
`id`, `source_text`, `bbox`, `semantic_role`, `translation_policy`, `parent_region_id`,
`row_index`, `column_index`, `row_span`, `column_span`, `cell_box`, `align_h`, `align_v`,
`peer_group_id`, `is_merged`. Lookups: `PageScene.unit_by_id`, `region_by_id`,
`get_translatable_units`, `get_preserved_units`.

**Known model bug to fix (Req 2.2):** `get_translatable_units()` returns only
`translation_policy == "translate"`, silently excluding `educational_adaptation`
(phonics). Broaden it (or add `get_renderable_units()`) to include adaptation units.

## Components & changes

### C1 — New id-based vocab renderer (scripts/pdf_translate_v8.py)
Add `render_vocabulary_page_by_id(page, page_scene, id_to_translation, fonts_dir,
page_num, report)`:
- Iterate `page_scene.text_units` for the page (renderable = translate OR
  educational_adaptation; headers handled in their cells).
- For each unit:
  - `translation = _lookup_translation(id_to_translation, unit.id)` (rich dict or str).
  - If missing/empty AND page has translated content → render blank, add id to
    `unresolved`, flag page for review (Req 3).
  - Else place `translation` in the unit's render box:
    - headers: center within `cell_box` (merged headers span `column_span`), vertical
      align per `align_v`, clipped to the cell (reuse existing `_place_vocab_headers`
      cell logic, but keyed by id, not source).
    - word/phonics items: place at the unit's `bbox`/`cell_box` with `align_h`,
      source-casing mirroring, per-cell clipping, shrink-to-fit (reuse
      `insert_translated_span` / `draw_paragraph_text`).
- Redact every source span for the page's units before placing (one redaction pass, as
  today) so no English ghosts through.
- Record report counters (Req 6): `units_total`, `placed_by_id`, `unresolved`, `mode`.

### C2 — Route vocabulary pages to the id renderer (scripts/pdf_translate_v8.py)
In `replace_text_in_pdf`, when `page_type == 'vocabulary'`:
- If `document_scene` is available AND a stable-id contract is present → call
  `render_vocabulary_page_by_id(...)` (the new default).
- Else → existing `render_vocabulary_page_v8(...)` with `allow_legacy_flat` semantics
  (fail-closed unless opted in). This preserves the legacy path as an explicit fallback
  (Req 3.3) and keeps behaviour when no scene graph could be built.

### C3 — Fix unit selection for adaptation (scripts/document_model.py)
Broaden `get_translatable_units()` to include `educational_adaptation`, or add
`get_renderable_units()` used by C1. Keep `get_translatable_units` semantics for the
translation-request path if other callers depend on the strict meaning (verify callers
first; adjust the minimal safe way).

### C4 — Header placement by id (scripts/pdf_translate_v8.py)
`_place_vocab_headers` currently assembles cell text from `id_to_translation` already
(via `_extract_header_translations` + geometric cell assignment). Confirm it keys on id
(it does) and feed it the scene-graph header units directly so it no longer needs the
source-text bucket (`_contract_source_to_translation`). Remove the source-text fallback
from the id path.

### C5 — Report/diagnostics (scripts/pdf_translate_v8.py)
Add the Req 6 counters to `report` under `vocabulary[page_num]`. Ensure
`CONTRACT_BRIDGE_MISS` is not emitted on the id path.

## Fail-closed behavior (unchanged intent, Req 3)
- Unresolved id on a page with translated content → blank glyph + `unresolved_span_ids`
  + `render_status = NEEDS_LAYOUT_REVIEW`. Fix C stays intact.
- No English source is ever placed as a silent fallback on a translated edition.

## Non-goals
- Not changing translation prompts or the `educational_adaptation` generation.
- Not redesigning the reader UI.
- Not removing the legacy flat mapper (kept behind the existing opt-in for one release).
- Not changing the compare/review gate logic (only feeding it correct data + counters).

## Testing strategy

### Python (engine)
- **New unit test** `test_vocab_id_render.py`:
  - Given a synthetic scene page with ids + cell_box and an id→translation map with a
    context-ADAPTED phonics entry (translation ≠ source), the renderer places every unit
    by id (assert placed_by_id == units_total, unresolved == 0).
  - Given a map MISSING one id, that unit is blank + flagged (fail-closed), no English.
- Re-run all existing suites (Req 5.1).

### Laravel
- Full suite green (Req 5.2). No app-layer changes expected beyond what's already done;
  if the renderer signature changes, `PdfTranslationService` call sites updated + covered.

### End-to-end (real books, real APIs)
- **Book A — "My Senses" (new):** rebuild manifest → retranslate af → render → screenshot
  p19; assert Afrikaans WOORDE + adapted phonics in-cell, no English (Req 4.2).
- **Book B — a second Kolulu title with a vocab page:** same flow; screenshot its vocab
  page; assert correct target text in-cell (Req 4.3).
- **Book C — original "A Fun Place":** regression render; vocab page still correct
  (Req 5.3).

## Risks / rollback
- Risk: the scene graph's header cell detection differs from the old vocab manifest's
  column clustering on some layouts. Mitigation: reuse the existing struct-cell detection
  (`universal_containers.detect_table_grid/detect_header_cells`) which the header path
  already uses; validate on Books A/B/C.
- Rollback: C2 routing is a single branch; flip vocabulary back to
  `render_vocabulary_page_v8` to revert. Legacy path remains intact.
- All changes in the bookstore repo; nothing committed/pushed without explicit checkout.
