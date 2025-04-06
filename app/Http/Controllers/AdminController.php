<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use App\Models\Teacher;
class AdminController extends Controller
{
    /**
     * Allow the admin to view the application as another user.
     *
     * @param Request $request
     * @param User $user The user to view as
     * @return \Illuminate\Http\RedirectResponse
     */
    public function viewAs(Request $request, User $user)
    {
        // Store admin info
        Session::put('admin_user_id', Auth::id());
        Session::put('original_role', $user->role);
        
        // Store original school session if exists
        if (session()->has('school_id')) {
            Session::put('original_school_id', session('school_id'));
            Session::put('original_school_name', session('school_name'));
        }
        
        // Clear current school session
        Session::forget(['school_id', 'school_name']);

        Auth::login($user);

        // If inspecting a teacher, always check for schools and redirect to selection page
        if ($user->role === 'teacher') {
            $teacher = Teacher::with('schools')->where('email', $user->email)->first();
            
            if ($teacher && !$teacher->schools->isEmpty()) {
                // Always redirect to school selection when admin is inspecting a teacher
                return redirect()->route('profiles.select');
            }
        }
        
        // If inspecting an assistant, check for schools and redirect to selection page
        if ($user->role === 'assistant') {
            $assistant = \App\Models\Assistant::with('schools')->where('email', $user->email)->first();
            
            if ($assistant && !$assistant->schools->isEmpty()) {
                // Always redirect to school selection when admin is inspecting an assistant
                return redirect()->route('profiles.select');
            }
        }

        return redirect()->route('dashboard')->with('success', 'You are now viewing as ' . $user->name);
    }
    /**
     * Allow the admin to switch back to their own account.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function switchBack(Request $request)
    {
        $adminUserId = Session::get('admin_user_id');
        $originalRole = Session::get('original_role');
        $originalSchoolId = Session::get('original_school_id');
        $originalSchoolName = Session::get('original_school_name');
    
        if (!$adminUserId) {
            abort(403, 'No admin session found.');
        }
    
        $adminUser = User::find($adminUserId);
    
        if (!$adminUser) {
            abort(404, 'Admin user not found.');
        }
    
        // Restore the inspected user's original role
        $inspectedUser = Auth::user();
        if ($originalRole) {
            $inspectedUser->role = $originalRole;
            $inspectedUser->save();
        }
    
        // Log back in as admin
        Auth::login($adminUser);
    
        // Restore original school session if it existed
        if ($originalSchoolId) {
            session([
                'school_id' => $originalSchoolId,
                'school_name' => $originalSchoolName,
            ]);
        } else {
            Session::forget(['school_id', 'school_name']);
        }
    
        // Clear all admin inspection session data
        Session::forget(['admin_user_id', 'original_role', 'original_school_id', 'original_school_name']);
    
        return redirect()->route('dashboard')->with('success', 'You have switched back to your admin account.');
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