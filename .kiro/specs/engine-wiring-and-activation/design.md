# Engine Wiring & Activation — Design

**Created:** 2026-10-07 · **Author:** Naz · Companion to `requirements.md`.

## Principle

We are NOT writing new capability. We are connecting existing, already-written modules to the one
live engine, one at a time, each proven on a real render. The hard risk is R2 (no two competing
paths): several LOST modules overlap something already inline in `pdf_translate_v8.py`. So the FIRST
move for every module is "does the live engine already do this inline?" — if yes, the module is a
DELETE?/reconcile, not a wire-in. We resolve overlaps before adding anything.

## Live integration points (where wiring attaches)

The live engine has exactly these seams a module can hook into:
1. **Pre-flight (before render)** — in `PdfTranslationService::createTranslatedPdf`, before the
   `pdf_translate_v8.py replace` subprocess. Good for input/asset validation (font integrity).
2. **Engine internals** — inside `pdf_translate_v8.py` render passes (document_model scene build,
   per-page renderers, the gate stack). Good for capabilities that must see per-unit geometry.
3. **Post-render gates** — after the PDF is saved, before `readiness()` decides publishable. The
   QaReport monotonic-merge already lives here (text-layer verification, visual QA attach here).
4. **Subprocess (PHP → python)** — `new Process(['python','scripts/x.py', ...])`, the pattern
   `page_manifest`/`illustration_*`/`cover_retypeset` already use. Good for heavy/isolated steps.

Every wired module MUST record its result into the `qa_report` (so it's observable and fail-closed via
`readiness()`), and MUST be gated by config where it costs money or time (vision/API/whole-book).

## Dependency order (why this sequence)

1. **Resolve DELETE? overlaps FIRST** — otherwise we might wire a module that fights an inline
   equivalent (violates R2). Confirm each DELETE? candidate's inline replacement, remove it.
2. **Font-asset-integrity preflight (R-W3)** — self-contained, seam #1, highest value (prevents the
   counterfeit class). No dependency on other wiring.
3. **Output text-layer verification (R-W4)** — seam #3, self-contained post-render gate.
4. **Illustration-repair trio (R-W5)** — depends on the already-live IllustrationTextService; the
   heaviest, so last. Config-gated + review-gated.
5. **Document DORMANT + wire the CI audit (R-W7/R-W8)** — housekeeping, any time.
6. **Full-engine book test (R-W9)** — only meaningful after 2–4.

## Component designs

### C1 — DELETE? reconciliation (do first)
For each of scene_graph, visual_qa.py, pdf_digital_twin, pikepdf_integration, container_detection,
list_detection, merged_cells: (a) repo-wide grep (py/php/mjs/md/json) for the name; (b) identify the
inline/live equivalent that supersedes it (e.g. render_gate+table path supersede merged_cells;
document_model+inline region graph supersede scene_graph; PHP VisualQaService supersedes visual_qa.py);
(c) if genuinely unused → delete; (d) if referenced → reclassify in triage, do NOT delete. Output: an
updated triage + a short "reconciliation note" per module. No behaviour change to the live engine.

### C2 — Font-asset-integrity preflight (R-W3)
New thin entry (reuse, don't rebuild): a python CLI `font_integrity.py` (or extend `font_registry`)
that, given the fonts dir + the set of font family names the book's manifest requests, returns per
font: {requested, file, internalName, matches:bool, glyphGapsForTargetText}. Uses `font_registry` to
read internal names and `glyph_preflight`/`typography_fingerprint` for coverage/identity.
- **Seam:** PHP pre-flight (seam #1), subprocess call, BEFORE render.
- **Fail-closed:** any `matches=false` (counterfeit/mislabeled) or glyph gap on a required font →
  record `qa_report['font_integrity']` with the offending font + reason, set the edition
  NEEDS_LAYOUT_REVIEW, and (policy decision) either block render or render-with-alias-and-flag.
  Default: flag + allow the approved-alias substitution we already have, but NEVER a silent
  unapproved file. Config `bookstore.font_integrity.enabled` (default true — it's cheap, no API).
- **Proof:** plant a counterfeit (rename a different ttf to AdLibBT-Regular.ttf), render → preflight
  flags it, qa_report records it, edition not publishable. Remove plant. Regression test from the
  real mismatch we already hit.

### C3 — Output text-layer verification (R-W4)
Wire `text_verification` as a post-render gate (seam #3): extract text from the SAVED pdf, confirm the
translated strings are present + searchable (not tofu/garbled ToUnicode). Record
`qa_report['text_layer']`; a corrupt layer fails closed. Cheap (no API) → default on.
- **Proof:** a page known-good passes; a deliberately corrupted-ToUnicode fixture fails.

### C4 — Illustration-repair trio (R-W5)
`IllustrationTextService` already detects + classifies regions (live, gated). Wire the repair half:
`artwork_repair` (background-preserving raster repair) + `crop_transform` (source→render→crop→model→
patch coordinate transforms) + `image_inpainting` (background-only inpaint, generative=review). These
are invoked as subprocess steps the service already uses for its python half.
- **Fail-closed:** uncertain background / protected-region overlap / coordinate degeneracy →
  NEEDS_LAYOUT_REVIEW, never a guessed rectangle. Generative repair is ALWAYS mandatory-review.
- **Config:** stays behind `bookstore.illustration_text.enabled` (default off) — it costs vision API.
- **Proof:** a book with real baked-in illustration text → repair runs, output shows the translated
  label on the artwork OR routes to review; verified via the PDF.js harness, not PyMuPDF pixmap.

### C5 — CI audit + DORMANT docs (R-W8/R-W7)
Add `python scripts/wiring_audit.py` to the test runner (assert no NEW dead production module appears
vs a committed baseline). Add one-line DORMANT notes to the steering LIVE SYSTEM STATE.

## Verification harness (shared)
- Real renders go through `createTranslatedPdf` (the production path), reusing stored
  item_translations (no translation-API spend). Engine-only re-render = cheap.
- Acceptance uses the REAL PDF.js harness (`render_pdfjs.mjs`) for anything visual, never PyMuPDF
  pixmap alone (it hides soft-mask/ToUnicode defects).
- Every change: `$env:PYTHONIOENCODING="utf-8"`; run the Python suites + Laravel Feature; render
  My House #10000 af AND a second book; clean temp files; update the audit.

## Risks / guardrails
- **R2 overlap:** wiring a module that duplicates inline logic → ALWAYS do C1 reconciliation first.
- **Cost blowout:** anything calling a model stays config-gated + queue/console-only (per the Q1
  heavy-gates rule already in PdfTranslationService).
- **False "done":** a module that only DETECTS must be allowed to FAIL CLOSED, not just log — else we
  re-create the "gate exists but never bites" problem (exactly how the counterfeit fonts shipped).
