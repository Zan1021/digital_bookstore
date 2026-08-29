<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->string('language_code'); // e.g., 'af', 'fr', 'zu'
            $table->string('language_name'); // e.g., 'Afrikaans', 'French', 'Zulu'
            $table->string('status')->default('pending'); // pending, processing, draft, approved
            $table->timestamps();

            $table->unique(['book_id', 'language_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
