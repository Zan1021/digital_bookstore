<?php

/*
 * Scene Engine pipeline (design §6). Per-page stages and their versions (task 9.4).
 *
 * A page is re-run by a stage only when that stage's inputs changed. Raising a stage's
 * version here makes every page run that stage again — do it whenever the stage's code
 * changes what it produces.
 */
return [

    'stages' => [
        // Book stages: one result per page, whatever the language.
        'extract' => ['scope' => 'book', 'version' => 1],
        'structure' => ['scope' => 'book', 'version' => 1],
        'classify' => ['scope' => 'book', 'version' => 1],
        'plates' => ['scope' => 'book', 'version' => 1],
        // Language stages: one result per page per edition.
        'translate' => ['scope' => 'language', 'version' => 1],
        'derive' => ['scope' => 'language', 'version' => 1],
        'layout' => ['scope' => 'language', 'version' => 1],
        'gates' => ['scope' => 'language', 'version' => 1],
        'outputs' => ['scope' => 'language', 'version' => 1],
    ],

];
