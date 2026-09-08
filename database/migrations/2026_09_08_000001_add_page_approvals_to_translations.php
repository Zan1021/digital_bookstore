<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gated translation & narration flow (spec: gated-translation-narration-flow).
 *
 * page_approvals: per-page review state for a translated edition, shaped
 *                 { "<page_number>": { "approved": bool, "approved_at": iso8601 } }.
 *                 An edition may transition to APPROVED only when every reviewable
 *                 page is approved.
 * approved_at:    timestamp the edition reached APPROVED (drives the narration gate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->json('page_approvals')->nullable()->after('item_translations');
            $table->timestamp('approved_at')->nullable()->after('page_approvals');
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn(['page_approvals', 'approved_at']);
        });
    }
};
