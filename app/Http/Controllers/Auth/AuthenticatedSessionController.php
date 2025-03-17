<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\Teacher; // Import the Teacher model
use App\Models\Assistant; // Import the Assistant model
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        // Authenticate the user
        $request->authenticate();

        // Regenerate the session
        $request->session()->regenerate();

        // Get the authenticated user
        $user = Auth::user();

        // Redirect based on the user's role
        if ($user->role === 'teacher') {
            // Find the teacher by email
            $teacher = Teacher::where('email', $user->email)->first();

            if ($teacher) {
                return redirect()->route('teachers.show', $teacher->id);
            }
        }

        if ($user->role === 'assistant') {
            // Find the assistant by email
            $assistant = Assistant::where('email', $user->email)->first();

            if ($assistant) {
                return redirect()->route('assistants.show', $assistant->id);
            }
        }

        // Default redirect for other roles (e.g., admin)
        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}