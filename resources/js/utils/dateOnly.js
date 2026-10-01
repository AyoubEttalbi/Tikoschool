/**
 * Timezone-immune rendering for DATE-only values (DATE columns carry no time).
 *
 * The trap: a value like "2026-10-01" (or "...T00:00:00Z") fed to
 * `new Date()` / `parseISO()` becomes a UTC-midnight instant, and any
 * local-zone formatting (`format(date, ...)`, `toLocaleDateString`) then
 * renders the previous day — or month — on devices behind UTC. The server
 * sends the same bytes everywhere; only each device's render differs.
 *
 * These helpers never construct an instant from the value for display:
 * parseDateOnly() builds a LOCAL calendar date from the Y/M/D parts
 * (midnight-local always formats back to the same date on every device),
 * and monthKey() is pure text. Output on correctly-set devices is
 * byte-identical to the old code; mis-set devices stop shifting.
 */

/** "2026-10-01" (or with a time suffix) -> Date at local midnight, or null. */
export const parseDateOnly = (value) => {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value ?? ""));
    if (!m) return null;
    const y = Number(m[1]);
    const mo = Number(m[2]);
    const d = Number(m[3]);
    if (mo < 1 || mo > 12 || d < 1 || d > 31) return null;
    const dt = new Date(y, mo - 1, d);
    // Round-trip: reject impossible dates (2026-02-31) instead of letting
    // JS roll them over into a confident wrong date.
    if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) return null;
    return dt;
};

/** Any date string -> "YYYY-MM" text, or "" when unparseable. */
export const monthKey = (value) => {
    const m = /^(\d{4})-(0[1-9]|1[0-2])/.exec(String(value ?? ""));
    return m ? `${m[1]}-${m[2]}` : "";
};
