<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Contributor;
use App\Models\Publisher;
use App\Models\Series;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Task 6.2 (Req 16.1, 16.2): series and contributors are records. The migration moves
 * books.series / series_volume into series rows and author/illustrator into contributor
 * links; afterwards the model keeps contributors in step with the author fields.
 *
 * DatabaseMigrations (not RefreshDatabase): the backfill test runs the migration itself.
 */
class SeriesAndContributorsTest extends TestCase
{
    use DatabaseMigrations;

    private const MIGRATION = 'database/migrations/2026_10_15_170000_create_series_and_contributors.php';

    private function book(array $attrs = []): Book
    {
        return Book::create($attrs + [
            'title' => 'B', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/b.pdf',
        ]);
    }

    public function test_the_migration_moves_series_text_and_authors_into_records(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('books', 'series'));

        $publisher = Publisher::default()->id;
        $row = fn (string $title, ?string $series, ?int $volume, ?string $author, ?string $illustrator) => DB::table('books')->insertGetId([
            'publisher_id' => $publisher, 'title' => $title, 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'x.pdf', 'sku' => 'BK-'.$title,
            'series' => $series, 'series_volume' => $volume, 'author' => $author, 'illustrator' => $illustrator,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $home = $row('Home', 'Kolulu Series 3', 1, 'Thandi M', 'Sipho K');
        $fun = $row('Fun', 'Kolulu Series 3 ', 2, 'Thandi M', null);
        $alone = $row('Alone', null, null, null, 'Thandi M');

        $this->artisan('migrate', ['--path' => self::MIGRATION])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('books', 'series'));
        $this->assertFalse(Schema::hasColumn('books', 'series_volume'));

        $series = Series::sole();
        $this->assertSame('Kolulu Series 3', $series->name);
        $this->assertSame('kolulu-series-3', $series->slug);
        $this->assertSame($publisher, $series->publisher_id);
        $this->assertSame([$home, $fun], $series->books->pluck('id')->all(), 'books in volume order');
        $this->assertSame(2, Book::find($fun)->series_position);
        $this->assertNull(Book::find($alone)->series_id);

        $thandi = Contributor::where('name', 'Thandi M')->sole();
        $this->assertEqualsCanonicalizing(
            [[$home, 'author'], [$fun, 'author'], [$alone, 'illustrator']],
            $thandi->books->map(fn ($b) => [$b->id, $b->pivot->role])->all()
        );
        $this->assertSame(2, Contributor::count(), 'one record per person');
    }

    public function test_rolling_back_restores_the_series_text(): void
    {
        $series = Series::create(['name' => 'Kolulu Series 3']);
        $book = $this->book(['series_id' => $series->id, 'series_position' => 4]);

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION])->assertSuccessful();

        $restored = DB::table('books')->where('id', $book->id)->first();
        $this->assertSame('Kolulu Series 3', $restored->series);
        $this->assertSame(4, (int) $restored->series_volume);
    }

    public function test_contributors_follow_the_author_fields(): void
    {
        $book = $this->book(['author' => 'Thandi M', 'illustrator' => 'Sipho K']);
        $roles = fn () => $book->fresh()->contributors->mapWithKeys(fn ($c) => [$c->pivot->role => $c->name])->all();

        $this->assertSame(['author' => 'Thandi M', 'illustrator' => 'Sipho K'], $roles());

        $book->update(['author' => 'Lerato N', 'illustrator' => null]);
        $this->assertSame(['author' => 'Lerato N'], $roles());
        $this->assertTrue(Contributor::where('name', 'Thandi M')->exists(), 'the person record is kept');
    }

    public function test_one_person_is_one_record_across_books(): void
    {
        $this->book(['author' => 'Thandi M']);
        $this->book(['illustrator' => 'Thandi M']);

        $this->assertSame(1, Contributor::where('name', 'Thandi M')->count());
        $this->assertCount(2, Contributor::where('name', 'Thandi M')->sole()->books);
    }

    public function test_series_belong_to_the_default_publisher_and_get_a_slug(): void
    {
        $series = Series::create(['name' => 'Wild Numbers']);

        $this->assertTrue($series->publisher->is(Publisher::default()));
        $this->assertSame('wild-numbers', $series->slug);
    }

    public function test_deleting_a_series_keeps_its_books(): void
    {
        $series = Series::create(['name' => 'Short Lived']);
        $book = $this->book(['series_id' => $series->id, 'series_position' => 1]);

        $series->delete();

        $this->assertNull($book->fresh()->series_id);
    }
}
