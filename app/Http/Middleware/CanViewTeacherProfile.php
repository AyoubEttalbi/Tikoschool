<?php

namespace App\Http\Middleware;

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
        $teacherId = $request->route('teacher');
        $teacher = Teacher::find($teacherId);

        // Allow if admin
        if ($user && $user->role === 'admin') {
            return $next($request);
        }

        // Allow if the user is a teacher and their email matches the teacher's email
        if ($user && $user->role === 'teacher' && $teacher && $user->email === $teacher->email) {
            return $next($request);
        }

        // Otherwise, deny access
        abort(403, 'Unauthorized');
    }
}
