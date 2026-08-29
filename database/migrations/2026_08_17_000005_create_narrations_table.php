<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('narrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->string('language_code');
            $table->string('language_name');
            $table->string('voice_id'); // ElevenLabs voice ID
            $table->string('voice_name');
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->string('audio_path')->nullable(); // Full book audio
            $table->json('page_audio_paths')->nullable(); // Per-page audio paths
            $table->integer('duration_seconds')->nullable();
            $table->timestamps();

            $table->unique(['book_id', 'language_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('narrations');
    }
};
