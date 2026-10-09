# Reconciliation — "wired (done)" vs "working (NOT done)"

**Author:** Naz · **Date:** 2026-10-10 · **Trigger:** Captain Zan put real eyes on a real browser
render of #10001 and found defects a "COMPLETE, 226 tests green" status had hidden.

## The one-sentence truth
**The engine is WIRED but NOT COMPLIANT.** The `engine-wiring-and-activation` spec (are the modules
reachable from the live render path?) is genuinely done. The `v8-brief-compliance` work (does a
rendered page actually LOOK right — fit, typography, casing, no overflow/overlap?) is **Phase 1–3,
all boxes still unchecked.** Two specs, two different definitions of "done," and the wiring one was
reported as if it were the layout one. That is the whole story.

## Why green tests + a passing gate still shipped visible defects
Verified by reading the live code this session (not memory):

1. **`render_gate.py` only strictly checks elements that carry a precise `cell_box`**, and only for
   roles `heading / table_header / merged_header / end_marker`. Its own comment: content/prose/
   artwork cells "share a full-column box … for those we only verify PRESENCE + approved FONT."
   → The cover subtitle, the ®, and the back-cover series list are prose/artwork roles WITHOUT
     precise per-element boxes → the gate never ran in-box / peer-size / overflow on them →
     a double-placed, overflowing subtitle and a wrong-font back cover both reported `visual: passed`.
   This is exactly Phase 1.2 ("post-render glyph gate for ALL page types") still being unchecked.

2. **The pieces exist but were never VERIFIED against rendered output and ticked (R4/R6).**
   `render_gate` has peer-size + font-approval checks (part of 2.6); `pdf_translate_v8` has
   `_cap_height_ratio` (part of 2.4); the forbidden flat-split (`splitTranslatedText`/`mergeLines`)
   is already removed from the live path (2.2 largely satisfied). So "unchecked" ≠ "unbuilt" — it
   means "built, never confirmed on a real page." That is the precise gap.

## The confirmed defects, each mapped to an existing (unchecked) task
| Defect seen in browser (#10001)                                   | Owning task (already written)        | Status      |
|-------------------------------------------------------------------|--------------------------------------|-------------|
| Cover subtitle "Speel saam met my" overflows the panel            | 1.1 clip all page types / 1.2 gate   | not built/verified for cover |
| Logo ® orphaned / mis-placed (double-inventoried)                 | 2.x region ownership + 1.2 gate      | dedup bug (see below) |
| Copyright page: mixed font sizes, "self" overlaps line above      | 2.6 hierarchy+peer / 1.1 clip        | not verified for prose |
| Back cover in a script font; interiors in a sans (inconsistent)   | 2.4 font resolution/visual-size match| not built/verified |
| Title context ("Speel saam met my" vs "Speel met my")             | (new) translation context assembly   | prompt lacks book/series context |

### Known concrete bug (not just "unverified")
`IllustrationTextService::filterContractOwnedRegions()` dedup is DEAD because
`contractOwnedRegions()` requires a 4-number `bbox` on each manifest REGION, but the manifest stores
geometry on the ITEMS inside each region (confirmed: page-1 regions report `bbox=(no bbox)`). So the
cover subtitle + ® get inventoried BOTH as native spans AND as artwork regions, and placed twice →
overflow + orphaned ®. Fix = union item bboxes to rebuild region geometry (+ text/id dedup safety net).

## What is actually TRUE about the engine (so we stop oscillating)
- Modules execute; one live engine path; flat-split removed; fail-closed routing works.
- A real visual QA capability ALREADY EXISTS (`VisualQaService`, gpt-4o, `VISUAL_QA_ENABLED=true`) —
  do NOT rebuild it. Its weakness is the same as the gate's: it flags on a sample and its coverage of
  cover/prose typography defects is weak enough that these slipped. Tightening it is Phase 1.2/3.4,
  not a new build.

## The plan (uses the checklist that ALREADY EXISTS in v8-brief-compliance.md)
NOT a vision-gate build (exists). NOT bug-by-bug patching. Work the written Phase 1→2→3 list in
dependency order, and VERIFY each item the doc's Section 6 way: render 2 books, LOOK at the pages,
run the gate — tick only when the user-visible outcome is right on ≥2 books.
Immediate order:
1. (done) Correct the misleading LIVE SYSTEM STATE header so cold-start isn't lied to.
2. Phase 1.2 FIRST (make the gate actually cover cover/prose/back-cover) — because until the gate can
   SEE these defects, no fix can be verified. This is the "honest eye" that was supposedly done.
3. Then 1.1 clipping, 2.4 fonts, 2.6 hierarchy, + the dedup bug + title-context — each re-verified
   through the now-honest gate, on #10000 AND #10001, by looking.

## Honesty note (R4)
I previously reported this engine "COMPLETE" off the wiring spec while the compliance checklist sat
at zero. That was wrong. "Wired" was true; "working" was not; I conflated them. This document exists
so that conflation cannot recur on a cold start.
