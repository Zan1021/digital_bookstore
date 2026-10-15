<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureStaffPermission;
use App\Models\Book;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Admin, reader and raw-PDF routes need a signed-in, verified staff user with the right
 * permission (task 2.3, Req 1.1, 1.4). Guests are sent to login; customers and staff
 * without the permission get 403, and the denial is logged.
 */
class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    /** Route names outside /admin that serve protected book content. */
    private const READER_ROUTES = ['flipbook', 'reader', 'read-curl', 'raw-pdf'];

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('private');
        Storage::disk('private')->put('books/pdfs/a.pdf', '%PDF-1.4 test');
        $this->book = Book::create([
            'title' => 'Access Book', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/a.pdf',
        ]);
    }

    /** @return array<string, string> route name => URL, for every protected GET route */
    private function protectedUrls(): array
    {
        $urls = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            $isAdmin = str_starts_with($route->uri(), 'admin');
            if (! $name || ! in_array('GET', $route->methods()) || (! $isAdmin && ! in_array($name, self::READER_ROUTES))) {
                continue;
            }
            $params = [];
            foreach ($route->parameterNames() as $p) {
                $params[$p] = $p === 'book' ? $this->book->id : 'af';
            }
            $urls[$name] = route($name, $params);
        }

        return $urls;
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    public function test_the_protected_route_list_is_complete(): void
    {
        $names = array_keys($this->protectedUrls());

        foreach (['admin.dashboard', 'admin.upload', 'admin.onboard', 'admin.book', 'admin.book-details',
                     'admin.review', 'admin.engine-compare', 'admin.review-queue', 'admin.fonts'] as $admin) {
            $this->assertContains($admin, $names);
        }
        foreach (self::READER_ROUTES as $reader) {
            $this->assertContains($reader, $names);
        }
    }

    public function test_every_admin_and_reader_route_requires_auth_and_a_staff_permission(): void
    {
        foreach (Route::getRoutes() as $route) {
            $isAdmin = str_starts_with($route->uri(), 'admin');
            if (! $isAdmin && ! in_array($route->getName(), self::READER_ROUTES)) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $this->assertTrue(
                in_array('auth', $middleware) || in_array(Authenticate::class, $middleware),
                "{$route->uri()} is missing auth middleware"
            );
            $this->assertNotEmpty(
                array_filter($middleware, fn ($m) => is_string($m) && str_starts_with($m, 'staff:')),
                "{$route->uri()} is missing a staff: permission"
            );
        }
    }

    public function test_guests_are_redirected_to_login(): void
    {
        foreach ($this->protectedUrls() as $name => $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_customers_get_403_and_the_attempt_is_logged(): void
    {
        $customer = User::factory()->create();
        Log::spy();

        foreach ($this->protectedUrls() as $name => $url) {
            $this->actingAs($customer)->get($url)->assertForbidden();
        }

        Log::shouldHaveReceived('warning')
            ->with('Admin access denied', \Mockery::on(fn ($ctx) => $ctx['user_id'] === $customer->id))
            ->times(count($this->protectedUrls()));
    }

    public function test_unverified_staff_must_verify_their_email_first(): void
    {
        $admin = User::factory()->unverified()->create()->assignRole('Super Admin');

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_super_admin_reaches_admin_and_raw_pdf(): void
    {
        $admin = $this->staff('Super Admin');

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('raw-pdf', ['book' => $this->book->id, 'lang' => 'en']))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_staff_are_limited_to_their_permissions(): void
    {
        $reviewer = $this->staff('Language Reviewer');
        $fulfilment = $this->staff('Fulfilment Manager');

        $this->actingAs($reviewer)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($reviewer)->get(route('admin.upload'))->assertForbidden();
        $this->actingAs($reviewer)->get(route('admin.fonts', $this->book))->assertForbidden();

        // Fulfilment can reach the admin area but not books or readers.
        $this->actingAs($fulfilment)->get(route('admin.book', $this->book))->assertForbidden();
        $this->actingAs($fulfilment)->get(route('raw-pdf', ['book' => $this->book->id, 'lang' => 'en']))->assertForbidden();
    }

    public function test_staff_middleware_accepts_any_of_several_permissions(): void
    {
        Route::middleware(['web', 'auth', EnsureStaffPermission::class.':translations.review|layout.review'])
            ->get('/_test/either-review', fn () => 'ok');

        $this->actingAs($this->staff('Language Reviewer'))->get('/_test/either-review')->assertOk();
        $this->actingAs($this->staff('Layout Reviewer'))->get('/_test/either-review')->assertOk();
        $this->actingAs($this->staff('Store Manager'))->get('/_test/either-review')->assertForbidden();
    }

    public function test_staff_land_in_admin_after_login_and_customers_on_their_dashboard(): void
    {
        $staff = $this->staff('Language Reviewer');
        Volt::test('pages.auth.login')->set('form.email', $staff->email)->set('form.password', 'password')
            ->call('login')->assertRedirect(route('admin.dashboard', absolute: false));

        auth()->logout();

        $customer = User::factory()->create();
        Volt::test('pages.auth.login')->set('form.email', $customer->email)->set('form.password', 'password')
            ->call('login')->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_admin_header_shows_the_user_and_offers_log_out(): void
    {
        $reviewer = $this->staff('Language Reviewer');

        $this->actingAs($reviewer)->get(route('admin.dashboard'))
            ->assertSee($reviewer->name)->assertSee('Log out')->assertSee(route('logout'));
    }

    public function test_log_out_form_signs_the_user_out(): void
    {
        $this->actingAs($this->staff('Support'))->post(route('logout'))->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_store_hides_the_reader_preview_from_customers(): void
    {
        $this->get(route('store.book', $this->book))->assertOk()->assertDontSee(route('flipbook', $this->book));
        $this->actingAs($this->staff('Content Manager'))->get(route('store.book', $this->book))
            ->assertOk()->assertSee(route('flipbook', $this->book));
    }
}
