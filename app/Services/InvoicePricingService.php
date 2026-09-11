<?php

namespace App\Services;

use App\Models\Membership;
use Carbon\Carbon;

/**
 * Server-side authority for invoice pricing.
 *
 * Until this existed, the pro-rata formula lived ONLY in the browser
 * (resources/js/Components/forms/InvoicesFrom.jsx) and InvoiceController stored whatever
 * numbers the client sent. A crafted request could therefore set any price on any invoice —
 * and because teacher commission is a percentage of the invoice, it could also mint
 * arbitrary teacher wallet credit.
 *
 * The client keeps its copy of the formula for live preview (see the /invoices/price
 * endpoint), but the server recomputes and is the only thing that decides what is stored.
 *
 * Discounts remain possible, but only DOWNWARD and only explicitly — see price().
 */
class InvoicePricingService
{
    /**
     * Compute what an invoice should cost.
     *
     * @param  array<string>  $selectedMonths  ['2025-09', '2025-10', ...]
     * @return array{
     *     totalAmount: float,
     *     fullMonthsAmount: float,
     *     partialMonthAmount: float,
     *     monthsCount: int,
     *     monthlyPrice: float,
     *     monthsAreConsecutive: bool
     * }
     */
    public function price(
        Membership $membership,
        array $selectedMonths,
        bool $includePartialMonth,
        Carbon|string|null $billDate = null
    ): array {
        $monthlyPrice = (float) ($membership->offer->price ?? 0);

        $selectedMonths = $this->normaliseMonths($selectedMonths);
        $consecutive = $this->areMonthsConsecutive($selectedMonths);

        // Mirrors the client: a non-consecutive selection charges for zero full months
        // rather than silently pricing a broken range.
        $monthsCount = ($selectedMonths !== [] && $consecutive) ? count($selectedMonths) : 0;

        $fullMonthsAmount = round($monthlyPrice) * $monthsCount;

        $partialMonthAmount = $includePartialMonth
            ? $this->partialMonthAmount($monthlyPrice, $billDate)
            : 0.0;

        return [
            'totalAmount' => round($fullMonthsAmount + $partialMonthAmount, 2),
            'fullMonthsAmount' => round($fullMonthsAmount, 2),
            'partialMonthAmount' => round($partialMonthAmount, 2),
            'monthsCount' => $monthsCount,
            'monthlyPrice' => round($monthlyPrice, 2),
            'monthsAreConsecutive' => $consecutive,
        ];
    }

    /**
     * Reconcile a client-submitted payload against the server price.
     *
     * Returns the authoritative money fields to persist. `totalAmount` from the client is
     * honoured ONLY when it is lower than the computed price (a discount); a higher value is
     * ignored rather than rejected, so a stale form cannot overcharge a student.
     *
     * @param  array<string,mixed>  $validated
     * @return array{
     *     totalAmount: float,
     *     amountPaid: float,
     *     rest: float,
     *     partialMonthAmount: float,
     *     discountApplied: float
     * }
     */
    public function reconcile(Membership $membership, array $validated): array
    {
        $computed = $this->price(
            $membership,
            $this->normaliseMonths($validated['selected_months'] ?? []),
            (bool) ($validated['includePartialMonth'] ?? false),
            $validated['billDate'] ?? null
        );

        $serverTotal = $computed['totalAmount'];
        $clientTotal = isset($validated['totalAmount']) ? round((float) $validated['totalAmount'], 2) : null;

        // Only a downward adjustment is accepted, and only a sane one.
        $total = $serverTotal;
        $discount = 0.0;
        if ($clientTotal !== null && $clientTotal >= 0 && $clientTotal < $serverTotal) {
            $total = $clientTotal;
            $discount = round($serverTotal - $clientTotal, 2);
        }

        // A discount (or a stale client total below the server price) can push the
        // total UNDER the computed partial — never store partial > total, or the
        // teacher commission (a share of partial) would exceed the cash received.
        $partialMonthAmount = min($computed['partialMonthAmount'], $total);

        // A payment can never exceed the (possibly discounted) total.
        $amountPaid = min(round((float) ($validated['amountPaid'] ?? 0), 2), $total);
        $amountPaid = max($amountPaid, 0.0);

        return [
            'totalAmount' => $total,
            'amountPaid' => $amountPaid,
            // Always derived. Accepting `rest` from the client allowed total/paid/rest to
            // disagree, which the payout engine then treated as authoritative.
            'rest' => round($total - $amountPaid, 2),
            'partialMonthAmount' => $partialMonthAmount,
            'discountApplied' => $discount,
        ];
    }

