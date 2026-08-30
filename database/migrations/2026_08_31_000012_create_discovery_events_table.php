<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Privacy-safe AGGREGATE discovery analytics. No user identifiers.
        Schema::create('discovery_events', function (Blueprint $table) {
            $table->id();
            $table->string('type'); // search, filter, no_result, impression, open, read_start, read_complete, collection
            $table->json('payload')->nullable(); // {term, filters, book_id, collection_id, ...} — no PII
            $table->date('day'); // day bucket for aggregation
            $table->unsignedInteger('count')->default(1);
            $table->timestamps();

            $table->index(['type', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_events');
    }
};
