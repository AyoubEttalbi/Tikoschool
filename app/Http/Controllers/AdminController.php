<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

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
        Session::put('admin_user_id', Auth::id());

      
        Auth::login($user);

      
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
        // Get the original admin user ID from the session
        $adminUserId = Session::get('admin_user_id');
    
        if (!$adminUserId) {
            abort(403, 'No admin session found.');
        }
    
        // Find the admin user
        $adminUser = User::find($adminUserId);
    
        if (!$adminUser) {
            abort(404, 'Admin user not found.');
        }
    
        // Log out the current user and log in as the admin
        Auth::login($adminUser);
    
        // Clear the session data
        Session::forget('admin_user_id');
    
        // Redirect to the admin dashboard
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