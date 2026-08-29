<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fail-closed publication support (overflow-fix brief §13/§14).
 *
 * render_status: edition/layout QA state. A translation may only be approved or
 *                published when this is READY_FOR_REVIEW/APPROVED/PUBLISHABLE.
 *                NEEDS_LAYOUT_REVIEW blocks publication.
 * qa_report:     the per-page/per-region diagnostic report from the render gate,
 *                persisted with the edition (not just logged) so admins can inspect.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->string('render_status')->nullable()->after('status');
            $table->json('qa_report')->nullable()->after('render_status');
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn(['render_status', 'qa_report']);
        });
    }
};
