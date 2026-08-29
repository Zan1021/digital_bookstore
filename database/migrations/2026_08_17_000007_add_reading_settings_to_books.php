<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->integer('narration_start_page')->default(3); // Skip cover + publisher info
            $table->integer('narration_end_page')->nullable(); // null = auto (total - 1)
            $table->integer('crop_percent')->default(0); // 0 = no crop, 3-5 = typical trim marks
            $table->boolean('crop_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['narration_start_page', 'narration_end_page', 'crop_percent', 'crop_enabled']);
        });
    }
};
