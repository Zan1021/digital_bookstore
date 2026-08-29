<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('manifest_path')->nullable()->after('pdf_path');
            $table->string('render_engine')->default('v7')->after('status'); // v7 or v8
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['manifest_path', 'render_engine']);
        });
    }
};
