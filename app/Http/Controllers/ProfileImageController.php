<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\ProfileImageService;
use App\Support\ProfileImageUrl;
use App\Support\SchoolScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves profile images through an authenticated route — they are NEVER public
 * static files (visibility model confirmed with the product owner: private).
 *
 * The storage root sits outside storage/app/public, so the nginx /storage/ alias
 * cannot reach it; this controller is the only door, and it re-applies exactly
 * the record-level visibility the app already grants on the underlying records.
 *
 * Filenames are constrained by the route regex to [a-f0-9]{40}.webp under a
 * known directory, so there is no path-traversal surface here.
 */
class ProfileImageController extends Controller
{
    public function __construct(private ProfileImageService $profileImages) {}

    public function show(Request $request, string $path): Response
    {
        $type = strtok($path, '/');

        if (! in_array($type, ProfileImageUrl::TYPES, true)) {
            throw new NotFoundHttpException;
        }

        $disk = Storage::disk('profile-images');
        if (! $disk->exists($path)) {
            throw new NotFoundHttpException;
        }

        // Find the owning record. An unreferenced file (orphan) 404s like any
        // other missing image instead of leaking its bytes.
        abort_unless($this->authorizeOwner($request->user(), $type, $path), 404);

        try {
            return $disk->response($path, null, [
                // Filenames are unique per upload and never mutated in place — a
                // replace creates a NEW file — so content for a given path is
                // immutable and can be cached hard. `private` keeps shared proxies
                // out of the loop.
                'Cache-Control' => 'private, max-age=31536000, immutable',
            ]);
        } catch (\Throwable $e) {
            // A concurrent replace/delete can remove the file between exists()
            // and response() ('throw' => true turns that into an exception).
            // The client should see the same 404 as any other vanished image.
            throw new NotFoundHttpException(previous: $e);
        }
    }

    private function authorizeOwner($user, string $type, string $path): bool
    {
        if (! $user) {
            return false;
        }

        return match ($type) {
            // Admins appear in every staff contact list already; their avatar is
            // visible to any authenticated user.
            'admins' => User::where('role', 'admin')->where('profile_image', $path)->exists(),

            // Staff images follow the chatContacts rule: visible to admins and to
            // staff sharing at least one school — plus the owner themselves.
            'teachers' => $this->authorizeStaff(
                Teacher::where('profile_image', $path)->first(['id', 'email']),
                $user,
                fn ($teacher) => $teacher->schools()->pluck('schools.id')->all()
            ),

            'assistants' => $this->authorizeStaff(
                Assistant::where('profile_image', $path)->first(['id', 'email']),
                $user,
                fn ($assistant) => $assistant->schools()->pluck('schools.id')->all()
            ),

            // Students reuse the exact scoping used everywhere else in the app.
            'students' => ($student = Student::where('profile_image', $path)->withTrashed()->first(['id', 'schoolId', 'classId'])) !== null
                && SchoolScope::allowsStudent($student, $user),

            default => false,
        };
    }

    /**
     * @param  \App\Models\Teacher|\App\Models\Assistant|null  $owner
     * @param  callable(): array  $schoolIdsOf
     */
    private function authorizeStaff($owner, $user, callable $schoolIdsOf): bool
    {
        if (! $owner) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        if (strcasecmp((string) $user->email, (string) $owner->email) === 0) {
            return true;
        }

        $mine = SchoolScope::schoolIdsFor($user);

        return is_array($mine) && array_intersect(
            array_map('intval', $mine),
            array_map('intval', $schoolIdsOf($owner))
        ) !== [];
    }

    /**
     * Removes an entity's profile image: clears the reference optimistically, then
     * deletes the file. Authorization mirrors serving ("can view" implies "may
     * remove", matching upload rights) EXCEPT students: their photos are managed
     * by admins only — staff can view them through school scope but not erase them.
     * Legacy absolute URLs (res.cloudinary.com) are cleared from the row while the
     * remote asset is left untouched.
     */
    public function destroy(Request $request, string $type, int $id): RedirectResponse
    {
        $model = match ($type) {
            'admins' => User::findOrFail($id),
            'teachers' => Teacher::withTrashed()->findOrFail($id),
            'assistants' => Assistant::withTrashed()->findOrFail($id),
            'students' => Student::withTrashed()->findOrFail($id),
            default => throw new NotFoundHttpException,
        };

        abort_unless($this->authorizeRemoval($request->user(), $type, $model), 403);

        $old = $model->getRawOriginal('profile_image');

        if ($old !== null) {
            // Optimistic clear: only null the column if it still holds what we read.
            // newModelQuery() skips the SoftDeletes global scope — the record may be
            // trashed (fetched via withTrashed above), and whereKey() alone would
            // silently match zero rows while still flashing success.
            $cleared = $model->newModelQuery()
                ->whereKey($model->getKey())
                ->where('profile_image', $old)
                ->update(['profile_image' => null]);

            if ($cleared > 0 && ProfileImageUrl::isLogicalPath($old)) {
                $this->profileImages->delete($old);
            }
        }

        return back()->with('success', 'La photo de profil a été supprimée.');
    }

    private function authorizeRemoval($user, string $type, $model): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        // Staff may remove only their own image (identity joins by email).
        return in_array($type, ['teachers', 'assistants'], true)
            && strcasecmp((string) $user->email, (string) $model->email) === 0;
    }
}
