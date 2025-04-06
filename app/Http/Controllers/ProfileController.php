<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\School; // Ensure School model is included
use App\Models\Teacher;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Session;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => session('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    /**
     * Show profile selection page (for teachers).
     */
    public function select()
    {
        $user = auth()->user();

        if ($user->role !== 'teacher') {
            return redirect()->route('dashboard');
        }

        $teacher = Teacher::with('schools')->where('email', $user->email)->first();

        if (!$teacher || $teacher->schools->isEmpty()) {
            abort(403, 'No schools found for this teacher.');
        }

        return Inertia::render('Auth/SelectProfile', [
            'schools' => $teacher->schools->map(fn($school) => [
                'id' => $school->id,
                'name' => $school->name,
            ])
        ]);
    }

    /**
     * Handle profile selection and store in session.
     */
    public function store(Request $request)
    {
        $request->validate([
            'school_id' => ['required', 'exists:schools,id'],
        ]);

        // Retrieve the selected school based on school_id
        $school = School::findOrFail($request->school_id);
        $teacher_id=Teacher::where('email', auth()->user()->email)->first()->id;
        // Store the school ID and name in session
        session([
            'school_id' => $school->id,
            'school_name' => $school->name,
        ]);

        // Redirect to dashboard or desired page with success message
        return redirect()
            ->route('teachers.show', $teacher_id) // Or wherever your main page is
            ->with('message', 'School profile selected successfully!');
    }
}