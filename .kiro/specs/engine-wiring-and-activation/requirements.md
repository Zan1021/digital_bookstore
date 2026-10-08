# Engine Wiring & Activation — Requirements

**Created:** 2026-10-07 · **Author:** Naz · **Status:** draft (not started)
**Inputs:** `../unified-rendering-and-testing/WIRING_AUDIT_MECHANICAL.md` (live/dead map),
`WIRING_TRIAGE.md` (DORMANT/LOST/DELETE? verdicts), the two steering files
(`v8-brief-compliance.md`, `v8-structure-aware-engine.md`), and the two ChatGPT briefs under `Brief/`.

## Problem statement

The V8 engine's capabilities were implemented module-by-module from the ChatGPT brief, but the
mechanical wiring audit proves MOST of those modules are not reachable from the one live render
engine (`pdf_translate_v8` + `page_manifest`). 15 modules are import-live, 11 subprocess-live, 12
are test-only, and 29 are dead. The result: **the brief's functionality exists as code but much of it
does nothing at runtime.** Testing a rendered book therefore proves only that the wired fraction
works — it gives false confidence about the engine as a whole (Captain Zan: "it doesn't help to test
anything if all our modules are not wired in perfectly").

## Goal

Bring the engine to a state where **every capability the brief promises is either (a) wired into the
live path and proven on a real render, (b) deliberately DORMANT behind a documented config flag, or
(c) deleted as superseded** — with no silent "built but inert" middle ground. Only then is testing a
book meaningful.

## Standing rules (inherited — these GOVERN every requirement here)
From `v8-brief-compliance.md` / `v8-structure-aware-engine.md`:
- R1 Book-agnostic (verify on ≥2 different books; no title/page/lang/coordinate literals).
- R2 One live engine path; remove or fully isolate superseded paths (no two renderers).
- R4 "Done" = user-visible OUTCOME verified on a real render, NOT "tests tick".
- R5 Fail-closed: any unresolved capability routes the edition to review, never a silent pass.
- R6 Verify after every change (suite green + render ≥2 books + visual confirm + clean temp files).

## Requirements

### R-W1 — Every LOST module is resolved to a definite end-state
Each module the triage marks LOST is either WIRED into the live path (with proof) or explicitly
reclassified DORMANT (with a config flag + doc) or DELETE (with repo-wide reference check). No module
remains in an ambiguous "exists but unused" state at the end of this spec.

### R-W2 — Wiring is incremental and independently proven
Modules are wired ONE AT A TIME in dependency order. Each wired module must be demonstrated to (a)
actually execute on a real render of a real book (observable in the qa_report / output / logs), and
(b) change behaviour as intended (a before/after difference or a newly-caught defect). "The test
passes" is necessary but NOT sufficient (R4).

### R-W3 — Font-asset-integrity preflight (highest priority)
Before the engine renders, it must verify each font file's INTERNAL name matches the family the book
requests, and fail closed on a mismatch/counterfeit (the exact class of bug that shipped Bangers as
"AdLibBT"). Reuse the already-built `font_registry` / `typography_fingerprint` / `glyph_preflight`
rather than writing new detection. A counterfeit or glyph-incomplete font must route the edition to
NEEDS_LAYOUT_REVIEW with an actionable reason, never render silently.

**R-W3.1 — Prompt-and-upload resolution loop (restored from the original BookOnboarding design).**
A fail-closed flag is not a dead end. When a required family is missing, counterfeit, or
glyph-incomplete, the preflight must surface an ACTIONABLE "upload the correct TTF/OTF for
`<family>`" prompt wired to the EXISTING FontManager / BookOnboarding upload path (LIVE-SUBPROC
`font_resolver` / FontManager.php), per the original wizard spec (vault:
`2026-08-17_mem_font-detector-todo`, `2026-08-18_session-summary`,
`2026-08-18_mem_product-workflow-brainstorm`). The resolution loop is:
  1. detect → attempt auto-resolve (existing policy);
  2. on failure, flag the edition NEEDS_LAYOUT_REVIEW AND emit a per-family upload prompt;
  3. an approved alias may be applied ONLY as a temporary preview stand-in (never a silent
     unapproved file), so the publisher sees a render while the real font is outstanding;
  4. when the publisher uploads a genuine file, re-run the integrity check on it; if its internal
     name matches and glyphs are complete, clear the font flag for that family and (if no other
     blocker remains) return the edition to publishable.
No book is left stuck in review with no path forward — the upload prompt IS the path.

### R-W4 — Output text-layer verification
The engine must verify the SAVED PDF's text layer is real and searchable (the ToUnicode-corruption
class). Wire `text_verification` (or equivalent) as a post-render gate; a corrupt/garbled text layer
fails closed.

### R-W5 — Illustration-repair trio wired behind the existing live illustration path
`artwork_repair` + `crop_transform` + `image_inpainting` are connected behind the already-live
(config-gated) `IllustrationTextService` so text-on-illustration is actually repaired, not just
detected. Remains config-gated and review-gated per the brief (generative = mandatory review).

