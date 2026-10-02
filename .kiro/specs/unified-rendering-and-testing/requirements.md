# Requirements — Unified Book-Agnostic Rendering & Testing

**Spec:** `unified-rendering-and-testing`
**Status:** Draft (2026-10-02)
**Project:** Digital Bookstore — `C:\Users\zande\Documents\Digital Bookstore\bookstore`
**Source brief:** `Documents/Codex/2026-10-02/.../kiro-unified-book-agnostic-rendering-and-testing-brief.md`

## 0. Context & relationship to prior work

The renderer is DONE. The `world-class-render-engine` spec delivered Phases 1–8 (bounded
containers, fit-before-erase, font policy, region artwork ownership, surgical repair +
transforms, structured fail-closed QA + fingerprints + staged commits, admin overlays,
acceptance matrix). Laravel 125 tests + Python suites + PDF.js harness all green.

This spec does NOT rebuild the renderer. It builds the **governance, testing, and
orchestration spine** the unified brief asks for, and closes the gaps a code-grounded
inspection confirmed on 2026-10-02:

- **Confirmed bypass:** `EngineCompare` shells Python directly, builds translations from
  whole-page text, skips `PdfTranslationService` / QA / fingerprints, and shows `ready`
  purely on file existence.
- **Confirmed scattered gates:** `Translation::isPublishable()` / `canBePublished()` /
  `pageApprovalProgress()` are three independent shortcuts. No `CandidateReadiness`
  authority; no `output_sha256`; approvals not bound to the rendered file.
