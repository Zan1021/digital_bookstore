# Wiring & Dead-Code Audit — unified-rendering-and-testing

**Date:** 2026-10-02 · **Scope:** is the admin interface actually on the new code
(readiness authority + orchestrator + testing/coverage/educational gates), and is there
duplicated / old-system / unused code? Read-only audit — no code changed.

---

## (a) Admin entry point → does it reach the new orchestrator / readiness / gates?

| Entry point | Route | Reaches new code? | Evidence |
|---|---|---|---|
| ReviewQueue | admin.review-queue | **WIRED** | calls `createTranslatedPdf` (ReviewQueue.php:241, :296) + `markApproved`/`canBePublished` (:380, :387); readiness panel (:readinessReport) |
| EngineCompare | admin.engine-compare | **WIRED** (fixed A2) | `createTranslatedPdf` (EngineCompare.php:91); status via `canBePublished()` (:60) |
| BookReviewer | admin.review | **WIRED** | `createTranslatedPdf` (BookReviewer.php:133); saveEdit un-approves + re-renders |
| BookManager | admin.book | **WIRED (async)** | dispatches `TranslateEditionJob` (BookManager.php:92/94) → Job calls `createTranslatedPdf` with reentrant lock owner |
| TranslateEditionJob | (queue) | **WIRED** | `createTranslatedPdf` (TranslateEditionJob.php:104), passes lock owner |
| BookReviewDetails | admin.book-details | **BYPASS (by design, different gate)** | `publish()` → `BookClassificationService::publish()` (BookReviewDetails.php:103). This publishes the BOOK on metadata completeness, NOT edition render readiness. See risk R1. |
| BookOnboarding | admin.onboard | **n/a** (ingest/classify, no render/publish) | — |
| FontManager | admin.fonts | **n/a** (typography policy editor) | — |
| Dashboard / BookUpload | admin / admin.upload | **n/a** | — |
| Console: RetranslateBook / TranscreateBook / TestPdfTranslation | CLI | **WIRED** | all call `createTranslatedPdf` |

**Verdict on coverage:** every RENDER path goes through the single orchestrator
(`createTranslatedPdf`) and every EDITION approval goes through `markApproved → canBePublished
→ readiness → CandidateReadiness`. One true bypass exists at the BOOK-publish level (R1).

---

## (b) Duplicated / old-system code paths

1. **Two render-report persistence sites, two shapes** — `createTranslatedPdfInner` persists the
   FULL gated report incl. `output_sha256` (PdfTranslationService.php:661), but the per-item
   re-render helper `reRenderPageItems` persists `render_status`+`qa_report` only, with
   `publishable = $report['publishable'] ?? true` and **NO fingerprint / NO output_sha256**
   (PdfTranslationService.php:1178-1185). Consequence: a per-item re-render leaves the edition
   WITHOUT a refreshed output hash, so `readiness()` will read it as stale/INVALID_GATE_INPUT
   until a full render runs. **Recommendation: consolidate — have the per-item path recompute
   fingerprint + output_sha256 (or route through the same tail as the full render).**

2. **Visual QA invoked in two places** — the OLD subset `VisualQaService::review()` is still
   called directly in `createTranslatedPdfInner` (PdfTranslationService.php:~452), AND the new
   `BookTestingService::visualCoverage()` ALSO calls `VisualQaService::review()` over the whole
   book (BookTestingService.php:86). If BOTH `visual_qa_enabled` AND
   `visual_coverage_gate.enabled` are on, the vision model runs TWICE (subset + whole-book) →
   double spend + two `visual*` checks on the QaReport. **Recommendation: when the coverage gate
   is enabled, SKIP the subset call (coverage subsumes it).**

3. **Whole-page `translated_text` edit path still live** — BookReviewer page editor + the
   `TranslatedPage.translated_text` column coexist with the per-ID `layout_overrides` path. The
   approval side was consolidated (A3), and both now re-render through `createTranslatedPdf`, so
   this is **acceptable, not a bypass** — but the dual store remains a latent source of
   confusion. **Recommendation: leave for now; document that per-ID overrides win.**

---

## (c) Genuinely dead / barely-wired NEW code

1. **`BookTestingService::visualCoverage()` is reachable ONLY when
   `bookstore.visual_coverage_gate.enabled=true` (default FALSE)** — PdfTranslationService.php:515.
   So out of the box the whole-book coverage ledger NEVER runs. It is correct + tested, but
   DORMANT by default. (Intentional per brief/API-budget, but worth stating plainly: shipping
   as-is, coverage is off.)
