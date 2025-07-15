<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Update the specified user in storage.
     *
     * @param Request $request
     * @param User $user
     * @return RedirectResponse
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        \Log::info('User update request', $request->all());
        try {
            $oldEmail = $user->email;
            $oldRole = $user->role;

            // Only validate and update email if it is changed
            $inputEmail = $request->input('email');
            $validateEmail = $inputEmail && $inputEmail !== $user->email;
            $validationRules = [
                'name' => 'sometimes|string|max:255',
                'role' => 'sometimes|in:admin,assistant,teacher',
                'password' => 'nullable|string|min:8',
            ];
            if ($validateEmail) {
                $validationRules['email'] = [
                    'required',
                    'string',
                    'lowercase',
                    'email',
                    'max:255',
                    'unique:users,email,' . $user->id,
                ];
            }
            \Log::info('Validating for user', ['id' => $user->id, 'email' => $user->email, 'inputEmail' => $inputEmail, 'validateEmail' => $validateEmail]);
            $validatedData = $request->validate($validationRules);
            // If email is not changed, always use the current email
            if (!$validateEmail) {
                $validatedData['email'] = $user->email;
            }

            \Log::info('Validated data', $validatedData);
            // Minimal manual password update for debug
            \Log::info('User primary key', ['id' => $user->id, 'email' => $user->email]);
            $user->password = Hash::make('testpassword');
            \Log::info('Is dirty?', $user->isDirty() ? ['dirty' => true] : ['dirty' => false]);
            $user->save();
            \Log::info('Manual password update', $user->fresh()->toArray());

            // If email is being updated, check uniqueness in related table
            if (isset($validatedData['email']) && $validatedData['email'] !== $oldEmail) {
                if ($user->role === 'teacher') {
                    $teacherWithEmail = \App\Models\Teacher::where('email', $validatedData['email'])->first();
                    if ($teacherWithEmail) {
                        return redirect()->back()
                            ->withErrors(['email' => 'Cette adresse e-mail est déjà utilisée par un autre enseignant.'])
                            ->withInput();
                    }
                } elseif ($user->role === 'assistant') {
                    $assistantWithEmail = \App\Models\Assistant::where('email', $validatedData['email'])->first();
                    if ($assistantWithEmail) {
                        return redirect()->back()
                            ->withErrors(['email' => 'Cette adresse e-mail est déjà utilisée par un autre assistant.'])
                            ->withInput();
                    }
                }
            }

            \Log::info('User before update', $user->toArray());
            // Only update password if present
            if (isset($validatedData['password']) && $validatedData['password']) {
                $validatedData['password'] = Hash::make($validatedData['password']);
            } else {
                unset($validatedData['password']);
            }
            $user->update($validatedData);
            \Log::info('User after update', $user->fresh()->toArray());

            // Update the related teacher or assistant record if needed
            if ($user->role === 'teacher') {
                $teacher = \App\Models\Teacher::where('email', $oldEmail)->first();
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
                $assistant = \App\Models\Assistant::where('email', $oldEmail)->first();
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

            return redirect()->back()->with('success', 'User updated successfully!');
        } catch (ValidationException $e) {
            \Log::error('Validation failed', $e->errors());
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
     *
     * @param User $user
     * @return RedirectResponse
     */
    public function destroy(User $user): RedirectResponse
    {
        try {
            // Delete the user
            $user->delete();

            // Redirect with success message
            return redirect()->back()->with('success', 'User deleted successfully!');
        } catch (\Exception $e) {
            // Handle any unexpected exceptions
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
        if ($request->has('search') && !empty($request->search)) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('email', 'LIKE', "%{$searchTerm}%");
            });
        }

        // Apply role filter
        if ($request->has('role') && !empty($request->role) && $request->role !== 'all') {
            $query->where('role', $request->role);
        }

        // Get users as simple array, newest first
        $users = $query->orderBy('created_at', 'desc')->get();

        // Get all roles for filter dropdown
        $roles = ['admin', 'assistant', 'teacher', 'student'];

        return \Inertia\Inertia::render('Menu/UserListPage', [
            'users' => $users,
            'filters' => $request->only(['search', 'role']),
            'roles' => $roles,
        ]);
    }
}