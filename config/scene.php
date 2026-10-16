<?php

/*
 * Scene Engine pipeline (design §6). Per-page stages, their versions (task 9.4), and how
 * their per-page jobs run in parallel (task 10.4).
 *
 * A page is re-run by a stage only when that stage's inputs changed. Raising a stage's
 * version here makes every page run that stage again — do it whenever the stage's code
 * changes what it produces.
 *
 * 'handler' is the class that does the stage's work for one page (App\Scene\Contracts\
 * PageStageHandler); it is filled in as each stage is built (tasks 11–19). 'queue' is the
 * worker queue its page jobs run on.
 */
return [

    'stages' => [
        // Book stages: one result per page, whatever the language.
        'extract' => ['scope' => 'book', 'version' => 3, 'queue' => 'intake', 'handler' => \App\Scene\Stages\ExtractStage::class],
        'structure' => ['scope' => 'book', 'version' => 1, 'queue' => 'intake', 'handler' => null],
        'classify' => ['scope' => 'book', 'version' => 1, 'queue' => 'ai', 'handler' => null],
        'plates' => ['scope' => 'book', 'version' => 1, 'queue' => 'intake', 'handler' => null],
        // Language stages: one result per page per edition.
        'translate' => ['scope' => 'language', 'version' => 1, 'queue' => 'ai', 'handler' => null],
        'derive' => ['scope' => 'language', 'version' => 1, 'queue' => 'ai', 'handler' => null],
        'layout' => ['scope' => 'language', 'version' => 1, 'queue' => 'layout', 'handler' => null],
        'gates' => ['scope' => 'language', 'version' => 1, 'queue' => 'layout', 'handler' => null],
        'outputs' => ['scope' => 'language', 'version' => 1, 'queue' => 'exports', 'handler' => null],
    ],

    /*
     * Req 3.2: pages of one book run in parallel, books in parallel, both capped. A page job
     * that finds no free slot waits release_seconds and tries again (it never fails for that).
     */
    'concurrency' => [
        'per_book' => (int) env('SCENE_PAGES_PER_BOOK', 4),
        'global' => (int) env('SCENE_PAGES_GLOBAL', 8),
        'release_seconds' => (int) env('SCENE_PAGES_RELEASE_SECONDS', 5),
        // A page job gives up retrying (waiting for slots or after errors) after this long.
        'retry_minutes' => (int) env('SCENE_PAGES_RETRY_MINUTES', 30),
    ],

];
