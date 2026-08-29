<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translated_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('translation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_page_id')->constrained()->cascadeOnDelete();
            $table->integer('page_number');
            $table->text('translated_text');
            $table->timestamps();

            $table->unique(['translation_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translated_pages');
    }
};
