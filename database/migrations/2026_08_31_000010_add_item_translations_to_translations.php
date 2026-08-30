<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable per-element translation store (fix B, 2026-08-30).
 *
 * item_translations: machine-generated per-element translations keyed by the
 *   stable contract/manifest item ID (id => translated string). Produced by the
 *   manifest translation pipeline (translateWithManifest) and consumed DIRECTLY by
 *   the render resolver, so each span carries its OWN translation instead of being
 *   reconstructed by splitting the flat per-page translated_text (the lossy path
 *   that leaked English on multi-span pages like the back cover / WOORDE).
 *
 * Kept DISTINCT from layout_overrides on purpose: layout_overrides is the human
 *   admin edit channel (with who/when audit trail) and must still WIN over the
 *   machine per-id translations. This column is the automated per-id source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->json('item_translations')->nullable()->after('translation_contract');
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn('item_translations');
        });
    }
};
