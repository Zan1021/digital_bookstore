<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * world-class-render-engine spec R9.2 (Phase 6.6): tie approvals to a render fingerprint.
 *
 * - render_fingerprint     : sha256 of all inputs that determine the rendered output.
 *                            A change invalidates layout/artwork approvals.
 * - narration_fingerprint  : sha256 of the TARGET TEXT only. A change invalidates narration
 *                            (R9.3) independently of layout-only changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            if (!Schema::hasColumn('translations', 'render_fingerprint')) {
                $table->string('render_fingerprint', 64)->nullable()->after('qa_report');
            }
            if (!Schema::hasColumn('translations', 'narration_fingerprint')) {
                $table->string('narration_fingerprint', 64)->nullable()->after('render_fingerprint');
            }
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            foreach (['render_fingerprint', 'narration_fingerprint'] as $col) {
                if (Schema::hasColumn('translations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
