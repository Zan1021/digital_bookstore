<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates the staff roles of Req 1.3 and syncs their default permissions from
 * config/roles.php. Safe to run repeatedly: it creates what is missing and resets
 * each listed role to the configured matrix.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(config('roles.permissions')) as $name) {
            Permission::findOrCreate($name, 'web');
        }

        Role::findOrCreate(config('roles.super_admin'), 'web');

        foreach (config('roles.roles') as $role => $permissions) {
            Role::findOrCreate($role, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
