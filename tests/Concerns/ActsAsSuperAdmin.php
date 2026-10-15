<?php

namespace Tests\Concerns;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;

/**
 * For tests that drive admin Livewire components directly: every admin action needs a
 * staff permission (#[Can]), so these tests act as a signed-in Super Admin. Tests about
 * who may do what use AccessControlTest / AdminActionPermissionsTest instead.
 *
 * Runs after RefreshDatabase (Laravel calls setUp<TraitName> after the DB traits).
 */
trait ActsAsSuperAdmin
{
    protected User $superAdmin;

    protected function setUpActsAsSuperAdmin(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->superAdmin = User::factory()->create()->assignRole(config('roles.super_admin'));
        $this->actingAs($this->superAdmin);
    }
}
