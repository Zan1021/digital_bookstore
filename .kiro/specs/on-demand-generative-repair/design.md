# On-Demand Generative Repair — Design

**Created:** 2026-10-07 · **Author:** Naz · Companion to `requirements.md`.

## Reuse, don't rebuild

Nearly everything exists and is proven (C6). This feature is a THIN per-page orchestration
layer + a review-queue UI, over the already-live pieces:
- `IllustrationTextService` — detect / cheap-inpaint / generative-inpaint / verify pipeline.
- `VisualQaService::review(book, translation, [pageNumbers])` — already accepts a page subset
  and returns per-page status records. The per-page re-compare is a direct call.
- `illustration_text.py repair ... --generative-bg` — the generative path, already wired.
- `ReviewQueue` Livewire component — already has currentPage, pages[], the flagged filter,
  and the per-page overlay panel to hang the button + two-version viewer on.

## The seam that changes (R1/R2)

Today generative is a GLOBAL config read inside `IllustrationTextService::process()` applied
to the whole book at render time. We add a NEW, NARROW entry point that does ONE page on
demand, independent of the global flag:

```
IllustrationTextService::repairPageGenerative(Book, Translation, int $pageNumber): array
  → runs detect + GENERATIVE inpaint + overlay for just $pageNumber
  → writes the result to a CANDIDATE artifact (NOT the live edition)
  → returns ['ok'=>bool, 'candidate_image'=>path, 'current_image'=>path, 'reason'=>..]
```

The global `process()` path is untouched (R1): normal renders still cheap-inpaint every page;
`ILLUSTRATION_TEXT_GENERATIVE` stays available only for a deliberate full-book run.

## Component designs

### C-A — Per-page generative candidate (R2/R3)
`repairPageGenerative()` renders BOTH:
- `current_image` — the page as it currently is in the edition (the cheap result).
- `candidate_image` — the page after generative inpaint, written to a scratch path keyed by
  (book, lang, page, fingerprint) so re-runs are idempotent and never touch the live PDF.
Fail-closed: detect/inpaint error → `ok=false`, both images untouched, reason returned.

### C-B — Apply the pick (R3/R5)
`applyPageVersion(Book, Translation, int $page, string $choice)` where choice ∈
{`keep_cheap`, `use_generative`}:
- `keep_cheap` → discard the candidate; mark the page "resolved by cheap, publisher-accepted".
- `use_generative` → splice the candidate page into the edition PDF (single-page replace,
  reusing the engine's page-level write), then re-run `VisualQaService::review` on JUST that
  page. Update the page's status; recompute edition readiness from all per-page statuses.
Both outcomes clear the page from "needs a decision". A still-failing generative re-compare
keeps the page flagged (escalate to manual).

### C-C — Review-queue UI (R4)
In `ReviewQueue`:
- On a flagged page, show a **"Fix with AI"** button (hidden on clean pages).
- Press → call `repairPageGenerative()` (queued/async so the ~1 min call doesn't block the
  request; button → "running…"). On completion, show the TWO images side by side with
  "Keep current" / "Use AI version" buttons.
- The pick calls `applyPageVersion()`; UI reflects applied + the re-compare verdict.
- Button/state machine: idle → running → choose → applied|failed.

### C-D — Audit + cost (R6)
`applyPageVersion(use_generative)` and each `repairPageGenerative()` call append an
audit_trail entry (reuse the C7 logAuditTrail pattern): page, user id, before/after compare
verdict, generative_spent=true. So per-page spend is traceable.

## Fail-safe / reversibility (R7)
- The live edition PDF is NEVER modified by `repairPageGenerative()` — only by an explicit
  `applyPageVersion(use_generative)`. Keep the pre-apply edition artifact so a bad apply can
  be rolled back to the cheap version.
- API/model failure → non-blocking UI error; page stays as-is.

## Verification
- Unit/Feature: `repairPageGenerative` writes a candidate without touching the live PDF;
  `applyPageVersion(keep_cheap)` is a no-op on the PDF; `applyPageVersion(use_generative)`
  replaces exactly one page and triggers a single-page re-compare; audit entry written.
- The generative call is MOCKED in tests (no real spend); a thin live smoke test is manual.
- Confirm no generative call path exists except through the per-page button (grep the render
  path still reads the global flag only for the deliberate full-book case).

## Risks
- **Cost leak:** ensure NO automatic trigger. The button is the only producer of a generative
  call in production. (Test asserts process() does not call repairPageGenerative.)
- **Single-page PDF splice correctness:** reuse the engine's existing page write; verify the
  other 15 pages are byte-stable after a single-page apply.
- **Async UX:** the ~1 min generative call must not hang the Livewire request — queue it.
