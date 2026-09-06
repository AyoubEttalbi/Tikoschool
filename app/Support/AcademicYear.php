<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Moroccan academic year: September (9) to August (8).
 *
 * ONE definition of the school-year boundary, shared by the year-transition
 * flow (SchoolYearController::academicYearLabel delegates here) and read-only
 * questions like "is this record history?". Change the boundary once, here.
 */
class AcademicYear
{
    /**
     * Academic year label for a date: month 9-12 → "Y/Y+1", month 1-8 → "Y-1/Y".
     */
    public static function label($date): string
    {
        $d = Carbon::parse($date);
        $m = (int) $d->format('n');
        $y = (int) $d->format('Y');

        if ($m >= 9 && $m <= 12) {
            return $y.'/'.($y + 1);
        }

        return ($y - 1).'/'.$y;
    }

    /**
     * Whether a date belongs to a past academic year (visual signal only —
     * never an authorization or money rule; settled-ness stays deadline-based).
     *
     * @param  string|null  $nowLabel  precomputed label() of "now", so callers
     *                                 mapping collections pay for it once.
     */
    public static function isHistorical($date, $nowLabel = null): bool
    {
        if (! $date) {
            return false;
        }

        return self::label($date) !== ($nowLabel ?? self::label(now()));
    }
}
