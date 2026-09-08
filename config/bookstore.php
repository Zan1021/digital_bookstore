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

    /*
    |--------------------------------------------------------------------------
    | Visual QA gate (gpt-4o vision)
    |--------------------------------------------------------------------------
    | When true, after an edition renders, VisualQaService compares each page's
    | source vs translated image with a vision model and flags layout defects
    | (clipping, inconsistent sizes, overlap, missing/garbled text), routing the
    | edition to NEEDS_LAYOUT_REVIEW. Costs one vision API call per reviewed page,
    | so it is OFF by default and can be limited to structured pages only.
    | (V8 audit remediation, Task 6)
    */
    'visual_qa_enabled' => env('VISUAL_QA_ENABLED', false),

    // 'all' = review every page; 'structured' = only vocabulary/table + cover/back
    // pages (where defects are most likely and calls are worth spending).
    'visual_qa_scope' => env('VISUAL_QA_SCOPE', 'structured'),
];
