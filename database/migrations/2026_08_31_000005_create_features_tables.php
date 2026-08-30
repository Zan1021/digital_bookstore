<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // narrated, read_along, interactive, animated, downloadable, ...
            $table->string('group')->nullable(); // digital, accessibility
            $table->json('translations')->nullable();
            $table->timestamps();
        });

        // Features belong to an EDITION (translation row).
        Schema::create('edition_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('translation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['translation_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edition_features');
        Schema::dropIfExists('features');
    }
};
