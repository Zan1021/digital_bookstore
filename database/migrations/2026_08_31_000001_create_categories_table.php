<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('group')->nullable();
            $table->string('status')->default('active'); // active, hidden, archived
            $table->integer('sort_order')->default(0);
            $table->foreignId('merged_into_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->json('translations')->nullable(); // {en: "Picture Books", af: "Prenteboeke"}
            $table->foreignId('created_by')->nullable();
            $table->foreignId('approved_by')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('parent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
