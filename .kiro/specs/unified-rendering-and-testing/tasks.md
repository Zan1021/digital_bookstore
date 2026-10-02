# Tasks — Unified Book-Agnostic Rendering & Testing

**Spec:** `unified-rendering-and-testing`
**Convention:** `[ ]` todo · `[~]` partial/honest-caveat · `[x]` done + verified.
Each task names the requirement(s) it satisfies. Verify (tests green) before marking `[x]`.
Deterministic tests per task; the LV live-API pass is deferred to the end (one pass).

---

## PHASE A — Governance spine (close the bypasses) · highest leverage, no API

- [ ] **A1. `CandidateReadiness` authority** (Req 2)
  - [ ] A1.1 Create `app/Services/Qa/CandidateReadiness.php` — static `evaluate()` per design §3.1.
  - [ ] A1.2 Unit test `tests/Unit/CandidateReadinessTest.php`: passed-OK, stale-fingerprint,
        stale-hash, missing-check, missing-approval, INVALID_GATE_INPUT, duplicate-key dedupe.
  - [ ] A1.3 Add `translations.output_sha256` migration (nullable string).
  - [ ] A1.4 Compute + persist `output_sha256` on every staged render (extend
        `PdfTranslationService`); extend `approval_tracks[*]` to store it; extend `approveTrack`.
  - [ ] A1.5 Rewire `Translation::canBePublished()`/`isPublishable()`/`pageApprovalProgress()`
        to build server-policy required keys + final-PDF expected set and delegate to A1.1.
        Keep thin back-compat shims. (Req 2.5/2.6)
  - [ ] A1.6 Feature test: stale fingerprint blocks publish; valid passes; expected set comes
        from PDF/inventory not row count.

- [ ] **A2. Close the EngineCompare bypass** (Req 1)
  - [ ] A2.1 Delete direct `Process::run` in `EngineCompare::runEngine()`; dispatch
        `TranslateEditionJob` / call `PdfTranslationService::createTranslatedPdf`.
  - [ ] A2.2 Feed per-ID contract inputs, not whole-page `translated_text`. (Req 1.2)
  - [ ] A2.3 `mount()` + status: derive from readiness (A1), not `Storage::exists`. Show
        `rendered`/`testing-pending` until checks pass. (Req 1.4)
  - [ ] A2.4 Version comparison URLs by candidate hash (no stale PNG/PDF pairing). (Req 7.3)
  - [ ] A2.5 Feature test: EngineCompare invokes the shared service (spy/fake), never Process.
  - [ ] A2.6 Audit job timeout vs queue visibility (900 vs 90s); fix the mismatch. (Req 1.5)

- [ ] **A3. Consolidate the review approval model** (Req 3)
  - [ ] A3.1 BookReviewer per-page `review_status='approved'` no longer satisfies edition
        readiness — readiness only via A1. (Req 3.1)
  - [ ] A3.2 Route the whole-page edit path through canonical resolution + the same
        dependency invalidation as the region path; drop its independent gate-visible flag.
        (Req 3.4)
  - [ ] A3.3 Verify per-region edits still write `layout_overrides[regionId]` only (no parallel
        store) — regression test. (Req 3.3)
  - [ ] A3.4 Dependency-invalidation map on edit (semantics→language+visual+narration;
        container/font→layout+artwork+visual). (Req 3.2)
  - [ ] A3.5 Feature test: human approving a page with a failed automated check leaves the
        failure and blocks the gate. (Req 3.5)

**Phase A completion:** UI + production use identical inputs/pipeline; missing/stale checks
block approval; no file-existence `ready`; one readiness authority.

---

## PHASE B — Coverage ledger + inventory contract · no API

- [ ] **B1. Whole-book visual coverage ledger** (Req 4)
  - [ ] B1.1 `scripts/visual_coverage.py` — port `visual_coverage_issues`; expected set from
        final PDF/inventory incl. blank/image-only pages.
  - [ ] B1.2 Python test: all four issue codes + fail-closed on missing/duplicate/mismatch.
  - [ ] B1.3 PHP caller in `BookTestingService` (stub acceptable now); schema-validate visual
        records; bind to output hash + model/prompt versions. (Req 4.5/4.6)
  - [ ] B1.4 Harden `VisualQaService`: remove `ok`/`skipped` success on missing PDF/API/JSON →
        ledger fail/not_run; bounded retries; exhaustion ≠ pass. (Req 4.3)
  - [ ] B1.5 Feature test: cover-only run reports whole-book incomplete + blocks approval.

