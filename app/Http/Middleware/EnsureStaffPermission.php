<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * staff:<permission>[|<permission>...] — the signed-in user must be staff (admin.access)
 * and hold at least one of the listed permissions. A denial is logged and answered with
 * 403 (Req 1.1, 1.4). Runs after `auth`, so guests never reach it.
 *
 * Registered as Livewire persistent middleware, so component updates are re-checked too.
 */
class EnsureStaffPermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();
        $required = explode('|', $permissions);

        if (! $user || ! $user->can('admin.access') || ! $user->canAny($required)) {
            Log::warning('Admin access denied', [
                'user_id' => $user?->id,
                'route' => $request->route()?->getName(),
                'path' => $request->path(),
                'method' => $request->method(),
                'required' => $required,
                'ip' => $request->ip(),
            ]);

            abort(403);
        }

        return $next($request);
    }
}
