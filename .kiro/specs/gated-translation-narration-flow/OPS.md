# Ops — Gated Translation & Narration Flow

## Queue worker (required for async translation)

Translation runs as a queued job (`App\Jobs\TranslateEditionJob`) on the `database`
queue (`QUEUE_CONNECTION=database`). A worker must be running or queued translations
will sit idle (the book page shows the edition as `TRANSLATING` until a worker picks it
up — this is honest, not a bug).

**Local:**
```
php artisan queue:work --queue=default --sleep=3 --tries=2 --max-time=3600
```

**Forge (production):** add a daemon with the same command (same pattern as other
projects' queue-worker daemons). Restart the daemon after each deploy.

## Demo fallback (synchronous translation)

When no worker is available (e.g. a quick demo), set:
```
TRANSLATION_SYNC=true
```
`config/bookstore.php` reads this; `BookManager::translate()` / `retryTranslation()` then
run the job with `dispatchSync()` in-request. Leave it `false` in production so the UI
never blocks on a long translation.

## Narration outdated flag

Editing an approved translated page sets that edition's narration `is_outdated = true`.
The reader (`/read/{book}?lang=…`) will not serve outdated audio. Re-generating narration
clears the flag.
