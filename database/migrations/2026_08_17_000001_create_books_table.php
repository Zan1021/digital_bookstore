<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('books', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('author')->nullable();
            $table->string('illustrator')->nullable();
            $table->string('sku')->unique()->nullable();
            $table->string('original_language')->default('en');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->string('age_group')->nullable();
            $table->integer('page_count')->default(0);
            $table->string('cover_image')->nullable();
            $table->string('pdf_path');
            $table->string('status')->default('draft'); // draft, processing, ready, published
            $table->json('metadata')->nullable(); // AI-suggested metadata
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('books');
    }
};