    /**
     * Pro-rata charge for the remainder of the billing month.
     *
     * daily rate = price / days in month; charged for the days AFTER the billing date.
     *
     * Tikoschool customization (CUSTOMIZATIONS.md): the school keeps no coin change,
     * so the charge is rounded to the nearest multiple of 5 DH. Stored rows are never
     * migrated, and InvoiceController::update keeps the stored price whenever the
     * billing inputs are unchanged — editing an old invoice (e.g. recording a later
     * payment) must not reprice its partial.
     */
    private function partialMonthAmount(float $monthlyPrice, Carbon|string|null $billDate): float
    {
        $date = $this->parseDate($billDate);

        $daysInMonth = (int) $date->daysInMonth;
        if ($daysInMonth <= 0) {
            return 0.0;
        }

        $remainingDays = $daysInMonth - (int) $date->day;
        if ($remainingDays <= 0) {
            return 0.0;
        }

        $raw = ($monthlyPrice / $daysInMonth) * $remainingDays;
        if ($raw <= 0) {
            // A free offer stays free — the floor below is only for real charges.
            return 0.0;
        }

        $rounded = round($raw / 5) * 5;

        // A raw 1-2 DH charge would round to a FREE partial while days remain, which
        // flips the `partial > 0` payout branches into the full-immediate path.
        // Floor at one 5 DH coin instead.
        return (float) ($rounded == 0 ? 5 : $rounded);
    }

    private function parseDate(Carbon|string|null $billDate): Carbon
    {
        if ($billDate instanceof Carbon) {
            return $billDate->copy();
        }

        if (is_string($billDate) && $billDate !== '') {
            try {
                return Carbon::parse(substr($billDate, 0, 10));
            } catch (\Throwable) {
                // fall through
            }
        }

        return Carbon::now();
    }

    /**
     * Accept an array, a JSON string, or null; return a sorted list of 'YYYY-MM' strings.
     *
     * The frontend sends `selected_months` as either form depending on the code path, so
     * both must be handled here rather than at every call site.
     *
     * @return array<string>
     */
    public function normaliseMonths(mixed $months): array
    {
        if (is_string($months)) {
            $months = json_decode($months, true) ?: [];
        }

        if (! is_array($months)) {
            return [];
        }

        $months = array_values(array_unique(array_filter(
            array_map(fn ($m) => is_string($m) ? trim($m) : null, $months),
            // Month 01-12 enforced here, not just shape: '2026-13' is not a
            // month, and downstream code trusts these tokens for counting and
            // release queues.
            fn ($m) => $m !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) === 1
        )));

        sort($months);

        return $months;
    }

    /** @param array<string> $months */
    public function areMonthsConsecutive(array $months): bool
    {
        if (count($months) < 2) {
            return true;
        }

        for ($i = 1; $i < count($months); $i++) {
            $prev = Carbon::createFromFormat('Y-m', $months[$i - 1])->startOfMonth()->addMonth();
            $curr = Carbon::createFromFormat('Y-m', $months[$i])->startOfMonth();

            if ($prev->format('Y-m') !== $curr->format('Y-m')) {
                return false;
            }
        }

        return true;
    }
}
