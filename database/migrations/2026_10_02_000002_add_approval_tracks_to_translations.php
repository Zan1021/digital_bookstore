<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * world-class-render-engine spec R10.4 (Phase 7.4): separate approval TRACKS.
 *
 * language / layout / artwork approvals are tracked independently (a reviewer can sign off
 * language without yet approving layout, etc.). Each track records the render_fingerprint
 * it was approved against, so a content change (new fingerprint) invalidates it (R10.5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            if (!Schema::hasColumn('translations', 'approval_tracks')) {
                $table->json('approval_tracks')->nullable()->after('narration_fingerprint');
            }
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            if (Schema::hasColumn('translations', 'approval_tracks')) {
                $table->dropColumn('approval_tracks');
            }
        });
    }
};
