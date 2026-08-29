<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Store the per-edge trim box as fractions of the MediaBox:
     *   { "left": 0.03, "top": 0.02, "right": 0.05, "bottom": 0.08 }
     *
     * This replaces the lossy single `crop_percent` average, which could not
     * represent asymmetric bleed and caused skewed / clipped rendering.
     * Any uploaded book gets its own detected box; null = display as-is.
     */
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->json('crop_box')->nullable()->after('crop_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('crop_box');
        });
    }
};
