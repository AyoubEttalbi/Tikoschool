<?php

namespace App\Support;

/**
 * The memory budget every dompdf render in this app runs under.
 *
 * WHY THIS EXISTS
 *
 * dompdf keeps the entire document in memory — every cell as a frame object with its own
 * resolved style — until the render returns, and it loads the whole DejaVu Sans face to
 * do it. That face is needed for the ✓/✗/é glyphs the core PDF fonts do not carry, so the
 * cost is unavoidable and it is paid per request regardless of how small the document is:
 *
 *     fixed  ~65-80 MB   font + dompdf bootstrap, every render
 *     plus   ~0.75 MB    per student row on the absence sheet (35 cells wide)
 *     plus   ~0.12 MB    per invoice on a bulk invoice run
 *
 * Add ~40 MB for a booted Laravel and the *cheapest* PDF in the app peaks near 105 MB.
 * That is why every one of these endpoints died instantly at PHP's stock 128 M limit and
 * survived on the server's 256 M — the absence list was simply the first one anybody
 * happened to click. Reproduced on teacher-invoices/bulk-download at 20 invoices before
 * this class existed.
 *
 * WHY NOT JUST RAISE php.ini
 *
 * docker-compose caps the whole php container at 768 MB, shared by php-fpm and all its
 * workers, the queue runner and reverb. A request permitted more memory than the
 * container can give is OOM-killed by the kernel, which takes the container down instead
 * of failing one download. Raising the ceiling for a short, bounded render is safe;
 * raising it for every request in the app is not.
 *
 * WHY A GUARD AS WELL
 *
 * PHP memory exhaustion is a fatal error, not a catchable exception — there is no way to
 * turn it into a friendly message after the fact. The only way to make the crash
 * impossible rather than unlikely is to refuse the oversized job before starting it.
 */
final class PdfBudget
{
    /**
     * Ceiling for one render. ~2.5x the realistic worst case, and comfortably inside the
     * container's 768 MB so a spike fails as a PHP error rather than an OOM kill.
     */
    public const LIMIT = '384M';

    /** Raise the ceiling for this request only. Call before loading the view. */
    public static function apply(): void
    {
        ini_set('memory_limit', self::LIMIT);
    }

    /**
     * How many rows LIMIT actually covers, for a document costing $perRowMb per row.
     * Subtracts the fixed font cost and a booted Laravel from the ceiling.
     */
    public static function maxRows(float $perRowMb): int
    {
        $usable = 384 - 80 /* font */ - 40 /* framework */;

        return (int) floor($usable / $perRowMb);
    }
}
