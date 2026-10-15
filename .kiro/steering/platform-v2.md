---
inclusion: always
---

# Steering: Publishing Platform v2 (master plan)

The master plan for this project is `.kiro/specs/publishing-platform-v2/` (`requirements.md`, `design.md`, `tasks.md`). It replaces `.kiro/steering/v8-brief-compliance.md` as the source of truth. That file is kept for history and is no longer included automatically.

## Where we are
Read `tasks.md` and continue with the first unticked task. Do not start a phase before the previous checkpoint passes.

## Standing rules (from requirements.md)
- **R1 Book-agnostic.** No logic keyed to a title, page number, language name, coordinates or publisher. Behaviour comes from block treatments, styles and policies.
- **R2 Fail closed.** A page that cannot be produced correctly is flagged, never shipped as passed.
- **R3 Publisher controls AI spend.** Customers never trigger AI.
- **R4 Source is immutable.**
- **R5 Verified by looking.** A layout task is done only when the corpus contact sheets have been looked at and the gates pass. Green tests alone are not done.
- **R6 Measured speed.** Record stage timings; check them against Req 3.4.

## Engine direction
- The scene (art plate + blocks with treatments) is the master copy. Do not add features to the V8 edit-the-PDF path; it is retired at task 31.
- Exactly eight treatments: `translate_reflow`, `translate_fixed`, `keep`, `template`, `derive`, `rewrite`, `structure`, `in_art`. Adding one needs a spec change.
- Layout is done by the Chromium layout service with the reader's CSS and fonts. Never measure text with a different engine.

## Testing
- Python tests: `python scripts/run_python_tests.py` (after task 1.1). PHP: `php artisan test`.
- Corpus: `php artisan corpus:run --engine=scene --lang=af` (after task 8.2).
