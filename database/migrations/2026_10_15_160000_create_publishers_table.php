<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenancy readiness (task 6.1, Req 25.2, design §4): a publishers table and a
 * publisher_id on publisher-owned records. The store launches single-publisher, so
 * Mthombothi Studios is created here and every existing book is assigned to it.
 * Later publisher-owned tables (series, fonts, products, templates) get publisher_id
 * when they are created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('publishers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        $now = now();
        $publisherId = DB::table('publishers')->insertGetId([
            'name' => 'Mthombothi Studios',
            'slug' => 'mthombothi-studios',
            'settings' => json_encode((object) []),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Schema::table('books', function (Blueprint $table) {
            $table->foreignId('publisher_id')->nullable()->after('id')
                ->constrained('publishers')->restrictOnDelete();
        });

        DB::table('books')->whereNull('publisher_id')->update(['publisher_id' => $publisherId]);
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropConstrainedForeignId('publisher_id');
        });

        Schema::dropIfExists('publishers');
    }
};
