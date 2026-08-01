<?php

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Teacher;

class CanViewTeacherProfile
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::user();
        $routeName = $request->route()->getName();

        // Real admins only.
        //
        // This previously also allowed anyone whose session merely CONTAINED an
        // `admin_user_id` key. That is backwards: while impersonating, the effective user is
        // deliberately LESS privileged, yet the flag granted access to every teacher's
        // profile. An impersonated session now gets exactly the impersonated user's rights,
        // which is the whole point of "view as" — switch back to browse as an admin.
        if ($user && $user->role === 'admin') {
            return $next($request);
        }

        // The teacher DIRECTORY is open to teachers and assistants. The sidebar has always
        // offered "Enseignants" to assistants (Menu.jsx), but this middleware only ever
        // allowed admin and teacher — so that menu entry led straight to a 403 for every
        // assistant. TeacherController::index scopes the rows to the caller's schools.
        //
        // Individual profiles are deliberately NOT included: they carry wallet balances,
        // invoices and payout history. The list page already hides row links and the actions
        // column from non-admins, so nothing in the UI leads an assistant there.
        if ($routeName === 'teachers.index' && $user && in_array($user->role, ['teacher', 'assistant'], true)) {
            return $next($request);
        }

        // For show routes, check if the teacher matches the user
        if ($routeName === 'teachers.show' && $user && $user->role === 'teacher') {
            // Use the user's email to find the teacher instead of route parameter
            $teacher = Teacher::where('email', $user->email)->first();

            if ($teacher) {
                // `route('teacher')` is the resolved Teacher MODEL, because SubstituteBindings
                // is group middleware and runs before this route middleware.
                //
                // Casting it with (string) does not throw — Eloquent\Model::__toString()
                // returns toJson() — so the old comparison quietly measured a ~300 character
                // JSON blob against "1" and was ALWAYS false. Every teacher was denied their
                // own profile. It went unnoticed because the admin check above used to pass
                // anyone whose session merely held an `admin_user_id`, which is exactly the
                // impersonation case, so the broken branch was never reached.
                $routeTeacher = $request->route('teacher');
                $routeTeacherId = $routeTeacher instanceof Teacher
                    ? $routeTeacher->getKey()
                    : $routeTeacher;

                if ($routeTeacherId !== null
                    && (string) $routeTeacherId === (string) $teacher->getKey()) {
                    return $next($request);
                }
            }
        }

        // Allow access to profile selection route for teachers
        if ($routeName === 'profiles.select' && $user && $user->role === 'teacher') {
            return $next($request);
        }

        // Otherwise, deny access
        abort(403, Impersonation::isActive()
            ? "Vous consultez l'application en tant qu'un autre utilisateur. Revenez à votre compte administrateur pour accéder à cette page."
            : 'Unauthorized');
    }
}
