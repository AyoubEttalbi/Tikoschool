<?php

namespace App\Support;

use App\Models\Invoice;
use Illuminate\Support\Carbon;

/**
 * When a payout record's commission falls due, month by month — THE release rule.
 *
 * The wallet settles to dueAt() (TeacherMembershipPaymentService::dueToDate) and
 * the « Gains Mensuels » statement shows split(): both come from this one rule,
 * so the page can never disagree with the money again (Nov 2026: the page
 * re-derived shares from today's offer and drifted ~5,000 DH on Tiko).
 *
 * The current month at billing, each later month on its 1st. A partial first
 * month carries its own share (the billing month); the rest spreads evenly over
 * the full months.
 */
final class PayoutSchedule
{
    /**
     * What is due once every billed month up to $asOfMonth has come round.
     *
     * @param  array<int, string>  $selectedMonths
     */
    public static function dueAt(
        float $total,
        array $selectedMonths,
        Invoice $invoice,
        float $teacherPercentage,
        string $asOfMonth
    ): float {
        $total = round($total, 2);

        if ($total <= 0) {
            return 0.0;
        }

        $selected = self::months($selectedMonths);
        $count = count($selected);

        if ($count === 0) {
            return $total;
        }

        // Due = every billed month that has come round, by the calendar. Not by the
        // queue: an edit landing before the monthly job ran must still release the
        // current month (the queue would say "not yet" and the job, finding a zero
        // monthly amount after the edit, would clear it unpaid).
        $dueMonths = array_values(array_filter($selected, fn ($m) => $m <= $asOfMonth));

        $includesPartial = (bool) ($invoice->includePartialMonth ?? false);
        $partialAmount = round((float) ($invoice->partialMonthAmount ?? 0), 2);
        $partialMonth = self::partialMonth($selected, $invoice);

        // Legacy creation path: a partial-month flag with no amount released the
        // whole commission at once — but only once that month has come round,
        // never ahead of a future-only plan (Nov 2026 review).
        if ($includesPartial && $partialAmount <= 0) {
            return $partialMonth <= $asOfMonth ? $total : 0.0;
        }

        if ($includesPartial && $count > 1) {
            // The rest of the commission spreads evenly over the full months.
            $partialShare = min($total, round($partialAmount * $teacherPercentage / 100, 2));
            $partialDue = in_array($partialMonth, $dueMonths, true) ? $partialShare : 0.0;
            $fullDue = count(array_diff($dueMonths, [$partialMonth]));

            return round($partialDue + ($total - $partialShare) * $fullDue / ($count - 1), 2);
        }

        return round($total * count($dueMonths) / $count, 2);
    }

    /**
     * The commission month by month: each month's share is what it adds to dueAt(),
     * so the shares of the months that came round sum to dueAt() to the cent.
     *
     * @param  array<int, string>  $selectedMonths
     * @return array<string, float> month (Y-m) => share
     */
    public static function split(float $total, array $selectedMonths, Invoice $invoice, float $teacherPercentage): array
    {
        $selected = self::months($selectedMonths);

        if ($selected === []) {
            return [];
        }

        $shares = [];
        $previous = 0.0;

        foreach ($selected as $month) {
            $due = self::dueAt($total, $selected, $invoice, $teacherPercentage, $month);
            $shares[$month] = round($due - $previous, 2);
            $previous = $due;
        }

        return $shares;
    }

    /**
     * The partial month is the billing month (the month that was current when the
     * invoice was paid — creation appends it), falling back to the first billed month.
     *
     * @param  array<int, string>  $selected  sorted, unique
     */
    private static function partialMonth(array $selected, Invoice $invoice): string
    {
        $billMonth = $invoice->billDate ? Carbon::parse($invoice->billDate)->format('Y-m') : null;

        return ($billMonth && in_array($billMonth, $selected, true)) ? $billMonth : $selected[0];
    }

    /**
     * @param  array<int, mixed>  $months
     * @return array<int, string> sorted, unique, non-empty
     */
    private static function months(array $months): array
    {
        $months = array_values(array_unique(array_filter($months, fn ($m) => is_string($m) && $m !== '')));
        sort($months);

        return $months;
    }
}
