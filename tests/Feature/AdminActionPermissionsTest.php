<?php

namespace Tests\Feature;

use App\Livewire\Admin\BookManager;
use App\Livewire\Admin\BookReviewDetails;
use App\Livewire\Admin\Dashboard;
use App\Livewire\Admin\ReviewQueue;
use App\Livewire\Admin\Testing\UndeclaredActionComponent;
use App\Livewire\Attributes\Can;
use App\Models\Book;
use App\Models\Translation;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

require_once __DIR__.'/../Fixtures/UndeclaredActionComponent.php';

/**
 * Task 2.3a — every action inside an admin page declares the permission it needs
 * (#[Can]); a lower role cannot run a higher role's action; an action that declares
 * nothing is refused for everyone (fail closed); denials are logged (Req 1.4).
 */
class AdminActionPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private Book $book;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('private');
        $this->book = Book::create([
            'title' => 'Action Book', 'original_language' => 'en', 'page_count' => 1,
            'status' => 'ready', 'pdf_path' => 'books/pdfs/a.pdf',
        ]);
    }

    private function as(string $role): User
    {
        $user = User::factory()->create()->assignRole($role);
        $this->actingAs($user);

        return $user;
    }

    /** @return list<array{string, string}> [component class, method] for every public action */
    private function adminActions(): array
    {
        $actions = [];
        foreach (glob(app_path('Livewire/Admin/*.php')) as $file) {
            $class = 'App\\Livewire\\Admin\\'.basename($file, '.php');
            $ref = new ReflectionClass($class);
            foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
                $name = $m->getName();
                if ($m->getDeclaringClass()->getName() !== $class
                    || in_array($name, ['mount', 'render', 'boot', 'hydrate', 'dehydrate', 'rules', 'messages'])
                    || str_starts_with($name, '__')
                    || preg_match('/^(updated|updating|hydrate|dehydrate|boot|mount)[A-Z]/', $name)
                    || Can::isFrameworkMethod($ref->newInstanceWithoutConstructor(), $name)) {
                    continue;
                }
                $actions[] = [$class, $name];
            }
        }

        return $actions;
    }

    public function test_every_admin_action_declares_known_permissions(): void
    {
        $declared = array_keys(config('roles.permissions'));
        $actions = $this->adminActions();
        $this->assertGreaterThan(70, count($actions), 'inventory looks too small');

        foreach ($actions as [$class, $method]) {
            $attrs = (new ReflectionMethod($class, $method))->getAttributes(Can::class);
            $this->assertCount(1, $attrs, "$class::$method has no #[Can] permission");
            foreach (explode('|', $attrs[0]->newInstance()->permissions) as $perm) {
                $this->assertContains($perm, $declared, "$class::$method uses unknown permission '$perm'");
            }
        }
    }

    public function test_an_undeclared_action_is_refused_even_for_super_admin(): void
    {
        $this->as('Super Admin');
        Log::spy();

        Livewire::test(UndeclaredActionComponent::class)
            ->call('forgotToDeclare')->assertForbidden()->assertSet('ran', false);
        Livewire::test(UndeclaredActionComponent::class)
            ->call('declared')->assertSet('ran', true);

        Log::shouldHaveReceived('warning')->with('Admin access denied', \Mockery::on(
            fn ($ctx) => $ctx['action'] === 'forgotToDeclare' && str_contains($ctx['reason'], '#[Can]')
        ))->once();
    }

    public function test_a_reviewer_cannot_delete_a_book_but_a_content_manager_can(): void
    {
        $reviewer = $this->as('Language Reviewer');
        Log::spy();

        Livewire::test(Dashboard::class)->call('deleteBook', $this->book->id)->assertForbidden();
        $this->assertNotNull($this->book->fresh(), 'book must survive a denied delete');
        Log::shouldHaveReceived('warning')->with('Admin access denied', \Mockery::on(
            fn ($ctx) => $ctx['user_id'] === $reviewer->id && $ctx['action'] === 'deleteBook'
        ))->once();

        $this->as('Content Manager');
        Livewire::test(Dashboard::class)->call('deleteBook', $this->book->id)->assertOk();
        $this->assertNull($this->book->fresh());
    }

    public function test_only_translation_managers_can_start_a_translation(): void
    {
        Bus::fake();

        foreach (['Language Reviewer', 'Store Manager', 'Support'] as $role) {
            $this->as($role);
            Livewire::test(BookManager::class, ['book' => $this->book])
                ->set('selectedLanguage', 'af')->call('translate')->assertForbidden();
        }
        Bus::assertNothingDispatched();
        $this->assertSame(0, Translation::count());
    }

    public function test_publishing_needs_editions_publish(): void
    {
        $this->as('Store Manager');
        Livewire::test(BookReviewDetails::class, ['book' => $this->book])->call('publish')->assertForbidden();

        $this->as('Content Manager');
        Livewire::test(BookReviewDetails::class, ['book' => $this->book])->call('publish')->assertOk();
    }

    public function test_approval_tracks_follow_the_reviewers_area(): void
    {
        $edition = Translation::create([
            'book_id' => $this->book->id, 'language_code' => 'af', 'language_name' => 'Afrikaans',
            'status' => 'draft', 'render_status' => Translation::STATE_READY_FOR_REVIEW,
        ]);

        $this->as('Layout Reviewer');
        Livewire::test(ReviewQueue::class, ['book' => $this->book, 'language' => 'af'])
            ->call('approveTrack', 'language')->assertForbidden();
        Livewire::test(ReviewQueue::class, ['book' => $this->book, 'language' => 'af'])
            ->call('approveTrack', 'layout')->assertOk();

        $tracks = $edition->fresh()->approval_tracks ?? [];
        $this->assertArrayNotHasKey('language', $tracks);
        $this->assertTrue($tracks['layout']['approved'] ?? false);
    }

    public function test_buttons_are_hidden_from_users_who_cannot_use_them(): void
    {
        $this->as('Language Reviewer');
        Livewire::test(BookManager::class, ['book' => $this->book])
            ->assertDontSeeHtml('wire:click="translate"')
            ->assertDontSeeHtml('wire:click="saveSettings"');

        $this->as('Content Manager');
        Livewire::test(BookManager::class, ['book' => $this->book])
            ->set('activeTab', 'translate')
            ->assertSeeHtml('wire:click="translate"');
    }
}
