<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 7.2 (design §4, §18; Req 3.5, 22.3, 22.5): one ledger row per AI call, a cache of
 * AI results keyed by input hash, and the admin's monthly spending limits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_ledger', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 40);
            $table->string('model', 80);
            $table->string('operation', 60)->index();
            // Rows outlive the book or edition (cost history stays), like jobs_runs.
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('translation_id')->nullable()->constrained()->nullOnDelete();
            $table->json('units');
            $table->decimal('cost_zar', 12, 4)->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->boolean('cache_hit')->default(false);
            $table->string('input_hash', 64)->nullable();
            $table->timestamp('at')->index();
        });

        Schema::create('ai_cache', function (Blueprint $table) {
            $table->id();
            $table->string('input_hash', 64)->unique();
            $table->string('kind', 20);            // text | image | speech
            $table->string('provider', 40);
            $table->string('model', 80);
            $table->json('units');
            $table->longText('text')->nullable();  // text answers
            $table->json('alignment')->nullable(); // speech timings
            $table->string('blob_path')->nullable(); // image/audio bytes on the private disk
            $table->timestamps();
        });

        Schema::create('ai_spending_limits', function (Blueprint $table) {
            $table->id();
            // null = the monthly total across all operations
            $table->string('operation', 60)->nullable()->unique();
            $table->decimal('monthly_limit_zar', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_spending_limits');
        Schema::dropIfExists('ai_cache');
        Schema::dropIfExists('ai_ledger');
    }
};
