<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Synchronous translation (demo fallback)
    |--------------------------------------------------------------------------
    | When true, translation runs in-request (dispatchSync) instead of on the
    | queue. Useful for a demo when no queue worker is running. In production,
    | keep this false and run `php artisan queue:work` so the UI never blocks.
    | (gated-translation-narration-flow, Task 12)
    */
    'translation_sync' => env('TRANSLATION_SYNC', false),
];
