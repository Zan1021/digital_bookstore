# Ops — Unified Rendering & Testing

Operational notes for the gated render pipeline (`PdfTranslationService::createTranslatedPdf`
+ `TranslateEditionJob` + the QA gates). Companion to the gated-translation-narration-flow
OPS.md (queue worker setup lives there).

## Whole-book visual coverage gate is QUEUE-ONLY (Q1)

The visual coverage gate (`bookstore.visual_coverage_gate.enabled`, OFF by default) runs
~1 GPT-4o vision call PER PAGE + illustration detect + PDF.js over the WHOLE book. On a
16-page book that exceeds the synchronous render/request timeout (minutes).

**Behaviour (as of 2026-10-07):** the gate only runs INLINE when the render is on a
background/queue path. `TranslateEditionJob` calls `$renderer->allowHeavyGates(true)`;
console/artisan renders are allowed via `runningInConsole()`. On a **synchronous web
request** (BookReviewer / EngineCompare / ReviewQueue / `TRANSLATION_SYNC=true`) the gate
is **deferred**, not run:
- `qa_report['visual_coverage']` is written as `{covered: null, deferred_to_queue: true}`.
- The `visual_coverage` QA check stays `not_run` → the edition remains fail-closed
  (cannot be published on a coverage check that never executed) until a queued render
  records a real coverage result.

**To actually exercise coverage:** enable the flag AND run the render through the queue:
```
# .env (per-environment, do NOT commit on for normal ops)
VISUAL_COVERAGE_GATE_ENABLED=true   # (confirm the exact env key in config/bookstore.php)
php artisan queue:work --queue=default --tries=2 --max-time=3600
```
Then trigger translation from BookManager (async path) — the job will run coverage inline.

## Hard-killed render holds the per-edition lock to its TTL (Q2 / audit D1)

Renders are serialized per edition with `Cache::lock("translate-edition-{id}", 900)`
(unified-rendering-and-testing D1). This is fail-closed and correct: a second concurrent
render of the SAME edition is refused with
`"Edition #<id> is already being rendered; try again shortly."`

**Gotcha:** if a render process is HARD-killed (SIGKILL, OOM, a crashed worker, a 15-min
job timeout) the `finally` block never runs, so the lock is NOT released. It then stays
held until its **900-second TTL expires**. During that window every retry of that edition
is refused with the "already being rendered" message — which looks like a stuck edition
but is the lock doing its job.

**Symptoms:** repeated `RuntimeException: Edition #<id> is already being rendered` on
retry, with no render process actually running.

**Recovery (manual, immediate):**
```
php artisan tinker
>>> Illuminate\Support\Facades\Cache::lock("translate-edition-123")->forceRelease();
```
(replace 123 with the edition/translation id). After that the edition can be re-rendered.

**Prevention:** keep the queue worker's `--max-time`/`timeout` below the lock TTL so a
timed-out job releases via its own `finally` before the lock would even matter; and prefer
`queue:restart` over killing workers mid-render. (Optional future work: a stale-lock age
check + an admin "release render lock" button — logged as a low-priority item in
PROBLEMS-FIX-TASKS.md Q2.)
