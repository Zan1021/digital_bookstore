<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_advisories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique(); // none, mild_peril, sadness_loss, conflict, ...
            $table->json('translations')->nullable();
            $table->timestamps();
        });

        Schema::create('book_content_advisories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_advisory_id')->constrained()->cascadeOnDelete();
            $table->string('source')->default('administrator');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['book_id', 'content_advisory_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_content_advisories');
        Schema::dropIfExists('content_advisories');
    }
};
