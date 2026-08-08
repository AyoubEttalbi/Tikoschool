<?php

/*
 * Fallbacks for the details that appear in parent messages.
 *
 * These were hardcoded literals inside AttendanceController's Arabic message block — a
 * different school's name, phone, Instagram URL and opening hours — in a product whose
 * schema is multi-school. CLAUDE.md lists that as a known gap.
 *
 * Which of these wins over the student's School row is decided per value, not globally:
 * the NAME and the Instagram account are brand-level and always come from here, while the
 * PHONE is branch-level and the school row wins. See each key below.
 */
return [
    /*
     * The BRAND, and it is not the same thing as the branch.
     *
     * The schools table holds branches — "Tiko school C1", "Tiko school C2" — because that
     * is what the app needs internally to scope students, classes and money. A parent does
     * not care which branch row their child sits in; they know the school as Tiko School,
     * and every message must say so however many branches exist.
     *
     * So the name is deliberately NOT read from the student's school row. The phone below
     * is, because that genuinely differs per branch and is the number a parent should call.
     */
    'name' => env('SCHOOL_NAME', 'Tiko School'),

    // Fallback only — the branch's own phone_number wins when it has one.
    'phone' => env('SCHOOL_PHONE', ''),
    'email' => env('SCHOOL_EMAIL', ''),

    // Brand-level, like the name. Empty leaves the line out of the message entirely rather
    // than pointing parents at an account that is not the school's.
    'instagram' => env(
        'SCHOOL_INSTAGRAM',
        'https://www.instagram.com/tikoschool?igsh=MXg1NjJwam80eTNoMw%3D%3D&utm_source=qr'
    ),

    'hours' => env('SCHOOL_HOURS', 'من 10:00 إلى 13:00 ومن 16:30 إلى 22:30'),

    /*
     * Quiet hours, in the app timezone.
     *
     * A class marked absent at 22:15 must not put a message on a parent's phone at
     * midnight, and a backlog at the end of the day must not silently expire. Messages
     * created outside this window are scheduled for the next opening instead of being
     * sent or dropped.
     */
    'notify_from' => env('SCHOOL_NOTIFY_FROM', '09:00'),
    'notify_until' => env('SCHOOL_NOTIFY_UNTIL', '21:30'),
];
