<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Languages as data (task 6.3, design §4): code, name, text direction, script (ISO
 * 15924), whether CSS hyphenation is used, and whether it is offered. Seeded with the
 * source language and the 15 targets that were hard-coded in TranslationService, all
 * enabled, so nothing offered today changes.
 *
 * hyphenation starts on only for languages with mature browser hyphenation dictionaries;
 * direction/script feed the layout engine and the per-script font check (Req 7.5).
 */
return new class extends Migration
{
    private const LANGUAGES = [
        // code, name, direction, script, hyphenation
        ['en', 'English', 'ltr', 'Latn', true],
        ['af', 'Afrikaans', 'ltr', 'Latn', true],
        ['zu', 'isiZulu', 'ltr', 'Latn', false],
        ['xh', 'isiXhosa', 'ltr', 'Latn', false],
        ['st', 'Sesotho', 'ltr', 'Latn', false],
        ['nso', 'Sepedi', 'ltr', 'Latn', false],
        ['tn', 'Setswana', 'ltr', 'Latn', false],
        ['fr', 'French', 'ltr', 'Latn', true],
        ['de', 'German', 'ltr', 'Latn', true],
        ['es', 'Spanish', 'ltr', 'Latn', true],
        ['pt', 'Portuguese', 'ltr', 'Latn', true],
        ['nl', 'Dutch', 'ltr', 'Latn', true],
        ['it', 'Italian', 'ltr', 'Latn', true],
        ['sw', 'Swahili', 'ltr', 'Latn', false],
        ['ar', 'Arabic', 'rtl', 'Arab', false],
        ['zh', 'Chinese (Simplified)', 'ltr', 'Hans', false],
    ];

    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->string('code', 12)->primary();     // BCP 47 primary subtag, e.g. af, nso
            $table->string('name');
            $table->string('direction', 3)->default('ltr');   // ltr | rtl
            $table->string('script', 4)->default('Latn');     // ISO 15924
            $table->boolean('hyphenation')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        DB::table('languages')->insert(array_map(fn (array $l, int $i) => [
            'code' => $l[0], 'name' => $l[1], 'direction' => $l[2], 'script' => $l[3],
            'hyphenation' => $l[4], 'enabled' => true, 'sort_order' => $i,
            'created_at' => $now, 'updated_at' => $now,
        ], self::LANGUAGES, array_keys(self::LANGUAGES)));
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
