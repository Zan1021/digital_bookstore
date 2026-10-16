<?php

/*
 * The golden corpus (task 8, Req 26.2, design §22): the fixed set of books every engine
 * change is measured on. The PDFs are copyrighted and live OUTSIDE the repo; they are found
 * by file name under the roots below (the repo's parent folder, ../Book_Pdfs, and any
 * extra folders in CORPUS_DIRS, separated by ';' on Windows or ':' elsewhere).
 *
 * 'features' records which hard cases a book covers. Req 26.2 needs at least three books
 * from other sources with at least one of: table, speech_bubbles, rotated_text,
 * text_in_art, two_column, rtl_target.
 */
return [

    'roots' => array_values(array_filter(array_merge(
        explode(PATH_SEPARATOR, (string) env('CORPUS_DIRS', '')),
        [dirname(base_path()), dirname(base_path()).DIRECTORY_SEPARATOR.'Book_Pdfs'],
    ))),

    'output_dir' => storage_path('app/corpus'),

    // Per 16-page book, Req 3.4: translation incl. layout and automated QA.
    'budget_translate_seconds' => 180,

    'books' => [
        // Mthombothi Studios — Kolulu Taktaki, series 2
        ['key' => 'kolulu-s2-1', 'file' => 'Kolulu S2 BK1 Engl - I am Kolulu Taktaki.pdf', 'source' => 'Mthombothi Studios', 'features' => []],
        ['key' => 'kolulu-s2-2', 'file' => 'Kolulu S2 BK2 Engl - Play with me.pdf', 'source' => 'Mthombothi Studios', 'features' => []],
        ['key' => 'kolulu-s2-3', 'file' => 'Kolulu S2 BK3 Engl - My Senses.pdf', 'source' => 'Mthombothi Studios', 'features' => []],
        ['key' => 'kolulu-s2-4', 'file' => 'Kolulu S2 BK4 Engl - My House.pdf', 'source' => 'Mthombothi Studios', 'features' => []],
        ['key' => 'kolulu-s2-5', 'file' => 'Kolulu S2 BK5 Engl - Colours.pdf', 'source' => 'Mthombothi Studios', 'features' => []],
        ['key' => 'kolulu-s2-6', 'file' => 'Kolulu S2 BK6 Engl - Wild Numbers.pdf', 'source' => 'Mthombothi Studios', 'features' => []],
        // Mthombothi Studios — Kolulu Taktaki, series 3
        ['key' => 'kolulu-s3-1', 'file' => "Kolulu Engl Series 3 - 1 - Kolulu's Home.pdf", 'source' => 'Mthombothi Studios', 'features' => []],
        ['key' => 'kolulu-s3-2', 'file' => 'Kolulu Engl Series 3 - 2 - A Fun Place.pdf', 'source' => 'Mthombothi Studios', 'features' => []],
        ['key' => 'kolulu-s3-3', 'file' => 'Kolulu Engl Series 3 - 3 - An Amazing World.pdf', 'source' => 'Mthombothi Studios', 'features' => []],
        ['key' => 'kolulu-s3-4', 'file' => 'Kolulu Engl Series 3 - 4 - Beyond the River.pdf', 'source' => 'Mthombothi Studios', 'features' => []],
        ['key' => 'kolulu-s3-5', 'file' => 'Kolulu Engl Series 3 - 5 - So Much to See.pdf', 'source' => 'Mthombothi Studios', 'features' => []],
        ['key' => 'kolulu-s3-6', 'file' => 'Kolulu Engl Series 3 - 6 - My Animal World.pdf', 'source' => 'Mthombothi Studios', 'features' => []],
        ['key' => 'math-fun-abby', 'file' => 'Math Fun With Abby .pdf', 'source' => 'Mthombothi Studios', 'features' => []],

        // Outside books (Req 26.2: at least three) — waiting for Captain Zan.
    ],

    // Feature names Req 26.2 accepts for outside books.
    'hard_case_features' => ['table', 'speech_bubbles', 'rotated_text', 'text_in_art', 'two_column', 'rtl_target'],

];
