<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates (or promotes) the first Super Admin from config('roles.seed_super_admin'),
 * which reads .env, so no credentials live in code:
 *   SUPER_ADMIN_EMAIL=...      required; the seeder skips without it
 *   SUPER_ADMIN_NAME=...       optional
 *   SUPER_ADMIN_PASSWORD=...   optional; without it a random password is set and the
 *                              owner uses "Forgot your password?" to choose one
 * An existing user with that email keeps their password and is given the role.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $seed = config('roles.seed_super_admin');

        if (empty($seed['email'])) {
            $this->command?->warn('SUPER_ADMIN_EMAIL is not set; no Super Admin seeded.');

            return;
        }

        $user = User::firstOrNew(['email' => $seed['email']]);

        if (! $user->exists) {
            $user->name = $seed['name'] ?: 'Super Admin';
            $user->password = $seed['password'] ?: Str::password(32);
            $user->email_verified_at = now();
            $user->save();
            $this->command?->info("Super Admin created: {$seed['email']}"
                .($seed['password'] ? '' : ' (set a password via "Forgot your password?")'));
        }

        $user->assignRole(config('roles.super_admin'));
    }
}
