<?php

namespace App\Support;

use App\Models\Assistant;
use App\Models\Classes;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Answers "which records may this user see?".
 *
 * The app has no object-level authorization: controllers take an id from the route and
 * findOrFail() it, so any authenticated staff member can read any student, grade or school
 * by guessing an id. This class centralises the scoping rules so each call site is one line.
 *
 * Rules:
 *  - admin     -> everything
 *  - assistant -> the schools they are assigned to (assistant_school pivot)
 *  - teacher   -> the schools they are assigned to (school_teacher pivot), and additionally
 *                 only students in classes they actually teach (classes_teacher pivot)
 *
 * NOTE: identity is joined on EMAIL rather than a foreign key (see User::teacher()).
 * That is a known design weakness; ProfileUpdateRequest now prevents a user from
 * re-pointing their email at somebody else's record.
 */
class SchoolScope
{
    /** Ids of the schools this user may access. `null` means "unrestricted" (admin). */
    public static function schoolIdsFor(?User $user = null): ?array
    {
        $user ??= Auth::user();

        if (! $user) {
            return [];
        }

        if ($user->role === 'admin') {
            return null; // unrestricted
        }

        if ($user->role === 'teacher') {
            $teacher = Teacher::where('email', $user->email)->first();

            return $teacher ? $teacher->schools()->pluck('schools.id')->all() : [];
        }

        if ($user->role === 'assistant') {
            $assistant = Assistant::where('email', $user->email)->first();

            return $assistant ? $assistant->schools()->pluck('schools.id')->all() : [];
        }

        return [];
    }

    /** Ids of the classes this user teaches. `null` means "unrestricted". */
    public static function classIdsFor(?User $user = null): ?array
    {
        $user ??= Auth::user();

        if (! $user) {
            return [];
        }

        if ($user->role === 'admin' || $user->role === 'assistant') {
            return null; // assistants are scoped by school, not by class
        }

        if ($user->role === 'teacher') {
            $teacher = Teacher::where('email', $user->email)->first();

            return $teacher ? $teacher->classes()->pluck('classes.id')->all() : [];
        }

        return [];
    }

    /** May this user read/write this student? */
    public static function allowsStudent(Student $student, ?User $user = null): bool
    {
        $user ??= Auth::user();

        if (! $user) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        $schoolIds = static::schoolIdsFor($user);
        if ($schoolIds !== null && ! in_array((int) $student->schoolId, array_map('intval', $schoolIds), true)) {
            return false;
        }

        // Teachers are further restricted to students in classes they teach.
        if ($user->role === 'teacher') {
            $classIds = static::classIdsFor($user);

            return $classIds !== null
                && in_array((int) $student->classId, array_map('intval', $classIds), true);
        }

        return true;
    }

    /** May this user read/write this class? */
    public static function allowsClass(Classes $class, ?User $user = null): bool
    {
        $user ??= Auth::user();

        if (! $user) {
            return false;
        }

        if ($user->role === 'admin') {
            return true;
        }

        $classIds = static::classIdsFor($user);
        if ($classIds !== null && ! in_array((int) $class->id, array_map('intval', $classIds), true)) {
            return false;
        }

        $schoolIds = static::schoolIdsFor($user);

        return $schoolIds === null
            || $class->school_id === null
            || in_array((int) $class->school_id, array_map('intval', $schoolIds), true);
    }

    /** May this user read this school? */
    public static function allowsSchool(int $schoolId, ?User $user = null): bool
    {
        $schoolIds = static::schoolIdsFor($user);

        return $schoolIds === null
            || in_array((int) $schoolId, array_map('intval', $schoolIds), true);
    }

    /** Abort with 403 unless the student is in scope. */
    public static function authorizeStudent(Student $student, ?User $user = null): void
    {
        if (! static::allowsStudent($student, $user)) {
            abort(403, "Vous n'avez pas accès à cet élève.");
        }
    }

    /** Abort with 403 unless the class is in scope. */
    public static function authorizeClass(Classes $class, ?User $user = null): void
    {
        if (! static::allowsClass($class, $user)) {
            abort(403, "Vous n'avez pas accès à cette classe.");
        }
    }

    /** Abort with 403 unless the school is in scope. */
    public static function authorizeSchool(int $schoolId, ?User $user = null): void
    {
        if (! static::allowsSchool($schoolId, $user)) {
            abort(403, "Vous n'avez pas accès à cet établissement.");
        }
    }
}
