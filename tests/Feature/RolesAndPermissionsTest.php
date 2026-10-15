<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Staff roles of Req 1.3 and their default permission matrix (config/roles.php),
 * Super Admin's full access, and the .env-driven Super Admin seed (task 2.2).
 */
class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const REQUIRED_ROLES = [
        'Super Admin', 'Content Manager', 'Language Reviewer', 'Layout Reviewer',
        'Narration Reviewer', 'Store Manager', 'Fulfilment Manager', 'Support',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function staff(string $role): User
    {
        return User::factory()->create()->assignRole($role);
    }

    public function test_all_roles_of_req_1_3_exist(): void
    {
        $this->assertEqualsCanonicalizing(self::REQUIRED_ROLES, Role::pluck('name')->all());
    }

    public function test_every_configured_permission_exists_and_roles_match_the_matrix(): void
    {
        $this->assertEqualsCanonicalizing(
            array_keys(config('roles.permissions')),
            Permission::pluck('name')->all()
        );

        foreach (config('roles.roles') as $role => $permissions) {
            $this->assertEqualsCanonicalizing(
                $permissions,
                Role::findByName($role)->permissions->pluck('name')->all(),
                "$role permissions differ from config/roles.php"
            );
        }
    }

    public function test_matrix_only_uses_declared_permissions(): void
    {
        $declared = array_keys(config('roles.permissions'));
        foreach (config('roles.roles') as $role => $permissions) {
            $this->assertSame([], array_diff($permissions, $declared), "$role uses an undeclared permission");
        }
    }

    public function test_seeder_is_idempotent_and_resets_drifted_roles(): void
    {
        Role::findByName('Support')->givePermissionTo('staff.manage');

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(count(self::REQUIRED_ROLES), Role::count());
        $this->assertSame(count(config('roles.permissions')), Permission::count());
        $this->assertFalse(Role::findByName('Support')->hasPermissionTo('staff.manage'));
    }

    public function test_super_admin_passes_every_permission_check(): void
    {
        $admin = $this->staff('Super Admin');

        foreach (array_keys(config('roles.permissions')) as $permission) {
            $this->assertTrue($admin->can($permission), "Super Admin denied $permission");
        }
    }

    public function test_staff_management_and_gate_override_are_super_admin_only(): void
    {
        foreach (array_keys(config('roles.roles')) as $role) {
            $user = $this->staff($role);
            $this->assertFalse($user->can('staff.manage'), "$role can manage staff");
            $this->assertFalse($user->can('gates.override'), "$role can override gates");
        }
    }

    public function test_a_reviewer_gets_only_their_own_area(): void
    {
        $reviewer = $this->staff('Language Reviewer');

        $this->assertTrue($reviewer->can('admin.access'));
        $this->assertTrue($reviewer->can('translations.review'));
        $this->assertFalse($reviewer->can('editions.publish'));
        $this->assertFalse($reviewer->can('translations.manage'));
        $this->assertFalse($reviewer->can('layout.review'));
    }

    public function test_a_customer_has_no_admin_permissions(): void
    {
        $customer = User::factory()->create();

        foreach (array_keys(config('roles.permissions')) as $permission) {
            $this->assertFalse($customer->can($permission), "customer can $permission");
        }
    }

    public function test_super_admin_seeder_skips_without_an_email(): void
    {
        config(['roles.seed_super_admin.email' => null]);

        $this->seed(SuperAdminSeeder::class);

        $this->assertSame(0, User::count());
    }

    public function test_super_admin_seeder_creates_a_verified_super_admin(): void
    {
        config(['roles.seed_super_admin' => ['email' => 'owner@example.test', 'name' => 'Owner', 'password' => null]]);

        $this->seed(SuperAdminSeeder::class);

        $owner = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue($owner->hasRole('Super Admin'));
        $this->assertNotNull($owner->email_verified_at);
        $this->assertNotEmpty($owner->password);
    }

    public function test_super_admin_seeder_promotes_an_existing_user_without_touching_the_password(): void
    {
        $existing = User::factory()->create(['email' => 'owner@example.test', 'password' => 'kept-password']);
        config(['roles.seed_super_admin' => ['email' => 'owner@example.test', 'name' => 'x', 'password' => 'other']]);

        $this->seed(SuperAdminSeeder::class);
        $this->seed(SuperAdminSeeder::class);

        $existing->refresh();
        $this->assertTrue($existing->hasRole('Super Admin'));
        $this->assertTrue(Hash::check('kept-password', $existing->password));
        $this->assertSame(1, User::count());
    }
}
