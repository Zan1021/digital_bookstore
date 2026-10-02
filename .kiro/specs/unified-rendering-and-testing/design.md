# Design — Unified Book-Agnostic Rendering & Testing

**Spec:** `unified-rendering-and-testing`
**Status:** Draft (2026-10-02)
**Depends on:** `world-class-render-engine` (Phases 1–8, complete)

## 1. Architecture overview

```text
Immutable publisher PDF
  → inventory + capability preflight        (document_model.py → inventory-layout-2)
  → reviewed region/layout/table/exercise contracts
  → per-ID translation + coherent exercise adaptation   (TranslationService + ExerciseService)
  → resolved fonts + explicit fit plans     (font_policy.py — existing)
  → private STAGED pdf + owned artwork repairs          (PdfTranslationService — existing)
  → BookTestingService: integrity/geometry/content/artwork/AI/educational checks → LEDGER
  → side-by-side review workspace           (EngineCompare / ReviewQueue / BookReviewer)
  → CandidateReadiness (fingerprint + output_sha256) → current-version human approvals
  → controlled promotion staging → public
```

**One orchestrator** (`PdfTranslationService`) for every entry point. The existing page
renderers remain adapters. Painting never infers alignment, chooses fonts, or grows
containers (already enforced in `document_model.py`/`pdf_translate_v8.py`).

**Single readiness authority** (`CandidateReadiness`). Every model gate delegates to it.

**Single evidence ledger** (`BookTestingService` → report persisted on `Translation`).

## 2. State model

Keep existing `render_status` strings; add orthogonal dimensions rather than overloading one
column.

- **Analysis:** pending / complete / needs_review / failed
- **Candidate:** queued / rendering / rendered / failed
- **Check (per layer):** pending / running / passed / failed / not_run / not_applicable
- **Human approval (per track):** pending / approved / rejected / stale
- **Edition:** testing / needs_review / ready_for_approval / approved

`approval_tracks` (exists) already models language/layout/artwork with fingerprint binding.
Extend records to also carry `output_sha256`. `rendered` never implies `approved`.
`not_applicable` requires a server-selected policy reason.

## 3. Key components

### 3.1 `CandidateReadiness` (NEW — `app/Services/Qa/CandidateReadiness.php`)
Pure, static evaluator exactly per the brief:

```php
final class CandidateReadiness
{
    /** @return array{ready: bool, issues: array<array<string,string>>} */
    public static function evaluate(
        array $requiredChecks, array $checks,
        array $requiredApprovals, array $approvals,
        string $fingerprint, string $outputSha256
    ): array;
}
```
Rules: empty fingerprint/hash/requiredChecks → `INVALID_GATE_INPUT`. A check passes only when
`status==passed && candidate_fingerprint==fingerprint && output_sha256==outputSha256`, else
`CHECK_UNRESOLVED_OR_STALE`. Approvals analogous → `APPROVAL_PENDING_OR_STALE`. Dedupe keys.
No I/O, fully unit-testable.

**Delegation:** `Translation::canBePublished()` / `isPublishable()` / `pageApprovalProgress()`
become thin shims that build the required-key set from server policy + the final-PDF page set
and call `CandidateReadiness::evaluate`. Existing callers keep working.

### 3.2 `BookTestingService` (NEW — `app/Services/Qa/BookTestingService.php`)
Coordinates validators over the **final staged PDF**; does not render. Layers (each
independent, each writes a check record bound to fingerprint + output hash):

| Layer | Source | Reuses |
|---|---|---|
| Inventory coverage | inventory-layout-2 | document_model |
| Mapping | per-ID targets, no orphan/dup/fallback | attachTargetsById |
| Integrity | opens, page boxes/count/rotation/fonts/nav | PyMuPDF + render_pdfjs.mjs |
| Geometry | anchors/containment/collision/padding/shaping | render_gate.py (exists) |
| Artwork | protected preserved, repairs confined, lettering replaced | artwork_repair.py (exists) |
| AI visual | whole-page + crops, all pages | VisualQaService (hardened) |
| Translation | meaning/omission/terminology/fluency | QualityReportService (advisory) |
| Educational | component-ID + pedagogy | ExerciseService (NEW) |
| Human | current-version approvals | approval_tracks |

Applicability selected from content/policy with a recorded reason (noneducational books skip
the educational layer; preserved pages still get integrity/preservation).

### 3.3 Visual coverage gate (NEW — `scripts/visual_coverage.py` + PHP caller)
Port the brief's `visual_coverage_issues(expected_pages, records, fingerprint)`. Expected set
derived from the final PDF/inventory, not DB rows. Emits PAGE_NOT_CHECKED /
STALE_VISUAL_RESULT / VISUAL_CHECK_UNRESOLVED / UNEXPECTED_OR_DUPLICATE_PAGE. `review` is
unresolved. Missing file/API/JSON → ledger entry, never success.

### 3.4 `ExerciseService` (NEW — `app/Services/ExerciseService.php`)
Models exercises as component slots with stable IDs; `componentIdIssues(expectedIds,
components)` validator (reject unknown/duplicate/missing). Educational validity is an
independent check feeding `BookTestingService`. Audience/curriculum from edition policy; no
hardcoded ages/English phonics; unsupported → specialist review. Replaces nothing in the
current keyword-flag path except by superseding it as the gate — the keyword sniff can remain
as a triage hint.

