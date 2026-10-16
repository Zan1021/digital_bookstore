<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 8.1 (Req 26.2, design §22): the golden corpus. Each row ties a corpus entry from
 * config/corpus.php to the book imported from its source PDF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('corpus_books', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('file');
            $table->string('source', 80);            // publisher or origin of the PDF
            $table->json('features');                // table, speech_bubbles, rotated_text, ...
            $table->foreignId('book_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_sha256', 64)->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corpus_books');
    }
};
