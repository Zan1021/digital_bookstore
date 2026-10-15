<?php

namespace App\Http\Middleware;

use App\Auth\StaffAuthorization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * staff:<permission>[|<permission>...] — the signed-in user must be staff (admin.access)
 * and hold at least one of the listed permissions. A denial is logged and answered with
 * 403 (Req 1.1, 1.4). Runs after `auth`, so guests never reach it.
 *
 * Registered as Livewire persistent middleware, so component updates are re-checked too.
 * Individual actions inside admin pages are checked by #[Can] (App\Livewire\Attributes).
 */
class EnsureStaffPermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        StaffAuthorization::ensure($permissions, [
            'route' => $request->route()?->getName(),
            'path' => $request->path(),
            'method' => $request->method(),
        ]);

        return $next($request);
    }
}
