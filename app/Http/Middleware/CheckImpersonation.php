<?php

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;

class CheckImpersonation
{
    public function handle(Request $request, Closure $next)
    {
        // Resolve the stored admin id and verify the role — do NOT trust the mere presence
        // of the session key. This previously also dumped session()->all() (including the
        // CSRF token and auth identifiers) to the log on every single request.
        if (! Impersonation::hasVerifiedAdmin()) {
            abort(403, 'Unauthorized action.');
        }

        return $next($request);
    }
}