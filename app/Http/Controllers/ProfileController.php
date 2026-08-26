<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\Assistant; // Ensure School model is included
use App\Models\School;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ProfileImageService;
use App\Support\ProfileImageUrl;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __construct(private ProfileImageService $profileImages) {}

    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();

        // The avatar lives on the identity row for this role (users row for admins,
        // staff rows joined by email otherwise) — same resolution as uploadImage.
        [$type, $model] = $this->resolveIdentity($user);

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            'avatarUrl' => $model->profile_image,
            // Staff see their HR card next to the account forms — it used to live
            // only on their show page, which stopped being their home. Salary
            // stays out: that is payroll data, not self-service profile data.
            'staff' => $this->staffInfo($user),
        ]);
    }

    /** The caller's own staff card, or null for admins. Role-resolved by email. */
    private function staffInfo($user): ?array
    {
        if (! $user || $user->role === 'admin') {
            return null;
        }

        if ($user->role === 'teacher') {
            $teacher = \App\Models\Teacher::where('email', $user->email)->first();

            return $teacher ? [
                'first_name' => $teacher->first_name,
                'last_name' => $teacher->last_name,
                'status' => $teacher->status,
                'phone_number' => $teacher->phone_number,
                'address' => $teacher->address,
                'bio' => null, // teachers table carries no bio column
                'schools' => $teacher->schools()->pluck('schools.name')->all(),
                'roleLabel' => 'Enseignant',
            ] : null;
        }

        $assistant = \App\Models\Assistant::where('email', $user->email)->first();

        return $assistant ? [
            'first_name' => $assistant->first_name,
            'last_name' => $assistant->last_name,
            'status' => $assistant->status,
            'phone_number' => $assistant->phone_number,
            'address' => $assistant->address,
            'bio' => $assistant->bio ?? null,
            'schools' => $assistant->schools()->pluck('schools.name')->all(),
            'roleLabel' => 'Assistant',
        ] : null;
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
     * Self-service: upload/replace the logged-in user's own profile image.
     *
     * Identity resolves by role — admins carry their image on the users row,
     * teachers/assistants on their staff rows (joined by email), matching every
     * other image surface in the app. The swap is optimistic like all replace
     * flows; a concurrent change loses cleanly with a French reload message.
     */
    public function uploadImage(Request $request): RedirectResponse
    {
        [$type, $model] = $this->resolveIdentity($request->user());

        $validated = $request->validate([
            'photo' => ['required', 'mimes:jpg,jpeg,png,webp,avif', 'max:5120'],
        ]);

        $newPath = $this->profileImages->store($validated['photo'], $type, 'photo');

        $old = $model->getRawOriginal('profile_image');
        $swapped = $model::whereKey($model->getKey())
            ->when($old === null,
                fn ($q) => $q->whereNull('profile_image'),
                fn ($q) => $q->where('profile_image', $old))
            ->update(['profile_image' => $newPath]);

        if ($swapped === 0) {
            $this->profileImages->discard($newPath);

            return Redirect::route('profile.edit')
                ->withErrors(['photo' => "L'image a été modifiée entre-temps. Rechargez la page et réessayez."]);
        }

        if ($old !== null && ProfileImageUrl::isLogicalPath($old)) {
            $this->profileImages->delete($old);
        }

        return Redirect::route('profile.edit')->with('success', 'Photo de profil mise à jour.');
    }

    /**
     * Self-service: remove the logged-in user's own profile image. Idempotent.
     */
    public function removeImage(Request $request): RedirectResponse
    {
        [$type, $model] = $this->resolveIdentity($request->user());

        $old = $model->getRawOriginal('profile_image');

        if ($old !== null) {
            $cleared = $model::whereKey($model->getKey())
                ->where('profile_image', $old)
                ->update(['profile_image' => null]);

            if ($cleared > 0 && ProfileImageUrl::isLogicalPath($old)) {
                $this->profileImages->delete($old);
            }
        }

        return Redirect::route('profile.edit')->with('success', 'Photo de profil supprimée.');
    }

    /**
     * @return array{0: string, 1: \App\Models\User|\App\Models\Teacher|\App\Models\Assistant}
     */
    private function resolveIdentity(User $user): array
    {
        return match ($user->role) {
            // refresh(): the shared authenticated instance can carry stale original
            // attributes across the same lifecycle, which would break the
            // optimistic swap's where-profile_image guard.
            'teacher' => ['teachers', Teacher::where('email', $user->email)->firstOrFail()],
            'assistant' => ['assistants', Assistant::where('email', $user->email)->firstOrFail()],
            // Admins — and any legacy row without a role — carry the image on the
            // users row itself. The profile page must render for every account.
            default => ['admins', $user->refresh()],
        };
    }

    /**
     * Show profile selection page (for teachers and assistants).
     */
    public function select(Request $request)
    {
        $user = auth()->user();
        $force = $request->query('force');

        if ($user->role === 'teacher') {
            $teacher = Teacher::with('schools')->where('email', $user->email)->first();

            if (! $teacher || $teacher->schools->isEmpty()) {
                abort(403, 'No schools found for this teacher.');
            }

            $isAdminInspection = session()->has('admin_user_id');

            // If force param is set, always show selection page
            if ($force) {
                return Inertia::render('Auth/SelectProfile', [
                    'schools' => $teacher->schools->map(fn ($school) => [
                        'id' => $school->id,
                        'name' => $school->name,
                    ]),
                    'isAdminInspection' => $isAdminInspection,
                ]);
            }
            // Only redirect if school already selected AND not in admin inspection mode
            if (session('school_id') && ! $isAdminInspection) {
                return redirect()->route('teachers.show', $teacher->id);
            }

            return Inertia::render('Auth/SelectProfile', [
                'schools' => $teacher->schools->map(fn ($school) => [
                    'id' => $school->id,
                    'name' => $school->name,
                ]),
                'isAdminInspection' => $isAdminInspection,
            ]);
        } elseif ($user->role === 'assistant') {
            $assistant = Assistant::with('schools')->where('email', $user->email)->first();

            if (! $assistant || $assistant->schools->isEmpty()) {
                abort(403, 'No schools found for this assistant.');
            }

            $isAdminInspection = session()->has('admin_user_id');

            // If force param is set, always show selection page
            if ($force) {
                return Inertia::render('Auth/SelectProfile', [
                    'schools' => $assistant->schools->map(fn ($school) => [
                        'id' => $school->id,
                        'name' => $school->name,
                    ]),
                    'isAdminInspection' => $isAdminInspection,
                ]);
            }
            // Only redirect if school already selected AND not in admin inspection mode
            // The cockpit is an assistant's home — with a school chosen, every path
            // lands there, whether they logged in themselves or an admin is viewing as.
            if (session('school_id')) {
                return redirect()->route('dashboard');
            }

            return Inertia::render('Auth/SelectProfile', [
                'schools' => $assistant->schools->map(fn ($school) => [
                    'id' => $school->id,
                    'name' => $school->name,
                ]),
                'isAdminInspection' => $isAdminInspection,
            ]);
        } else {
            return redirect()->route('dashboard');
        }
    }

    /**
     * Store the selected school for the teacher or assistant.
     */
    public function store(Request $request)
    {
        $request->validate([
            'school_id' => 'required|exists:schools,id',
        ]);

        $user = auth()->user();

        if ($user->role === 'teacher') {
            $teacher = Teacher::where('email', $user->email)->firstOrFail();

            // Verify the teacher has access to this school
            if (! $teacher->schools()->where('schools.id', $request->school_id)->exists()) {
                abort(403, "Cet enseignant n'a pas accès à l'école sélectionnée.");
            }

            // Store in session instead of database
            $school = School::find($request->school_id);
            session([
                'school_id' => $school->id,
                'school_name' => $school->name,
            ]);

            // Inspection or not: the teacher lands on their cockpit like any
            // other staff login. The HR/earnings page stays one click away via
            // the profile chip, and view-as grants nothing beyond it.
            return redirect()->route('dashboard');
        } elseif ($user->role === 'assistant') {
            $assistant = Assistant::where('email', $user->email)->firstOrFail();

            // Verify the assistant has access to this school
            if (! $assistant->schools()->where('schools.id', $request->school_id)->exists()) {
                abort(403, 'This assistant does not have access to the selected school.');
            }

            // Store in session instead of database
            $school = School::find($request->school_id);
            session([
                'school_id' => $school->id,
                'school_name' => $school->name,
            ]);

            // Admin inspection follows the real login path: an assistant's home is
            // the /dashboard cockpit, not their own HR file. Landing on
            // assistants.show here was a leftover of when RoleRedirect pinned
            // assistants to that page.
            if (session()->has('admin_user_id')) {
                return redirect()->route('dashboard')->with('success', 'School selected successfully.');
            }

            // Otherwise go to the assistant's actual home: /dashboard now renders
            // the operations cockpit; landing on the HR profile page here was a
            // leftover of when RoleRedirect pinned them to assistants.show.
            return redirect()->route('dashboard');
        } else {
            abort(403, 'Unauthorized role.');
        }
    }
}
