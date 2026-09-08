# Design — Gated Translation & Narration Flow

## Overview

Reshape the pipeline from a single synchronous wizard into three decoupled stages driven
by the existing `Translation` edition-state machine:

```
UPLOAD (Book: draft)
  └─ original-language narration  ── available immediately (ungated)

TRANSLATE (per language, queued)
  Translation.render_status: TRANSLATING → RENDERING → AUTOMATED_QA
    → READY_FOR_REVIEW            (gate passed)
    → NEEDS_LAYOUT_REVIEW         (gate flagged, fail-closed)

REVIEW  (compare EN ↔ target, inline edit, per-page re-render, per-page approve)
    → APPROVED                    (all pages approved)

NARRATE (translated edition)      ── unlocked only when APPROVED
    edit after narration → narration.status = 'outdated'
```

Almost all backend machinery exists. This is primarily **rewiring** plus one queued job,
one comparison UI, a couple of nullable columns, and a narration-gate check.

## Architecture & existing pieces reused

| Concern | Existing piece | Change |
| --- | --- | --- |
| Edition states | `Translation` consts + `isPublishable/canBePublished` | reuse; add page-approval + narration-gate helpers |
| Render gate | `scripts/render_gate.py`, `qa_report`, `structureDeviations` | reuse; surface deviations in compare UI |
| Reviewer UI | `BookReviewer`, `ReviewQueue`, `book-reviewer.blade.php` | extend into side-by-side compare + inline edit |
| Translation run | `PdfTranslationService`, `TranslationService::translate` | wrap in a queued job; make idempotent |
| Narration | `NarrationService::narrate`, `Narration` model | gate translated editions; add `outdated` state |
| Upload | `BookUpload` (wizard) | strip translate/narrate; end at book page |
| Teardown | `Book::deleting` (done this session) | reuse; add per-edition file cleanup |
| Queue | `QUEUE_CONNECTION=database`, `ProcessingJob` | add worker; job writes ProcessingJob progress |

## Data model changes (migrations)

1. **`translations`** — add:
   - `page_approvals` JSON nullable — `{ "<page_number>": { "approved": bool, "approved_at": ts } }`.
     (Per-page approval; edition APPROVED when all reviewable pages approved.)
   - `approved_at` timestamp nullable — when the edition reached APPROVED.
   - (Reuse existing `render_status`, `qa_report`, `layout_overrides`, `item_translations`.)

2. **`narrations`** — add:
   - `is_outdated` boolean default false — set true when approved translated text changes
     after narration; cleared on re-generate.
   - `language_code` already exists → a narration is per-edition-language, so English and
     each translated edition each own a narration row.

No columns are dropped. All additions are nullable/defaulted (safe for existing rows,
though the DB is currently empty).

## Components / classes

### Jobs
- `App\Jobs\TranslateEditionJob(int $translationId)`
  - Sets `render_status = TRANSLATING`, runs `TranslationService::translate` +
    `PdfTranslationService` render, evaluates the gate, then sets `READY_FOR_REVIEW` or
    `NEEDS_LAYOUT_REVIEW` from `qa_report.publishable`.
  - **Idempotent:** clears the edition's existing `translatedPages`, rendered PDF, and
    comparison renders before regenerating (reuses the per-edition teardown helper).
  - Writes progress to a `ProcessingJob` row (type `translation`) for UI polling.
  - On throw: `render_status` unchanged-or-`NEEDS_LANGUAGE_REVIEW`, `qa_report.error` set,
    `ProcessingJob` marked failed. Retryable (`$tries = 2`).

### Livewire
- `Admin\BookManager` (exists) — becomes the hub:
  - Original-language **Narrate** control: always visible post-upload (Req 2).
  - Per-language **Translate** control: dispatches `TranslateEditionJob` (Req 3);
    shows live edition status via `wire:poll` against ProcessingJob/`render_status`.
  - Per-translated-edition **Review** link → compare view.
  - Per-translated-edition **Narrate** control: rendered only when
    `$translation->isApprovedForNarration()` (Req 5); disabled with reason otherwise.