2. **`educationalCheck()` only does work when `exercise_contract` is populated** — nothing in the
   current ingest/translation path WRITES `exercise_contract` yet, so in practice it always
   returns `not_applicable` today. The gate is wired but has no data to act on until an
   exercise-extraction step is built. **Not dead, but inert pending an upstream producer.**
3. **`QualityReportService`** — confirmed **ZERO callers** anywhere in `app/` (only its own file
   matches). Already documented advisory-only. **Recommendation: leave (documented) or delete in
   a cleanup; it is not referenced by any gate or screen.**
4. **`Translation::STATE_PUBLISHABLE`** constant — defined (Translation.php:118) but never
   assigned anywhere. Minor dead constant. Leave/remove.

---

## (d) Correctness risks

- **R1 (real, medium): BookReviewDetails `publish()` bypasses edition render-readiness.**
  `BookClassificationService::publish()` sets `book.status='published'` based ONLY on metadata
  completeness (primary_category, language, book_type, age_range, description —
  BookClassificationService.php:96-120). It never checks `Translation::canBePublished()` /
  `readiness()`. So a book can be marked "published" (and surface in the public store, which
  filters on published books) even if its translated EDITION failed layout/visual/educational
  QA. The store/reader then serve whatever `rendered_pdf_path` exists.
  **This is the single most important finding.** Recommendation: gate `publish()` so a book with
  translated editions requires each surfaced edition to be `canBePublished()` (or only expose
  approved editions in the store query).
- **R2 (minor): per-item re-render staleness** — see (b)1; a per-item re-render makes the edition
  temporarily unreadable to the readiness gate (no fresh hash). Fail-closed, so it blocks rather
  than wrongly passes — safe direction, but surprising.
- **R3 (noted, not a bug): `readiness()` binds the EDITION-level fingerprint+output_sha256 to each
  passed QA check** (Translation.php:154-160) rather than per-check identities. Correct for the
  current single-render model (all checks ran against the one artifact). Becomes wrong only if
  checks are ever recorded across different candidates — revisit when per-layer records land.

---

## (e) Prioritised fix list

1. **R1 — close the book-publish bypass** (store should never surface an edition that isn't
   `canBePublished()`). Highest value; it's the one place a failed edition can still reach a reader.
2. **(b)2 — de-duplicate visual QA** (skip subset when coverage gate on) to avoid double API spend.
3. **(b)1 / R2 — per-item re-render should refresh fingerprint + output_sha256** (consolidate with
   the full-render tail).
4. **Cleanup — remove/relocate `QualityReportService` + the unused `STATE_PUBLISHABLE`** (optional).
5. **Pre-live-pass — flip `visual_coverage_gate.enabled` on** for the LV run so coverage actually
   exercises; note educational stays inert until an exercise producer exists.

---

## One-line verdict
**The interface is substantially on the new code — every RENDER and every EDITION approval flows
through the new orchestrator + readiness authority — BUT it is NOT fully wired: the book-level
`publish()` (BookReviewDetails → BookClassificationService) bypasses edition render-readiness
(R1), two gates are dormant by default/by missing upstream data, and there is duplicate visual-QA
spend and a stale-hash seam on the per-item re-render.**


---

## FIXES APPLIED (2026-10-02, autonomous pass — all 5)

1. **R1 — publish bypass CLOSED.** `Translation::publishEdition()` + `isStoreVisible()` added
   (Translation.php): a translated edition can only be marked published / surfaced when it
   passes `canBePublished()`; English source is trusted. `BookClassificationService::
   missingRequirements()` now adds `publishable_edition` when a book has editions but none is
   render-ready, so `publish()` refuses. Tests: PublishGateTest (+4) — failed-edition blocks,
   ready-edition publishes, English source counts, publishEdition guard. 
2. **(b)2 — duplicate visual QA REMOVED.** The subset `VisualQaService::review()` in
   createTranslatedPdfInner is now skipped when `visual_coverage_gate.enabled` is on (coverage
   subsumes it) — no double vision spend. (PdfTranslationService.php ~439.)
3. **(b)1 / R2 — per-item re-render staleness FIXED.** `reRenderItems()` now recomputes
   `render_fingerprint` + `output_sha256` and invalidates stale approvals, same as the full
   render tail — so a per-item re-render no longer leaves the edition unreadable to readiness.
   (Live-proven in the LV pass; logic covered by existing readiness tests.)