- [ ] **B2. `inventory-layout-2` contract** (Req 5)
  - [ ] B2.1 Add `source_kind` to `TextUnit`/`Region` (distinct from `semantic_role`),
        defaulting from existing data (additive, no removal). (Req 5.2)
  - [ ] B2.2 Serializer → `inventory-layout-2` JSON (schema_version/source_hash/
        analysis_version/pages/regions/tables/exercises).
  - [ ] B2.3 Persist forward+inverse transforms (fold `crop_transform.py`); round-trip test for
        CropBox offset / rotation / mixed page sizes. (Req 5.4)
  - [ ] B2.4 Validation pass: IDs, cycles, single-owner, parent/membership refs, finite
        confidence, geometry; empty capabilities ≠ universal support. (Req 5.5/5.6)
  - [ ] B2.5 `BookTestingService` inventory-coverage layer consumes it.

**Phase B completion:** every PDF page accounted for in coverage; inventory carries
source_kind + policy + transforms; transforms round-trip under test.

---

## PHASE C — Educational adaptation gate · greenfield, no API for structure

- [ ] **C1. Exercise component model** (Req 6)
  - [ ] C1.1 Decide storage: `translations.exercise_contract` (array cast) vs `exercises`
        table — lean JSON contract first (design §5).
  - [ ] C1.2 `app/Services/ExerciseService.php` — component slots (objective/instruction/
        pattern/examples/questions/answer_key) with stable component IDs.
  - [ ] C1.3 `componentIdIssues(expectedIds, components)` — unknown/duplicate/missing;
        unit test. (Req 6.2)
  - [ ] C1.4 Validate IDs/schema/roles/counts/language/group refs before persist on
        regeneration. (Req 6.3)

- [ ] **C2. Independent educational check** (Req 6)
  - [ ] C2.1 Educational validity feeds `BookTestingService` as its own layer; failure blocks
        approval regardless of layout/visual. (Req 6.4)
  - [ ] C2.2 Audience/curriculum from edition policy; no hardcoded ages/English phonics;
        unsupported → specialist review. (Req 6.5)
  - [ ] C2.3 Demote `QualityReportService` substring/length checks to advisory. (Req 6.6)
  - [ ] C2.4 Feature test: invalid exercise + perfect layout → approval blocked.

**Phase C completion:** educational failure blocks approval independently; invalid component
IDs rejected + visible; no silent English-phonics fallback.

---

## PHASE D — Orchestration safety + reporting + workspace polish · no API

- [ ] **D1. Safe orchestration** (Req 7) — confirm per-edition lock + staged→public promotion
      + prior-edition retention are enforced on ALL entry points (incl. the reworked
      EngineCompare); version images/PDFs by hash; promotion-failure recovery test.
- [ ] **D2. Reporting** (Req 8) — one persisted structured report (array cast; legacy decode)
      with candidate ID + input fp + output sha + expected pages/regions + per-layer results +
      approvals + aggregate readiness; layer-independence assertions.
- [ ] **D3. Workspace** (Req 1/3/4) — EngineCompare/ReviewQueue/BookReviewer show exact tested
      PDF, coverage counts, unresolved issues; human decisions vs automated status vs readiness
      visually distinct; filters: failed/review/not-tested/stale/approved-current/all.

**Phase D completion:** all pages/checks explicit; failures unresolved; UI shows the exact
tested candidate; no alternate route bypasses evidence.

---

## DEFERRED — one live-API verification pass (after ALL coding; standing directive)

- [ ] **LV1** Full live render→test→readiness round-trip on My House (af): staged → QA layers →
      coverage ledger → CandidateReadiness → approve tracks → promote. Failed render keeps prior.
- [ ] **LV2** Live educational validation on a real vocabulary/phonics page.
- [ ] **LV3** Live whole-book visual coverage (all pages checked, blank/image-only included).
- [ ] **LV4** EngineCompare live = identical artifact to production path.
- [ ] **LV5** Edit-after-approval live: dependents go stale, retest required.
- [ ] **LV6** Browser-drive the review workspace (Dusk/Playwright): toggle overlays, confirm
      versioned URLs, confirm no file-existence `ready`.

---

## PARKED — §17 external-repo experiments (behind governance + real corpus)

Reference memo: `C:\Users\zande\.kiro\vault\2026-10-02_mem_rendering-engine-reference-repos.md`.
Needs a real multi-publisher corpus (OPEN — Captain Zan to supply). Per repo, produce a
decision table (adapt idea / trial dependency / reject). BabelDOC is AGPL — idea-inspection
only. Prioritised gaps: mask precision (manga-image-translator), atomic table transactions +
replacement ledger (TranslaTHOR), alignment/emphasis evidence (pdf-layout-translator).

---

## Open decisions for Captain Zan
1. Exercise storage: JSON contract vs relational `exercises` table (C1.1). I lean JSON first.
2. Does this spec's LV pass REPLACE the old world-class-render-engine LV1–LV6, or run after it?
3. Phase C (educational) is the heaviest + least dependent — build it in sequence (A→B→C→D) or
   split it to a parallel track once A is in?
