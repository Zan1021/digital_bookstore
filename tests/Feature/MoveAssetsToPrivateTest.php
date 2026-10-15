<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Services\CoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Task 3.3: assets:move-to-private moves existing book files off the public disk,
 * verifying each copy, leaving store covers public, and creating missing covers.
 */
class MoveAssetsToPrivateTest extends TestCase
{
    use RefreshDatabase;

    private CoverService $covers;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('private');

        Storage::disk('public')->put('books/pdfs/1_book.pdf', 'SOURCE');
        Storage::disk('public')->put('books/translated/1_af.pdf', 'EDITION');
        Storage::disk('public')->put('books/comparison/1_af/source/source_001.png', 'PNG');
        Storage::disk('public')->put('narrations/book-1/af/page-1.mp3', 'MP3');
        Storage::disk('public')->put('temp-previews/upload.pdf', 'TEMP');
        Storage::disk('public')->put('covers/1.jpg', 'COVER');

        $this->covers = Mockery::mock(CoverService::class);
        $this->app->instance(CoverService::class, $this->covers);
    }

    public function test_moves_book_files_to_the_private_disk_and_keeps_covers_public(): void
    {
        $this->covers->shouldReceive('generate')->andReturn(true);

        $this->artisan('assets:move-to-private')->assertSuccessful();

        $private = Storage::disk('private');
        $this->assertSame('SOURCE', $private->get('books/pdfs/1_book.pdf'));
        $this->assertSame('EDITION', $private->get('books/translated/1_af.pdf'));
        $this->assertSame('PNG', $private->get('books/comparison/1_af/source/source_001.png'));
        $this->assertSame('MP3', $private->get('narrations/book-1/af/page-1.mp3'));
        $this->assertSame('TEMP', $private->get('books/temp-previews/upload.pdf'));

        $public = Storage::disk('public');
        $this->assertSame([], $public->allFiles('books'));
        $this->assertSame([], $public->allFiles('narrations'));
        $this->assertFalse($public->exists('temp-previews'));
        $this->assertFalse($public->exists('books'), 'empty public book directories are removed');
        $this->assertSame('COVER', $public->get('covers/1.jpg'), 'covers stay public');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->covers->shouldNotReceive('generate');
        Book::create(['title' => 'B', 'original_language' => 'en', 'page_count' => 1, 'status' => 'ready', 'pdf_path' => 'books/pdfs/1_book.pdf']);

        $this->artisan('assets:move-to-private --dry-run')
            ->expectsOutputToContain('would move books/pdfs/1_book.pdf')
            ->expectsOutputToContain('would create cover')
            ->assertSuccessful();

        $this->assertTrue(Storage::disk('public')->exists('books/pdfs/1_book.pdf'));
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_running_again_is_safe(): void
    {
        $this->covers->shouldReceive('generate')->andReturn(true);

        $this->artisan('assets:move-to-private')->assertSuccessful();
        $this->artisan('assets:move-to-private')->expectsOutputToContain('Moved 0 file(s)')->assertSuccessful();

        $this->assertSame('SOURCE', Storage::disk('private')->get('books/pdfs/1_book.pdf'));
    }

    public function test_a_different_file_already_at_the_target_is_left_alone(): void
    {
        $this->covers->shouldReceive('generate')->andReturn(true);
        Storage::disk('private')->put('books/pdfs/1_book.pdf', 'SOMETHING ELSE');

        $this->artisan('assets:move-to-private')->expectsOutputToContain('conflict: books/pdfs/1_book.pdf')->assertFailed();

        $this->assertSame('SOMETHING ELSE', Storage::disk('private')->get('books/pdfs/1_book.pdf'));
        $this->assertSame('SOURCE', Storage::disk('public')->get('books/pdfs/1_book.pdf'), 'original kept');
    }

    public function test_keep_originals_copies_without_deleting(): void
    {
        $this->covers->shouldReceive('generate')->andReturn(true);

        $this->artisan('assets:move-to-private --keep-originals')->assertSuccessful();

        $this->assertTrue(Storage::disk('private')->exists('books/pdfs/1_book.pdf'));
        $this->assertTrue(Storage::disk('public')->exists('books/pdfs/1_book.pdf'));
    }

    public function test_creates_covers_only_for_books_without_one(): void
    {
        $without = Book::create(['title' => 'No cover', 'original_language' => 'en', 'page_count' => 1, 'status' => 'ready', 'pdf_path' => 'books/pdfs/1_book.pdf']);
        $with = Book::create(['title' => 'Has cover', 'original_language' => 'en', 'page_count' => 1, 'status' => 'ready', 'pdf_path' => 'books/pdfs/1_book.pdf', 'cover_image' => 'covers/1.jpg']);

        $this->covers->shouldReceive('generate')->once()->with(Mockery::on(fn ($b) => $b->is($without)))->andReturn(true);

        $this->artisan('assets:move-to-private')->assertSuccessful();
    }
}
