# Engine Module Triage — unified-rendering-and-testing

**Created:** 2026-10-07 · **Author:** Naz · Companion to `WIRING_AUDIT_MECHANICAL.md`.

Every `scripts/*.py` module that the mechanical audit found is **NOT reachable from the one live
render engine** (`pdf_translate_v8` + `page_manifest`) is triaged below. Each module's PURPOSE is
its own docstring (so this is grounded in what the code says it does, not its filename). Each is
assigned ONE verdict:

- **DORMANT** — real, working feature deliberately gated OFF (config flag) or invoked only on a
  specific admin/subprocess path. NOT a bug. Leave as-is; document the flag.
- **LOST** — meaningful brief functionality that SHOULD be in the live path but isn't wired. This is
  the "we built it and never plugged it in" bucket. Candidate to wire.
- **DELETE?** — superseded, duplicated by the inline engine, or a one-off; safe to remove after a
  per-module confirm (grep for any doc/CLI/subprocess ref first — see CAVEAT).

> **CAVEAT (verification rule):** "not reachable" = no python import, no PHP `Process([...])`, no
> test import. Before DELETING any row, re-grep the whole repo (incl. *.md, *.php, *.mjs, Makefiles)
> for the module name. A couple may be referenced in docs or run ad-hoc. DORMANT/LOST rows need no
> deletion. Nothing in this doc is deleted yet — it is a decision sheet.

---

## TEST-ONLY (built + tested, no production caller) — 12

| Module | Purpose (docstring) | Verdict | Brief ref / note |
|---|---|---|---|
| artwork_repair | artwork-preserving raster repair (Phase 5.1/5.2/5.4, R6) | **LOST** | Brief §6/§7 artwork repair. Core to translating text-on-illustration. Should be wired behind the illustration path (which IS partly live via IllustrationTextService). High-value. |
| crop_transform | explicit coord transforms for artwork repair (R7) | **LOST** | Pairs with artwork_repair (brief §7 CropTransform). Wire together or not at all. |
| scene_graph | Scene Graph Builder | **DELETE?** | Superseded: the engine has its OWN inline region graph (pdf_translate_v8 ~L4290: "scene_renderer.py removed; graph lives inside the one production engine"). Standalone scene_graph.py is the old design. Confirm then remove. |
| ocr_integration | OCR Integration | **LOST** | Brief: OCR fallback for baked-in/raster text. Not wired — a scanned/image-only page can't be read today. Wire if such books are in scope. |
| script_detection | RTL/Complex Script Detection | **DORMANT→LOST** | Brief §2 RTL. Needed only when a non-Latin target language ships; today only af. DORMANT for current scope, LOST the day an RTL language is added. |
| inventory_layout | the `inventory-layout-2` canonical contract | **LOST** | Looks like an alternate canonical layout contract. Overlaps page_manifest (live). Verify it's not a competing contract before wiring/deleting. |
| page_inventory | Full Page Object Inventory | **LOST** | Brief: full object inventory (incl. non-text). Overlaps page_manifest; confirm scope difference. |
| content_cache | Content-Addressed Caching | **DORMANT** | Perf optimisation; safe to leave until render cost matters. Not a correctness feature. |
| incremental_render | Incremental Re-rendering | **DORMANT** | Perf: re-render only changed pages. Nice-to-have; the full-edition re-render is correct, just slower. |
| content_stream_surgery | Content-Stream Surgery | **DORMANT** | Low-level PDF surgery helper; the engine currently uses redaction+redraw. Keep as a tool. |
| corpus_fixtures | synthetic fixtures for the verification corpus | **KEEP (test infra)** | Legit test-support; not meant to be live. Not dead. |
| security | Security Hardening | **LOST** | Input/path hardening for the python entrypoints. SHOULD be wired (defense-in-depth on PDF inputs). Review what it enforces. |

## DEAD (no import, no subprocess, no test) — meaningful features — ~20

