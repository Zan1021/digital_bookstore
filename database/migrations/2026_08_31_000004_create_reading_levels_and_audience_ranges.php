<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_levels', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // pre_reader, beginner, developing, independent, advanced
            $table->integer('rank')->default(0); // easiest -> hardest ordering
            $table->integer('numeric_score')->nullable();
            $table->json('translations')->nullable();
            $table->timestamps();
        });

        Schema::create('audience_ranges', function (Blueprint $table) {
            $table->id();
            $table->string('band')->unique(); // 0-3, 4-6, 7-9, 10-12, 13-15, 16+
            $table->integer('min_age');
            $table->integer('max_age');
            $table->integer('sort_order')->default(0);
            $table->json('translations')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audience_ranges');
        Schema::dropIfExists('reading_levels');
    }
};
