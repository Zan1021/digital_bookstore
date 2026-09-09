# Cover Raster Re-typeset — Design

## Principle
Never mutate the source PDF's soft-masked content stream. Render the clean source cover to a
high-res raster, repair the subtitle region on the raster (method chosen by background type),
typeset the translated subtitle ourselves, embed the repaired raster as the cover page, and
validate against the real PDF.js renderer + a vision gate.

This is book-agnostic: flat, gradient, and illustration backgrounds all flow through the same
locate → remove → retypeset → verify path; only the REMOVE step branches by background type.
Text-on-illustration is a first-class case (inpaint), not an afterthought.

## Pipeline (cover only)

```
clean source cover page
  → render to raster  (600 ppi, lossless RGB; compute px from pt at final physical size)
  → LOCATE subtitle   (native extraction → OCR+gpt-4o vision fallback; JSON: bbox, bg_type,
                        sample_regions[], protected_regions[])
  → REMOVE source text on raster, by bg_type:
        flat/gradient   → deterministic robust-median fill over (glyphs+AA+shadow) mask
        illustration    → gpt-image-1 masked inpaint (BACKGROUND ONLY) → deterministic composite
        uncertain/crit  → NEEDS_LAYOUT_REVIEW (no fill)
  → embed repaired raster as cover page (preserve MediaBox/TrimBox/BleedBox, rotation, bleed)
  → TYPESET translated subtitle as vector text layer above the raster
        (embedded font, source size/colour/align, new-glyph shadow only if design had one,
         shrink-to-fit, overflow/missing-glyph detection)
  → VERIFY: render SAVED pdf via real PDF.js → vision gate (residual text/ghost/halo/seam/
            colour/misspelling? pass/fail+reason) + regression diff outside edit region
```

## Components

### 1. scripts/cover_retypeset.py  (new, Python)
Orchestrates the pipeline for page 0. Pure-Python deterministic steps: raster render (PyMuPDF
get_pixmap at target ppi from clean source), colour sampling (robust per-channel median + uniformity),
mask building (glyph bbox ⊕ dilation for AA ⊕ detected shadow extent), deterministic composite,
vector-text typesetting via existing draw_paragraph_text on the NEW image-backed page.

### 2. app/Services/CoverVisionService.php  (new, PHP; reuses OpenAI creds/config)
- locateCoverSubtitle(pngPath, imgW, imgH) → JSON {source_text, bbox_normalized[4],
  background_type, background_sample_regions[], protected_regions[]}. Validates schema + bounds.
- inpaintBackground(pngPath, maskPngPath) → returns background-only patch (gpt-image-1 edit w/ mask).
- verifyCoverClean(finalPngPath) → {pass:bool, reason:string} (gpt-4o vision).
Reuses the existing VisualQaService OpenAI plumbing. All model calls config-gated + logged.

### 3. Wiring
PdfTranslationService::createTranslatedPdf → for page 0 (cover), if COVER_RETYPESET_ENABLED,
route through cover_retypeset instead of render_cover_page_v8. Old path kept behind the flag.

### 4. Verification harness
- Real PDF.js: headless render of the SAVED pdf via Node + pdfjs-dist (NOT Chrome --screenshot,
  which is blank headless; NOT PyMuPDF pixmap). Node 24 present. Add scripts/render_pdfjs.mjs
  (pdfjs-dist + @napi-rs/canvas or node-canvas) → page png for diff + vision gate.
- Regression: compare translated-cover png vs clean-source-cover png OUTSIDE the approved edit bbox;
  fail on any material diff (borders/logo/art changed). The current washed band must FAIL.

## Resolution / print
- 600 ppi for the cover (fine lettering + logo). 8x10in → 4800x6000 px. Lossless (PNG/Flate).
- Preserve MediaBox/TrimBox/BleedBox; render bleed area, not just visible crop.
- Colour: RGB for PDF.js/screen. Flag that raster fallback needs explicit CMYK handling for print
  (out of scope now; documented as a limitation). Keep vector text on top for crispness/search.

## Config (config/bookstore.php)
```
'cover_retypeset' => [
  'enabled' => env('COVER_RETYPESET_ENABLED', false),
  'ppi' => 600,
  'inpaint_model' => 'gpt-image-1',
  'vision_model' => 'gpt-4o',
  'flat_uniformity_max_stddev' => 6,      // per-channel; above → not flat
  'require_review_on_uncertain' => true,
  'validate_with_pdfjs' => true,
]
```

## Failure modes & controls (from brief)
- Vision bbox approximate/missed shadow → cross-check native extraction + dilate mask + protected regions.
- Vision colour wrong → never trust model RGB; sample pixels from model-proposed regions programmatically.
- Deterministic fill halo/mismatch → uniformity gate + mask covers AA+shadow; reject if stddev high.
- Inpaint alters art/logo/faces → background-only prompt + deterministic mask composite + vision reject +
  protected-region overlap check; uncertain/critical → review.
- Hidden English text remains selectable under a raster? The raster path removes source glyph PIXELS;
  ensure the embedded page does not also carry the original selectable text layer (verify via extraction).

## Non-goals
- Not converting story/back/vocab pages now.
- Not producing PDF/X print-ready colour now (documented limitation).