| Module | Purpose (docstring) | Verdict | Brief ref / note |
|---|---|---|---|
| font_registry | Font Registry (analyse every font file) | **LOST** | FONT-DETECTION subsystem. Reuse for the font-asset-integrity preflight (catch counterfeits like today's). HIGH value. |
| glyph_preflight | Glyph Preflight Verification | **LOST** | Pre-render glyph-coverage. font_policy has a smaller inline check; this is the fuller one. Wire into render start. |
| optical_calibration | Optical Font Calibration | **LOST→maybe DORMANT** | Compensates optical size diff between source + substitute fonts. Relevant NOW that we substitute (AdLibBT→PlaypenSans etc.). Could visibly improve substitutions. |
| typography_fingerprint | Typography Fingerprints (match unknown font by metrics) | **LOST** | The "detect what font this really is" engine. Pair with font_registry for the integrity preflight. HIGH value — directly addresses the counterfeit-font class. |
| visual_qa | AI Visual QA Engine (python) | **DELETE?** | SUPERSEDED by the live PHP `VisualQaService` (turned ON today). This python one is the old subset module. Confirm no caller, then remove to avoid confusion. |
| quality_gates | Quality Gates & Continuous Improvement | **LOST** | Brief §8 structured fail-closed QA. Overlaps the live render_gate + QaReport. Verify overlap; likely partially superseded, partially LOST. |
| text_verification | Searchable/Selectable Text Verification | **LOST** | Brief: verify the output text layer is real/searchable (the ToUnicode concern that bit us before). Should be a gate. |
| accessibility | Tagged PDF / Accessibility | **LOST** | Brief: tagged/accessible PDF output. Not wired → outputs are not tagged. Real gap if accessibility is a requirement. |
| audit_trail | Full Audit Trail | **LOST** | Brief §9: provenance/audit. Partially covered by RenderFingerprint (live). Confirm overlap. |
| variable_fonts | Variable Font Support | **DORMANT** | Only relevant if a variable font is adopted; none in the fonts dir. Leave. |
| image_inpainting | Local Image Inpainting | **DORMANT/LOST** | Scaffold for background inpaint (brief §6). Pairs with artwork_repair; wire together when illustration repair goes live. |
| pdf_digital_twin | PDF Digital Twin | **DELETE?** | Ambitious full-model twin; appears superseded by document_model (live) + page_manifest. Verify no unique capability, then remove. |
| pikepdf_integration | pikepdf Integration | **DELETE?** | Alt PDF lib path; engine standardised on PyMuPDF (notes: no numpy/cv2, pure PyMuPDF+PIL). Likely abandoned branch. |
| raster_fallback | Raster Fallback Mode | **DORMANT** | Full-page raster fallback; brief says reviewed-fallback-only. Keep as opt-in; confirm it's reachable when needed. |
| caption_detection | Caption and Label Detection | **LOST** | Detects captions/labels — relevant to illustration labels (brief §3). Overlaps artwork labelling. |
| container_detection | Container Detection | **DELETE?** | Likely superseded by the live `universal_containers` + document_model generic-structure attach. Verify overlap. |
| list_detection | Semantic List Detection | **DELETE?** | Likely superseded by inline list handling in document_model. Verify. |
| merged_cells | Merged Cell Detection | **DELETE?** | Table merged-cells; the live render_gate + table_structure already handle merged headers. Verify overlap. |
| translation_variants | Translation Variants | **DORMANT/LOST** | Alt-translation handling; not in current flow. Low priority. |
| render_comparison | Render Comparison Tool | **KEEP (dev tool)** | Dev/diagnostic comparison; like wiring_audit, a tool not engine code. Not "dead" in the bad sense. |

## ONE-OFF / HELPER SCRIPTS (correctly not wired) — ignore
analyze_page2, render_page2_check, render_page15_check, show_qa_report, view_pdf, get_translations,
render_book, visual_coverage_cli (the last IS live-subproc). These are scratch/diagnostic; not part
of the audit concern.

---

## SUMMARY FOR CAPTAIN ZAN

**The pattern:** the ChatGPT brief was implemented feature-by-feature into separate modules, but most
were never wired into the single live engine. So the capability EXISTS as code yet does nothing at
runtime. That is the precise shape of your worry.

**Buckets (meaningful modules):**
- **LOST (should be wired — real missing functionality):** artwork_repair + crop_transform +
  image_inpainting (illustration repair), font_registry + glyph_preflight + typography_fingerprint +
  optical_calibration (font-detection/integrity — the counterfeit-catcher), text_verification,
  accessibility, ocr_integration, security, quality_gates/audit_trail (verify overlap), caption_detection.
- **DORMANT (fine as-is, config/perf/scope-gated):** content_cache, incremental_render,
  content_stream_surgery, variable_fonts, raster_fallback, translation_variants, script_detection
  (until an RTL language), plus the already-live-but-gated cover_retypeset/illustration_*/visual_coverage.
- **DELETE? (superseded/duplicate — confirm then remove):** scene_graph, visual_qa(py),
  pdf_digital_twin, pikepdf_integration, container_detection, list_detection, merged_cells.

**Highest-value wiring targets (my recommendation, in order):**
1. **Font-asset-integrity preflight** — reuse font_registry + typography_fingerprint to fail-closed on
   a counterfeit/mismatched font BEFORE render. Directly prevents a repeat of today.
2. **text_verification** gate — guard the searchable-text-layer (the ToUnicode defect class).
3. **artwork_repair + crop_transform + image_inpainting** — wire the illustration-repair trio behind
   the already-live IllustrationTextService (biggest brief feature still only half-connected).

**How you'll keep knowing:** re-run `python scripts/wiring_audit.py` any time; it regenerates
WIRING_AUDIT_MECHANICAL.md. Add it to CI to catch "built-but-never-wired" at the moment it happens.

**NOT YET DONE:** no module deleted, no feature wired. This is a decision sheet. Next step needs
Captain Zan's pick of which LOST features to wire and which DELETE? candidates to confirm-and-remove.
