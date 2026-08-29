# V8 Defective-Page Investigation Record (brief §16)

## The defective page
Book 2 ("A Fun Place"), Afrikaans, **page 15** (vocabulary/word-table) and
**page 16** (back cover). Reported visibly broken: white blocks, flattened
numbered list, all-caps mismatch (p16); header "HOË FREKWENSIE WOORDE" crossing a
column border (p15).

## §16 investigation findings

1. **Which engine/build produced it:** `PdfTranslationService::createTranslatedPdf`
   → `scripts/pdf_translate_v8.py replace` (whole-page redact-and-reinsert), driven
   by a flat `{page_number, translated_text}` per page.

2. **Region manifest / were cells detected separately:** No. The old
   `classify_page()` assigned a single page-type by heuristics keyed on page number
   + small-text counts; there was no per-cell graph. The back cover was mis-typed as
   `story` (its title text is large caps, so it failed the `small > 3` back-cover
   test) and routed to the story renderer, which flattened the list.

3. **Source-to-translation mapping:** flat string re-split by
   `splitTranslatedText()` proportional word distribution — the exact string-inference
   the brief forbids (§8). (Now removed.)

4. **Measurement vs embedded font:** the engine copied source point size onto a
   substitute font (Playpen/AdLib) without visual-size matching → apparent size
   drift; casing of the source display font was not mirrored → lowercase output.

5. **Clipping paths per cell:** none existed → a wide header could paint across a
   column border.

6. **Overflow/collision results:** not computed on rendered output. `pdf_validation`
   only checked page boxes/rotation/PDF integrity.

7. **Why it "passed":** the engine always saved the PDF and returned success;
   `generateAllTranslatedPdfs` set status `approved` regardless. No fail-closed gate.
   Layout failures were, at most, warnings in the log.

## Fixes (Phases 1–3)
- Content/geometry classification (no page-number assumptions).
- Per-cell clipping; `render_gate.py` glyph-geometry hard-constraint validation on
  rendered output; raster validation of protected zones; semantic validation.
- Fail-closed edition states (`render_status`/`qa_report`); publish path checks
  `canBePublished()`.
- True-grid-line header bounds + fit ladder (p15 header no longer crosses).
- Source-casing mirroring on all renderers; font-resolution hash + fallback flag.
- Legacy proportional-split methods removed.

## Regression protection
- `scripts/test_render_gate.py` enshrines the defective page behaviour: golden
  good/bad fixtures + layout-family fixtures + deliberate-failure cases assert that a
  border-crossing/overflow page FAILS the gate and is NOT publishable.
- p15/p16 of book 2 verified rendering correctly and passing the gate.
