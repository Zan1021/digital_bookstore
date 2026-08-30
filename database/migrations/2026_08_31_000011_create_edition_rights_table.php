<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edition_rights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('translation_id')->constrained()->cascadeOnDelete();
            $table->string('rights_holder')->nullable();
            $table->json('territories')->nullable(); // ["ZA","NA"] or ["*"]
            $table->json('languages')->nullable();
            $table->date('licence_start')->nullable();
            $table->date('licence_end')->nullable();
            $table->boolean('digital_rights')->default(true);
            $table->boolean('audio_rights')->default(false);
            $table->boolean('animation_rights')->default(false);
            $table->boolean('print_rights')->default(false);
            $table->json('store_visibility')->nullable(); // territory visibility overrides
            $table->timestamps();

            $table->unique('translation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edition_rights');
    }
};
