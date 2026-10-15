<?php

namespace App\Services\Staff;

use App\Models\User;
use App\Notifications\StaffInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Invite, re-role, deactivate and reactivate staff (task 2.4, Req 1.5). Callers must hold
 * staff.manage (checked by the admin screen). Rules enforced here, whatever the caller:
 *  - a role must be one of the configured staff roles;
 *  - nobody changes their own role or deactivates themselves;
 *  - the last active Super Admin can never be demoted or deactivated.
 * Every change is written to the log until the audit log exists (task 42).
 */
class StaffManagement
{
    /** @return list<string> */
    public function roles(): array
    {
        return array_values(array_unique(array_merge(
            [config('roles.super_admin')],
            array_keys(config('roles.roles'))
        )));
    }

    /** Invite a new staff member, or give an existing customer account a staff role. */
    public function invite(User $actor, string $name, string $email, string $role): User
    {
        $this->assertRole($role);
        $email = Str::lower(trim($email));

        $user = User::where('email', $email)->first();
        if ($user && $user->roles()->exists()) {
            throw ValidationException::withMessages(['email' => __('This person is already on the staff list.')]);
        }

        if ($user) {
            // Existing customer account: they keep their password and simply gain the role.
            $user->assignRole($role);
            $this->log('staff.promoted', $actor, $user, ['role' => $role]);

            return $user;
        }

        $user = DB::transaction(function () use ($name, $email, $role) {
            $user = new User(['name' => trim($name), 'email' => $email]);
            $user->password = Str::password(40); // unusable until they accept the invite
            $user->email_verified_at = now();     // the invite link proves the address
            $user->invited_at = now();
            $user->save();
            $user->assignRole($role);

            return $user;
        });

        $this->sendInvite($user, $role);
        $this->log('staff.invited', $actor, $user, ['role' => $role]);

        return $user;
    }

    public function resendInvite(User $actor, User $user): void
    {
        if (! $user->isPendingInvite()) {
            throw ValidationException::withMessages(['staff' => __('This person has already accepted their invitation.')]);
        }

        $this->sendInvite($user, $user->getRoleNames()->first() ?? '');
        $this->log('staff.invite_resent', $actor, $user);
    }

    public function changeRole(User $actor, User $user, string $role): void
    {
        $this->assertRole($role);
        $this->assertNotSelf($actor, $user, __('You cannot change your own role.'));

        $old = $user->getRoleNames()->first();
        if ($old === $role) {
            return;
        }
        if ($old === config('roles.super_admin')) {
            $this->assertNotLastSuperAdmin($user);
        }

        $user->syncRoles([$role]);
        $this->log('staff.role_changed', $actor, $user, ['from' => $old, 'to' => $role]);
    }

    public function deactivate(User $actor, User $user): void
    {
        $this->assertNotSelf($actor, $user, __('You cannot deactivate your own account.'));
        if ($user->hasRole(config('roles.super_admin'))) {
            $this->assertNotLastSuperAdmin($user);
        }

        $user->forceFill(['deactivated_at' => now()])->save();
        $this->log('staff.deactivated', $actor, $user);
    }

    public function reactivate(User $actor, User $user): void
    {
        $user->forceFill(['deactivated_at' => null])->save();
        $this->log('staff.reactivated', $actor, $user);
    }

    private function sendInvite(User $user, string $role): void
    {
        $token = Password::broker('invites')->createToken($user);
        $user->notify(new StaffInvitation($token, $role));
    }

    private function assertRole(string $role): void
    {
        if (! in_array($role, $this->roles(), true) || ! Role::where('name', $role)->exists()) {
            throw ValidationException::withMessages(['role' => __('Choose one of the staff roles.')]);
        }
    }

    private function assertNotSelf(User $actor, User $user, string $message): void
    {
        if ($actor->is($user)) {
            throw ValidationException::withMessages(['staff' => $message]);
        }
    }

    private function assertNotLastSuperAdmin(User $user): void
    {
        $others = User::role(config('roles.super_admin'))
            ->whereNull('deactivated_at')
            ->whereKeyNot($user->getKey())
            ->count();

        if ($others === 0) {
            throw ValidationException::withMessages([
                'staff' => __('There must always be at least one active Super Admin.'),
            ]);
        }
    }

    /** @param array<string, mixed> $details */
    private function log(string $event, User $actor, User $user, array $details = []): void
    {
        Log::info('Staff change', [
            'event' => $event,
            'actor_id' => $actor->id,
            'user_id' => $user->id,
            'email' => $user->email,
        ] + $details);
    }
}
