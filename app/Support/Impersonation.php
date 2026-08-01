<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Session;

/**
 * Single source of truth for admin impersonation ("view as").
 *
 * The session key `admin_user_id` records who started the impersonation. Several places
 * used to treat the mere PRESENCE of that key as proof of admin rights, which is backwards:
 * while impersonating, the *current* user is deliberately LESS privileged. Always resolve
 * the stored id and verify the role.
 */
class Impersonation
{
    public const SESSION_KEYS = [
        'admin_user_id',
        'original_role',
        'original_school_id',
        'original_school_name',
    ];

    /** Is an impersonation session currently in progress? */
    public static function isActive(): bool
    {
        return Session::has('admin_user_id');
    }

    /**
     * The real admin behind the current session, or null.
     *
     * Returns null if no impersonation is active, the stored user no longer exists,
     * or that user is not (or is no longer) an admin.
     */
    public static function impersonator(): ?User
    {
        $id = Session::get('admin_user_id');

        if (! $id) {
            return null;
        }

        $user = User::find($id);

        return ($user && $user->role === 'admin') ? $user : null;
    }

    /** True only when a verified admin is impersonating someone. */
    public static function hasVerifiedAdmin(): bool
    {
        return static::impersonator() !== null;
    }

    /** Clear every impersonation key. Safe to call unconditionally. */
    public static function forget(): void
    {
        Session::forget(static::SESSION_KEYS);
    }
}
