<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Work-level (books): analysis workflow + audience + series/type.
        Schema::table('books', function (Blueprint $table) {
            $table->string('classification_status')->default('pending')
                ->after('status'); // pending, analysing, suggested, reviewed, failed
            $table->string('book_type')->nullable()->after('classification_status'); // picture_book, early_reader, ...
            $table->integer('age_min')->nullable()->after('book_type');
            $table->integer('age_max')->nullable()->after('age_min');
            $table->foreignId('audience_range_id')->nullable()->after('age_max');
            $table->string('series')->nullable()->after('audience_range_id');
            $table->integer('series_volume')->nullable()->after('series');
        });

        // Edition-level (translations): reading level + education phase + language role.
        Schema::table('translations', function (Blueprint $table) {
            $table->foreignId('reading_level_id')->nullable()->after('status');
            $table->string('education_phase')->nullable()->after('reading_level_id'); // preschool, foundation, intermediate, senior, fet, adult
            $table->string('language_role')->nullable()->after('education_phase'); // home_language, first_additional_language, not_applicable
            $table->decimal('price', 8, 2)->nullable()->after('language_role');
            $table->string('publication_status')->default('draft')->after('price'); // draft, published, coming_soon
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['classification_status', 'book_type', 'age_min', 'age_max',
                'audience_range_id', 'series', 'series_volume']);
        });
        Schema::table('translations', function (Blueprint $table) {
            $table->dropColumn(['reading_level_id', 'education_phase', 'language_role',
                'price', 'publication_status']);
        });
    }
};
