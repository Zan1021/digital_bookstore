<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 9.4 (Req 3.6, design §6): which pipeline stage last completed for which page, and
 * from which inputs. A stage re-runs a page only when the hash of its inputs differs.
 *
 * edition_key is '' for book stages (extract, plates, …) and the translation id for
 * language stages (translate, layout, …): a NOT NULL key, because unique constraints treat
 * NULLs as distinct in both SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_stage_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('source_pages')->cascadeOnDelete();
            $table->foreignId('translation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('edition_key', 20)->default('');
            $table->string('stage', 20);
            $table->string('inputs_hash', 64);
            $table->timestamp('completed_at');
            $table->timestamps();
            $table->unique(['page_id', 'edition_key', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_stage_states');
    }
};
