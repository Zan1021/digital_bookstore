<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Task 10.2 (Req 2.2, 2.6, design §4): SHA-256 of the uploaded original, for duplicate detection. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('source_checksum', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex(['source_checksum']);
            $table->dropColumn('source_checksum');
        });
    }
};
