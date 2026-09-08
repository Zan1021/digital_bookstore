# Handoff — Vocabulary page renders ENGLISH despite correct id→translation mapping

**Date:** 2026-09-08
**Author:** Naz
**Status:** BLOCKED — need a second opinion on the placement path.

## One-line problem
On the vocabulary/phonics page (Book "My Senses", p19), the translation mapping is now
100% correct by stable id, but the **rendered PDF still contains the English source text**
in its text layer. Something in the placement half of `render_vocabulary_page_v8`
(scripts/pdf_translate_v8.py) is not placing the mapped Afrikaans (or English is not being
redacted / is placed instead).

## What is PROVEN correct (verified, not assumed)
1. Scene graph (`document_model.build_document_scene`) → p19 has 64 text_units, 100% with
   `cell_box`, stable ids `p19_s0006`, correct roles. ✅
2. New `_vocab_manifest_from_scene(page_scene, 19)` builds 4 regions / 64 items with those
   stable ids + cell_box. ✅
3. Contract JSON from PHP has 64 p19 items with correct Afrikaans + cell_box. ✅
4. `_lookup_id_translation(id2t, id)` returns correct Afrikaans for every id
   (`p19_s0001 WORDS→WOORDE`, `hear→hoor`, phonics `- ck rock,lock → - kk lekker,trekk`). ✅
5. Render report now says: `vocabulary.19 = {units_total:64, placed_by_id:64,
   unresolved:[], mode:"id"}`. So `mapped_items` has 64 correct Afrikaans entries. ✅

## The FAILURE (ground truth)
`doc[18].get_text()` on the rendered PDF = **English** ("WORDS\nHIGH\nFREQUENCY\nhear...").
So despite `mapped_items` being correct, the page's text layer is English.

## Suspected mechanism (needs confirmation)
Placement loop (pdf_translate_v8.py ~line 1545):
```python
for item_id, translation in id_to_translation.items():
    if item_id in id_to_span and translation:
        span = id_to_span[item_id]
        items_to_render.append({"span": span, "translation": translation, ...})
```
`id_to_span` is built via `_find_span_at_origin(page_spans, item.get("origin"))`.
- My scene-manifest items set `origin = [scene_unit.bbox[0], scene_unit.bbox[1]]`.
- `page_spans` come from the RENDER's own extraction (`_cached_page_spans`), whose origins
  may differ from the scene graph's bbox coordinate convention (top-left vs baseline, or
  a different extractor). If `_find_span_at_origin` uses a tight tolerance, scene ids fail
  to match any page span → `id_to_span` misses them.
- If `id_to_span` is empty, `items_to_render` is empty → all content spans become
  `unmatched_content` → masked white → page should be BLANK. But it shows ENGLISH, which
  is inconsistent with that theory — so either the redaction isn't happening, or headers
  (placed via a different `_render_vocab_headers`/`_place_vocab_headers` path) and body use
  different coordinate assumptions.

## KEY QUESTION for the reviewer
In `render_vocabulary_page_v8`, when the page_manifest is built from the SCENE GRAPH
(ids = contract ids, geometry = scene bbox/cell_box), what is the correct way to:
  (a) find the physical source span for each unit to REDACT it, and
  (b) place the mapped translation at the right spot,
given that `page_spans` (render extraction) and the scene units may use different origin
conventions? Options considered:
  - Option A: match `id_to_span` by cell_box/bbox overlap instead of exact origin.
  - Option B: don't use `page_spans` at all on the scene path — redact by each unit's
    bbox rectangle and place translation into its cell_box directly (fully scene-driven,
    no `_find_span_at_origin`). This seems most correct + book-agnostic.
  - Option C: reconcile coordinate conventions between scene bbox and page_spans origin.

Naz's lean: **Option B** — on the scene path, render entirely from scene units
(redact each unit.bbox, place translation in unit.cell_box with align + clip), bypassing
`_find_span_at_origin`/`page_spans` which is the legacy coupling causing the mismatch.

## Files touched this session (uncommitted)
- scripts/document_model.py — get_translatable_units includes educational_adaptation;
  added get_renderable_units.
- scripts/pdf_translate_v8.py — `_looks_like_vocabulary_page` classifier (font-agnostic);
  `_vocab_manifest_from_scene` + `_lookup_id_translation`; scene-driven manifest + id
  mapping branch in render_vocabulary_page_v8; page_scene threaded through dispatch.
- app/Jobs/TranslateEditionJob.php — uses translateWithManifest; saves rendered_pdf_path.
- app/Services/PdfService.php — detectCropMarks now uses cropmark_detection.py engine.
- scripts/cropmark_detection.py — --json now emits media_box.

## Repro
`php artisan tinker --execute="require 'storage/app/temp/_probe_render.php';"` then
`python scripts/_diag_text.py` → shows PROBE/LIVE p19 text = English.
