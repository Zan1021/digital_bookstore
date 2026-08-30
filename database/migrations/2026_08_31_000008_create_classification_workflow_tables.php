<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Raw AI/analysis output (admin-only). One row per suggested field.
        Schema::create('classification_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('translation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('field'); // book_type, primary_category, age_range, reading_level, tag, theme, ...
            $table->json('value');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->boolean('requires_confirmation')->default(false);
            $table->string('model')->nullable();
            $table->string('engine_version')->nullable();
            $table->timestamps();

            $table->index(['book_id', 'field']);
        });

        // Human review decisions (audit trail).
        Schema::create('classification_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable();
            $table->string('field');
            $table->string('decision'); // accepted, edited, rejected
            $table->json('final_value')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classification_reviews');
        Schema::dropIfExists('classification_suggestions');
    }
};
