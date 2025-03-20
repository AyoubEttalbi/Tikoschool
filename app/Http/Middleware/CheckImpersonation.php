<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;

class CheckImpersonation
{
    public function handle(Request $request, Closure $next)
    {
        // Check if admin_user_id exists in the session
        if (!Session::has('admin_user_id')) {
            abort(403, 'Unauthorized action.');
        }

        return $next($request);
    }
}