- **Confirmed dual store:** `BookReviewer` runs an old whole-page edit path
  (`translated_text` + its own `review_status='approved'`) ALONGSIDE the correct per-region
  `layout_overrides` path. Two approval notions coexist. (The brief's "duplicate to every
  item" claim was NOT found — corrected.)
- **Confirmed inventory maturity:** `document_model.py` already has `TranslationPolicy`
  (incl. `EDUCATIONAL_ADAPTATION`), `SemanticRole`, `RegionType`, `Bounds`/`resolve_safe_box`
  fail-closed, `TextUnit.layout_container/safe_box/paragraph_id`. Missing: `source_kind` as
  a distinct field, persisted forward/inverse transforms, the `inventory-layout-2` JSON
  contract.
- **Confirmed greenfield:** educational adaptation is a keyword flag
  (`isVocabularyPage()` → yellow "needs specialist review"). No structured exercises, no
  component-ID validation, no independent educational gate.

## 1. Guiding principles

- **Book-agnostic:** source-derived structure + reviewed edition config. No production
  branch keyed to a title/publisher/page/font/coordinate. Per-book data is fine; special
  renderer exceptions are not.
- **One pipeline, no side doors:** every render entry point (EngineCompare, BookReviewer,
  ReviewQueue, onboarding, jobs) routes through the single orchestrator. Python is an
  implementation detail, never a UI shortcut around checks.
- **Fail closed:** missing files, API timeouts, malformed responses, unresolved geometry →
  review/failure, never a silent pass. "Rendered" ≠ "approved."
- **Evidence, not vibes:** readiness is a function of per-check + per-approval records, each
  bound to BOTH a candidate input fingerprint AND the output file SHA-256.
- **Extend, don't replace:** adapt existing models/services; additive migrations only.

---

## Requirement 1 — Single orchestration path (close the EngineCompare bypass)

**User story:** As an admin comparing engine output, I want the compare screen to produce
the exact same artifact the production pipeline does, so that what I review is what ships.

**Acceptance criteria:**
1. WHEN `EngineCompare` renders an edition THEN it SHALL invoke `PdfTranslationService`
   (or the shared job), NOT a direct `Process::run('python ...')`.
2. The compare render SHALL use per-ID `item_translations`/contract inputs, NOT whole-page
   `translated_text`.
3. The compare render SHALL run the full illustration pass, QA report, and fingerprinting
   that production uses.
4. WHEN a rendered PDF merely exists on disk THEN the screen SHALL show
   `rendered`/`testing-pending`, NOT `ready` — `ready` requires passed checks bound to the
   current fingerprint + output hash.
5. Heavy rendering SHALL dispatch to a queued job (not block a Livewire request to timeout);
   job timeout vs. queue retry/visibility timing SHALL be reconciled (audit the prior
   900-vs-90s mismatch).

## Requirement 2 — One candidate-aware readiness authority

**User story:** As the publication gate, I want a single readiness decision bound to the
exact tested file, so that no edition is approved against stale or unverified evidence.

**Acceptance criteria:**
1. A `CandidateReadiness` service SHALL evaluate `(requiredChecks, checks,
   requiredApprovals, approvals, fingerprint, outputSha256)` and return `{ready, issues[]}`.
2. Empty fingerprint, empty output hash, or empty requiredChecks SHALL yield
   `ready=false` with `INVALID_GATE_INPUT`.
3. A required check SHALL count as satisfied ONLY when its status is `passed` AND its
   `candidate_fingerprint` AND `output_sha256` match the candidate being gated; otherwise
   `CHECK_UNRESOLVED_OR_STALE`.
4. A required approval SHALL count ONLY when `approved` AND bound to the same fingerprint +
   output hash; otherwise `APPROVAL_PENDING_OR_STALE`.
5. `Translation::isPublishable()`, `canBePublished()`, and `pageApprovalProgress()` SHALL
   delegate to this authority (keep thin back-compat shims; remove independent logic).
6. The expected page/region set SHALL be derived from the final PDF/inventory, NOT the
   `translatedPages` row count.
7. Server policy (not the browser) SHALL select required check/approval keys. Not-applicable
   checks are excluded ONLY with a recorded policy reason.
8. The actual output file hash SHALL be re-verified at approval and at promotion.

## Requirement 3 — Consolidate the review approval model

**User story:** As a reviewer, I want one approval notion tied to the tested candidate, so a
manual page edit can't silently mark an edition approved outside the gate.

**Acceptance criteria:**
1. BookReviewer's per-page `review_status='approved'` SHALL NOT, by itself, satisfy edition
   readiness; readiness flows only through Requirement 2.
2. A manual text edit SHALL invalidate dependent checks/approvals (semantics → language +
   visual + narration; container/font/alignment → layout + artwork + visual) by fingerprint
   change.
3. Per-region edits SHALL continue to write canonical `layout_overrides[regionId]` (the
   store the contract resolver + `attachTargetsById` consume) — no parallel store.
4. The whole-page edit path, if retained, SHALL write through the same canonical resolution
   and trigger the same invalidation; it SHALL NOT set an independent approved flag that the
   gate reads.
5. Human decisions, automated status, and readiness SHALL remain distinct and all visible
   after approval. Approving SHALL NOT turn a failed automated check green.

## Requirement 4 — Whole-book visual coverage ledger

**User story:** As the publication gate, I want proof every required page was visually
checked against the exact final PDF, so partial coverage can't masquerade as a pass.

**Acceptance criteria:**
1. The expected page set SHALL be derived from the final staged PDF/inventory, INCLUDING
   blank, preserved, and image-only pages with no translated-page record.
2. A `visual_coverage_issues(expected_pages, records, fingerprint)` gate SHALL emit:
   `PAGE_NOT_CHECKED`, `STALE_VISUAL_RESULT` (fingerprint mismatch),
   `VISUAL_CHECK_UNRESOLVED` (status ≠ passed; `review` counts as unresolved), and
   `UNEXPECTED_OR_DUPLICATE_PAGE`.
3. Missing PDFs, render failures, API failures, and invalid JSON SHALL each produce a ledger
   entry (fail/not_run) — NEVER an `ok`/`skipped` success.
4. Final approval SHALL require all-page coverage; draft sampling SHALL be clearly labelled
   incomplete.
5. Visual records SHALL be schema-validated (mandatory fields, enums, requested
   candidate_fingerprint); unknown IDs or malformed responses SHALL NOT pass.
6. Each visual record SHALL be bound to the output hash and record model/prompt versions +
   input hashes (no secrets).

## Requirement 5 — Canonical inventory contract (`inventory-layout-2`)

**User story:** As the pipeline, I want one persisted inventory describing every region's
role, policy, geometry, and transforms, so every stage reasons from the same source of truth.

**Acceptance criteria:**
1. `document_model.py` SHALL be EXTENDED (not replaced) to emit/consume an
   `inventory-layout-2` contract carrying `schema_version`, `source_hash`, `analysis_version`,
   per-page coverage, and per-region records.
2. Each region record SHALL carry `source_kind` SEPARATE from `semantic_role` (native /
   outlined_vector / raster_text vs. the role).
3. Each region SHALL carry a `processing_policy` ∈ {translate, educational_adaptation,
   preserve, review}.
4. Forward (source pt → render px → image px) AND inverse transforms SHALL be persisted;
   CropBox offset, rotation, and mixed page sizes SHALL round-trip under test.
5. Validation SHALL check IDs, cycles, parent/membership references, finite confidence, and
   geometry; each unit SHALL have exactly one direct owner (parents must not duplicate child
   ownership).
6. An empty `required_capabilities` list SHALL NOT imply universal support.

## Requirement 6 — Educational adaptation as an independent gate (greenfield)

**User story:** As a curriculum reviewer, I want exercises validated structurally and
pedagogically, so an educationally broken exercise blocks approval even if the layout is
perfect.

**Acceptance criteria:**
1. An exercise SHALL be modelled with component slots: objective, instruction, pattern,
   examples, questions/choices, answer key — each with a stable component ID.
2. A `componentIdIssues(expectedIds, components)` validator SHALL reject
   `UNKNOWN_OR_INVALID_COMPONENT_ID`, `DUPLICATE_COMPONENT_ID`, and `MISSING_COMPONENT_ID`
   before persisting.
3. General translation/regeneration SHALL NOT overwrite components independently; exact IDs,
   schema, roles, counts, language, and group references SHALL be validated first.
4. Educational validity SHALL be an INDEPENDENT check layer: an educational failure blocks
   approval regardless of layout/visual pass; a layout pass cannot clear it.
5. Audience/curriculum SHALL come from edition policy — NO assumption of ages 5–8 or English
   phonics. Unsupported-language/educational validators SHALL route to specialist review,
   never silently fall back to English phonics.
6. Substring/length heuristics (`QualityReportService`) SHALL be advisory only, never a
   readiness authority.

## Requirement 7 — Safe orchestration & reproducibility

**User story:** As the operator, I want staged candidates, per-edition locks, and
versioned artifacts, so concurrent jobs never produce mixed-version evidence or overwrite a
good edition.

**Acceptance criteria:**
1. Renders SHALL write to a private unique staged path; promotion to public SHALL occur only
   after checks/approvals pass; the prior approved output SHALL be retained until then.
2. Each edition render SHALL hold a per-edition lock (idempotent, bounded retry, cancellable,
   progress-persisting).
3. Images/PDFs SHALL be versioned by candidate/hash; a UI SHALL never pair an old PNG with a
   newly overwritten PDF (version comparison URLs).
4. Fingerprints SHALL cover source/manifest, exact targets/overrides, font hashes, effective
   policy, backend versions, repair artifacts, relevant config — excluding secrets/timestamps,
   with canonical ordering. (Reuse the existing `RenderFingerprint`; extend as needed.)
5. Testing-budget exhaustion SHALL leave pending checks, never passes.

## Requirement 8 — Reporting & readiness persistence

**User story:** As an auditor, I want one structured report proving why this exact edition is
approved or needs review.

**Acceptance criteria:**
1. Reports SHALL be structured arrays assigned to array-cast fields (NOT re-JSON-encoded);
   legacy double-encoded values SHALL be decoded transparently (reuse `decodeQaReport()`).
2. A report SHALL persist candidate ID, input fingerprint, output SHA-256, expected
   pages/regions, required checks, per-page/per-region results, issues, approvals, and
   aggregate readiness.
3. Layers SHALL stay independent: AI pass cannot clear a geometry failure; human approval
   cannot overwrite an automated failure; ID coverage cannot clear an invalid exercise.
4. Edits SHALL invalidate the correct dependency closure and rebind evidence to a new
   candidate; no stale boolean approvals.

## Non-goals (this spec)

- Replacing the V8 renderer or swapping in an external engine (BabelDOC/etc.).
- The §17 external-repo experiments (parked behind this spec + a real multi-publisher
  corpus; kept as a reference memo).
- Controlled reflow/redesign edition modes (report unsupported until backed by real
  backends).
- Generating a synthetic test book (not requested; real authorized holdouts preferred).

## Acceptance matrix (abridged — full matrix in design.md §8)

| Case | Expected |
|---|---|
| EngineCompare render | Same contracts/fonts/artwork/QA as production; no direct Python |
| File exists but unchecked | Shows rendered/testing-pending, not ready |
| Required check stale (fingerprint mismatch) | Readiness = false, CHECK_UNRESOLVED_OR_STALE |
| Human approves a page with a failed automated check | Automated failure persists, gate blocked |
| Blank/image-only page | Present in inventory + coverage ledger + UI |
| Missing PDF / API timeout / bad JSON | Required check fails/not_run, never pass |
| Only covers checked | Whole-book coverage incomplete; approval blocked |
| Invalid exercise, perfect layout | Educational failure blocks approval |
| Missing/duplicate/unknown component ID | Rejected + visible, not persisted |
| Edit after approval | Dependent checks/approvals stale; fresh candidate retested |
| Concurrent jobs/retries | No overwrite; no mixed-version evidence |
