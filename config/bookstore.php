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
    | Whole-book visual coverage gate (unified-rendering-and-testing Req 4)
    |--------------------------------------------------------------------------
    | When enabled, BookTestingService runs visual QA over EVERY page of the final
    | PDF (including blank/preserved/image-only pages) and makes all-page coverage a
    | REQUIRED gate: any unchecked, stale or unresolved page routes the edition to
    | review. Off by default because full-book coverage spends one vision call per
    | page; the subset visual_qa above remains the cheaper draft check.
    */
    'visual_coverage_gate' => [
        'enabled' => env('VISUAL_COVERAGE_GATE_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Independent PDF.js visual check (spec R8.6)
    |--------------------------------------------------------------------------
    | Renders cover + flagged pages through the SAME engine the reader uses
    | (scripts/render_pdfjs.mjs) and flags blank/washed output PyMuPDF can't see.
    | Off by default; needs node. Recorded not_run when unavailable (never a false pass).
    */
    'pdfjs_check' => [
        'enabled' => env('PDFJS_CHECK_ENABLED', false),
    ],

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
    | Font-asset-integrity preflight (engine-wiring-and-activation R-W3/R-W3.1)
    |--------------------------------------------------------------------------
    | Before the engine renders, verify every font file the book REQUESTS (via its
    | typography policy) actually IS the family it claims — i.e. the file's embedded
    | internal name matches the requested family. This is the guard that would have
    | caught the counterfeit "AdLibBT" file whose internal name was really "Bangers"
    | (it shipped the wrong typeface for weeks with nothing catching it).
    |
    | Cheap (pure PyMuPDF name/glyph read, no API spend) so it is ON by default. On a
    | mismatch / glyph gap / missing file for a REQUIRED family, the edition is routed
    | to NEEDS_LAYOUT_REVIEW (fail-closed) and the qa_report carries an actionable
    | "upload the correct TTF/OTF for <family>" prompt wired to the existing FontManager
    | upload path. An approved RETIRED-font alias (font_policy._RETIRED_FONT_ALIASES) is
    | a deliberate substitution, NOT a counterfeit, and does not fail the edition.
    | A book with no typography policy requests nothing specific → the preflight is a
    | no-op (the engine falls back to source/house fonts, which ship approved).
    */
    'font_integrity' => [
        'enabled' => env('FONT_INTEGRITY_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Output text-layer gate (engine-wiring-and-activation R-W4)
    |--------------------------------------------------------------------------
    | After render, verify the SAVED pdf has a REAL, searchable text layer that
    | matches the translation — catching the ToUnicode-corruption class where a page
    | looks correct as pixels but extracts to empty text or mojibake. Cheap (pure
    | PyMuPDF extraction, no API) so it is ON by default. A failure (no extractable
    | text / garbled encoding / too few translated words searchable) routes the edition
    | to NEEDS_LAYOUT_REVIEW. min_match_rate = the fraction of expected significant words
    | that must be searchable in the output for the layer to be considered verified.
    */
    'text_layer' => [
        'enabled' => env('TEXT_LAYER_GATE_ENABLED', true),
        'min_match_rate' => (float) env('TEXT_LAYER_MIN_MATCH_RATE', 0.6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tagged-PDF / accessibility pass (engine-wiring-and-activation R-W10)
    |--------------------------------------------------------------------------
    | Post-render pass (scripts/accessibility.py) that stamps the output's /Lang
    | metadata to the edition's target language, re-checks accessibility, and emits
    | alt-text placeholders for human review. Cheap, no API, default on. Fail-closed
    | ONLY when the language write fails (a true regression); a missing structure
    | tree / alt text is a recorded recommendation, NOT a block (PyMuPDF cannot
    | synthesize a StructTreeRoot). Fail-safe: a pass that cannot run never sinks the
    | render.
    */
    'accessibility' => [
        'enabled' => env('ACCESSIBILITY_PASS_ENABLED', true),
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

    /*
    |--------------------------------------------------------------------------
    | Exercise contract extraction (educational gate producer, S1)
    |--------------------------------------------------------------------------
    | When enabled, ExerciseContractService reads the render manifest and writes a
    | structured `exercise_contract` for each edition that has exercise (vocabulary)
    | pages, which activates the INDEPENDENT educational gate for that edition.
    |
    | OFF by default on purpose: populating a contract makes the educational gate
    | active, and until a language pedagogy validator is registered a populated
    | contract routes the edition to specialist review (fail-closed). Storybooks with
    | no vocabulary pages are unaffected (no contract is written → not_applicable).
    | Enable per-environment when you actually want to gate workbook/exercise content.
    */
    'exercise_extraction' => [
        'enabled' => env('EXERCISE_EXTRACTION_ENABLED', false),
    ],
];
