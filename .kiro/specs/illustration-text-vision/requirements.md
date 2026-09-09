# Illustration-Text Vision Module — Requirements

Derives from: `Brief/kiro-pdf-translation-cover-fix.md` §4 (text over illustrations)
and §5 (vision vs deterministic editing).

## Problem

The V8 pipeline translates text that exists as PDF text objects. When a book bakes
text **into a raster illustration** (no selectable text, letters are pixels of the
artwork), the contract renderer has nothing to redact/replace, so the English text
survives on the translated page. This module handles that case: detect the baked-in
text, erase it from the artwork, and place translated text on top — for **any book,
any background**, verified by AI.

## Scope

IN scope:
- Detect text baked into raster illustrations on any page (not PDF text objects).
- Erase it deterministically (default) or generatively (opt-in) without destroying
  surrounding artwork.
- Overlay translated text as live PDF vector text on top of the repaired region.
- Verify the result with the existing `VisualQaService` (GPT-4o compare).
- Be book-agnostic — no per-book rules, no hardcoded coordinates or colours.
- Fail closed: uncertain background / low confidence → route to review, never
  silently paint a rectangle over artwork.

OUT of scope (this iteration):
- PDF text-object replacement (already handled by the V8 contract renderer).
- Text used as a clipping mask (special-cased to review).
- Print/CMYK colour management (documented limitation; output is RGB raster region).

## Requirements

### R1 — Detection (AI eyes, deterministic hands)
1.1 GIVEN a rendered page image, WHEN detection runs, THEN GPT-4o vision SHALL return
    for each baked-in text region: `source_text`, `bbox_normalized [x0,y0,x1,y1]`,
    `background_type` (flat|gradient|illustration|uncertain), `background_sample_regions`,
    and `protected_regions`.
1.2 The system SHALL specify exact input image dimensions, top-left origin, and
    coordinate convention to the model, and SHALL validate every returned coordinate
    is within [0,1] and forms a positive-area box.
1.3 Detection SHALL cross-check against native PDF text extraction: a region that
    corresponds to real PDF text SHALL be excluded (that path owns it).
1.4 The model SHALL propose sampling regions; the system SHALL measure actual pixel
    colours from those regions — it SHALL NOT trust model-reported RGB values.

### R2 — Erase (background repair)
2.1 The system SHALL erase located text using a deterministic inpaint (OpenCV Telea/NS
    or edge interpolation) by default.
2.2 A generative inpaint route SHALL be available behind an opt-in flag; when used it
    SHALL reconstruct background only, be composited via an explicit deterministic mask,
    and SHALL be routed to human review (never auto-approved).
2.3 The removal mask SHALL cover the glyphs AND their full shadow/antialiasing (rounded
    outward) so no halo or ghost remains.
2.4 WHEN `background_type` is `uncertain` OR flat-background uniformity checks fail,
    THEN the system SHALL NOT fill; it SHALL route the page to review with a diagnostic.

### R3 — Overlay (translated text)
3.1 Translated text SHALL be placed as live PDF vector text (not baked into pixels),
    using an embedded font with the required glyphs.
3.2 Text SHALL be measured with the actual font; overflow and missing glyphs SHALL be
    detected explicitly; size reduction/wrapping SHALL be bounded (no stretching).
3.3 Placement SHALL preserve the original's alignment, baseline, and colour intent.

### R4 — Verification (AI compare)
4.1 After overlay, the page SHALL be re-rendered and compared to its source by
    `VisualQaService`; a flagged page SHALL route the edition to `NEEDS_LAYOUT_REVIEW`.
4.2 Verification SHALL use the same-page comparison already implemented; no new
    per-book logic.

### R5 — Integration & safety
5.1 The whole module SHALL be OFF by default behind a config flag; enabling it SHALL NOT
    change the default render path when there is no baked-in text.
5.2 All OpenAI usage SHALL be mockable; tests SHALL make NO live API calls.
5.3 Every decision (route, geometry, colour source, confidence, verify outcome) SHALL be
    logged for reproducibility.
5.4 Any single-page failure SHALL be non-fatal to the book render (fail to review, not crash).

## Acceptance (mirrors brief §7, illustration subset)
- Baked-in English text is not visible in the saved PDF.js render.
- Translated text is legible, aligned, within bounds, correct glyphs.
- Surrounding artwork, borders, and protected regions remain intact (no halo/seam).
- Uncertain/complex backgrounds are routed to review, not filled with a rectangle.
- Default-off path renders byte-for-byte as before.
- Tests pass with mocked OpenAI; no live calls.
