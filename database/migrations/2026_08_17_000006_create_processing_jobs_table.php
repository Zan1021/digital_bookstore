<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // pdf_process, translate, narrate, metadata
            $table->string('status')->default('queued'); // queued, processing, completed, failed
            $table->integer('progress')->default(0); // 0-100
            $table->text('error_message')->nullable();
            $table->json('details')->nullable(); // Extra context (language, voice, etc.)
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_jobs');
    }
};
