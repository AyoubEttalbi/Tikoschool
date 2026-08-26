<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class AdminController extends Controller
{
    /**
     * Allow the admin to view the application as another user.
     *
     * @param  User  $user  The user to view as
     * @return \Illuminate\Http\RedirectResponse
     */
    public function viewAs(Request $request, User $user)
    {
        // Store values in temp vars BEFORE login
        $adminUserId = Auth::id();
        $originalRole = $user->role;
        $originalSchoolId = session('school_id');
        $originalSchoolName = session('school_name');

        // Refuse to impersonate another admin — that is a lateral privilege move with no
        // legitimate support use, and it makes the audit trail ambiguous.
        if ($user->role === 'admin') {
            return redirect()->back()->with('error', 'Impossible de consulter en tant qu\'un autre administrateur.');
        }

        activity()
            ->causedBy(Auth::user())
            ->performedOn($user)
            ->withProperties(['impersonated_role' => $user->role])
            ->log('impersonation.start');

        Auth::login($user);

        // Auth::login() does NOT rotate the session id on its own. Rotate it explicitly so
        // a session fixed before the privilege change cannot be replayed after it.
        $request->session()->regenerate();

        // Restore session values AFTER login
        Session::put('admin_user_id', $adminUserId);
        Session::put('original_role', $originalRole);
        if ($originalSchoolId) {
            Session::put('original_school_id', $originalSchoolId);
            Session::put('original_school_name', $originalSchoolName);
        }
        // Clear current school session for impersonated user
        Session::forget(['school_id', 'school_name']);

        // If inspecting a teacher, always check for schools and redirect to selection page
        if ($user->role === 'teacher') {
            $teacher = Teacher::with('schools')->where('email', $user->email)->first();
            if ($teacher && ! $teacher->schools->isEmpty()) {
                return redirect()->route('profiles.select');
            }
        }
        // If inspecting an assistant, check for schools and redirect to selection page
        if ($user->role === 'assistant') {
            $assistant = Assistant::with('schools')->where('email', $user->email)->first();
            if ($assistant && ! $assistant->schools->isEmpty()) {
                return redirect()->route('profiles.select');
            }
        }

        return redirect()->route('dashboard')->with('success', 'Vous consultez maintenant le compte de '.$user->name);
    }

    /**
     * Allow the admin to switch back to their own account.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function switchBack(Request $request)
    {
        // Resolve AND verify. This previously did User::find($id) then Auth::login() with no
        // role check, so any path that put an arbitrary id in the session was a full
        // account-takeover primitive.
        $adminUser = Impersonation::impersonator();

        if (! $adminUser) {
            Impersonation::forget();
            abort(403, 'No valid admin session found.');
        }

        $impersonatedId = Auth::id();

        Auth::login($adminUser);
        // Rotate the session id across the privilege change (Auth::login does not).
        $request->session()->regenerate();

        if (Session::has('original_school_id')) {
            Session::put('school_id', Session::pull('original_school_id'));
            Session::put('school_name', Session::pull('original_school_name'));
        } else {
            Session::forget(['school_id', 'school_name']);
        }

        Impersonation::forget();

        activity()
            ->causedBy($adminUser)
            ->withProperties(['impersonated_user_id' => $impersonatedId])
            ->log('impersonation.stop');

        return redirect()->route('dashboard')->with('success', 'Switched back.');
    }

    /**
     * Check if the current user is an admin.
     *
     * @return bool
     */
    protected function isAdmin()
    {
        return Auth::user()->role === 'admin';
    }
}
