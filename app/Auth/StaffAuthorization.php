<?php

namespace App\Auth;

use Illuminate\Support\Facades\Log;

/**
 * The one place that decides "may this signed-in staff user do X" for admin pages and
 * admin actions (Req 1.1, 1.4). A user passes when they hold admin.access and at least
 * one of the listed permissions ("a|b"). Every denial is logged, then answered with 403.
 */
class StaffAuthorization
{
    public static function allows(string $permissions): bool
    {
        $user = auth()->user();

        return $user !== null
            && $user->can('admin.access')
            && $user->canAny(explode('|', $permissions));
    }

    /** @param array<string, mixed> $context what was attempted, for the log */
    public static function ensure(string $permissions, array $context = []): void
    {
        if (self::allows($permissions)) {
            return;
        }

        self::deny($context + ['required' => explode('|', $permissions)]);
    }

    /**
     * Log and refuse, whoever the user is (Super Admin included). Used for admin actions
     * that declare no permission: unknown means not allowed (fail closed).
     *
     * @param array<string, mixed> $context
     */
    public static function deny(array $context = []): never
    {
        Log::warning('Admin access denied', $context + [
            'user_id' => auth()->id(),
            'ip' => request()->ip(),
        ]);

        abort(403);
    }
}
