# Cover Raster Re-typeset — Requirements

## Scope
FRONT PAGE (cover) ONLY. Replace the source-language cover subtitle with the translated
subtitle in a way that is book-agnostic and works even when text sits on an illustration —
without ever mutating the source PDF's fragile (soft-masked) content stream.

Out of scope (this spec): story pages, back cover, vocabulary/table pages. The engine is
designed so it CAN extend to them later, but only the cover is built and wired now.

## Background / why
- The existing cover path calls `page.apply_redactions()` to erase the source subtitle. On
  covers whose subtitle shadow is a Form XObject drawn through a LUMINOSITY soft mask, that
  redaction re-serialises the content stream and the previously-invisible luminosity backdrop
  renders as a dark/washed box behind the translated subtitle — but ONLY in PDF.js. PyMuPDF
  `get_pixmap()` flattens the mask and shows it clean, so it is invisible to local raster checks.
- The SOURCE cover renders correctly. The defect is introduced by our mutation of the source.
- Verified via ChatGPT handoff brief: Brief/kiro-pdf-translation-cover-fix.md (treated as guidance,
  not gospel). Root cause NOT positively confirmed; candidate incl. PDF.js luminosity /BC bug
  (PR #21348). We therefore avoid depending on any single root-cause theory and instead avoid the
  mutation entirely.

## Requirements

### R1 — Start from the untouched source
1.1 Every cover build starts from the ORIGINAL English source PDF page, never a redacted/translated output.
1.2 The source PDF is never edited in place for this path.

### R2 — Book-agnostic subtitle location
2.1 Locate the source subtitle region using native PDF text extraction FIRST (exact glyph boxes).
2.2 When extraction yields no text in the title area (text baked into a raster), fall back to OCR + a
    vision model (gpt-4o) that returns STRUCTURED JSON only (bbox, background_type, sample regions,
    protected regions). The model proposes regions; it does not supply final pixels or colours.
2.3 No hardcoded coordinates or colours for any specific book.

### R3 — Background-type-aware removal (the book-agnostic core)
3.1 Classify the background behind the subtitle: flat | gradient | illustration | uncertain.
3.2 flat/gradient → deterministic fill sampled robustly (per-channel median, uniformity-checked) from
    the CLEAN rendered plate, over a removal mask that covers glyphs + antialiasing + full shadow.
3.3 illustration (text on artwork) → generative inpaint of BACKGROUND ONLY (gpt-image-1 masked edit);
    composite the accepted patch back via a deterministic mask. Reject seams / changed semantic detail.
3.4 uncertain / critical artwork (faces, hands, logo) → DO NOT guess; mark cover NEEDS_LAYOUT_REVIEW.
3.5 Removal must not touch protected regions (logo, borders, publisher mark, illustration outside band).

### R4 — We typeset the translated text (no model-drawn letters)
4.1 The translated string comes from the existing translation pipeline, not the image model.
4.2 Typeset with an embedded font that has the required glyphs; match source size/colour/alignment;
    recreate a shadow for the NEW glyphs only if the source design had one (never keep the English shadow).
4.3 Bounded shrink-to-fit / approved wrapping; explicit overflow + missing-glyph detection.

### R5 — Re-embed without breaking the page
5.1 Embed the repaired cover as a lossless high-res raster page (see design for ppi), preserving
    MediaBox/TrimBox/BleedBox, rotation, bleed, and page geometry.
5.2 Keep translated text as a real vector text layer above the raster where feasible (crispness + search).

### R6 — Verify against the REAL renderer
6.1 Acceptance validates the SAVED output rendered by the actual PDF.js path — NOT PyMuPDF pixmap alone.
6.2 A gpt-4o vision pass flags residual text/ghost/halo/seam/wrong-colour/misspelling → pass/fail + reason.
6.3 A visual regression compares translated vs clean source outside the approved edit region; the broad
    washed band from the current defect MUST fail this check.

### R7 — Fail loud, never silently destructive
7.1 Uncertain background or protected-region overlap → route to review with an actionable diagnostic,
    never a blind rectangle fill.
7.2 Cache-busting for the reader PDF URL stays in place (already implemented) so verification is real.

## Acceptance (cover)
- "My Sintuie" correctly spelled, legible, aligned, within bounds.
- Old subtitle glyphs + shadow not visible; no washed/dark band in the PDF.js render.
- Borders, logo, publisher text, illustration intact.
- Overlay/raster inherits no unintended soft mask/blend/opacity/clip.
- Physical dimensions, bleed, rotation correct; effective raster ppi computed from placement.
- Works on this coral flat-bg cover AND on a synthetic text-on-illustration test cover.
