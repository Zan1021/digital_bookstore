<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per pipeline stage run (task 5.3, Req 3.3, design §20): when it started and
 * finished, how long it took, which attempt, whether it succeeded, and the error.
 * Append-only history for timing budgets (R6, Req 3.4) and corpus run comparisons;
 * processing_jobs stays the single live-progress row the admin UI polls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();
            $table->string('stage', 64);                 // e.g. intake.upload, translate.render
            $table->string('status', 16);                // running | succeeded | failed
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->timestamp('started_at', 3);
            $table->timestamp('finished_at', 3)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->json('meta')->nullable();            // language, page, counts, cost (task 7)
            $table->timestamps();

            $table->index(['book_id', 'stage']);
            $table->index(['stage', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs_runs');
    }
};
