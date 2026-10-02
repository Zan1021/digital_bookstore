<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * unified-rendering-and-testing spec Req 6 / task C1.1: structured exercise contract.
 *
 * Stored as a JSON contract on the edition (array-cast), mirroring item_translations /
 * layout_overrides — NOT a relational table. Each exercise carries component slots
 * (objective/instruction/pattern/examples/questions/answer_key) with stable component IDs,
 * so educational validity can be gated by exact ID (ExerciseService::componentIdIssues)
 * independently of layout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            if (!Schema::hasColumn('translations', 'exercise_contract')) {
                $table->json('exercise_contract')->nullable()->after('item_translations');
            }
        });
    }

    public function down(): void
    {
        Schema::table('translations', function (Blueprint $table) {
            if (Schema::hasColumn('translations', 'exercise_contract')) {
                $table->dropColumn('exercise_contract');
            }
        });
    }
};
