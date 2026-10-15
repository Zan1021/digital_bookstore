<?php

namespace Tests\Feature;

use App\Assets\AssetRef;
use App\Assets\Entitlements;
use App\Models\Book;
use App\Models\Narration;
use App\Models\Translation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Task 3.1 (Req 20.1, 20.2): protected book files live on the private disk and are only
 * delivered by AssetController — staff, entitled customers, or preview pages — through a
 * short-lived signed URL bound to the user who asked for it.
 */
class AssetDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('private');

        $this->book = Book::create([
            'title' => 'Asset Book', 'original_language' => 'en', 'page_count' => 3,
            'status' => 'ready', 'pdf_path' => 'books/1/source.pdf',
        ]);
        Storage::disk('private')->put('books/1/source.pdf', 'SOURCE-PDF');

        Translation::create([
            'book_id' => $this->book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans',
            'status' => 'draft', 'rendered_pdf_path' => 'books/1/af.pdf',
        ]);
        Storage::disk('private')->put('books/1/af.pdf', 'AF-PDF');

        Narration::create([
            'book_id' => $this->book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans',
            'voice_id' => 'v', 'voice_name' => 'V', 'status' => 'completed',
            'page_audio_paths' => [1 => 'books/1/audio/af/p1.mp3', 2 => 'books/1/audio/af/p2.mp3'],
        ]);
        Storage::disk('private')->put('books/1/audio/af/p1.mp3', 'MP3-PAGE-1');
        Storage::disk('private')->put('books/1/audio/af/p2.mp3', 'MP3-PAGE-2');
    }

    private function staff(string $role = 'Content Manager'): User
    {
        return User::factory()->create()->assignRole($role);
    }

    private function previewPages(array $pages): void
    {
        $this->book->forceFill(['metadata' => ['preview_pages' => $pages]])->save();
    }

    private function signedUrlFor(string $kind, array $query = []): string
    {
        $response = $this->get(route('assets.show', ['book' => $this->book, 'kind' => $kind] + $query));
        $response->assertRedirect();

        return $response->headers->get('Location');
    }

    public function test_the_private_disk_is_outside_the_public_web_root(): void
    {
        $root = str_replace('\\', '/', config('filesystems.disks.private.root'));
        $this->assertStringNotContainsString('app/public', $root);
        $this->assertFalse(config('filesystems.disks.private.serve'));
        $this->assertSame('private', config('assets.disk'));
    }

    public function test_staff_get_a_signed_url_that_serves_the_file(): void
    {
        $this->actingAs($this->staff());

        $url = $this->signedUrlFor('edition-pdf', ['lang' => 'af']);
        $this->assertStringContainsString('/assets/serve/', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString('books/1/af.pdf', $url, 'the storage path must not leak');

        $this->get($url)->assertOk()
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertStreamedContent('AF-PDF');
    }

    public function test_guests_and_customers_cannot_get_whole_book_files(): void
    {
        Log::spy();
        $customer = User::factory()->create();

        $this->get(route('assets.show', [$this->book, 'source-pdf']))->assertForbidden();
        $this->actingAs($customer)->get(route('assets.show', [$this->book, 'source-pdf']))->assertForbidden();
        $this->actingAs($customer)->get(route('assets.show', [$this->book, 'edition-pdf', 'lang' => 'af']))->assertForbidden();

        Log::shouldHaveReceived('warning')->with('Asset access denied', \Mockery::type('array'))->times(3);
    }

    public function test_staff_without_books_view_are_refused(): void
    {
        $this->actingAs($this->staff('Fulfilment Manager'))
            ->get(route('assets.show', [$this->book, 'source-pdf']))->assertForbidden();
    }

    public function test_preview_pages_are_open_to_anyone_and_nothing_else_is(): void
    {
        $this->previewPages([1]);

        $url = $this->signedUrlFor('audio', ['lang' => 'af', 'page' => 1]);
        $this->get($url)->assertOk()->assertStreamedContent('MP3-PAGE-1');

        $this->get(route('assets.show', [$this->book, 'audio', 'lang' => 'af', 'page' => 2]))->assertForbidden();
        // A whole-book file contains every page, so it is never a preview.
        $this->get(route('assets.show', [$this->book, 'source-pdf']))->assertForbidden();
    }

    public function test_entitled_customers_get_their_asset(): void
    {
        $customer = User::factory()->create();
        $this->app->instance(Entitlements::class, new class($customer) implements Entitlements {
            public function __construct(private User $owner) {}

            public function allows(User $user, AssetRef $asset): bool
            {
                return $user->is($this->owner) && $asset->language === 'af';
            }
        });

        $this->actingAs($customer);
        $this->get($this->signedUrlFor('edition-pdf', ['lang' => 'af']))->assertOk()->assertStreamedContent('AF-PDF');
        $this->get(route('assets.show', [$this->book, 'source-pdf']))->assertForbidden();
    }

    public function test_a_signed_url_only_works_for_the_user_it_was_issued_to(): void
    {
        $this->actingAs($this->staff());
        $url = $this->signedUrlFor('source-pdf');

        $this->actingAs($this->staff())->get($url)->assertForbidden();
        auth()->logout();
        $this->get($url)->assertForbidden();
    }

    public function test_a_tampered_or_expired_signed_url_is_refused(): void
    {
        $this->previewPages([1]);
        $url = $this->signedUrlFor('audio', ['lang' => 'af', 'page' => 1]);

        $this->get(str_replace('page=1', 'page=2', $url))->assertForbidden();

        $this->travel(config('assets.signed_url_minutes') + 1)->minutes();
        $this->get($url)->assertForbidden();
    }

    public function test_access_is_checked_again_when_the_file_is_served(): void
    {
        $this->previewPages([1]);
        $url = $this->signedUrlFor('audio', ['lang' => 'af', 'page' => 1]);

        $this->previewPages([]); // publisher withdrew the preview page

        $this->get($url)->assertForbidden();
    }

    public function test_unknown_or_missing_assets_are_404(): void
    {
        $this->actingAs($this->staff());

        $this->get(route('assets.show', [$this->book, 'edition-pdf', 'lang' => 'zu']))->assertNotFound();
        $this->get(route('assets.show', [$this->book, 'audio', 'lang' => 'af', 'page' => 3]))->assertNotFound();
        $this->get('/assets/books/'.$this->book->id.'/..%2F..%2F.env')->assertNotFound();

        Storage::disk('private')->delete('books/1/source.pdf');
        $this->get(route('assets.show', [$this->book, 'source-pdf']))->assertNotFound();
    }

    public function test_outdated_narration_is_not_served(): void
    {
        Narration::where('book_id', $this->book->id)->update(['is_outdated' => true]);
        $this->actingAs($this->staff());

        $this->get(route('assets.show', [$this->book, 'audio', 'lang' => 'af', 'page' => 1]))->assertNotFound();
    }
}
