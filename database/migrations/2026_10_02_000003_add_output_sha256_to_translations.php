<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * unified-rendering-and-testing spec Req 2 / task A1.3: the readiness authority
 * (CandidateReadiness) binds every check + approval to BOTH the input fingerprint
 * (render_fingerprint, already present) AND the output file hash. This column stores
 * the SHA-256 of the exact rendered/promoted PDF so a stale file can be detected even
 * when the input fingerprint happens to match.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            if (!Schema::hasColumn('translations', 'output_sha256')) {
                $table->string('output_sha256', 64)->nullable()->after('narration_fingerprint');
            }
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            if (Schema::hasColumn('translations', 'output_sha256')) {
                $table->dropColumn('output_sha256');
            }
        });
    }
};
