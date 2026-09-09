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

    /*
    |--------------------------------------------------------------------------
    | Cover re-typeset / flatten (front-page fix)
    |--------------------------------------------------------------------------
    | Some covers draw the subtitle drop-shadow via a Form XObject through a
    | LUMINOSITY soft mask. Per-span redaction re-serialises the content stream
    | and that backdrop then renders as a dark/washed box behind the translated
    | subtitle in PDF.js (invisible to a PyMuPDF pixmap check). When enabled, the
    | cover page (page 0) is flattened to an opaque raster after render, which
    | composites the mask to its intended (invisible) state — renderer-proof and
    | book-agnostic (works even for text baked onto an illustration).
    | Tradeoff: the cover becomes a raster (RGB); print-CMYK is a separate concern.
    */
    'cover_retypeset' => [
        'enabled' => env('COVER_RETYPESET_ENABLED', false),
        'ppi' => (int) env('COVER_RETYPESET_PPI', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Illustration-text vision module (text baked into artwork)
    |--------------------------------------------------------------------------
    | Some books bake text (a title, a label) INTO a raster illustration, so it
    | is not a PDF text object the contract renderer can redact/replace — the
    | English survives on the translated page. When enabled, IllustrationTextService
    | uses GPT-4o vision to LOCATE the baked-in text (eyes only), deterministically
    | inpaints it out (pure PyMuPDF), overlays translated vector text, and verifies
    | the result with the VisualQa compare gate. Book-agnostic; fail-closed (uncertain
    | backgrounds route to review, never a blind rectangle).
    |
    | - generative: opt-in background reconstruction via the images endpoint for hard
    |   pages; always review-gated. OFF by default (deterministic inpaint is preferred).
    | - Off by default; enabling it does NOT change the render for pages with no
    |   baked-in text (candidate pre-filter skips them cheaply, no vision spend).
    | Spec: .kiro/specs/illustration-text-vision.
    */
    'illustration_text' => [
        'enabled' => env('ILLUSTRATION_TEXT_ENABLED', false),
        'generative' => env('ILLUSTRATION_TEXT_GENERATIVE', false),
        'ppi' => (int) env('ILLUSTRATION_TEXT_PPI', 300),
        'model' => env('ILLUSTRATION_TEXT_MODEL', 'gpt-4o'),
        'image_model' => env('ILLUSTRATION_TEXT_IMAGE_MODEL', 'gpt-image-1'),
        'verify' => env('ILLUSTRATION_TEXT_VERIFY', true),
    ],
];
