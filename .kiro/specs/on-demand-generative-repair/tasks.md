# On-Demand Generative Repair — Tasks

**Created:** 2026-10-07 · **Author:** Naz · Companion to `requirements.md` + `design.md`.
**Convention:** `[ ]` todo · `[~]` partial · `[x]` done + PROVEN (test/behaviour named). One
task at a time; suite green after each; the generative call is MOCKED in automated tests
(no real spend) with a manual live smoke at the end.

---

- [x] **G1. Per-page generative candidate — `repairPageGenerative(Book,Translation,page)`**
      (R2/R3, design C-A). DONE + PROVEN: `IllustrationTextService::repairPageGenerative` runs
      detect + generative inpaint on a COPY, writes a candidate image + retained candidate PDF
      keyed by (book,lang,page,fingerprint), returns {ok,candidate_image,current_image,
      candidate_pdf,reason}. Live edition byte-identical (hash guard). Independent of the global
      flag; fail-closed. TEST: `GenerativeRepairCandidateTest` (4/4, generative mocked = no spend).

- [x] **G2. Apply the pick — `applyPageVersion(Book,Translation,page,choice)`** (R3/R5,
      design C-B). DONE + PROVEN. choice ∈ {keep_cheap, use_generative}. keep_cheap = no PDF
      change + page approved. use_generative = single-page splice (pymupdf delete+insert at the
      same index) + per-page `VisualQaService::review` re-compare + approve only on pass; backs
      up the edition and rolls back on error. TEST: `ApplyPageVersionTest` (5/5): keep_cheap
      no-op; use_generative splices exactly 1 page + re-compare + status; failing re-compare
      leaves page unapproved; fail-closed on missing candidate / invalid choice.

- [x] **G3. R1 guard — generative stays off the auto path.** DONE + PROVEN. TEST:
      `GenerativeAutoPathGuardTest` (2/2): `process()` with the global flag off makes ZERO
      generative calls; the per-page button path is the one and only producer.

- [x] **G4. Review-queue UI — "Fix with AI" button + two-version picker** (R4, design C-C).
      DONE + PROVEN. `ReviewQueue` shows the button ONLY on flagged pages; state machine
      idle→running→choose→applied|failed; two-image picker (Keep current / Use AI version) →
      `applyPageVersion`. TEST: `ReviewQueueFixWithAiTest` (3/3): button present on flagged /
      absent on clean; full state machine; page-switch resets the panel.

- [x] **G5. Audit + cost trail** (R6, design C-D). DONE + PROVEN. Each `applyPageVersion`
      appends a qa_report['audit'] entry (page, action, recompare verdict, generative_spent,
      user id). TEST: `GenerativeAuditTrailTest` (3/3): spend entry w/ user; no-spend entry;
      entries accumulate across pages.

- [~] **G6. Fail-safe/reversibility + verification sweep** (R7). ASYNC DONE + PROVEN; manual
      smoke still OPEN. Generative call now QUEUED (`GeneratePageRepairJob`, locked per
      edition+page so a double-click can't double-spend); ReviewQueue dispatches + polls
      (`wire:poll.2s="pollFix"`). Edition backed up before a use_generative splice; rolls back
      on error. TESTS: `GeneratePageRepairJobTest` (3/3) + full suite 37/37. ⚠️ STILL OPEN: the
      MANUAL live smoke — one REAL generative page on My House p1 — needs Captain Zan's
      OpenAI-spend go-ahead (first real spend). Update steering if production behaviour changed.

---

## Decisions — Captain Zan (2026-10-07)
1. Button lives in the admin **ReviewQueue** screen (where NEEDS_LAYOUT_REVIEW pages surface). ✅
2. v1 = **show BOTH versions (cheap vs generative), publisher PICKS** — not a blind replace. ✅
