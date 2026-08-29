<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_memory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->string('language_code', 10);
            $table->string('source_term');           // Original English term
            $table->string('translated_term');       // Approved translation
            $table->string('context')->nullable();   // Where/how it's used (e.g., "character name", "location")
            $table->string('category')->default('general'); // general, character, location, term, phonics
            $table->integer('first_page')->nullable();      // Page where first encountered
            $table->decimal('confidence', 3, 1)->default(9.0); // How confident we are in this translation
            $table->boolean('review_required')->default(false);
            $table->boolean('locked')->default(false);      // Manually approved — never change
            $table->text('notes')->nullable();
            $table->timestamps();

            // Unique: one translation per source term per book per language
            $table->unique(['book_id', 'language_code', 'source_term'], 'tm_unique_term');
            $table->index(['book_id', 'language_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_memory');
    }
};
