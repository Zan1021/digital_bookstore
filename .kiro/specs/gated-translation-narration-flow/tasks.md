# Implementation Plan — Gated Translation & Narration Flow

- [ ] 1. Schema: edition approval + narration invalidation
  - Migration: add `page_approvals` (json, nullable), `approved_at` (timestamp, nullable)
    to `translations`.
  - Migration: add `is_outdated` (boolean, default false) to `narrations`.
  - Update `Translation` + `Narration` `$fillable`/`$casts`.
  - _Requirements: 4.4, 5.2, 6.1_

- [ ] 2. Translation model — approval & narration-gate helpers
  - `pageApprovalProgress()`, `allPagesApproved()`, `markApproved()` (guarded by
    `allPagesApproved()` + `canBePublished()`; sets `render_status = APPROVED`,
    `approved_at`).
  - `isApprovedForNarration()` — true for `en`; for others requires `render_status = APPROVED`.
  - Unit tests for each.
  - _Requirements: 5.1, 5.2, 5.3, 4.4_

- [ ] 3. Per-edition teardown helper
  - Add `Translation::deleteAssociatedFiles()` (rendered PDF, comparison dir, edition
    narration files) + `static::deleting` hook cascading `translatedPages` and the
    edition's narration.
  - Ensure `Book::deleting` still removes everything (regression guard).
  - Feature test: deleting one edition leaves the original + other editions intact.
  - _Requirements: 7.1, 7.2_

- [ ] 4. Decouple upload from translate/narrate
  - Strip the `languages`, `voice`, and processing translate/narrate calls from
    `BookUpload::startProcessing`; upload SHALL only create the draft book, extract text,
    apply crop, and redirect to the book management page.
  - Preserve duplicate-title guard and multi-file upload.
  - Feature test: upload creates a `draft` book with pages and NO translations/narrations.
  - _Requirements: 1.1, 1.2, 1.3, 1.4_

- [ ] 5. Queued translation job
  - `App\Jobs\TranslateEditionJob(translationId)`: set `TRANSLATING` → run
    `TranslationService::translate` + render → evaluate gate → `READY_FOR_REVIEW` or
    `NEEDS_LAYOUT_REVIEW` (from `qa_report.publishable`).
  - Idempotent: clear existing translated pages + rendered PDF + comparison renders first.
  - Write/refresh a `ProcessingJob` (type `translation`) for progress; `$tries = 2`;
    record failure state + message on throw.
  - _Requirements: 3.1, 3.3, 3.4, 3.5_

- [ ] 6. BookManager — dispatch translation + live status
  - Add per-language **Translate** action creating/reusing the edition and dispatching
    `TranslateEditionJob`; return immediately.
  - `wire:poll` edition status (TRANSLATING/RENDERING/AUTOMATED_QA/READY_FOR_REVIEW/
    NEEDS_LAYOUT_REVIEW) from `render_status` + ProcessingJob.
  - Retry action for a failed edition (re-dispatch) without re-upload.
  - _Requirements: 3.1, 3.2, 3.4_

- [ ] 7. Original-language narration — immediate & ungated
  - BookManager: always-available **Narrate (English)** control post-upload; runs
    `NarrationService::narrate` on original text; independent of any translation state.
  - Feature test: English narration succeeds with zero translations present.
  - _Requirements: 2.1, 2.2, 2.3, 2.4_

- [ ] 8. Compare-and-edit review view
  - `Admin\TranslationReview` (extend `BookReviewer`/`ReviewQueue`): side-by-side EN source
    page vs translated page (from `books/comparison/{id}_{lang}` renders).
  - Flag pages/elements with `qa_report.structureDeviations` in red.
  - Per-page **Approve/Reject**, persist to `page_approvals`, show aggregate progress.
  - **Approve edition** action (enabled when `allPagesApproved()`) → `markApproved()`.
  - _Requirements: 4.1, 4.2, 4.4, 4.5, 5.2_

- [ ] 9. Inline edit + single-page re-render
  - Editable translated text per page in the review view; **Save & re-render page** calls
    a single-page re-render via the V8 **contract path** (no legacy flat mapping) and
    refreshes the right-hand image in place.
  - Land the English-leak fix here (Fix C: never fall back to English `source_text` on a
    non-empty translated page; route to review instead).
  - Unit/feature: editing a page updates its render; leaked-English page no longer renders
    English after edit.
  - _Requirements: 4.3, 8.1_

- [ ] 10. Narration gate for translated editions
  - BookManager: render **Narrate (<lang>)** for a translated edition ONLY when
    `isApprovedForNarration()`; otherwise hidden/disabled with reason.
  - `NarrationService` (or `narrateEdition`) asserts approval at execution time; throws
    `NarrationNotAllowed` if not APPROVED (independent re-check).
  - Feature test: narration refused pre-approval, allowed post-approval.
  - _Requirements: 5.1, 5.3, 5.4_

- [ ] 11. Narration invalidation on post-approval edit
  - In `TranslationReview::savePage`: if a completed narration exists for the edition, set
    `is_outdated = true`.
  - Reader route treats `is_outdated` narration as not-current; BookManager shows
    "re-generate narration".
  - Re-generating narration clears `is_outdated` and replaces audio files.
  - Feature test: edit-after-narration flags outdated; re-generate clears it.
  - _Requirements: 6.1, 6.2, 6.3_

- [ ] 12. Queue worker + honest status
  - Local: document/run `php artisan queue:work`. Ensure UI shows "queued" (not "done")
    when a job hasn't started.
  - Optional demo fallback flag to run translation synchronously if no worker.
  - _Requirements: 3.1, 3.2_

- [ ] 13. Regression sweep + manual E2E
  - Run full Unit + Feature suites green (Req 8.2); confirm store shows only publishable
    (Req 8.3).
  - Manual E2E on clean slate: upload → translate af → review/fix p15/p16 → approve →
    narrate → verify `/read/{book}?lang=af` and `/raw-pdf/{book}?lang=af`.
  - _Requirements: 8.1, 8.2, 8.3_
```
```
Notes:
- Tasks 1–3 are backend foundations (schema + model + teardown), safe and test-first.
- Tasks 4–7 rewire the flow (upload decouple, queued translate, English narration).
- Tasks 8–11 are the review gate + narration gating + invalidation (the heart of the ask).
- Tasks 12–13 are ops + verification.
- English-leak fix (Fix C) intentionally lands in task 9, where single-page re-render lives.
```