4. **Cleanup.** Deleted dead `QualityReportService` (zero callers, confirmed). Kept
   `STATE_PUBLISHABLE` constant (harmless; removing a public const risked unseen refs — low value).
5. **Live-pass prep.** Coverage gate default stays OFF in config (correct for normal ops /
   budget); it will be enabled via `.env` as the FIRST step of the authorized LV pass, not
   committed. (Educational gate stays inert until an exercise producer exists — unchanged.)

**Verification:** full suite **186/186** (was 182; +4 publish-gate). All lint-clean. Dead-service
deletion broke nothing (confirms it was unused).

**Updated verdict:** the interface is now fully on the new code for render + approval + PUBLISH
— the R1 book-publish bypass is closed. Remaining dormancy (coverage gate off by default,
educational inert pending an exercise producer) is by-design/awaiting-upstream, not a bypass.


---

## LV LIVE PASS FINDINGS (2026-10-02, autonomous — My House #10000 / af #8, local, real engine)

Ran a REAL render through `createTranslatedPdf` on My House. Results + what it proved/caught:

### PROVEN end-to-end (LV1 core)
- Full render completed on the real book; staging→public promotion ran.
- `render_fingerprint` + `output_sha256` were populated after render (e6ea0873… / 26502ee6…)
  — confirms the A1 identity binding + the R2 per-render hash logic work on a real artifact.
- `readiness()` returned `ready=false` with `BLOCKING_RENDER_STATE: NEEDS_LAYOUT_REVIEW`, and
  `canBePublished()=false` — the FAIL-CLOSED path works end-to-end on a live render.
- D1 lock behaved: a SIGKILLed render (10-min timeout) left the per-edition lock held; a second
  run was correctly refused ("already being rendered"); `Cache::lock(...)->forceRelease()`
  cleared it. REAL-WORLD NOTE: a hard-killed render holds the lock until its 900s TTL or a
  manual forceRelease — acceptable (fail-closed) but worth an ops note.

### 🐞 BUG CAUGHT BY THE LIVE PASS (did NOT show in any unit/feature test)
**The persisted `qa_report` came back EMPTY (zero keys) after a real render, while
`render_status=NEEDS_LAYOUT_REVIEW`.** Because `readiness()` reads `qa_report['checks']`, an
empty report means NO required check is ever `passed` → `readiness()` can NEVER return true for
a real render, even one that should pass. In this case the edition was independently
NEEDS_LAYOUT_REVIEW so it was blocked correctly, but the empty-report shape is a latent blocker
for the happy path.
- Likely cause: the Python engine's stderr JSON report was not parsed into an array at the
  point `$report['qa'] = $qaReport->toArray()` runs, so the structured QA (built unconditionally
  as `$qaReport`) is never embedded, and `qa_report` persists as `$report` (empty/`[]`).
- This is the single most important live finding: the deterministic tests seed `qa_report`
  by hand (QaReport::toArray()), so they never exercised the engine→report→persist path that
  real renders use. **The gates are correct; the WIRING of the engine's report into the
  structured QaReport is not proven on real output.**

### PERFORMANCE finding
- Whole-book visual coverage gate ON = ~1 GPT-4o vision call PER PAGE (×16) + illustration
  detect per page + PDF.js — exceeds a 10-min synchronous render. It MUST run via the queued
  job (TranslateEditionJob) or a background worker, NOT a synchronous admin request. Confirms
  the gate is correctly opt-in and should be queue-only.

### NOT RUN (needs attended session / breadth)
- M5 educational live (no exercise_contract producer exists — inert, expected).
- M6 whole-book coverage to completion (too slow sync; run via queue).
- M9 browser-drive overlay (Playwright available this session, but needs the app served +
  admin session; deferred to an attended run).
- Multi-publisher breadth: only My House imported; 11 more PDFs on disk (incl. Math Fun =
  different publisher) not yet ingested.

### RECOMMENDED NEXT (attended)
1. **Fix the engine-report→QaReport wiring** so a real render persists `qa_report['checks']`
   (root of the empty-report bug). Highest priority — without it the happy path can't pass.
2. Run the heavy coverage/illustration pass via the QUEUE, not sync.
3. Import Math Fun (+ a couple Kolulu) for a 2nd/3rd book; drive the browser overlay via
   Playwright with the app served.

`.env` was restored to safe defaults (all heavy flags OFF) after the run; config:clear'd.
Full suite 186/186 green.
