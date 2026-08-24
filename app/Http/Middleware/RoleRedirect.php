<?php

namespace App\Http\Middleware;

use App\Models\Assistant;
use App\Models\Teacher;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class RoleRedirect
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();

        if ($user) {
            // Skip redirection for profile routes, update routes, list pages, and other specific routes
            if ($request->routeIs('teachers.show', 'assistants.show', 'teachers.index', 'assistants.index', 'profile.*', 'results.*', 'classes.*', 'profiles.select', 'profiles.store', 'teachers.update', 'assistants.update')) {
                return $next($request);
            }

            // Skip redirection for PUT/POST requests to teacher or assistant routes (updates/creates)
            if (($request->isMethod('put') || $request->isMethod('post')) &&
                ($request->is('teachers/*') || $request->is('assistants/*'))) {
                return $next($request);
            }

            // Skip redirection for GET requests to specific teacher or assistant profiles (redundant due to routeIs check but safe)
            // if ($request->isMethod('get') &&
            //     ($request->is('teachers/*') || $request->is('assistants/*'))) {
            //     // Check if the route matches the show pattern e.g., teachers/{teacher}
            //     $route = $request->route();
            //     if ($route && preg_match('/^(teachers|assistants)\.show$/', $route->getName())) {
            //          return $next($request);
            //     }
            // }

            // Redirect teachers and assistants away from the dashboard and other restricted routes
            if ($user->role === 'teacher') {
                // **** ADDED CHECK: If already going to the show page, let it through ****
                if ($request->routeIs('teachers.show')) {
                    return $next($request);
                }

                $teacher = Teacher::where('email', $user->email)->first();

                // Debug: Log teacher information
                Log::debug('Teacher lookup result:', [
                    'teacher_found' => $teacher ? 'yes' : 'no',
                    'teacher_id' => $teacher ? $teacher->id : null,
                    'email_used' => $user->email,
                ]);

                if ($teacher) {
                    // Check if the user has already selected a school in this session
                    if (session()->has('school_id')) {
                        // Avoid redirect loop if already on the correct show page
                        if (! $request->routeIs('teachers.show', ['teacher' => $teacher->id])) {
                            return redirect()->route('teachers.show', $teacher->id);
                        }
                    } else {
                        // If no school is selected yet, don't redirect if already on the select-profile route
                        if (! $request->routeIs('profiles.select')) {
                            return redirect()->route('profiles.select');
                        }
                    }
                } else {
                    // If no teacher found, log the error
                    Log::error('Teacher not found for user with email: '.$user->email);
                    // Still return to the next middleware to avoid breaking the application
                }
            }

            if ($user->role === 'assistant') {
                // **** ADDED CHECK: If already going to the show page, let it through ****
                if ($request->routeIs('assistants.show')) {
                    return $next($request);
                }

                $assistant = Assistant::where('email', $user->email)->first();

                // Debug: Log assistant information
                Log::debug('Assistant lookup result:', [
                    'assistant_found' => $assistant ? 'yes' : 'no',
                    'assistant_id' => $assistant ? $assistant->id : null,
                    'email_used' => $user->email,
                ]);

                if ($assistant) {
                    if (! session()->has('school_id')) {
                        // No school selected yet: nothing is scoped without one.
                        if (! $request->routeIs('profiles.select')) {
                            return redirect()->route('profiles.select');
                        }
                    }
                    // With a school selected the assistant goes straight to /dashboard:
                    // their home is now the role-aware cockpit (AssistantDashboardController),
                    // not a bounce to their own HR profile. Pinning every request to
                    // assistants.show was what made the profile page the de-facto dashboard.
                } else {
                    // If no assistant found, log the error
                    Log::error('Assistant not found for user with email: '.$user->email);
                }
            }
        }

        return $next($request);
    }
}
