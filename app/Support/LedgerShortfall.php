<?php

namespace App\Support;

use App\Models\Membership;
use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;
use Illuminate\Support\Collection;

/**
 * The ledger-vs-record invariant (prod Sept 2026 class).
 *
 * A payout record says its teacher was paid CLAIM dirhams for an invoice; the
 * append-only ledger must agree. Two shapes hide from every other monitor:
 *
 *   A. active zombie — record active + paid-in-full on a live invoice, wallet
 *      short (reversal took the money, a later re-save reactivated for free).
 *      The cron skips it (fully paid), payouts:audit needs an underpaid total
 *      or a deleted invoice, wallet:check compares the cache against a ledger
 *      that is itself missing the money.
 *
 *   B. dead but owed — record inactive on a live invoice whose teacher is STILL
 *      assigned (membership edited, invoice never re-saved; prod invoice 7027:
 *      teachers 1 and 6 short 100 + 80). The audit's underpaid query requires
 *      is_active, so these are invisible too.
 *
 * Scoped to the ledger era (billDate >= 2026-08-01): movements before the ledger
 * existed have no per-invoice credits to compare against, so older rows would
 * false-positive. They are a separate review, not this invariant.
 */
class LedgerShortfall
{
    public const LEDGER_ERA_START = '2026-08-01';

    /**
     * @return Collection<int, array{record: TeacherMembershipPayment, teacher_id: int, invoice_id: int, subject: ?string, claim: float, ledger: float, shortfall: float, assigned: bool, shape: string}>
     */
    public static function find(float $threshold = 0.01): Collection
    {
        // Per (teacher, invoice, SUBJECT): the record identity is per subject, so a
        // teacher holding two subjects on one invoice must be compared per subject —
        // comparing one row's claim against both subjects' ledger hides shortfalls.
        $nets = TeacherWalletEntry::query()
            ->selectRaw('teacher_id, invoice_id, teacher_subject, SUM(amount) as net')
            ->groupBy('teacher_id', 'invoice_id', 'teacher_subject')
            ->get()
            ->keyBy(fn ($row) => $row->teacher_id.':'.$row->invoice_id.':'.OfferPercentages::normalise((string) ($row->teacher_subject ?? '')));

        $records = TeacherMembershipPayment::query()
            ->whereHas('invoice', fn ($q) => $q
                ->whereNull('deleted_at')
                ->where('billDate', '>=', self::LEDGER_ERA_START))
            ->with(['invoice', 'membership'])
            ->get();

        // Ledger rows written before teacher_subject existed carry '' — they belong
        // to whichever single subject the teacher holds on that invoice. With
        // several subjects they cannot be attributed, so they are ignored rather
        // than counted toward every subject (which would hide real shortfalls).
        $subjectsPerInvoice = $records
            ->groupBy(fn ($r) => $r->teacher_id.':'.$r->invoice_id)
            ->map(fn ($group) => $group
                ->map(fn ($r) => OfferPercentages::normalise((string) ($r->teacher_subject ?? '')))
                ->unique()->values());

        return $records
            ->map(function (TeacherMembershipPayment $record) use ($nets, $subjectsPerInvoice) {
                $key = $record->teacher_id.':'.$record->invoice_id;
                $normalisedSubject = OfferPercentages::normalise((string) ($record->teacher_subject ?? ''));

                $ledger = round((float) ($nets->get($key.':'.$normalisedSubject)->net ?? 0), 2);

                if ($normalisedSubject !== '' && ($subjectsPerInvoice->get($key)?->count() ?? 0) === 1) {
                    $ledger = round($ledger + (float) ($nets->get($key.':')->net ?? 0), 2);
                }

                $claim = round((float) ($record->total_paid_to_teacher ?? 0), 2);

                return [
                    'record' => $record,
                    'teacher_id' => $record->teacher_id,
                    'invoice_id' => $record->invoice_id,
                    'subject' => $record->teacher_subject,
                    'claim' => $claim,
                    'ledger' => $ledger,
                    'shortfall' => round($claim - $ledger, 2),
                    'assigned' => self::isAssigned($record),
                    'shape' => $record->is_active ? 'active-zombie' : 'dead-but-owed',
                ];
            })
            ->filter(fn ($row) => $row['shortfall'] > $threshold)
            ->values();
    }

    /**
     * Whether the record's teacher still holds THIS subject on the membership's
     * CURRENT teacher list. Matching the teacher alone is not enough: a teacher
     * kept on Math but removed from PC must read REVIEW for the PC row, or the
     * repair would pay those hours twice (the new PC teacher was already paid).
     */
    public static function isAssigned(TeacherMembershipPayment $record): bool
    {
        $membership = $record->relationLoaded('membership') && $record->membership
            ? $record->membership
            : Membership::withTrashed()->find($record->membership_id);

        if (! $membership || ! is_array($membership->teachers)) {
            return false;
        }

        $need = (string) $record->teacher_id.'|'.OfferPercentages::normalise((string) ($record->teacher_subject ?? ''));

        return collect($membership->teachers)->contains(fn ($t) => (string) ($t['teacherId'] ?? '')
            .'|'.OfferPercentages::normalise((string) ($t['subject'] ?? '')) === $need);
    }
}
