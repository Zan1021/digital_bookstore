<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->string('source')->default('administrator'); // publisher, administrator, ai, imported
            $table->decimal('confidence', 4, 3)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable();
            $table->timestamps();

            $table->unique(['book_id', 'category_id']);
            $table->index(['book_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_categories');
    }
};