### 3.5 Inventory extension (`scripts/document_model.py`)
Additive: add `source_kind` field to `TextUnit`/`Region` distinct from `semantic_role`; a
serializer emitting the `inventory-layout-2` JSON (schema_version/source_hash/
analysis_version/pages/regions/tables/exercises); persist forward+inverse transforms
(fold in `crop_transform.py`). Validation pass for IDs/cycles/ownership/geometry. No existing
field removed.

### 3.6 EngineCompare rework (`app/Livewire/Admin/EngineCompare.php`)
Delete `runEngine()`'s direct `Process::run`. Dispatch the shared render job
(`TranslateEditionJob`) / call `PdfTranslationService::createTranslatedPdf`. `mount()` stops
setting `ready` on file existence; status derives from the readiness evaluation + candidate
fingerprint. Load persisted reports + language/candidate identity. Version comparison URLs by
candidate hash.

## 4. Data flow — a render + test cycle

1. Admin triggers render on EngineCompare/BookReviewer → `TranslateEditionJob` (locked,
   staged path).
2. Service builds inventory-layout-2, resolves per-ID targets, fonts, fit plans; renders to
   staging; runs illustration pass; computes `render_fingerprint` + `output_sha256`.
3. `BookTestingService` runs all applicable layers over the staged PDF → check records
   (status + both identities) → persisted report (array cast).
4. `CandidateReadiness::evaluate` with server-policy required keys → `{ready, issues}`.
5. UI shows per-layer status + coverage (`16/16 visual pages checked`) + unresolved issues.
6. Human approves tracks (bound to fingerprint + hash). Edit → dependency invalidation →
   fingerprint change → stale checks/approvals → retest.
7. On ready + approvals: promote staging → public (re-verify hash), retain prior until success.

## 5. Migrations (additive)

- `translations.output_sha256` (string, nullable) — latest staged/approved output hash.
- Extend `approval_tracks[*]` records with `output_sha256` (no schema migration; JSON).
- `exercises` table OR `translations.exercise_contract` (array cast) — decide in Task 6.1;
  lean toward a JSON contract to avoid a heavy relational model first.
- Reuse `render_fingerprint`, `narration_fingerprint`, `approval_tracks`, `qa_report`
  (all exist).

## 6. Reuse ledger (do NOT rebuild)

| Need | Existing asset |
|---|---|
| Fail-closed geometry | `document_model.py` Bounds/resolve_safe_box/LayoutReviewRequired |
| Fit-before-erase | `pdf_translate_v8.py` generic placer |
| Font policy | `font_policy.py::resolve_role_font` |
| Artwork repair | `artwork_repair.py`, `crop_transform.py` |
| Structured QA | `app/Services/Qa/QaReport.php` |
| Fingerprints | `app/Services/Qa/RenderFingerprint.php` |
| Report decode | `Translation::decodeQaReport()` |
| Approval tracks | `Translation::approveTrack/isTrackApproved/...` |
| Staged promotion | `PdfTranslationService` staging path (Phase 6.5) |
| PDF.js blank check | `scripts/render_pdfjs.mjs` |

## 7. Testing strategy

- **PHP unit:** `CandidateReadiness` (passed/stale/missing/invalid-input matrix),
  `componentIdIssues` (unknown/dup/missing), `BookTestingService` layer independence.
- **PHP feature:** EngineCompare routes through service (no Process::run — assert via a
  faked/ spy service); readiness blocks on stale fingerprint; human approve cannot clear a
  failed automated check; whole-page edit invalidates dependents.
- **Python:** `visual_coverage.py` (all four issue codes + fail-closed on missing/bad input);
  inventory-layout-2 round-trip incl. CropBox/rotation/mixed-size transforms; ownership
  validation.
- **Node:** reuse `render_pdfjs.mjs` blank/variance.
- Deterministic first. The LV1–LVn live pass stays deferred (see tasks.md) — one live API
  pass after all coding, per standing directive.

## 8. Full acceptance matrix

| # | Case | Expected outcome |
|---|---|---|
| 1 | EngineCompare render | Shared service/contracts/fonts/artwork/QA; no direct Python |
| 2 | PDF exists, unchecked | `rendered`/`testing-pending`, not `ready` |
| 3 | Required check stale | readiness=false, CHECK_UNRESOLVED_OR_STALE |
| 4 | Approval stale (fp mismatch) | APPROVAL_PENDING_OR_STALE |
| 5 | Empty fingerprint/hash | INVALID_GATE_INPUT |
| 6 | Human approves failed page | automated failure persists; gate blocked |
| 7 | Blank/image-only page | in inventory + coverage ledger + UI |
| 8 | Missing PDF/API timeout/bad JSON | check fails/not_run, never pass |
| 9 | Only covers checked | whole-book coverage incomplete; blocked |
| 10 | Invalid exercise, perfect layout | educational failure blocks approval |
| 11 | Unknown/dup/missing component ID | rejected + visible, not persisted |
| 12 | Edit after approval | dependents stale; fresh candidate retested |
| 13 | Concurrent jobs/retries | no overwrite; no mixed-version evidence |
| 14 | Browser cache | fresh matching candidate images/PDF (versioned URLs) |
| 15 | Rotation/crop/mixed sizes | transforms round-trip under test |
| 16 | source_kind vs semantic_role | distinct fields in inventory-layout-2 |
| 17 | Noneducational book | educational layer not_applicable with reason |
| 18 | Budget exhaustion | pending checks, never passes |
```