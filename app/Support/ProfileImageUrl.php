<?php

namespace App\Support;

/**
 * Resolves a raw profile_image column value into a URL.
 *
 * Two kinds of value live in the database:
 *  - legacy absolute URLs (the old Cloudinary CDN links) — returned untouched;
 *  - logical relative paths such as "students/<40hex>.webp" — resolved to the
 *    authenticated serving route (ProfileImageController).
 *
 * Models expose this through a profileImage accessor so every existing frontend
 * consumer keeps receiving a ready-to-render <img src>; internal code that needs
 * the stored value must use getRawOriginal('profile_image').
 */
class ProfileImageUrl
{
    public const TYPES = ['students', 'teachers', 'assistants', 'admins'];

    // <type>/<40 hex chars>.webp — kept in sync with TYPES by the tests.
    public const PATH_PATTERN = '#^(?:students|teachers|assistants|admins)/[a-f0-9]{40}\.webp$#';

    public static function isLogicalPath(?string $value): bool
    {
        return $value !== null && preg_match(self::PATH_PATTERN, $value) === 1;
    }

    public static function resolve(?string $rawValue): ?string
    {
        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        // Legacy Cloudinary URLs (and any other absolute URL) keep rendering as-is:
        // per decision, existing images are not migrated, they age out as records
        // are re-uploaded.
        if (str_starts_with($rawValue, 'http://') || str_starts_with($rawValue, 'https://')) {
            return $rawValue;
        }

        if (! static::isLogicalPath($rawValue)) {
            // Unknown format — never hand it to the route, it would 404 anyway.
            return null;
        }

        return url('profile-images/'.$rawValue);
    }
}
