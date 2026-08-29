<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interactive layout-debug overlay support (overflow-fix brief §15).
 *
 * layout_overrides: per-EDITION region overrides made by an admin in the layout
 *                   overlay (edited translation, chosen font, size/tracking/
 *                   line-height adjustments, region boundary edits, split/merge).
 *                   Stored against the edition — NOT in code — keyed by stable
 *                   region/item ID, with an audit trail of who/when.
 * translation_contract: the stored stable-ID contract items for this edition, so
 *                   per-item re-render can map an edited ID back to page + order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->json('layout_overrides')->nullable()->after('qa_report');
            $table->json('translation_contract')->nullable()->after('layout_overrides');
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn(['layout_overrides', 'translation_contract']);
        });
    }
};
