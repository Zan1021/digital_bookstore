# Implementation Plan — Publishing Platform v2

Work top to bottom. Each phase ends with a **checkpoint** that must pass before the next phase starts. Requirement numbers refer to `requirements.md`; section numbers (§) refer to `design.md`.

**Definition of done for every task**
- Code and tests written; the full PHP and Python suites pass.
- For anything that changes what a page looks like: the contact sheet of the affected corpus books has been looked at and attached to the task notes (standing rule R5).
- Timings recorded where the task touches the pipeline (R6).
- No book-specific logic (R1).

---

## Phase 0 — Foundation and safety

- [ ] 1. Make the test suites trustworthy
  - [x] 1.1 Add `scripts/run_python_tests.py` that runs every `scripts/test_*.py`, reports pass/fail per file and a total, and exits non-zero on any failure; add `composer test:all` running PHP and Python suites.
  - [x] 1.2 Fix or delete the four stale tests that point to missing files: `test_manifest_flow.py`, `test_v8_render.py`, `test_validation.py` (missing "A Fun Place" upload), `test_surgery.py` (old `Digital Bookstore\` path). Use corpus fixtures instead of hard-coded paths.
    - _Note (added during 1.2, agreed with Zan):_ `test_e2e_contract.py` also failed — not a stale path but V8 failing its own structural gate (page 2 copyright text spills its column). V8 is frozen, so the test now asserts **fail closed** (every page the gate rejects is routed to review with a named reason) instead of a perfect render; the known V8 failures are printed each run. Missing corpus books now exit 77 and the runner reports SKIP, not PASS.
  - [x] 1.3 Make `test_corpus.py` and `test_unit.py` assert something when run with no arguments (today they exit 0 without testing).
    - _Note (found during 1.3):_ only `test_corpus.py` exited 0 without testing (it printed help). `test_unit.py` already runs 18 tests / 64 assertions with no arguments and exits 1 on failure, so it was left unchanged. `test_corpus.py` with no arguments now self-tests: each committed fixture's manifest is re-extracted and compared, its source page passes verify, and create→verify round-trips with wrong-size and textless pages failing.
  - [ ] 1.4 Add CI (GitHub Actions if the repo is on GitHub) running both suites on every push.
    - _Status:_ `.github/workflows/tests.yml` committed on `phase-0-foundation`. The open-licence fonts are now tracked (`storage/app/fonts/OFL.txt`; Comic Sans and Grade 1 excluded, Zan's decision). A clean-clone run passes Python (35 pass, 7 SKIP without corpus) and PHP except 2 `UploadDecouplingTest` tests that need Zan's uncommitted working-tree changes. **Not ticked until** those changes are committed and the workflow has passed on GitHub (needs a push: Zan's OK, and revoke the token in the git remote URL first).
  - _Requirements: 26.5_

- [x] 2. Lock down access
  - [x] 2.1 Install Laravel Breeze (Livewire stack) for login, registration, password reset and email verification.
    - _Note:_ Breeze's installer overwrites `routes/web.php`, the welcome page and the Vite/Tailwind setup, and downgrades Livewire to 3, so it was run in a scratch copy and only the auth pieces were ported. Livewire stays on 4 (with Volt 1.11), Tailwind on 4 (+ `@tailwindcss/forms`). Checked by looking: login, register, forgot-password, verify-email → dashboard, profile. Pages still use Breeze's default look (Laravel logo, Figtree from fonts.bunny.net); branding and tokens come with tasks 4 and 27. `/dashboard` is a placeholder customer home.
  - [x] 2.2 Install `spatie/laravel-permission`; seed the roles of Req 1.3 and a Super Admin user.
    - _Note:_ neither the spec nor PB §74 lists permissions, so a default matrix (21 permissions, by admin area of design §17) lives in `config/roles.php` for Zan to adjust (edit the file, re-run `RolesAndPermissionsSeeder`); on-screen editing of the matrix is not in any task yet. Super Admin gets everything via `Gate::before`. The Super Admin user comes from `SUPER_ADMIN_EMAIL` (+ optional `_NAME`, `_PASSWORD`) in `.env` — no credentials in code; local DB has the roles but no Super Admin until Zan sets the email and runs `php artisan db:seed --class=SuperAdminSeeder`.
  - [x] 2.3 Put `auth` and role middleware on every `/admin` route (today none have it) and on reader, raw-PDF and audio routes; add feature tests that a guest and a customer get 302/403.
    - _Note:_ `staff:<perm>` middleware (logs denials, Req 1.4) on all 9 admin routes and the 4 reader/raw-PDF routes; readers are staff-only until task 3.1. There are no audio routes — MP3s are public files until task 3.2. **Gap found:** route-level checks only. Actions inside a page (e.g. delete/translate buttons on the dashboard, which any staff role can open) are not yet permission-checked per action — needs a follow-up before staff other than the owner get accounts.
  - [x] 2.3a Per-action permission checks inside admin pages (added 2026-10-15, agreed with Zan): every public Livewire action on an admin component declares the permission it needs and is denied (logged, 403) otherwise; an action with no declaration is denied (fail closed); buttons a user cannot use are hidden; tests prove a lower role cannot run a higher role's action.
    - _Note:_ 80 actions on 9 components carry `#[Can]`; the fail-closed guard caught one the inventory missed (`EngineCompare::renderV8`). AI-spending actions (translate, narrate, Fix with AI, metadata suggestion) need a `*.manage` permission, so reviewers cannot spend AI budget. Zan's uncommitted `Dashboard::publish/unpublish` got `#[Can('editions.publish')]` in the working tree only. Dashboard view buttons (Zan's uncommitted file) are not yet hidden by permission — the actions themselves are refused.
  - [x] 2.4 Staff management screen: invite, deactivate, change role.
  - _Requirements: 1.1–1.5_

- [x] 3. Move protected assets to private storage
  - [x] 3.1 Add a `private` disk (local now, S3-compatible later) and an `AssetController` that checks permission (staff, entitlement or preview) and redirects to a short-lived signed URL.
  - [x] 3.2 Replace the 51 `Storage::disk('public')` uses for books, translations, plates and audio; keep `public` only for store marketing images.
    - _Note:_ found that the store drew every cover by loading the whole source PDF in the visitor's browser (anyone could download every book). Covers are now `covers/{id}.jpg` on the public disk, rendered from page 1 with the reader's trim (`scripts/render_cover.py`, redrawn when the crop changes). Readers use the asset gatekeeper; admin working files use signed staff-only `/assets/file` links. Checked by looking: store home, book page, browse, dashboard, reader (af), flipbook (af), review queue.
  - [x] 3.3 Artisan command to move existing files and update stored paths; verify the existing book still opens in the reader.
    - _Note:_ `php artisan assets:move-to-private` (`--dry-run`, `--keep-originals`) moved 41 files / 142.7 MB with SHA-256 verification and created the missing cover. Stored paths are relative and unchanged (no absolute paths found in the DB or JSON), so no path rewrite was needed. Also found `public/storage` was an empty folder, not the storage link — `/storage` URLs never worked locally (the cause of the blank reader seen earlier); fixed with `php artisan storage:link`. Book 10002 opens in the reader and flipbook in Afrikaans. Production deploys must run `storage:link` and this command once.
  - [x] 3.4 Remove the `/raw-pdf/{book}` public route or put it behind staff auth.
    - _Note:_ staff-only since 2.3; it no longer serves files itself — it redirects to the asset gatekeeper (edition PDF for `?lang=`, else the source), so old bookmarks keep working and there is one file-serving path.
  - _Requirements: 20.1, 20.2_

- [x] 4. Build and self-host front-end assets
  - [x] 4.1 Replace the Tailwind CDN script in every layout with the Vite + Tailwind build (Tailwind 4 is already in `package.json`); move the brand colour scale into the theme.
    - _Note:_ CDN was Tailwind 3. Before/after full-page screenshots of 13 store/admin pages compared pixel by pixel: store home and book page now match exactly; v3→v4 line-height difference fixed on two store headings; v3 defaults (border colour, placeholder, button cursor) kept in `app.css`; the file picker's lost button look restored. Remaining differences are intentional: `@tailwindcss/forms` (needed by the sign-in pages) gives selects a chevron, text inputs a visible border and styled checkboxes.
  - [x] 4.2 Bundle Alpine, page-flip and PDF.js (until the old reader is retired) through Vite; remove CDN script tags.
    - _Note:_ same versions as the CDN: pdf.js 3.11.174 (npm alias `pdfjs-dist-v3`, so `scripts/render_pdfjs.mjs` keeps pdfjs-dist 4.10.38), page-flip 2.0.7, Alpine pinned to 3.17.4 (was unpinned `3.x.x`). Entries `pdf-readers.js` and `alpine.js`; flipbook and page-curl inline scripts became modules so they run after the bundle. Checked: all three readers render the Afrikaans edition with no external requests; flipbook and prototype turn pages. The reader did not turn on a scripted click in headless Edge — identical with the old CDN version, so not caused by this change; to confirm by hand.
  - [x] 4.3 Self-host Playpen Sans (Regular, Medium, SemiBold, Bold from `Fonts/`) and Instrument Sans as WOFF2; remove the fonts.bunny.net link.
    - _Note:_ Playpen Sans converted from `Fonts/` to WOFF2 in `resources/fonts/` (identical glyphs, cmap and metrics; ~60% smaller), Instrument Sans from `@fontsource/instrument-sans@5.3.0`; both in `resources/css/fonts.css` (imported by `app.css`, loaded alone by the flipbook). No external font requests on store, admin, sign-in or flipbook. Store and admin text now renders in Instrument Sans (design §16 UI font) instead of the system font; all 13 checked pages keep their height. **Open for Zan:** `public/fonts/Grade1Font.ttf` (non-commercial licence) is committed and publicly downloadable; the flipbook's unused `@font-face` for it was removed, the file was left for Zan's decision.
  - [x] 4.4 Create the design tokens of §16 in `resources/css/tokens.css`.
    - _Note:_ static Tailwind `@theme` (CSS variables on `:root` + utilities): brand scale, `ink-900`, `paper-50`, `sky-500`/`leaf-500`/`sun-400`, `font-sans` (Instrument Sans), `font-display` (Playpen Sans), `rounded-card` 12px, `rounded-chip`, `shadow-card`; type scale and spacing are Tailwind's defaults. Defining them changes no page (13 pages pixel-identical). **To confirm with Zan:** §16 gives no values for sky/leaf/sun — chosen #3b9fd8 / #4caf6a / #f7c948 (ink-900 text on each passes WCAG AA). **Flag for Req 27.6:** white text on brand-600 is 3.87:1, below AA for normal-size text.
  - _Requirements: 27.1, 27.2, 7.3_

- [x] 5. Production-ready infrastructure settings
  - [x] 5.1 Check all migrations run on PostgreSQL as well as SQLite (CI matrix).
    - _Note:_ checked locally on PostgreSQL 16.10 (throwaway Docker container): all 38 migrations run, reset and re-run cleanly; the full PHP suite passes (374). Two store-search tests failed on PostgreSQL — `BookQuery` used SQLite-only `json_extract()`, and `LIKE` is case-sensitive on PostgreSQL — fixed with `whereLike()` (ILIKE on PostgreSQL) and a `translations->en` JSON path, plus the admin dashboard search; two tests added. CI's PHP job is now a sqlite/pgsql matrix with a postgres:16.10 service and a migrations up/down step; it runs once the branch is pushed.
  - [x] 5.2 Configure Redis queues and Horizon with queues `intake`, `ai`, `layout`, `exports`, `mail`.
    - _Note:_ Horizon 5.50 + predis; one supervisor per queue group (mail+default, intake, ai, layout, exports). Jobs routed: classification → intake, translation and AI page repair → ai; staff invitations queued on mail; layout/exports wait for their jobs. Fixed Redis `retry_after` 90s → 960s (translation runs up to 900s and would have been started twice, paying for AI twice). Dashboard Super Admin only in every environment. Horizon cannot run on Windows (needs pcntl/posix; declared in composer `config.platform` so it installs) — local dev keeps the database queue; the worker command now lists all queues. Checked against a real Redis 7.4 container: jobs land on their queues and a worker drains only its own.
  - [x] 5.3 Add a `jobs_runs` table and a small helper that records stage start, end, duration, status and error.
    - _Note:_ `jobs_runs` (book, stage, status, attempt, started/finished at ms precision, duration_ms from a monotonic clock, error, meta) + `App\Support\StageTimer::run()`; rows survive book deletion (book_id nulled). Timed at the service level so jobs and admin buttons are measured alike: intake.upload, intake.cover, intake.classify, translate.text, translate.render, translate.edition, ai.page_repair, narration.generate. Cost per run joins via meta once the AI ledger exists (task 7). `processing_jobs` stays the live-progress row. First real timing: intake.cover for book 10002 ≈ 0.9 s.
  - _Requirements: 3.1, 3.3; design §20_

- [x] 6. Core data model corrections
  - [x] 6.1 Add `publishers` and `publisher_id` on publisher-owned tables; seed Mthombothi Studios.
    - _Note:_ `publishers` (name, slug, settings) created with Mthombothi Studios in the migration, plus an idempotent `PublisherSeeder`. Of the publisher-owned tables in design §4 only `books` exists yet: it gets `publisher_id` (backfilled, restrict on delete; new books default to `config('publisher.default_slug')`). Series (6.2), fonts, font profiles, text templates and products get `publisher_id` when created; book children reach the publisher through the book; classification/discovery tables stay store-wide. Checked on SQLite and PostgreSQL (migrations up/down, full suite).
  - [x] 6.2 Add `series` and `contributors`; migrate the existing `books.series` / `series_volume` values into them.
    - _Note:_ `series` (design §4 columns, publisher-owned) with `books.series_id` / `series_position`; the migration moves the old text values (trimmed, case-insensitive per publisher) and drops `books.series` / `series_volume`; its rollback restores them. Discovery filter, facet, search and `nextInSeries` use the record. `contributors` + `book_contributor` (role author/illustrator/narrator) backfilled from `author` / `illustrator`, which stay the edited fields for now — `Book` keeps the links in step one way; switch editing to contributors when the book workspace is built (task 41.1, Source tab). Checked on SQLite and PostgreSQL; pages unchanged.
  - [x] 6.3 Add `languages` (code, name, direction, script, enabled).
    - _Note:_ `languages` (code PK, name, direction, script ISO 15924, hyphenation, enabled, sort_order) seeded with English + the 15 targets that were hard-coded, same order (Arabic rtl/Arab, Chinese Hans). `TranslationService::SUPPORTED_LANGUAGES` removed; the translate tab, upload wizard, narration, TranscreateBook and the translation guard (enabled and not the book's own language) read the table. Checked on SQLite and PostgreSQL; Translate tab dropdown looked at (same 15, same order). `scripts/translation_request.py` keeps its own Python name map for now (Scene Engine work).
  - [x] 6.4 Stop storing the source language as a translation: migrate away the "en · draft" row for English books and update screens that read it.
    - _Note (scope reduced, agreed with Zan 2026-10-16):_ the source-language row still carries the English version's store data (published flag, price, reading level, education phase, features, edition rights) that the catalogue and publish flow use, and its real home is the per-language products of task 32. Done here: screens stop treating it as a translation — `Book::translatedEditions()`, `languageCount()`, `scopeWithTranslatedEditions()`; fixed the store book page listing "English, Afrikaans, English" / "3 Languages"; store/browse language counts, dashboard "Translated" stat, filter and badge, book-manager Translations/Editions lists and narration menu. **Removing the row itself moved to task 32.5.**
  - _Requirements: 16.1–16.3, 25.2, 6.8_

- [ ] 7. AI provider layer and cost ledger
  - [x] 7.1 Interfaces `TranslationProvider`, `VisionProvider`, `ImageEditProvider`, `TtsProvider`; OpenAI and ElevenLabs implementations wrapping today's calls.
    - Done: `app/Ai/Contracts/*`, `App\Ai\OpenAi\{OpenAiTextProvider, OpenAiImageEditProvider}`, `App\Ai\ElevenLabs\ElevenLabsTtsProvider`; each call returns its result plus a `Usage` (provider, model, operation, units, ms). Bound in AppServiceProvider. One call each, no retry: callers are not switched over yet (task 7.4), and their retry/fallback moves then.
  - [x] 7.2 `MeteredProvider` decorator: writes `ai_ledger`, caches by input hash, enforces spending limits.
    - Done: `App\Ai\MeteredProvider` wraps all four interfaces (the container resolves every interface to it). Tables `ai_ledger` (design §4 columns + `input_hash`), `ai_cache` (text in the row; image/audio bytes under `ai-cache/` on the private disk) and `ai_spending_limits` (operation null = monthly total; no row = no limit). Cost in rand from `config/ai.php` rates × `AI_USD_TO_ZAR` (rates are list-price estimates, unknown models priced at the provider default). Cache hits are logged at R0 and still served when a limit is reached; a used-up limit throws `App\Ai\SpendingLimitReached` before the call. The cache key leaves out bookkeeping options (`operation`, `book_id`, `translation_id`, `fresh`); images are keyed by content. Link-only image results and failed calls are not cached or logged. Admin screen for limits stays with 39.2 / 41.3.
  - [x] 7.3 Move model names to `config/ai.php` per operation.
    - Done: `config/ai.php` `operations` (translate + fallback, metadata, visual_qa, locate_art_text, art_repair, narrate), each overridable in `.env` (`AI_<OPERATION>_MODEL`; the old `ILLUSTRATION_TEXT_MODEL` / `_IMAGE_MODEL` still work), plus per-interface `defaults`. `App\Ai\AiModels` (`for`, `chain`, `resolve`); an unconfigured operation is an error. The five services and the providers read it; `MeteredProvider` settles the model before hashing, so a model change never reuses another model's cached answer. A test fails if a model name is written into `app/Services` again. The operation names are the ledger labels 7.4 passes.
  - [ ] 7.4 Route every existing AI call in `TranslationService`, `VisualQaService`, `IllustrationTextService`, `PdfService` and `NarrationService` through the layer.
    - Pass `book_id` / `translation_id` / `operation` on each call so the ledger can show cost per book and edition. Jobs that hit `SpendingLimitReached` pause (release with a delay and show "paused: spending limit") instead of failing.
  - _Requirements: 3.5, 22.3, 22.5, 13.2; design §18_

- [ ] 8. Golden corpus and baseline
  - [ ] 8.1 Register corpus books: all 12 Kolulu PDFs, "Math Fun With Abby", plus at least three outside books (ask Captain Zan for them: a table, speech bubbles, rotated text, text in artwork, two columns).
  - [ ] 8.2 `php artisan corpus:run {--engine=} {--lang=}`: runs the pipeline per book, stores gate results, contact sheet and per-stage timings under `storage/app/corpus/{run_id}/`, prints a summary compared with the previous run.
  - [ ] 8.3 Run V8 on the corpus for en→af and save it as the baseline for timings and gate results.
  - _Requirements: 26.2, 26.3; design §22_

- [ ] **Checkpoint 0:** all suites green in CI; `/admin` requires login; no protected file reachable by a public URL; corpus baseline stored.

## Phase 1 — Scene Engine core

- [ ] 9. Scene data model
  - [ ] 9.1 Migrations for `source_pages`, `blocks`, `block_translations`, `resolved_layouts`, `page_checks` (design §4).
  - [ ] 9.2 `SceneCompiler` that writes `scenes/{book}/scene.json` and `{lang}.json` from the database, with a version number.
  - [ ] 9.3 Unit tests for compile, version bump and stale-hash detection.
  - [ ] 9.4 Inputs hash per page and stage; every stage skips pages whose inputs have not changed.
  - _Requirements: 4.9, 8.7, 3.6_

- [ ] 10. Intake stage
  - [ ] 10.1 One intake flow replacing "Upload Books" and "Book Onboarding": drag-and-drop, many files, per-file progress, leave-and-return.
  - [ ] 10.2 Worker: private store, checksum and duplicate warning, `security.py` preflight, `cropmark_detection.py` trim boxes, live-text vs scanned per page.
  - [ ] 10.3 Clean draft title from PDF metadata and cover text; trigger the existing classification job for metadata suggestions, which stay drafts until accepted.
  - [ ] 10.4 Fan out per-page work as Laravel job batches; per-book and global concurrency limits in config.
  - [ ] 10.5 Record timings; target ≤ 90 s for a 16-page book through task 12.
  - _Requirements: 2.1–2.8, 3.2, 3.4, 16.4_

- [ ] 11. Block extraction and structure (Python `scene_extract.py`)
  - [ ] 11.1 Spans → lines → blocks with bbox, style, rotation and original line breaks; stable ULIDs.
  - [ ] 11.2 Paragraphs, lists, columns and reading order.
  - [ ] 11.3 Tables (rows, columns, header, merged cells), reusing logic from `borderless_table.py` and `document_model.py`.
  - [ ] 11.4 OCR blocks for scanned pages via `ocr_integration.py`; text-in-image detection via the `illustration_*` modules.
  - [ ] 11.5 Tests on corpus pages: cover, copyright page, story page, word-list page, back cover.
  - _Requirements: 4.1, 4.2, 5.4_

- [ ] 12. Art plates (Python `scene_plates.py`)
  - [ ] 12.1 Text-only redaction (images and line art kept) → vector plate PDF per page (proven in `evidence/art-plate-test-*.jpg`).
  - [ ] 12.2 Screen plate WebP/AVIF at 2× display size; measure file sizes.
  - [ ] 12.3 For `structure` blocks, remove ruling lines inside the structure's area.
  - [ ] 12.4 Test: pixels outside text areas unchanged against the source render.
  - _Requirements: 5.1–5.3_

- [ ] 13. Layout service (Node + Playwright, `layout-service/`)
  - [ ] 13.1 Long-lived service with one Chromium and a page pool; HTTP endpoints `layout`, `check`, `screenshot`, `pdf-text-layer`.
  - [ ] 13.2 Page HTML template (design §9) shared with the reader as one module.
  - [ ] 13.3 Fit ladder (Req 8.2) with the step used recorded per block.
  - [ ] 13.4 Free-space mask from the plate for the "grow" step.
  - [ ] 13.5 Role consistency pass across the edition (design §9).
  - [ ] 13.6 Language-aware hyphenation and widow control.
  - [ ] 13.7 Tests with synthetic blocks: fits, needs shrink, needs grow, needs short alternative, impossible → flagged.
  - _Requirements: 8.1–8.7, 7.6, 7.7_

- [ ] 14. Treatment classification
  - [ ] 14.1 Deterministic signals (design §7) with confidence per block.
  - [ ] 14.2 One vision call per unclear page returning JSON for unclear blocks only.
  - [ ] 14.3 Series matching: pre-fill treatments from a confirmed book in the same series; ask only about differing pages.
  - [ ] 14.4 Measure: share of blocks classified correctly on the Kolulu corpus against a hand-labelled answer key (target ≥ 95%).
  - _Requirements: 4.3, 4.4, 4.6–4.8_

- [ ] 15. Pages tab: confirm the analysis
  - [ ] 15.1 Page view with block overlays coloured by treatment, plus a legend.
  - [ ] 15.2 Change treatment, merge and split blocks, redraw the allowed area; "confirm book" action.
  - [ ] 15.3 Flagged-only filter so the admin sees just the blocks that need a decision.
  - _Requirements: 4.5, 4.6_

- [ ] 16. Translation v2
  - [ ] 16.1 Book brief generation (summary, characters, tone, age band) once per book, cached.
  - [ ] 16.2 Batched translation with JSON schema, budgets from the layout service, short alternatives (design §8).
  - [ ] 16.3 ID validation and single retry for failed IDs.
  - [ ] 16.4 Checks of Req 6.7; failures go to review.
  - [ ] 16.5 Title context step; reuse in cover, title page and store listing.
  - [ ] 16.6 Glossary and translation memory read from existing tables; written only from approved text.
  - [ ] 16.7 Timing: ≤ 3 min per 16-page book including layout and gates.
  - _Requirements: 6.1–6.10, 3.4_

- [ ] 17. Template treatment
  - [ ] 17.1 `text_templates` table and admin screen per language.
  - [ ] 17.2 Parse variables (publisher, year, ISBN, names) from the source block and fill the template.
  - _Requirements: 10.7_

- [ ] 18. Quality gates and contact sheet
  - [ ] 18.1 DOM-based gates of design §12 for every page type.
  - [ ] 18.2 `needs_layout_review` status that blocks publishing; Super Admin override with reason.
  - [ ] 18.3 Contact sheet image per edition with flagged blocks outlined.
  - _Requirements: 11.1–11.6_

- [ ] 19. PDF export
  - [ ] 19.1 Text-layer PDF per page from the layout service; merge over the vector plate with PyMuPDF.
  - [ ] 19.2 Check: text is selectable, fonts embedded, artwork identical, size close to the source PDF; ≤ 30 s per edition.
  - _Requirements: 15.1, 3.4_

- [ ] 20. Engine switch and comparison
  - [ ] 20.1 Book setting `engine = v8 | scene`; the edition pipeline picks the path.
  - [ ] 20.2 Extend Engine Compare to show both contact sheets and gate summaries side by side.
  - _Requirements: 26.1; design §19_

- [ ] **Checkpoint 1 (prove it):** Kolulu S2 "Play with me" and S3 "Kolulu's Home", English → Afrikaans, on the Scene Engine: Captain Zan reviews both contact sheets; no hard-gate failures; cover subtitle, ® and copyright page defects from `START-HERE-NEXT-SESSION.md` are gone; timings within Req 3.4. Word-list pages may still be flagged (Phase 2).

## Phase 2 — Special content, fonts and review

- [ ] 21. Structure treatment
  - [ ] 21.1 Render tables as HTML tables in the original style (line weight, colour, header style).
  - [ ] 21.2 Dynamic column count to fit content inside the original area.
  - [ ] 21.3 Peer-size enforcement per column.
  - _Requirements: 9.1–9.5_

- [ ] 22. Derived word lists
  - [ ] 22.1 Word-list generator (design §10) with a language-aware tokenizer.
  - [ ] 22.2 `hf_word_lists` table with CSV import screen per language and grade.
  - [ ] 22.3 Test: on Kolulu's Home in English, the generator reproduces the publisher's list (≥ 95% of words, near-identical order).
  - [ ] 22.4 Regenerate when approved story text changes.
  - _Requirements: 10.1, 10.2, 10.8_

- [ ] 23. Rewrite framework
  - [ ] 23.1 Rule files (YAML) with prompt, schema and validators; loader and runner.
  - [ ] 23.2 Phonics rules and validators (pattern in example, real word, no source language).
  - [ ] 23.3 Alphabet-page and rhyme rules.
  - [ ] 23.4 Series curriculum plan editor on the series page.
  - [ ] 23.5 Educational review track wiring.
  - _Requirements: 10.3–10.6_

- [ ] 24. Text inside artwork
  - [ ] 24.1 Keep / replace / flag choice per `in_art` block, defaulting as in design §11.
  - [ ] 24.2 Masked inpainting (simple backgrounds) and image-edit model (complex), with the outside-mask pixel check.
  - [ ] 24.3 Cost and provider recorded in the ledger.
  - _Requirements: 5.4–5.7_

- [ ] 25. Fonts and font profile
  - [ ] 25.1 `fonts` and `font_profiles` tables; settings screen to upload fonts and map roles.
  - [ ] 25.2 Seed Mthombothi's profile: Playpen Sans for all roles; Grade 1 for `educational` once decision D3 is confirmed.
  - [ ] 25.3 Glyph coverage check per language before translation, reusing `glyph_preflight.py`; block with a clear message.
  - [ ] 25.4 Script variants for non-Latin languages (Playpen Sans script families) when those languages are enabled.
  - _Requirements: 7.1–7.5_

- [ ] 26. Edition review screen
  - [ ] 26.1 Three-panel screen (design §13) replacing "Translation Review" and "Review Queue".
  - [ ] 26.2 Click-to-edit block, shorter alternative, change treatment, move and resize area; single-page re-layout ≤ 2 s.
  - [ ] 26.3 Approval tracks (language, layout, educational, narration) and final approval; honest page statuses.
  - [ ] 26.4 Reviewer assignment and notifications; comments per page and block; request-changes loop.
  - [ ] 26.5 Version history and audit entries for every change.
  - [ ] 26.6 Remove the old review screens and routes.
  - _Requirements: 12.1–12.8, 3.4_

- [ ] **Checkpoint 2:** the full Kolulu corpus (12 books) plus "Math Fun With Abby" in Afrikaans on the Scene Engine: ≥ 90% of pages need no reviewer layout change; every flagged page states its reason; word-list and phonics pages pass validators and educational review; timings within budget. Captain Zan signs off the contact sheets.

## Phase 3 — Reader, narration and formats

- [ ] 27. Reader manifest and asset delivery
  - [ ] 27.1 Manifest endpoint per edition (pages, plate URLs, resolved text, audio, timings), cached per version.
  - [ ] 27.2 Signed, short-lived URLs bound to user and device; preview manifests contain only preview pages.
  - _Requirements: 24.3, 20.2, 14.10_

- [ ] 28. Scene reader
  - [ ] 28.1 One reader replacing `/read`, `/flipbook` and `/read-curl`, reusing today's controls and look.
  - [ ] 28.2 Single and spread modes, cover pairing, swipe and keys, reduced motion, thumbnails, zoom, fullscreen.
  - [ ] 28.3 Mobile: page fitted and centred; loading overlay clears.
  - [ ] 28.4 Language switch on the same page; reading position saved and restored.
  - [ ] 28.5 RTL books; screen-reader text; preload current ± 2 pages only.
  - [ ] 28.6 Measure: first page ≤ 1.5 s at 10 Mbit/s, page turn ≤ 150 ms.
  - _Requirements: 14.1–14.10, 3.4_

- [ ] 29. Narration v2
  - [ ] 29.1 Generate from approved block text by sentence ID through `TtsProvider`.
  - [ ] 29.2 Pronunciation dictionary per language.
  - [ ] 29.3 Timings mapped to block and word spans; reader highlighting.
  - [ ] 29.4 Play, approve and regenerate one sentence in the review screen; outdated flag on text change.
  - _Requirements: 13.1–13.6, 12.8_

- [ ] 30. EPUB, audiobook and watermarking
  - [ ] 30.1 Fixed-layout EPUB 3 from the scene; media overlays when narration exists; validate with EPUBCheck.
  - [ ] 30.2 Audiobook MP3 per book and per page.
  - [ ] 30.3 Watermarked customer PDF downloads.
  - [ ] 30.4 Automatic re-export on edition change, keeping the old version until the new one passes.
  - _Requirements: 15.2–15.5_

- [ ] 31. Retire V8
  - [ ] 31.1 Switch the default engine to `scene`; re-render existing editions; compare with the baseline.
  - [ ] 31.2 Delete `pdf_translate_v8.py`, V8 paths in `PdfTranslationService`, V8-only modules and their tests; keep the reused modules of design §2.1.
  - [ ] 31.3 Switch `.kiro/steering/v8-brief-compliance.md` to archived.
  - _Requirements: 26.1, 26.4_

- [ ] **Checkpoint 3:** a customer-style account reads an Afrikaans Kolulu book on phone and desktop with narration and highlighting; PDF and EPUB downloads open correctly; V8 code removed with all suites green.

## Phase 4 — Commerce

- [ ] 32. Products and publishing matrix
  - [ ] 32.1 `products` table, SKU generator and uniqueness rules.
  - [ ] 32.2 Products tab: formats × languages matrix showing enabled, blocked (with reason) and missing assets.
  - [ ] 32.3 Publish gate: no product without approved edition, required assets and rights for that language, format and territory (reuse the existing edition-rights gate).
  - [ ] 32.4 Bundles data model.
  - [ ] 32.5 (moved from 6.4) Stop storing the source language as a translation: move the source edition's store data (publication, price, reading level, education phase, features, edition rights) to the book/products, switch the catalogue gate, filters and publish flow to it, then migrate away the source-language translation rows and `Book::ensureSourceEdition()`; `Translation::isSourceLanguage()` stops hard-coding 'en'.
  - _Requirements: 17.1–17.4, 17.6, 25.1_

- [ ] 33. Stock
  - [ ] 33.1 Stock fields, adjustments with history, low-stock threshold and statuses, backorders.
  - [ ] 33.2 Low-stock notifications.
  - _Requirements: 17.5_

- [ ] 34. Storefront redesign
  - [ ] 34.1 Home page in the PB §39 order using the design system; move publisher marketing to `/for-publishers`.
  - [ ] 34.2 Browse with combinable filters and counts (reusing `BookQuery`), sorting, mobile filter sheet; fix cover images.
  - [ ] 34.3 Live search (Scout, database engine) over title, author, series, category, tags, aliases, ISBN and SKU; ≤ 200 ms on 10k seeded books.
  - [ ] 34.4 Book page showing only approved languages and real products; preview button.
  - [ ] 34.5 Series page.
  - [ ] 34.6 One brand name across store, emails and titles (decision D1).
  - [ ] 34.7 Gallery and preview images generated from pages the admin selects; existing categories, tags, aliases, reading levels and collections kept and shown in the new design.
  - _Requirements: 18.1–18.7, 27.3, 27.4, 16.5, 16.6_

- [ ] 35. Cart
  - [ ] 35.1 Session and user carts; add without reload; mini-cart drawer; full cart page.
  - [ ] 35.2 Coupons and promotions applied in the cart.
  - _Requirements: 19.1, 19.5_

- [ ] 36. Checkout and PayFast
  - [ ] 36.1 Checkout with digital-only (no address) and mixed carts (address, delivery fee).
  - [ ] 36.2 PayFast integration per its current documentation, sandbox first: payment request, return and cancel pages, ITN endpoint with signature and server confirmation, idempotency.
  - [ ] 36.3 Orders and payments tables; order states of PB §47.
  - [ ] 36.4 Only a confirmed payment grants entitlements and reduces stock.
  - _Requirements: 19.2–19.4_

- [ ] 37. Library and entitlements
  - [ ] 37.1 Entitlements granted on confirmed payment; revoked on refund.
  - [ ] 37.2 My Library with Read, Listen, Download and language switch.
  - [ ] 37.3 Devices list and limit; downloads log; suspicious-activity rules and admin revoke.
  - _Requirements: 20.3–20.5, 1.6_

- [ ] 38. Orders admin and emails
  - [ ] 38.1 Order list and detail, status changes, tracking number, notes, refunds.
  - [ ] 38.2 Order confirmation, digital access and shipping emails.
  - _Requirements: 19.6, 19.7_

- [ ] **Checkpoint 4:** in the PayFast sandbox, a new customer buys an Afrikaans flipbook and a paperback in one order; the flipbook appears in My Library; stock drops by one; a refund removes access.

## Phase 5 — Operations and content

- [ ] 39. Bulk operations and cost control
  - [ ] 39.1 Multi-select book list with the bulk actions of PB §66.
  - [ ] 39.2 Cost estimate and confirmation before bulk AI work; monthly and per-operation limits.
  - [ ] 39.3 Cost per book, per edition and per approved page in the book's History tab.
  - _Requirements: 22.1–22.5_

- [ ] 40. Content management
  - [ ] 40.1 Homepage blocks, How It Works, footer, banners.
  - [ ] 40.2 FAQ with categories, ordering and publish.
  - [ ] 40.3 Newsletter sign-up with consent records, export and provider setting.
  - [ ] 40.4 Promotions and coupons admin.
  - _Requirements: 21.1–21.3_

- [ ] 41. Admin information architecture
  - [ ] 41.1 Book workspace with tabs Source, Pages, Languages, Products, History (design §17).
  - [ ] 41.2 Dashboard as a production board (books by stage, reviews waiting, failed jobs, spend).
  - [ ] 41.3 Settings: fonts, languages, templates, HF lists, AI limits, roles.
  - _Requirements: 27.5, 23.3_

- [ ] 42. Audit and analytics
  - [ ] 42.1 Audit log on all admin writes of PB §75 with before and after values.
  - [ ] 42.2 Store, reading, narration and production events without personal data about children.
  - _Requirements: 23.1, 23.2_

- [ ] 43. Accessibility pass
  - [ ] 43.1 WCAG 2.1 AA review of store, reader and admin: contrast, keyboard, focus, labels; fix findings.
  - _Requirements: 27.6_

- [ ] **Checkpoint 5:** the publisher can bulk-translate a series with a cost estimate, run the store's content without a developer, and every admin change appears in the audit log.

## Phase 6 — Launch hardening and mobile readiness

- [ ] 44. Performance
  - [ ] 44.1 Run the corpus and confirm every Req 3.4 budget; fix the slowest stage first.
  - [ ] 44.2 Seed 10,000 books; check store search and filter latency; add indexes or Meilisearch if needed.
  - _Requirements: 3.4, 18.4_

- [ ] 45. API v1
  - [ ] 45.1 Catalogue, search, book, cart, orders, library, entitlements, manifest and reading-position endpoints with Sanctum tokens.
  - [ ] 45.2 OpenAPI document and API tests.
  - _Requirements: 24.1–24.3_

- [ ] 46. Privacy, backups and security
  - [ ] 46.1 Daily database and private-storage backups; documented restore; first restore test done.
  - [ ] 46.2 POPIA: consent records, data export and deletion requests.
  - [ ] 46.3 Security review: rate limits, security headers, upload limits, dependency audit.
  - _Requirements: 25.3–25.5, 20.4_

- [ ] 47. Production deployment
  - [ ] 47.1 Deployment runbook (decision D7): web, workers, layout service, Redis, PostgreSQL, storage, CDN, PayFast live keys.
  - [ ] 47.2 Monitoring and alerts for failed jobs, queue backlog and payment errors.
  - _Requirements: 3.7_

- [ ] **Checkpoint 6 (launch):** live PayFast test purchase succeeds; restore test passes; corpus budgets met in production; Captain Zan signs off.
