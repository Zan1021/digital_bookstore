# Wiring Audit — Triage (2026-10-10)

Re-ran `scripts/wiring_audit.py` against current code (Naz). Compared to the 2026-10-07
baseline (LIVE 15+11, TEST-ONLY 12, DEAD 29).

## Current totals
- **LIVE-IMPORT: 24** · **LIVE-SUBPROC: 19** → **43 modules genuinely live** (was 26)
- **TEST-ONLY: 6** (was 12)
- **DEAD: 13** (was 29 — more than halved)

### Notable improvement
The font-detection subsystem that 10-07 flagged DEAD — `font_registry`, `glyph_preflight`,
`optical_calibration`, `typography_fingerprint` — is now **LIVE-IMPORT**, because the new
`font_integrity.py` preflight (wired LIVE-SUBPROC via PdfTranslationService) imports them.
i.e. building the counterfeit-font preflight resurrected the very detector that could have
caught the original counterfeits. No longer "paid for, not used."

## DEAD (13) — disposition

### Safe to delete — one-off debug/dev scripts (8), NOT engine modules
`analyze_page2`, `render_page2_check`, `render_page15_check`, `capture_evidence`,
`get_translations`, `render_book`, `show_qa_report`, `view_pdf`
→ throwaway diagnostics from earlier debugging. Recommend delete. (Low risk; none imported.)

### DEAD but CORRECT — keep
`wiring_audit` — the audit tool itself (a CLI; nothing should import it). Not a defect.

### Real built-but-unwired FEATURES — DECISION NEEDED (do NOT delete blind) (4)
- `raster_fallback` — raster render fallback path.
- `render_comparison` — pixel comparison (the 10-07 note said it masks out text; superseded
  in practice by VisualQaService's vision compare).
- `translation_variants` — alternative translation candidates.
- `variable_fonts` — variable-font axis handling.
→ Each is a genuine capability. Options per module: WIRE (if wanted), or DELETE (if abandoned).
  Needs Captain Zan's call; flagged, not actioned.

## TEST-ONLY (6) — disposition
- `corpus_fixtures`, `make_gate_fixtures` — legitimate TEST SUPPORT. Correct as-is.
- `content_cache`, `content_stream_surgery`, `incremental_render`, `script_detection` —
  latent features: built + unit-tested but no production caller. Keep (tested) or wire when
  the need arises; none are dead weight in the render path.

## Recommendation
1. Delete the 8 one-off debug scripts (quick, safe) — pending Captain Zan's OK.
2. Decide WIRE-vs-DELETE on the 4 real dead features (raster_fallback, render_comparison,
   translation_variants, variable_fonts).
3. Leave TEST-ONLY as-is; they are either test support or tested latent features.
4. Keep re-running `scripts/wiring_audit.py` as the source of truth (not memory).

NOTE: no files deleted in this pass — triage only. Deletions await Captain Zan's go.