- `Admin\TranslationReview` (extend `BookReviewer`/`ReviewQueue`) — the compare view:
  - Left: English source page image; Right: translated page image (from comparison
    renders already produced by the engine, `books/comparison/{id}_{lang}/...`).
  - Inline editable translated text per page; **Save & re-render page** action calls a
    single-page re-render (reuse `PdfTranslationService` scoped to one page) and refreshes
    the right image.
  - Pages with `qa_report.structureDeviations` flagged red (Req 4.2).
  - Per-page **Approve/Reject**; aggregate progress; **Approve edition** enabled when all
    reviewable pages approved (Req 4, 5.2).

### Model helpers (Translation)
- `pageApprovalProgress(): array` → `[approved, total]`.
- `allPagesApproved(): bool`.
- `markApproved(): void` → sets `render_status = APPROVED`, `approved_at = now()` (guarded
  by `allPagesApproved()` and `canBePublished()`).
- `isApprovedForNarration(): bool` → `language_code !== 'en' ? render_status === APPROVED : true`.

### NarrationService gate
- `narrate()` (or a thin `narrateEdition`) SHALL assert the edition is approved for
  narration before spending API calls (Req 5.4); throws `NarrationNotAllowed` otherwise.
- On a translated-edition text edit (in `TranslationReview::savePage`), if a completed
  narration exists for that edition, set `narration.is_outdated = true` (Req 6.1).
- Reader route: when serving audio, treat `is_outdated` narration as not-current (Req 6.2).

## Narration ↔ edition mapping

A `Narration` is keyed by `book_id` + `language_code`. English narration and each
translated-edition narration are distinct rows. This lets the gate apply only to
non-`en` editions and lets invalidation target a single language.

## Flow: sequence (translated edition)

1. Admin opens book page → clicks **Translate → Afrikaans**.
2. `BookManager` creates/reuses the `af` Translation (`TRANSLATING`), dispatches
   `TranslateEditionJob`, returns immediately. UI polls.
3. Job translates + renders + gates → `READY_FOR_REVIEW` (or `NEEDS_LAYOUT_REVIEW`).
4. Admin opens **Review** → compares pages, edits the leaking back-cover/vocab spans,
   re-renders those pages, approves each.
5. All pages approved → **Approve edition** → `APPROVED`.
6. **Narrate (Afrikaans)** now appears → generates audio for the approved text.
7. If admin later edits an approved page → that edition's narration flagged `outdated`.

## Testing strategy

- **Unit:** `Translation` helpers (`allPagesApproved`, `isApprovedForNarration`,
  page-approval accounting); narration-gate rejects non-approved editions.
- **Feature:**
  - Upload creates draft book only; no translation/narration side effects (Req 1).
  - English narration works with no translation present (Req 2).
  - `TranslateEditionJob` moves state correctly on success and on gate-flag; idempotent
    re-run replaces not duplicates (Req 3).
  - Narration action refused for un-approved translated edition; allowed after approval
    (Req 5).
  - Editing approved text flags narration outdated (Req 6).
  - Per-edition delete removes edition files only (Req 7); full suite still green (Req 8).
- **Manual E2E (the real test we set up):** clean slate → upload → translate af →
  review/fix p15/p16 English leak → approve → narrate → verify reader + raw-pdf.

## Risks / notes

- **Queue worker required** for async translation. Locally: `php artisan queue:work`.
  Forge: daemon (same pattern as the Mahala queue-worker TODO). If the worker is not
  running, translations sit queued — the UI must show "queued" honestly, not "done".
- Single-page re-render must reuse the exact V8 contract path (no legacy flat mapping) so
  edits don't reintroduce the English-leak fallback. This intersects the still-open
  English-leak fix (Fix C/B from the 2026-08-30 notes) — the compare/edit step is where
  Fix C ("never fall back to English source_text on a non-empty translation") should land.
- Keep synchronous path available behind a flag for a fast demo fallback if the worker is
  unavailable.
