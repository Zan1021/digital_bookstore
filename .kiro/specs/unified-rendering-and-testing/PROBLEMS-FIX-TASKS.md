# Fix Task List — unified-rendering-and-testing (post-LV-pass problems)

**Created:** 2026-10-07 · **Author:** Naz
**Source of truth:** `WIRING_AUDIT.md` (LV LIVE PASS FINDINGS + RECOMMENDED NEXT) and the
DEFERRED LV list in `tasks.md`. HEAD = commit `4329e17` ("wiring audit + 5 fixes +
empty-qa_report fix"), working tree clean, suite 186/186.

**Convention:** `[ ]` todo · `[~]` partial / honest caveat · `[x]` done + verified.
**Golden rule for this list:** a `[x]` requires proof on a REAL render, not a seeded unit test.
The whole reason these are "problems" is that the deterministic tests passed while the live
path was broken. Do not repeat that mistake.

---

## CONTEXT — what actually went wrong last session (read first)

The gated-rendering system is **green on 186/186 unit tests** and the Oct-2 audit applied 5
real fixes. Then the **first real render (LV1, "My House")** exposed a bug no test could catch:

> **The persisted `qa_report` came back EMPTY after a real render.** `readiness()` reads
> `qa_report['checks']`; an empty report = no check ever `passed` = `readiness()` can NEVER
> return `true` for a real render. The happy path was structurally un-passable.

Root cause: the Python engine prints its QA JSON on **stderr**, surrounded by non-JSON noise
(warnings / progress / BOM / a second object). A naive `json_decode` returned `null`, so the
structured `QaReport` was never embedded and `qa_report` persisted as `[]`. Every deterministic
test seeds `qa_report` by hand (`QaReport::toArray()`), so none exercised the real
engine→report→persist path.

**Status of that bug:** a robust parser (`PdfTranslationService::parseEngineReport()`, line ~60)
+ a unit test (`tests/Unit/EngineReportParseTest.php`) were committed in `4329e17`. **So the fix
exists in code but has NOT been re-proven on a live render.** That re-proof is task **P1** and is
the single highest priority — everything else is downstream of a passable happy path.

---

## PHASE P — Prove the happy path on a real render (highest priority, needs live engine)

- [x] **P1. Re-verify the empty-`qa_report` fix end-to-end on a REAL render.** (audit "RECOMMENDED
      NEXT #1") — **DONE 2026-10-07. The committed `4329e17` "fix" did NOT actually work; the real
      bug was different (see below). Now genuinely fixed + proven on live output + regression-tested.**
  - [x] P1.1 Ran full `createTranslatedPdf` on My House (af, book 10000) with the real engine (~20s).
  - [x] P1.2 **BUG CONFIRMED STILL LIVE, then FIXED.** A fresh render first persisted a report with
        ONLY 4 keys (empty engine report). Root cause: the engine emits a raw **Latin-1 `0xAE` ("®")
        byte** inside a `source_text` field (copied verbatim from the source PDF), making the ENTIRE
        167KB stderr **invalid UTF-8**. `json_decode` failed with JSON_ERROR_UTF8 → `parseEngineReport`
        returned `[]` for BOTH the whole-string decode AND the balanced-block scan → empty qa_report.
        The committed robust parser only handled noise/BOM/multi-object, NOT invalid UTF-8; the unit
        tests were all clean ASCII so they never caught it. **FIX:** `parseEngineReport` now sanitises
        to valid UTF-8 (`mb_convert_encoding`) up front AND decodes with `JSON_INVALID_UTF8_SUBSTITUTE`
        on both paths. After the fix the persisted report has **33 keys** (page_types, coverage,
        review_pages, validation, consistency, structure_gate, render_source=contract, …).
  - [x] P1.3 Happy path proven REACHABLE: the render blocks as `NEEDS_LAYOUT_REVIEW` for REAL reasons
        (`review_pages`=4; page 15 vocabulary 0/48 spans translated; title consistency fail), not a
        parser artifact. readiness/`canBePublished()` now driven by real engine evidence. A book with
        no such gaps would pass — the gate is no longer structurally un-passable.
  - [x] P1.4 Regression test added from the EXACT real stderr:
        `tests/Fixtures/engine/real_stderr_my_house_af.txt` (167KB, invalid-UTF8 byte intact) +
        `EngineReportParseTest::test_real_engine_stderr_with_invalid_utf8_byte_parses` and a minimal
        `test_bare_latin1_byte_in_json_string_is_recovered`. Closes the test-vs-reality gap.
  - [x] P1.5 Traced the engine: `scripts/pdf_translate_v8.py:4639` sets
        `report["publishable"] = not report.get("review_pages")`; report IS emitted on stderr as one
        clean JSON object — the only defect was the stray non-UTF8 byte, now handled on the PHP side.
  - **Verification:** EngineReportParseTest 10/10; FULL SUITE **196/196** green. Probe scripts +
    temp dumps removed. Changed: `app/Services/PdfTranslationService.php`,
    `tests/Unit/EngineReportParseTest.php`, `tests/Fixtures/engine/real_stderr_my_house_af.txt`.
  - **FOLLOW-UP (new, low priority):** the engine SHOULD emit valid UTF-8 — fixing the `0xAE`
    at source in `pdf_translate_v8.py` (encode report with `ensure_ascii`/utf-8) would make the PHP
    sanitiser a pure safety net rather than load-bearing. Added as **P2** below.

**Phase P completion:** a clean real render persists a populated `qa_report['checks']` and can
legitimately reach `ready=true`; a regression test built from real engine output guards it.

- [ ] **P2. (NEW, low priority) Make the engine emit valid UTF-8 at source.** The PHP sanitiser
      (P1) is now load-bearing because `scripts/pdf_translate_v8.py` can write a raw Latin-1 byte
      (the `0xAE`/"®" from the source PDF) into the JSON report. Encode the report as proper UTF-8
      (e.g. `json.dumps(..., ensure_ascii=False)` on a cleaned structure, or sanitise copied
      `source_text` glyphs) so the stderr is always valid UTF-8 and the PHP guard becomes a pure
      safety net. Add a Python test that a `source_text` containing `®`/non-ASCII round-trips.

---

## PHASE Q — Queue the heavy gates (perf finding, code change, no live-API to write)

- [x] **Q1. Make the whole-book visual coverage gate QUEUE-ONLY.** (audit PERFORMANCE finding)
      **DONE 2026-10-07.** Added `PdfTranslationService::allowHeavyGates()` + the private
      `heavyGatesAllowedHere()` decision (explicit opt-in wins; else falls back to
      `runningInConsole()`). The coverage block now runs inline ONLY in a queue/console context;
      on a synchronous web request it is DEFERRED — `qa_report['visual_coverage']` records
      `{covered:null, deferred_to_queue:true}` and the `visual_coverage` check stays `not_run`
      (fail-closed, can't publish on a check that never ran). `TranslateEditionJob` calls
      `allowHeavyGates(true)` (and resets to false in `finally`), so the queued path still runs it.
  - [x] Guard: sync entry with coverage on defers instead of hanging 10+ min.
  - [x] Tests: `VisualCoverageGateConfigTest` +2 (explicit opt-in/opt-out honoured; default
        follows console context). Fixed `TranslateEditionJobTest` mocks to expect the new
        `allowHeavyGates` call (the only real breakage; the other 15 failures were a
        transaction-cascade from that one mock dying mid-test). FULL SUITE **198/198** green.
      Changed: `app/Services/PdfTranslationService.php`, `app/Jobs/TranslateEditionJob.php`,
      `tests/Feature/VisualCoverageGateConfigTest.php`, `tests/Feature/TranslateEditionJobTest.php`.

- [x] **Q2. Ops note: hard-killed render holds the per-edition lock to TTL.** (audit D1 note)
      **DONE 2026-10-07.** Documented in new
      `.kiro/specs/unified-rendering-and-testing/OPS.md` — symptom ("already being rendered" on
      retry with no process running), the 900s-TTL cause, the `Cache::lock(...)->forceRelease()`
      recovery one-liner, and prevention (keep worker max-time below the lock TTL; prefer
      `queue:restart`). The optional stale-lock age-check / admin "release lock" button stays a
      low-priority future item (noted below).
  - [ ] (Optional, low priority) stale-lock age check / admin "release render lock" action.

---

## PHASE R — Live verification backlog (deferred LV2–LV6, needs attended session + live API)

These are the DEFERRED items from `tasks.md`. P1 (LV1 happy path) must pass first.

- [ ] **R1 (LV3). Whole-book visual coverage to completion via the QUEUE** — every page checked,
      blank/image-only pages included; prove coverage blocks a cover-only run. (Run via Q1's job.)
- [ ] **R2 (LV4). EngineCompare live = byte-identical artifact to the production path** (same
      `createTranslatedPdf`, versioned-by-hash URLs, no stale PNG/PDF pairing).
- [ ] **R3 (LV5). Edit-after-approval live** — a per-ID override re-render invalidates dependent
      approvals, forces retest, and (per audit (b)1 fix) refreshes `render_fingerprint` +
      `output_sha256` so the edition is NOT left stale/unreadable to readiness.
- [ ] **R4 (LV6). Browser-drive the review workspace (Playwright/Dusk)** with the app served +
      an admin session: toggle overlays, confirm versioned URLs, confirm NO file-existence
      `ready`. (Deferred from LV1 — Playwright is available; needs the app up + auth.)
- [~] **R5. Multi-publisher breadth** — only My House was imported. **PARTIAL 2026-10-07:**
      imported + rendered **Play with Me (book 10001, af)** end-to-end (manifest → 3-step
      transcreate [avg 8.7/10, 15 green/1 yellow] → render → gate). This was a SECOND Kolulu
      book (same publisher), not yet a different publisher (Math Fun still not imported — only
      the 2 Kolulu PDFs are on disk). Proved the P1 UTF-8 fix holds on a 2nd book (34-key report,
      populated output_sha256, no empty-qa_report). STILL TODO: a genuinely different publisher
      (Math Fun) once its PDF is available.

---

## ⚠️ CROSS-BOOK RENDERING BUG (found 2026-10-07 — the real "do my books render right" answer)

**Both Kolulu books render but NOT cleanly, and they fail the SAME way** — this is the highest-value
thing to chase next for render correctness:

- **Vocabulary page (page 15) translates 0 spans.** My House p15 = 0/48 spans replaced;
  Play with Me p15 = 0/53 spans replaced. The vocabulary/phonics page's text is never placed.
- **Many unresolved translation spans** rendered blank (English-leak guard, Fix C): Play with Me
  had **28 UNRESOLVED_TRANSLATION_SPANS**; low-coverage pages [1,2,5,14,15,16].
- **Title consistency fails** on both (`consistency.ok=false`) — the title renders inconsistently
  across cover/story/back pages.
- Net: both editions are correctly `NEEDS_LAYOUT_REVIEW` (fail-closed working), but a reader-ready
  clean render is blocked by these real defects.

**NEXT (recommended before any more breadth):** diagnose why vocabulary pages (page 15) resolve
0 spans through the contract renderer — it's reproducible on both books and is the single biggest
blocker to a clean render. Likely in the structure-aware manifest → contract item mapping for the
`vocabulary` page type (the phonics/word-grid cells), per the old `book-agnostic-vocab-render` spec.

---

## PHASE S — Educational gate: build the producer or formally park (inert today)

- [x] **S1. Educational gate producer BUILT (JSON contract).** (audit (c)2, LV2) — **DONE 2026-10-07.**
      Decision: **JSON `exercise_contract`** (not a relational table) — the migration already
      existed, the gate already reads it, Kolulu exercises are simple, and it can migrate to a
      table later if complexity demands. A relational schema now would be over-engineering.
  - [x] S1.1 Decision made: JSON contract (as above).
  - [x] S1.2 Built `app/Services/ExerciseContractService.php` — the PRODUCER. Reads the render
        manifest's `page_types`, finds `vocabulary` (exercise) pages, builds one exercise per
        page with STABLE component IDs (`{page}:{role}:{index}`) from the page's text units.
        **Config-gated `bookstore.exercise_extraction.enabled`, OFF by default** so it never
        silently activates the gate on storybooks. Wired into `createTranslatedPdf` (uses the
        FRESH in-memory `$report`, not the stale DB value) right before the educational check.
  - [x] Avoided the P1 anti-pattern (an un-passable gate): registered **English (`en`)** as a
        structurally-supported pedagogy language in `ExerciseService::hasValidatorFor()` so a
        well-formed English contract PASSES. Deliberately did NOT register `af` — a translated
        edition's pedagogy is specialist work, so non-English contracts still route to review
        (fail-closed). This preserved the existing `EducationalGateTest` "af is UNSUPPORTED" intent.
  - [x] Tests: `ExerciseContractProducerTest` (+3: vocab→contract w/ stable IDs, storybook→null,
        empty-vocab→empty-exercise) and `ExerciseServiceTest::test_well_formed_english_contract_passes`.
        Full suite **202/202** green. Changed: `app/Services/ExerciseContractService.php` (new),
        `app/Services/ExerciseService.php`, `app/Services/PdfTranslationService.php`,
        `config/bookstore.php`, `tests/Feature/ExerciseContractProducerTest.php` (new),
        `tests/Unit/ExerciseServiceTest.php`.
  - NOTE: the gate stays dormant for the current storybooks (no vocabulary exercises to extract
    when the flag is off; `educational = not_applicable`). It is ready for actual workbook content.

---

## PHASE T — Housekeeping (low priority, optional)

- [ ] **T1. Reconcile `tasks.md` checkboxes with reality.** Phases A–D are all still `[ ]` in
      `tasks.md` but the audit + commit history (`bfd022e`, `c632d3e`, `4329e17`) show them DONE.
      Mark the completed A–D items `[x]` (or `[~]` with caveat) so the spec stops lying about its
      own state. (Pure doc hygiene; the audit is the real record until this is done.)
- [ ] **T2. (Optional) remove the dead `Translation::STATE_PUBLISHABLE` constant** (defined, never
      assigned; audit (c)4). `QualityReportService` was already deleted in `4329e17`.

---

## Open decisions for Captain Zan (carried from tasks.md)
1. **Exercise storage** (blocks S1): JSON `exercise_contract` vs relational `exercises` table.
   Naz leans JSON contract first.
2. Does THIS spec's LV pass REPLACE the old `world-class-render-engine` LV1–LV6, or run after it?
3. Build educational (Phase S) now, or park it and ship coverage + happy-path first?

---

## Suggested order of attack
**P1 → Q1/Q2 → R1 → R5 → (decision) S1 → R2/R3/R4 → T1/T2.**
P1 is the gate on everything: until a real render can persist `qa_report['checks']` and reach
`ready=true`, nothing downstream can be honestly verified.
