# On-Demand Generative Repair — Tasks

**Created:** 2026-10-07 · **Author:** Naz · Companion to `requirements.md` + `design.md`.
**Convention:** `[ ]` todo · `[~]` partial · `[x]` done + PROVEN (test/behaviour named). One
task at a time; suite green after each; the generative call is MOCKED in automated tests
(no real spend) with a manual live smoke at the end.

---

- [ ] **G1. Per-page generative candidate — `repairPageGenerative(Book,Translation,page)`**
      (R2/R3, design C-A). New method on `IllustrationTextService`: run detect + GENERATIVE
      inpaint + overlay for ONE page; write the result to a scratch CANDIDATE artifact keyed
      by (book,lang,page,fingerprint); return {ok, candidate_image, current_image, reason}.
      MUST NOT touch the live edition PDF. Independent of the global
      `ILLUSTRATION_TEXT_GENERATIVE` flag. Fail-closed on error. PROOF: Feature test (mocked
      generative) — produces a candidate image, live PDF untouched (bytes unchanged), error
      path returns ok=false without side effects.

- [ ] **G2. Apply the pick — `applyPageVersion(Book,Translation,page,choice)`** (R3/R5,
      design C-B). choice ∈ {keep_cheap, use_generative}. keep_cheap = discard candidate,
      mark page publisher-accepted. use_generative = splice the single candidate page into
      the edition (reuse the engine's page-level write), then re-run
      `VisualQaService::review` on JUST that page; update page status; recompute edition
      readiness. PROOF: Feature test — keep_cheap leaves PDF unchanged; use_generative
      replaces exactly ONE page (other pages byte-stable) + triggers a single-page
      re-compare (mocked) + updates status; readiness recomputes.

- [ ] **G3. R1 guard — generative stays off the auto path.** Confirm/ensure `process()` does
      NOT call the generative route in normal production (only the deliberate full-book flag
      path does). PROOF: test asserts a default render makes ZERO generative calls; grep
      shows the per-page button is the only production producer of a generative call.

- [ ] **G4. Review-queue UI — "Fix with AI" button + two-version picker** (R4, design C-C).
      In `ReviewQueue`: show the button ONLY on flagged pages; press → queued
      `repairPageGenerative` (button → running); on completion show the two images
      (current vs AI) with Keep/Use buttons → `applyPageVersion`; reflect applied/failed.
      Button state machine idle→running→choose→applied|failed. PROOF: Livewire test drives
      idle→running→choose→applied; button absent on a clean page.

- [ ] **G5. Audit + cost trail** (R6, design C-D). Each repairPageGenerative + an
      applyPageVersion(use_generative) appends an audit_trail entry (reuse C7 logAuditTrail):
      page, user id, compare verdict before/after, generative_spent=true. PROOF: test asserts
      the audit entry is written with the page + spend marker.

- [ ] **G6. Fail-safe/reversibility + verification sweep** (R7). Keep the pre-apply edition
      artifact so a bad apply rolls back to cheap. API failure = non-blocking UI error.
      Final: full Laravel suite green; a MANUAL live smoke (one real generative page from the
      queue on My House page 1) confirms end-to-end, cost noted. Update steering if the
      production illustration behaviour changed.

---

## Decisions — Captain Zan (2026-10-07)
1. Button lives in the admin **ReviewQueue** screen (where NEEDS_LAYOUT_REVIEW pages surface). ✅
2. v1 = **show BOTH versions (cheap vs generative), publisher PICKS** — not a blind replace. ✅
