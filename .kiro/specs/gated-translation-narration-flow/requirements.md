# Requirements — Gated Translation & Narration Flow

## Introduction

Today the admin upload wizard runs upload → translate → narrate as one synchronous
blast, before a human sees a single page. This means machine translations are narrated
(paid for) before anyone confirms they are correct, and a slow multi-language run holds
the browser tab hostage.

This feature reshapes the pipeline into a **quality-gated, stage-based flow**:

- The **original (publisher) book** is trusted — it is already proofed. Its narration is
  available immediately, with no compare/edit/approval step.
- **Translations** are machine-produced and therefore untrusted. Each translated edition
  must pass a **human compare-and-edit review** and be **approved** before its narration
  can be generated.
- Translation runs as a **queued background job** so the UI never blocks and failures
  retry instead of losing the whole run.
- Editing an already-narrated translation **invalidates that edition's narration** so
  audio can never silently mismatch the text.

The `Translation` model already has an edition-state machine
(`ANALYSING → TRANSLATING → RENDERING → AUTOMATED_QA → NEEDS_LAYOUT_REVIEW /
NEEDS_LANGUAGE_REVIEW → READY_FOR_REVIEW → APPROVED → PUBLISHABLE`), a render gate
(`render_gate.py`), and reviewer components (`BookReviewer`, `ReviewQueue`). This feature
wires those into the flow rather than building QA from scratch.

Language codes used below: `en` (English source), `af` (Afrikaans), `zu` (Zulu).

---

## Requirement 1 — Decouple upload from translation and narration

**User story:** As an admin, I want uploading a book to only create the ebook and extract
its text, so that I can choose translation and narration separately and deliberately.

#### Acceptance criteria
1. WHEN an admin uploads a PDF THEN the system SHALL create a `Book` (status `draft`),
   store the source PDF, extract per-page text, and detect crop marks, WITHOUT starting
   any translation or narration.
2. WHEN upload completes THEN the system SHALL route the admin to the book's management
   page rather than continuing into a translate/narrate wizard.
3. The upload step SHALL NOT call `TranslationService::translate` or
   `NarrationService::narrate`.
4. WHEN multiple PDFs are uploaded at once THEN each SHALL become an independent `Book`
   with the duplicate-title guard preserved.

## Requirement 2 — Immediate, ungated narration for the original book

**User story:** As an admin, I want to narrate the publisher's original book immediately,
because that content is already proofed and needs no review.

#### Acceptance criteria
1. WHEN viewing a book's management page THEN the system SHALL offer a narration option
   for the **original language** (`en`) at any time after upload.
2. The original-language narration SHALL NOT require any compare, edit, or approval step.
3. WHEN original-language narration is requested THEN the system SHALL run it against the
   original extracted text using the selected voice, drama, and speed settings.
4. The original-language narration availability SHALL NOT depend on any translation's
   state.

## Requirement 3 — Queued (asynchronous) translation

**User story:** As an admin, I want translation to run in the background, so the browser
never hangs and a failure can retry without losing everything.

#### Acceptance criteria
1. WHEN an admin requests translation into a language THEN the system SHALL create (or
   reuse) a `Translation` for that language, set `render_status = TRANSLATING`, and
   dispatch a queued job — returning control to the UI immediately.
2. WHILE a translation job is running THE system SHALL display the edition's live status
   (e.g. TRANSLATING → RENDERING → AUTOMATED_QA) on the management page.
3. WHEN a translation job completes automated QA THEN the system SHALL set the edition to
   `READY_FOR_REVIEW`, OR to `NEEDS_LAYOUT_REVIEW` when the render gate reports blocking
   layout deviations (fail-closed).
4. WHEN a translation job throws THEN the system SHALL record the failure on the edition
   (state + message) and SHALL allow the admin to retry without re-uploading the book.
5. The translation job SHALL be idempotent for a given edition: re-running it replaces
   that edition's translated pages and rendered PDF rather than duplicating them.

## Requirement 4 — Compare-and-edit review for translations

**User story:** As an admin, I want to compare the English and translated pages side by
side and fix errors, so that only correct content proceeds.

#### Acceptance criteria
1. WHEN an edition is `READY_FOR_REVIEW` or `NEEDS_LAYOUT_REVIEW` THEN the system SHALL
   present a page-by-page comparison of the English source page beside the translated
   page for that edition.
2. WHERE the render gate recorded structure deviations for a page THE system SHALL
   visually flag those pages/elements so the reviewer's attention is drawn to them.
3. WHEN a reviewer edits a page's translated text THEN the system SHALL persist the edit
   and re-render ONLY that page, updating the comparison in place.
4. WHEN a reviewer approves a page THEN the system SHALL record per-page approval state.
5. The reviewer SHALL be able to approve or reject at the page level and see aggregate
   progress (e.g. "12 / 16 pages approved").

## Requirement 5 — Approval gate before translated-edition narration

**User story:** As an admin, I want narration for a translated edition to be unavailable
until I have approved that edition, so I never pay to narrate wrong text.

#### Acceptance criteria
1. WHILE a translated edition is not `APPROVED` THE system SHALL NOT offer a narration
   action for that edition (control hidden or disabled with a reason).
2. WHEN every page of a translated edition is approved THEN the system SHALL allow the
   edition to transition to `APPROVED`.
3. WHEN a translated edition is `APPROVED` THEN the system SHALL offer narration for that
   edition's language.
4. The narration trigger for a translated edition SHALL independently re-check the
   edition is `APPROVED` at execution time (not rely solely on a hidden button), refusing
   otherwise.

## Requirement 6 — Narration invalidation on post-approval edits

**User story:** As an admin, I want the system to flag narration as outdated when I change
approved translated text, so published audio never mismatches the words on the page.

#### Acceptance criteria
1. WHEN a reviewer edits the translated text of an edition that already has a completed
   narration THEN the system SHALL mark that edition's narration as `outdated`.
2. WHERE an edition's narration is `outdated` THE system SHALL surface a "re-generate
   narration" prompt and SHALL NOT serve the outdated audio as current on the reader.
3. WHEN narration is re-generated for an edition THEN the system SHALL clear the
   `outdated` flag and replace the prior audio files.

## Requirement 7 — Complete teardown preserved

**User story:** As an admin, I want deleting a book or an edition to remove all its files
and records, so nothing orphans (regression guard for the delete fix).

#### Acceptance criteria
1. WHEN a book is deleted THEN the system SHALL remove all editions, pages, narrations,
   and every associated file on disk (existing `Book::deleting` behaviour).
2. WHEN a single edition (translation) is deleted THEN the system SHALL remove that
   edition's translated pages, rendered PDF, comparison renders, and any edition-specific
   narration files, without affecting other editions or the original.

## Requirement 8 — No regressions to the V8 engine or store

**User story:** As an admin, I want the existing engine, render gate, and store to keep
working, so this flow change does not break shipped functionality.

#### Acceptance criteria
1. The existing V8 render-gate and structure-comparison behaviour SHALL remain in force
   for translated editions.
2. Existing automated tests (Unit + Feature suites) SHALL continue to pass.
3. The public store SHALL continue to surface only publishable editions.
