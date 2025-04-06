<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Teacher;
use App\Models\Assistant;
use Illuminate\Support\Facades\Log;

class RoleRedirect
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if ($user) {
            // Skip redirection for profile routes and other specific routes
            if ($request->routeIs('teachers.show', 'assistants.show', 'profile.*', 'results.*', 'classes.*')) {
                return $next($request);
            }

            // Debug: Log user information
            Log::info('RoleRedirect middleware: User found', [
                'id' => $user->id,
                'email' => $user->email,
                'role' => $user->role,
                'requested_route' => $request->route()->getName()
            ]);

            // Redirect teachers and assistants away from the dashboard and other restricted routes
            if ($user->role === 'teacher') {
                $teacher = Teacher::where('email', $user->email)->first();
                
                // Debug: Log teacher information
                Log::info('Teacher lookup result:', [
                    'teacher_found' => $teacher ? 'yes' : 'no',
                    'teacher_id' => $teacher ? $teacher->id : null,
                    'email_used' => $user->email
                ]);
                
                if ($teacher) {
                    return redirect()->route('teachers.show', $teacher->id);
                } else {
                    // If no teacher found, log the error
                    Log::error('Teacher not found for user with email: ' . $user->email);
                    // Still return to the next middleware to avoid breaking the application
                }
            }

            if ($user->role === 'assistant') {
                $assistant = Assistant::where('email', $user->email)->first();
                if ($assistant) {
                    return redirect()->route('assistants.show', $assistant->id);
                } else {
                    // If no assistant found, log the error
                    Log::error('Assistant not found for user with email: ' . $user->email);
                }
            }
        }

        return $next($request);
    }
}