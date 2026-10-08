# On-Demand Generative Repair — Requirements

**Created:** 2026-10-07 · **Author:** Naz · For Captain Zan.

## Problem

Text baked into illustrations is repaired by the engine in two ways: a CHEAP deterministic
inpaint (free, fast, runs for every candidate page by default) and an EXPENSIVE generative
inpaint (gpt-image-1 — proven in C6 at ~R5–R45 and ~12 min for a 16-page book). Running
generative blanket-on across a whole book (and a whole catalogue) is slow and costly, and
most pages don't need it — the cheap path is usually fine.

## Goal

Spend generative ONLY where the cheap path demonstrably failed, under human control:
1. Every candidate page gets the CHEAP deterministic repair first (unchanged).
2. The AI compare gate (VisualQaService, already live) flags pages that came out wrong.
3. In the review queue, each flagged page gets a **"Fix with AI"** button that runs the
   generative repair for THAT ONE PAGE only.
4. The result is shown as **two versions side by side — cheap vs generative — and the
   publisher PICKS** which to keep (generative is not always better; it can hallucinate).
5. The chosen version is applied; the page is re-compared; a passing page clears.

So generative cost becomes proportional to actual, confirmed problems — not page count —
and a human is always in the loop (which generative already requires: AI-painted
backgrounds are mandatory-review).

## Requirements

### R1 — Cheap-first is unchanged, generative is NOT blanket-on
The default render path keeps running the deterministic inpaint for every candidate page.
Generative must NOT run automatically for a whole book from the render flag. The global
`ILLUSTRATION_TEXT_GENERATIVE` behaviour is retained ONLY for a deliberate full-book run
(e.g. the C6 proof); the normal production path leaves it off and escalates per-page.

### R2 — Per-page, on-demand generative entry point
A single-page generative repair action: given (book, language, page_number), run ONLY that
page through the generative inpaint path, producing a candidate repaired page WITHOUT
overwriting the current edition until the publisher accepts it. Idempotent and safe to
re-run. Fail-closed: an error leaves the current page untouched and reports why.

### R3 — Two-version compare + explicit pick (no blind replace)
The action produces BOTH the current (cheap) page image and the new generative page image
and surfaces them to the publisher. Nothing is applied until the publisher explicitly picks
one. Picking "keep cheap" is a valid, first-class outcome (discards the generative result).
Picking "use generative" applies the generative page to the edition.

### R4 — Button lives in the review queue, on flagged pages only
The "Fix with AI" button appears in `ReviewQueue` (admin) on pages the compare gate flagged
(status != passed / ILLUSTRATION_TEXT_REVIEW). It is NOT shown on clean pages (no wasted
spend). The button states reflect progress: idle → running → pick-a-version → applied.

### R5 — Re-compare after apply; honest status
After a generative page is applied, re-run VisualQaService on THAT page only. A pass clears
the page's flag; a still-failing page stays flagged (and may be escalated to full manual).
The edition's publishable/readiness recomputes from the updated per-page statuses.

### R6 — Cost + audit visibility
Each on-demand generative action is recorded (audit_trail): which page, who triggered it,
the compare verdict before/after, and that a generative call was spent. So the spend is
traceable per page, per user.

### R7 — Fail-safe + reversible
A generative attempt never destroys the existing accepted page. The publisher can always
fall back to the cheap version. A model/API failure is a non-blocking error surfaced in the
UI, not a crash.

## Out of scope
- Changing the detection/cheap-repair logic (already live + proven).
- Batch "generative-all" from the UI (deliberately avoided — defeats the cost goal).
- Narration, translation, non-illustration gates.

## Acceptance
- A flagged page can be generatively repaired from the review queue, producing two versions.
- The publisher picks; only the chosen version is applied; the page is re-compared.
- No generative call fires except on an explicit per-page button press.
- The action is audit-logged; the edition readiness recomputes correctly.
