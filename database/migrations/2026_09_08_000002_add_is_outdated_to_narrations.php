<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gated translation & narration flow (spec: gated-translation-narration-flow).
 *
 * is_outdated: set true when the approved translated text of an edition is edited
 *              after its narration was generated, so stale audio is never served as
 *              current. Cleared when the narration is re-generated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('narrations', function (Blueprint $table) {
            $table->boolean('is_outdated')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('narrations', function (Blueprint $table) {
            $table->dropColumn('is_outdated');
        });
    }
};
