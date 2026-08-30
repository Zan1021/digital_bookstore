<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('type')->default('manual'); // manual, rule
            $table->json('translations')->nullable(); // {en: "Bedtime Stories"}
            $table->json('rules')->nullable(); // rule-based: {language:[...], features:[...], ...}
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('collection_books', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->boolean('pinned')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['collection_id', 'book_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_books');
        Schema::dropIfExists('collections');
    }
};
