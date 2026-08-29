<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translated_pages', function (Blueprint $table) {
            $table->text('back_translation')->nullable()->after('translated_text');
            $table->decimal('confidence_score', 4, 2)->nullable()->after('back_translation');
            $table->string('quality_flag', 20)->default('pending')->after('confidence_score'); // pending, green, yellow, red
            $table->text('quality_notes')->nullable()->after('quality_flag');
            $table->text('reviewer_notes')->nullable()->after('quality_notes');
            $table->string('review_status', 20)->default('unreviewed')->after('reviewer_notes'); // unreviewed, approved, needs_edit
        });
    }

    public function down(): void
    {
        Schema::table('translated_pages', function (Blueprint $table) {
            $table->dropColumn([
                'back_translation',
                'confidence_score',
                'quality_flag',
                'quality_notes',
                'reviewer_notes',
                'review_status',
            ]);
        });
    }
};
