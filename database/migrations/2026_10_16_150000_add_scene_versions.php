<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 9.2 (design §4–5): the compiled scene files and their versions. The database is the
 * source of truth; scenes/{book}/scene.json and scenes/{book}/{lang}.json are compiled
 * copies. The version goes up only when the compiled content changes (scene_hash).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('scene_path')->nullable();
            $table->unsignedInteger('scene_version')->default(0);
            $table->string('scene_hash', 64)->nullable();
        });

        Schema::table('translations', function (Blueprint $table) {
            $table->unsignedInteger('scene_version')->default(0);
            $table->string('scene_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('translations', fn (Blueprint $table) => $table->dropColumn(['scene_version', 'scene_hash']));
        Schema::table('books', fn (Blueprint $table) => $table->dropColumn(['scene_path', 'scene_version', 'scene_hash']));
    }
};
