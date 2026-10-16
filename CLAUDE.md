# CLAUDE.md — Digital Bookstore

Children's book publishing platform: a publisher uploads finished picture-book PDFs, the platform builds approved language editions (translation, layout, narration) and sells them as flipbook, PDF, EPUB, audiobook and print. First publisher: Mthombothi Studios (Kolulu Taktaki series). Owner: Zander ("Captain Zan").

## The plan — read this first

The master plan is **`.kiro/specs/publishing-platform-v2/`**:
- `requirements.md` — what to build (Req n.m)
- `design.md` — how (§n). §2 explains the current state and what is reused or replaced.
- `tasks.md` — the task list. **Continue with the first unticked task.**

Work rules for the task list:
- One task at a time. Tick it in `tasks.md` (`- [x]`) only when its definition of done (top of `tasks.md`) is met.
- Do not start a phase until the previous **checkpoint** has been signed off by Zan. At a checkpoint, stop and show the evidence (contact sheets, timings, test results).
- If a task turns out wrong or incomplete, update `tasks.md` (and the requirement or design section if needed) and say so. Don't silently drift from the spec.
- Ignore older plans unless a task points to them: `.kiro/steering/v8-brief-compliance.md` and the other specs in `.kiro/specs/` are history.

## Where we are (update at the end of each session)

- **Resume at task 8.3** (V8 baseline run: needs Zan's OK to spend AI credit). 8.1 waits for three outside books from Zan; 8.2 done. Zan should check the AI prices in config/ai.php. Then Checkpoint 0. (6.4 was reduced; its data move is task 32.5.)
- Task 1.4 (CI) is the first unticked task but is **blocked on Zan**: revoke the GitHub token in the git remote URL, then push. Skip it until Zan says it's done.
- Zan's open items: set `SUPER_ADMIN_EMAIL` in `.env` + run `SuperAdminSeeder` (local DB has no users); `APP_URL=http://127.0.0.1:8080`; confirm sky/leaf/sun colours in `resources/css/tokens.css`; try a reader page turn by hand.
- Explain decisions to Zan in plain words with simple numbered options, and offer to do the simple thing for them.

## Standing rules

- **R1 Book-agnostic.** No logic keyed to a title, page number, language name, coordinates or publisher. Behaviour comes from block treatments, styles and policies.
- **R2 Fail closed.** A page that cannot be produced correctly is flagged for review, never shipped as passed.
- **R3 Publisher controls AI spend.** Customers never trigger AI calls. All AI calls go through the provider layer (task 7) once it exists.
- **R4 Source is immutable.** Never overwrite an uploaded PDF or the source text.
- **R5 Verify by looking.** Anything that changes how a page looks is done only when you have rendered the affected pages, looked at them, and the gates pass. Green tests alone are not done — this project has been burned by "226 tests green" while pages were visibly broken.
- **R6 Measure speed.** Record stage timings and compare with the budgets in Req 3.4.

## Engine direction

- New work goes into the **Scene Engine**: art plate (page with live text removed) + text blocks with one of eight treatments: `translate_reflow`, `translate_fixed`, `keep`, `template`, `derive`, `rewrite`, `structure`, `in_art`. Adding a treatment needs a spec change.
- Text layout is done by the Chromium layout service with the same CSS and fonts as the reader. Never measure text with a different engine.
- **V8** (`scripts/pdf_translate_v8.py`, V8 paths in `app/Services/PdfTranslationService.php`) is frozen: no new features; it is kept only for comparison until task 31 deletes it.
- Fonts: Playpen Sans for translations (files in `../Fonts/`); Grade 1 font only for the `educational` role once its licence is confirmed.

## Stack

- Laravel 12, Livewire 4, SQLite locally (`database/database.sqlite`), PostgreSQL planned for production.
- Python engine scripts in `scripts/` (PyMuPDF 1.28, uharfbuzz, fontTools, Pillow) — see `requirements.txt`.
- Front end: Blade + Livewire + Alpine; Tailwind 4 and Vite in `package.json` (views still load the Tailwind CDN until task 4).
- AI: OpenAI (translation, vision, image edits), ElevenLabs (narration). Keys live in `.env` — never print, log or commit them.

## Commands (Windows, this machine)

- Serve: `php artisan serve --port=8080` — **use 8080**, Docker Desktop occupies 8000.
- Queue worker: `php artisan queue:work --queue=mail,intake,ai,layout,exports,default` (jobs run on named queues; a bare `queue:work` only takes `default`). Production: Redis + `php artisan horizon` (Linux only: Horizon needs pcntl/posix, which Windows PHP lacks).
- PHP tests: `php artisan test`
- Python tests: each `scripts/test_*.py` is a standalone script, not pytest. Run them one by one with `python scripts/<name>.py` until task 1.1 adds `python scripts/run_python_tests.py`.
- Known failing Python tests (task 1.2 fixes them): `test_manifest_flow`, `test_v8_render`, `test_validation`, `test_surgery` point to files that no longer exist; `test_e2e_contract` fails V8's own structural gate.
- Module reachability: `python scripts/wiring_audit.py`

## Test books

- Source PDFs: `../Book_Pdfs/Mthombothi Studios Kolulu Taktaki/` (12 Kolulu books + "Math Fun With Abby") and `../Kolulu Engl Series 3 - 2 - A Fun Place.pdf`.
- In the local database: book **10002** "Kolulu's Home" with an Afrikaans edition in review. Reader: `http://127.0.0.1:8080/read/10002?lang=af`.
- First proof target (Checkpoint 1): Kolulu S2 "Play with me" and S3 "Kolulu's Home", English → Afrikaans.

## Looking at pages

- Render pages to PNG and look at them; for a whole edition, use the contact sheet (task 18.3) once it exists.
- Downscale screenshots to at most ~1600 px on the long side before viewing them.

## Git

- Work on a branch per phase (`phase-0-foundation`, …). Commit in small steps with clear messages. Don't push or merge to `master` without Zan's OK.
