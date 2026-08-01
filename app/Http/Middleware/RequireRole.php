<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Restrict a route to one or more roles.
 *
 *   Route::get(...)->middleware(RequireRole::class . ':admin');
 *   Route::get(...)->middleware(RequireRole::class . ':admin,assistant');
 *
 * Unlike AdminMiddleware (which redirects to /dashboard, so a denied XHR looks like a
 * success to the frontend) this aborts with 403. Inertia surfaces that as an error.
 */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = Auth::user();

        if (! $user) {
            abort(401);
        }

        if (! in_array($user->role, $roles, true)) {
            abort(403, 'Vous n\'avez pas les droits nécessaires pour accéder à cette page.');
        }

        return $next($request);
    }
}
