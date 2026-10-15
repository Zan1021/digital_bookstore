<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Task 6.2 (Req 16.1, 16.2, design §4): series becomes a first-class record, and
 * contributors (author, illustrator, narrator) become separate records linked to books.
 *
 * Existing books.series / books.series_volume text values move into series rows and
 * books.series_id / books.series_position, then the old columns are dropped (one source
 * of truth). books.author / books.illustrator stay as the edited fields for now; the
 * Book model keeps the contributor links in step with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('publisher_id')->constrained('publishers')->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->string('cover_path')->nullable();
            $table->unsignedTinyInteger('age_min')->nullable();
            $table->unsignedTinyInteger('age_max')->nullable();
            $table->string('status', 16)->default('active');   // active | draft | archived
            $table->json('curriculum_plan')->nullable();          // task 23.4
            $table->timestamps();

            $table->unique(['publisher_id', 'slug']);
        });

        Schema::table('books', function (Blueprint $table) {
            $table->foreignId('series_id')->nullable()->after('publisher_id')
                ->constrained('series')->nullOnDelete();
            $table->unsignedSmallInteger('series_position')->nullable()->after('series_id');
            $table->index(['series_id', 'series_position']);
        });

        Schema::create('contributors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();

            $table->index('name');
        });

        Schema::create('book_contributor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contributor_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);                          // author | illustrator | narrator
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['book_id', 'contributor_id', 'role']);
        });

        $this->moveSeriesValues();
        $this->createContributorLinks();

        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn(['series', 'series_volume']);
        });
    }

    public function down(): void
    {
        Schema::table('books', function (Blueprint $table) {
            $table->string('series')->nullable();
            $table->integer('series_volume')->nullable();
        });

        foreach (DB::table('books')->whereNotNull('series_id')->get(['id', 'series_id', 'series_position']) as $book) {
            DB::table('books')->where('id', $book->id)->update([
                'series' => DB::table('series')->where('id', $book->series_id)->value('name'),
                'series_volume' => $book->series_position,
            ]);
        }

        Schema::dropIfExists('book_contributor');
        Schema::dropIfExists('contributors');
        Schema::table('books', function (Blueprint $table) {
            $table->dropIndex(['series_id', 'series_position']);
            $table->dropConstrainedForeignId('series_id');
            $table->dropColumn('series_position');
        });
        Schema::dropIfExists('series');
    }

    private function moveSeriesValues(): void
    {
        $now = now();
        $books = DB::table('books')->whereNotNull('series')->where('series', '!=', '')
            ->get(['id', 'publisher_id', 'series', 'series_volume']);

        $seriesIds = [];
        foreach ($books as $book) {
            $name = trim($book->series);
            $key = $book->publisher_id.'|'.mb_strtolower($name);
            if (! isset($seriesIds[$key])) {
                $slug = Str::slug($name) ?: 'series';
                $seriesIds[$key] = DB::table('series')->where('publisher_id', $book->publisher_id)
                    ->where('slug', $slug)->value('id')
                    ?? DB::table('series')->insertGetId([
                        'publisher_id' => $book->publisher_id, 'name' => $name, 'slug' => $slug,
                        'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                    ]);
            }
            DB::table('books')->where('id', $book->id)->update([
                'series_id' => $seriesIds[$key],
                'series_position' => $book->series_volume !== null ? max(0, (int) $book->series_volume) : null,
            ]);
        }
    }

    private function createContributorLinks(): void
    {
        $now = now();
        $contributorIds = [];
        foreach (DB::table('books')->get(['id', 'author', 'illustrator']) as $book) {
            foreach (['author' => $book->author, 'illustrator' => $book->illustrator] as $role => $name) {
                $name = trim((string) $name);
                if ($name === '') {
                    continue;
                }
                $contributorIds[$name] ??= DB::table('contributors')->where('name', $name)->value('id')
                    ?? DB::table('contributors')->insertGetId(['name' => $name, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('book_contributor')->insertOrIgnore([
                    'book_id' => $book->id, 'contributor_id' => $contributorIds[$name], 'role' => $role,
                    'position' => 0, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }
};
