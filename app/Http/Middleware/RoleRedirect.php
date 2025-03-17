<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Teacher;
use App\Models\Assistant;

class RoleRedirect
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if ($user) {
            // Skip redirection for profile routes
            if ($request->routeIs('teachers.show', 'assistants.show')) {
                return $next($request);
            }

            // Redirect teachers and assistants away from the dashboard and other restricted routes
            if ($user->role === 'teacher') {
                $teacher = Teacher::where('email', $user->email)->first();
                if ($teacher) {
                    return redirect()->route('teachers.show', $teacher->id);
                }
            }

            if ($user->role === 'assistant') {
                $assistant = Assistant::where('email', $user->email)->first();
                if ($assistant) {
                    return redirect()->route('assistants.show', $assistant->id);
                }   
            }
        }

        return $next($request);
    }
}