### R-W10 — Tagged-PDF / accessibility output (wire now — Decision 2)
The engine must emit an accessible output and verify it, rather than leaving `accessibility.py`
inert. Reuse the existing module (`check_pdf_accessibility`, `set_document_language`,
`extract_reading_order`, `generate_alt_text_placeholders`) — do NOT rewrite detection.
Concretely, on every render:
  1. **Set document language metadata** (`/Lang`) on the SAVED PDF to the edition's target language
     (BCP-47 via the module's `LANGUAGE_MAP`), so screen readers pronounce correctly. This is a
     deterministic, no-API, always-on step.
  2. **Verify accessibility as a post-render gate:** record `qa_report['accessibility']`
     (language set?, structure tree?, marked content?, score, recommendations). A render whose
     language metadata could NOT be set fails closed (NEEDS_LAYOUT_REVIEW) — that is a real
     regression. Missing structure-tree/alt-text is RECORDED as a recommendation but does NOT by
     itself sink the edition (PyMuPDF cannot synthesize a full StructTreeRoot; forcing it would be
     a false block). Alt-text placeholders are emitted into the report for human review, never
     auto-published as final.
  3. Config `bookstore.accessibility.enabled` (default true — cheap, no API). Fail-safe: a gate
     that cannot run records `ran=false` and never sinks the render.
No edition renders with the wrong (or absent) language tag silently.

### R-W11 — Scanned-page OCR fallback + caption/label classification (wire now — Decision 3)
Image-only (scanned) pages currently yield zero translatable spans and pass through invisibly —
exactly the "capability exists but does nothing" trap. Wire the two detection modules into the
pre-render manifest stage:
  1. **OCR fallback (`ocr_integration.py`):** during manifest build, classify each page with
     `is_scanned_page`; a scanned page (few spans + large image) is OCR'd via the best available
     backend (`ocr_for_manifest`, PyMuPDF→pytesseract) and its recovered spans feed the SAME
     translation/render pipeline. OCR is **best-effort and gated**: when no backend is available
     the page is flagged `OCR_UNAVAILABLE` → `qa_report['ocr']` + NEEDS_LAYOUT_REVIEW (a scanned
     page we cannot read must route to review, never silently drop its text). Low-confidence OCR
     (< threshold) is also flagged for review. Config `bookstore.ocr.enabled` (default true;
     no-op when a book has no scanned pages).
  2. **Caption/label classification (`caption_detection.py`):** during manifest build, run
     `detect_captions` over the page's text units + image bboxes and tag the matched units
     (`caption_type`, related image, position) in the manifest so the renderer can size and place
     them as captions, and so they are translated as captions rather than body. Deterministic,
     no-API, default on. This is informational metadata — it refines rendering, it does not gate
     publishability.
Both record their outcome into the `qa_report` (`ocr`, `captions`) so they are observable (R-W2),
and both degrade safely to a no-op on books that have neither scanned pages nor image-adjacent
captions.

### R-W6 — DELETE? candidates are confirmed and removed
`scene_graph`, `visual_qa.py`, `pdf_digital_twin`, `pikepdf_integration`, `container_detection`,
`list_detection`, `merged_cells` are each repo-grepped (incl. *.md/*.php/*.mjs); if genuinely unused
and superseded by an inline/live equivalent, they are removed so the codebase stops implying
capability it doesn't have. Any that turn out referenced are reclassified, not deleted.

### R-W7 — DORMANT modules are documented, not just left
Each DORMANT module (content_cache, incremental_render, variable_fonts, raster_fallback,
translation_variants, script_detection, content_stream_surgery) gets a one-line note in the steering
LIVE SYSTEM STATE: what it is, which flag/condition activates it, why it is off now. No orphan code
with no explanation.

### R-W8 — The audit stays honest (CI)
`scripts/wiring_audit.py` is added to the test/CI run so a future "built but never wired" module is
flagged the moment it appears. The steering LIVE SYSTEM STATE references the generated map, never a
hand-typed list.

### R-W9 — Only after R-W1..R-W8: a full-engine book test is meaningful
Once the live path exercises the intended capability set, run the end-to-end book test (My House +
a second, different book) and record which capabilities actually fired. This is the test that was
previously meaningless; it becomes the acceptance gate.

## Out of scope
- Sourcing the genuine licensed fonts (AdLibBT/Calibri/Edu-Aid/OzHandicraft) — the approved
  substitutes stand until Captain Zan supplies real files.
- The non-font NEEDS_LAYOUT_REVIEW causes on My House (grid-cross geometry, vocab consistency) —
  tracked separately; not caused by wiring.
- New capability beyond the brief. This spec WIRES what exists; it does not invent features.

## Acceptance (whole spec)
- `wiring_audit.py` shows ZERO modules in an undocumented LOST state (every one is live / dormant+
  documented / deleted) — INCLUDING accessibility, ocr_integration, caption_detection now LIVE.
- The font-integrity preflight catches a deliberately-planted counterfeit font on a real render
  (fail-closed), proven with a test built from a real mismatched file.
- Every rendered edition carries correct `/Lang` metadata and an `qa_report['accessibility']` score;
  a scanned page with no OCR backend routes to review (never silently drops its text); captions are
  tagged in the manifest and recorded in `qa_report['captions']`.
- A full book render exercises the wired capability set, logged in the qa_report; verified on ≥2 books.
- Steering LIVE SYSTEM STATE + the generated audit agree; CI runs the audit.
