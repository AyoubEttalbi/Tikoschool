<?php

namespace App\Http\Controllers;

use App\Events\CheckEmailUnique;
use App\Models\Assistant;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ProfileImageService;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    public function __construct(private ProfileImageService $profileImages) {}

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        try {
            $oldEmail = $user->email;
            $oldRole = $user->role;

            // Only validate and update email if it is changed
            $inputEmail = $request->input('email');
            $validateEmail = $inputEmail && $inputEmail !== $user->email;
            $validationRules = [
                'name' => 'sometimes|string|max:255',
                'role' => 'sometimes|in:admin,assistant,teacher',
                'profile_image' => 'nullable|mimes:jpg,jpeg,png,webp,avif|max:5120',
                // Use the app-wide policy rather than a raw min:8 string.
                'password' => ['nullable', 'string', Password::defaults()],
            ];
            if ($validateEmail) {
                $validationRules['email'] = [
                    'required',
                    'string',
                    'lowercase',
                    'email',
                    'max:255',
                    'unique:users,email,'.$user->id,
                ];
            }
            $validatedData = $request->validate($validationRules);
            // If email is not changed, always use the current email
            if (! $validateEmail) {
                $validatedData['email'] = $user->email;
            }

            // NOTE: a debug line here used to unconditionally reset every edited user's
            // password to a hardcoded literal. The real, conditional update is below.

            // If email is being updated, enforce the app-wide staff email rule via
            // its single authority: ValidateEmailUnique refuses ANY live teacher or
            // assistant row — other than this person's own staff row (ignoreId) —
            // holding the new address. The old code checked only the SAME-role
            // table, so an assistant login could silently take over a live
            // teacher's email and poison the email-based identity join.
            if ($validateEmail && $oldRole !== 'admin') {
                $ownStaffId = $oldRole === 'teacher'
                    ? Teacher::where('email', $oldEmail)->value('id')
                    : Assistant::where('email', $oldEmail)->value('id');
                event(new CheckEmailUnique($validatedData['email'], $ownStaffId));
            }

            // Only update password if present
            if (isset($validatedData['password']) && $validatedData['password']) {
                $validatedData['password'] = Hash::make($validatedData['password']);
            } else {
                unset($validatedData['password']);
            }

            $newImagePath = null;
            $oldRawImage = $user->getRawOriginal('profile_image');
            if ($request->hasFile('profile_image')) {
                // users.profile_image is the admin-avatar slot only. Teacher/assistant
                // photos live on their staff tables (managed from their own screens);
                // storing e.g. 'admins/<hex>.webp' on a role=teacher row would 404 forever
                // in ProfileImageController::authorizeOwner and flag as an orphan nightly.
                if ($user->role !== 'admin') {
                    return redirect()->back()
                        ->withErrors(['profile_image' => "La photo de profil n'est gérée ici que pour les comptes administrateur."])
                        ->withInput();
                }

                $newImagePath = $this->profileImages->store($request->file('profile_image'), 'admins');

                // Optimistic concurrency: swap the reference only if it still holds the value
                // this request was rendered with. Two simultaneous replaces cannot both win;
                // the loser discards its freshly stored file instead of leaving an orphan leak.
                $swapped = User::whereKey($user->getKey())
                    ->when($oldRawImage === null,
                        fn ($q) => $q->whereNull('profile_image'),
                        fn ($q) => $q->where('profile_image', $oldRawImage))
                    ->update(['profile_image' => $newImagePath]);

                if ($swapped === 0) {
                    $this->profileImages->discard($newImagePath);

                    return redirect()->back()
                        ->withErrors(['profile_image' => "L'image a été modifiée entre-temps. Rechargez la page et réessayez."])
                        ->withInput();
                }

                // Reference already atomically updated; keep it out of the bulk update below.
                unset($validatedData['profile_image']);
            }

            try {
                $user->update($validatedData);

                // Update the related teacher or assistant record if needed
                if ($user->role === 'teacher') {
                    $teacher = Teacher::where('email', $oldEmail)->first();
                    if ($teacher) {
                        if (isset($validatedData['email'])) {
                            $teacher->email = $validatedData['email'];
                        }
                        if (isset($validatedData['name'])) {
                            $nameParts = explode(' ', $validatedData['name'], 2);
                            $teacher->first_name = $nameParts[0];
                            if (isset($nameParts[1])) {
                                $teacher->last_name = $nameParts[1];
                            }
                        }
                        $teacher->save();
                    }
                } elseif ($user->role === 'assistant') {
                    $assistant = Assistant::where('email', $oldEmail)->first();
                    if ($assistant) {
                        if (isset($validatedData['email'])) {
                            $assistant->email = $validatedData['email'];
                        }
                        if (isset($validatedData['name'])) {
                            $nameParts = explode(' ', $validatedData['name'], 2);
                            $assistant->first_name = $nameParts[0];
                            if (isset($nameParts[1])) {
                                $assistant->last_name = $nameParts[1];
                            }
                        }
                        $assistant->save();
                    }
                }
            } catch (\Throwable $e) {
                if ($newImagePath !== null) {
                    // The reference was already swapped; roll it back so the state matches
                    // the error the user is about to see, and discard the fresh file —
                    // otherwise the new image strands and the old one orphans.
                    User::whereKey($user->getKey())->update(['profile_image' => $oldRawImage]);
                    $this->profileImages->discard($newImagePath);
                }

                throw $e;
            }

            if ($newImagePath !== null && $oldRawImage !== null) {
                $this->profileImages->delete($oldRawImage);
            }

            // French: every other string on this screen is, and this one is rendered.
            return redirect()->back()->with('success', 'Utilisateur mis à jour.');
        } catch (ValidationException $e) {
            Log::error('Validation failed', $e->errors());

            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput();
        } catch (QueryException $e) {
            $errorMessage = 'An error occurred while updating the user. Please try again.';
            if ($e->errorInfo[1] === 1062) {
                $errorMessage = 'The email address is already in use.';
            }

            return redirect()->back()
                ->withErrors(['email' => $errorMessage])
                ->withInput();
        } catch (\Exception $e) {
            return redirect()->back()
                ->withErrors(['general' => 'An unexpected error occurred. Please try again later.'])
                ->withInput();
        }
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(User $user): RedirectResponse
    {
        try {
            // Capture the raw path BEFORE delete(): users hard-delete, so the file goes
            // with the row and there is no restore to bring it back for.
            $rawProfileImage = $user->getRawOriginal('profile_image');

            // Delete the user. forceDelete keeps this account-management surface
            // hard-deleting as always — SoftDeletes exists for STAFF lifecycle
            // (TeacherController/AssistantController destroy), not for admin
            // account removal here.
            $user->forceDelete();

            $this->profileImages->delete($rawProfileImage);

            // Redirect with success message
            return redirect()->back()->with('success', 'Utilisateur supprimé.');
        } catch (\Exception $e) {
            // A half-completed delete (row gone, file orphaned, or vice versa)
            // must not masquerade as a generic hiccup with no trace.
            Log::error('Error deleting user '.$user->id.': '.$e->getMessage());

            return redirect()->back()
                ->withErrors(['general' => 'An unexpected error occurred while deleting the user. Please try again later.']);
        }
    }

    /**
     * Display a listing of the users with search and filter.
     */
    public function index(Request $request)
    {
        $query = User::query();

        // Apply search filter
        if ($request->has('search') && ! empty($request->search)) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('email', 'LIKE', "%{$searchTerm}%");
            });
        }

        // Apply role filter
        if ($request->has('role') && ! empty($request->role) && $request->role !== 'all') {
            $query->where('role', $request->role);
        }

        // Paginate users (10 per page)
        $users = $query->orderBy('created_at', 'desc')->paginate(10)->withQueryString();

        // Get all roles for filter dropdown
        $roles = ['admin', 'assistant', 'teacher', 'student'];

        return \Inertia\Inertia::render('Menu/UserListPage', [
            'users' => $users,
            'filters' => $request->only(['search', 'role']),
            'roles' => $roles,
        ]);
    }
}
