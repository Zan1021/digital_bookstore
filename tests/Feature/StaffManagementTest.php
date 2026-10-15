<?php

namespace Tests\Feature;

use App\Livewire\Admin\StaffManager;
use App\Models\User;
use App\Notifications\StaffInvitation;
use App\Services\Staff\StaffManagement;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * Staff management screen (task 2.4, Req 1.5): a Super Admin invites, re-roles,
 * deactivates and reactivates staff; deactivated accounts cannot sign in and are signed
 * out; the last active Super Admin is protected; nobody else can manage staff.
 */
class StaffManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->owner = User::factory()->create(['name' => 'Owner'])->assignRole('Super Admin');
        $this->actingAs($this->owner);
    }

    private function member(string $role, array $attrs = []): User
    {
        return User::factory()->create($attrs)->assignRole($role);
    }

    private function inviteTokenFor(string $email): string
    {
        $token = null;
        Notification::assertSentTo(User::where('email', $email)->first(), StaffInvitation::class,
            function (StaffInvitation $n) use (&$token) {
                $token = $n->token;

                return true;
            });

        return $token;
    }

    public function test_only_super_admin_can_open_the_staff_screen(): void
    {
        $this->get(route('admin.staff'))->assertOk()->assertSee('Invite a staff member');

        $this->actingAs($this->member('Content Manager'))->get(route('admin.staff'))->assertForbidden();
    }

    public function test_invite_creates_a_pending_staff_member_and_emails_a_set_password_link(): void
    {
        Notification::fake();

        Livewire::test(StaffManager::class)
            ->set('name', 'Thandi')->set('email', 'Thandi@Example.test')->set('role', 'Language Reviewer')
            ->call('invite')->assertHasNoErrors()->assertSee('Invitation sent');

        $invited = User::where('email', 'thandi@example.test')->firstOrFail();
        $this->assertTrue($invited->hasRole('Language Reviewer'));
        $this->assertTrue($invited->isPendingInvite());
        $this->assertNotNull($invited->email_verified_at);

        Notification::assertSentTo($invited, StaffInvitation::class,
            fn (StaffInvitation $n) => str_contains($n->toMail($invited)->actionUrl, '/accept-invite/'));
    }

    public function test_accepting_the_invite_sets_the_password_and_signs_in_to_admin(): void
    {
        Notification::fake();
        Livewire::test(StaffManager::class)
            ->set('name', 'Thandi')->set('email', 'thandi@example.test')->set('role', 'Language Reviewer')
            ->call('invite');
        $token = $this->inviteTokenFor('thandi@example.test');
        auth()->logout();

        Volt::test('pages.auth.accept-invite', ['token' => $token])
            ->set('email', 'thandi@example.test')
            ->set('password', 'a-long-new-password')->set('password_confirmation', 'a-long-new-password')
            ->call('acceptInvite')->assertHasNoErrors()->assertRedirect(route('admin.dashboard'));

        $invited = User::where('email', 'thandi@example.test')->first();
        $this->assertAuthenticatedAs($invited);
        $this->assertFalse($invited->isPendingInvite());
        $this->assertTrue(Hash::check('a-long-new-password', $invited->password));
    }

    public function test_invite_link_expires_after_seven_days(): void
    {
        Notification::fake();
        Livewire::test(StaffManager::class)
            ->set('name', 'Late')->set('email', 'late@example.test')->set('role', 'Support')->call('invite');
        $token = $this->inviteTokenFor('late@example.test');
        auth()->logout();

        $this->travel(8)->days();

        Volt::test('pages.auth.accept-invite', ['token' => $token])
            ->set('email', 'late@example.test')
            ->set('password', 'a-long-new-password')->set('password_confirmation', 'a-long-new-password')
            ->call('acceptInvite')->assertHasErrors('email');
        $this->assertGuest();
    }

    public function test_inviting_an_existing_customer_gives_them_the_role_without_an_email(): void
    {
        Notification::fake();
        $customer = User::factory()->create(['email' => 'reader@example.test', 'password' => 'their-own-password']);

        Livewire::test(StaffManager::class)
            ->set('name', 'x')->set('email', 'reader@example.test')->set('role', 'Support')->call('invite')
            ->assertHasNoErrors();

        $customer->refresh();
        $this->assertTrue($customer->hasRole('Support'));
        $this->assertTrue(Hash::check('their-own-password', $customer->password));
        Notification::assertNothingSent();
    }

    public function test_inviting_someone_already_on_staff_is_refused(): void
    {
        $this->member('Support', ['email' => 'staff@example.test']);

        Livewire::test(StaffManager::class)
            ->set('name', 'x')->set('email', 'staff@example.test')->set('role', 'Store Manager')->call('invite')
            ->assertHasErrors('email');
    }

    public function test_role_must_be_a_staff_role(): void
    {
        $member = $this->member('Support');

        Livewire::test(StaffManager::class)->call('changeRole', $member->id, 'Wizard')->assertHasErrors('role');
        $this->assertTrue($member->fresh()->hasRole('Support'));
    }

    public function test_change_role_replaces_the_old_role(): void
    {
        $member = $this->member('Support');

        Livewire::test(StaffManager::class)->call('changeRole', $member->id, 'Store Manager')->assertHasNoErrors();

        $member->refresh();
        $this->assertSame(['Store Manager'], $member->getRoleNames()->all());
    }

    public function test_you_cannot_change_your_own_role_or_deactivate_yourself(): void
    {
        Livewire::test(StaffManager::class)->call('changeRole', $this->owner->id, 'Support')->assertHasErrors('staff');
        Livewire::test(StaffManager::class)->call('deactivate', $this->owner->id)->assertHasErrors('staff');

        $this->assertTrue($this->owner->fresh()->hasRole('Super Admin'));
        $this->assertFalse($this->owner->fresh()->isDeactivated());
    }

    public function test_the_last_active_super_admin_cannot_be_demoted_or_deactivated(): void
    {
        $service = app(StaffManagement::class);
        $colleague = $this->member('Support');
        // A deactivated Super Admin does not count as a remaining one.
        $this->member('Super Admin')->forceFill(['deactivated_at' => now()])->save();

        foreach ([fn () => $service->deactivate($colleague, $this->owner),
                  fn () => $service->changeRole($colleague, $this->owner, 'Support')] as $attempt) {
            try {
                $attempt();
                $this->fail('the last active Super Admin was removed');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('staff', $e->errors());
            }
        }
        $this->assertTrue($this->owner->fresh()->hasRole('Super Admin'));
        $this->assertFalse($this->owner->fresh()->isDeactivated());
    }

    public function test_a_super_admin_can_be_removed_while_another_active_one_remains(): void
    {
        $second = $this->member('Super Admin');

        Livewire::test(StaffManager::class)->call('changeRole', $second->id, 'Support')->assertHasNoErrors();

        $this->assertSame(['Support'], $second->fresh()->getRoleNames()->all());
    }

    public function test_deactivated_staff_cannot_sign_in_and_see_why(): void
    {
        $member = $this->member('Support', ['email' => 'gone@example.test']);
        Livewire::test(StaffManager::class)->call('deactivate', $member->id)->assertHasNoErrors();
        auth()->logout();

        Volt::test('pages.auth.login')
            ->set('form.email', 'gone@example.test')->set('form.password', 'password')
            ->call('login')->assertHasErrors('form.email')->assertSee('This account has been deactivated.');
        $this->assertGuest();

        // A wrong password still gets the generic message (no account probing).
        Volt::test('pages.auth.login')
            ->set('form.email', 'gone@example.test')->set('form.password', 'wrong')
            ->call('login')->assertDontSee('deactivated');
    }

    public function test_a_signed_in_user_is_signed_out_once_deactivated(): void
    {
        $member = $this->member('Support');
        $member->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($member)->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_reactivate_restores_access_with_the_same_role(): void
    {
        $member = $this->member('Store Manager');
        Livewire::test(StaffManager::class)->call('deactivate', $member->id);
        Livewire::test(StaffManager::class)->call('reactivate', $member->id)->assertHasNoErrors();

        $member->refresh();
        $this->assertFalse($member->isDeactivated());
        $this->assertTrue($member->hasRole('Store Manager'));
        $this->actingAs($member)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_customers_are_not_listed_and_cannot_be_managed_here(): void
    {
        $customer = User::factory()->create(['name' => 'Just A Reader']);

        Livewire::test(StaffManager::class)->assertDontSee('Just A Reader');
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test(StaffManager::class)->call('deactivate', $customer->id);
    }

    public function test_staff_actions_are_refused_without_staff_manage(): void
    {
        $member = $this->member('Support');
        $this->actingAs($this->member('Content Manager'));

        Livewire::test(StaffManager::class)->call('deactivate', $member->id)->assertForbidden();
        $this->assertFalse($member->fresh()->isDeactivated());
    }
}
