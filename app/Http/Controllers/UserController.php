<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;

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
        try {
            // Validate the incoming request data
            $validatedData = $request->validate([
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|string|lowercase|email|max:255|unique:users,email,' . $user->id,
                'role' => 'sometimes|in:admin,assistant,teacher',
            ]);

            // Update the user in the database
            $user->update($validatedData);

            // Redirect with success message
            return redirect()->back()->with('success', 'User updated successfully!');
        } catch (ValidationException $e) {
            // Handle validation errors (e.g., missing fields, invalid format)
            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput();
        } catch (QueryException $e) {
            // Handle database-related errors (e.g., duplicate email)
            $errorMessage = 'An error occurred while updating the user. Please try again.';

            // Check if the error is due to a duplicate email
            if ($e->errorInfo[1] === 1062) { // MySQL error code for duplicate entry
                $errorMessage = 'The email address is already in use.';
            }

            return redirect()->back()
                ->withErrors(['email' => $errorMessage])
                ->withInput();
        } catch (\Exception $e) {
            // Handle any other unexpected exceptions
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
}