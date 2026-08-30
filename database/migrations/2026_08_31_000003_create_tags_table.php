<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('canonical_name');
            $table->string('group')->nullable(); // themes_and_values, topics, educational, cultural, experience
            $table->foreignId('parent_id')->nullable()->constrained('tags')->nullOnDelete();
            $table->string('status')->default('active'); // active, pending, archived
            $table->foreignId('merged_into_id')->nullable()->constrained('tags')->nullOnDelete();
            $table->boolean('pinned')->default(false);
            $table->foreignId('created_by')->nullable();
            $table->foreignId('approved_by')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('group');
        });

        Schema::create('tag_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('language_code');
            $table->string('label');
            $table->timestamps();

            $table->unique(['tag_id', 'language_code']);
        });

        Schema::create('tag_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('alias');
            $table->timestamps();

            $table->index('alias');
        });

        Schema::create('book_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->string('source')->default('administrator');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable();
            $table->timestamps();

            $table->unique(['book_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_tags');
        Schema::dropIfExists('tag_aliases');
        Schema::dropIfExists('tag_translations');
        Schema::dropIfExists('tags');
    }
};
