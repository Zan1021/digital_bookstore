<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_descriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('translation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('language_code')->default('en');
            $table->text('short_text')->nullable();
            $table->text('long_text')->nullable();
            $table->string('status')->default('suggested'); // suggested, approved, rejected
            $table->string('source')->default('ai'); // ai, administrator, publisher
            $table->string('model')->nullable();
            $table->foreignId('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['book_id', 'language_code', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_descriptions');
    }
};
