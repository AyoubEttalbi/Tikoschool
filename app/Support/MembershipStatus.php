<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Membership;
use App\Services\InvoicePricingService;
use Illuminate\Support\Carbon;

/**
 * Single home of the membership lifecycle rule.
 *
 * A membership reads `paid` iff a live MONTHLY invoice is fully paid AND its
 * coverage reaches today or beyond; otherwise `pending`. Coverage — not the
 * cash moment — decides, so:
 *
 *   - paying October in October → paid; prepaying it in September → paid;
 *   - paying September late in October → pending (the month already passed);
 *   - voiding October while September stands paid, viewed in October → pending;
 *   - a multi-month invoice covers every month up to its end.
 *
 * Assurance bills never qualify (yearly product, not monthly cover). Never
 * returns 'expired': birth (creation → pending) and death (void-all →
 * expired, nightly end_date pass) keep their writers; time passing without
 * money is the cron's job, and every display already counts expired as unpaid.
 *
 * `end_date` is owned here too (coverageEndDate()): the same qualifying set
 * decides the period, so status and period can never disagree. All writers
 * (store/update/destroy) must call resolve() + coverageEndDate() instead of
 * re-deriving paid-vs-pending or extending end_date inline — copies WILL drift.
 */
class MembershipStatus
{
    public static function resolve(Membership $membership, ?Carbon $today = null, ?int $exceptInvoiceId = null): string
    {
        $today = ($today ?? Carbon::today())->startOfDay();

        foreach (self::paidMonthlyInvoices($membership, $exceptInvoiceId) as $invoice) {
            $end = self::coverageEnd($invoice);
            if ($end !== null && $end->gte($today)) {
                return 'paid';
            }
        }

        return 'pending';
    }

    /**
     * Latest covered day across the qualifying set (fully-paid live monthly
     * invoices), or null when nothing qualifies. Writers store this as
     * end_date so the period always agrees with the status.
     */
    public static function coverageEndDate(Membership $membership, ?int $exceptInvoiceId = null): ?string
    {
        $max = null;
        foreach (self::paidMonthlyInvoices($membership, $exceptInvoiceId) as $invoice) {
            $end = self::coverageEnd($invoice);
            if ($end !== null && ($max === null || $end->gt($max))) {
                $max = $end;
            }
        }

        return $max?->format('Y-m-d');
    }

    public static function isFullyPaid(mixed $amountPaid, mixed $totalAmount): bool
    {
        return round((float) $amountPaid, 2) >= round((float) $totalAmount, 2);
    }

    /** Fully-paid live monthly invoices. The single qualifying set. */
    private static function paidMonthlyInvoices(Membership $membership, ?int $exceptInvoiceId = null)
    {
        $query = Invoice::where('membership_id', $membership->id)
            ->whereNull('deleted_at')
            ->where('type', 'invoice');
        if ($exceptInvoiceId !== null) {
            $query->where('id', '!=', $exceptInvoiceId);
        }

        return $query->get(['amountPaid', 'totalAmount', 'endDate', 'selected_months', 'billDate', 'includePartialMonth'])
            ->filter(fn ($invoice) => self::isFullyPaid($invoice->amountPaid, $invoice->totalAmount))
            ->values();
    }

    /**
     * Last covered day: the max of invoice end date, last selected month,
     * and (for partial-only rows) billDate month-end. Max-union, matching
     * the duplicate-month guard's union semantics: a month the guard calls
     * billed is a month the status calls covered, by construction.
     */
    private static function coverageEnd(Invoice $invoice): ?Carbon
    {
        $ends = [];

        $end = $invoice->endDate;
        if ($end instanceof \DateTimeInterface) {
            $ends[] = Carbon::parse($end->format('Y-m-d'))->startOfDay();
        }

        $months = (new InvoicePricingService)->normaliseMonths($invoice->selected_months);
        if ($months !== []) {
            $last = $months[count($months) - 1];
            [$y, $m] = array_map('intval', explode('-', $last));
            $ends[] = Carbon::create($y, $m, 1)->endOfMonth()->startOfDay();
        }

        if ($invoice->includePartialMonth) {
            $bill = $invoice->billDate;
            $day = $bill instanceof \DateTimeInterface
                ? $bill->format('Y-m-d')
                : (is_string($bill) ? substr($bill, 0, 10) : '');
            if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])-\d{2}$/', $day, $m) === 1) {
                $ends[] = Carbon::create((int) $m[1], (int) $m[2], 1)->endOfMonth()->startOfDay();
            }
        }

        if ($ends === []) {
            return null;
        }

        usort($ends, fn ($a, $b) => $a->lt($b) ? -1 : 1);

        return $ends[count($ends) - 1];
    }
}
