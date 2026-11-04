<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;
use App\Services\TeacherMembershipPaymentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The teacher's earnings statement — THE canonical source for « Gains Mensuels ».
 *
 * Built from what really happened, never re-derived from today's data:
 *  - one payout record per invoice × teacher × subject carries the commission
 *    pinned at billing and its months (PayoutSchedule splits it, the same rule
 *    the wallet settles to);
 *  - the ledger says what the teacher holds for each record.
 *
 * So the statement always reconciles with the wallet, to the cent:
 *
 *     Gagné à ce jour − Versé = Solde (teachers.wallet)
 *
 * Nov 2026: the old version recomputed amountPaid × today's offer % for the
 * memberships the teacher is CURRENTLY on, and drifted from the wallet for 11 of
 * 14 Tiko teachers (~5,000 DH): money kept after a removal or a late delete
 * vanished, an offer changed after billing re-rated old invoices, money paid to
 * another account showed up, and adjustments were invisible.
 *
 * Consumers: TeacherController::show (« Gains Mensuels »), MyPaymentsController
 * (« Mes gains »), ledger:invariants (I4, I6).
 */
class TeacherEarnings
{
    public const PAID = 'paid';

    public const UPCOMING = 'upcoming';

    public const OWED = 'owed';

    public const KEPT_REMOVED = 'kept_removed';

    public const KEPT_DELETED = 'kept_deleted';

    public const ADJUSTMENT = 'adjustment';

    public const HISTORY = 'history';

    /** Statuses whose amount is NOT money earned to date. */
    private const NOT_COUNTED = [self::UPCOMING, self::HISTORY];

    /**
     * The per-month rows (one per record × month, plus one adjustment row per
     * record whose ledger differs from its schedule). Same keys as before, plus
     * `status`, `counted`, `record_id` and `subject`.
     */
    public static function monthlyRows(Teacher $teacher): Collection
    {
        return self::statement($teacher)['rows'];
    }

    /**
     * @return array{
     *     rows: Collection<int, array<string, mixed>>,
     *     other: array<int, array<string, mixed>>,
     *     payouts: array<int, array<string, mixed>>,
     *     anomalies: array<int, array<string, mixed>>,
     *     summary: array<string, float|bool>
     * }
     */
    public static function statement(Teacher $teacher): array
    {
        $currentMonth = now()->format('Y-m');
        $cashOut = self::cashOutReasons();
        $ledgerStart = TeacherWalletEntry::min('created_at');
        $ledgerStart = $ledgerStart ? Carbon::parse($ledgerStart) : null;

        $entries = TeacherWalletEntry::where('teacher_id', $teacher->id)->orderBy('id')->get();
        [$cash, $earned] = $entries->partition(fn ($e) => in_array($e->reason, $cashOut, true));

        $records = TeacherMembershipPayment::where('teacher_id', $teacher->id)->orderBy('id')->get();

        $invoiceIds = $records->pluck('invoice_id')->merge($earned->pluck('invoice_id'))->filter()->unique()->values();
        $invoices = Invoice::withTrashed()->with('offer')->whereIn('id', $invoiceIds)->get()->keyBy('id');

        $membershipIds = $records->pluck('membership_id')->merge($invoices->pluck('membership_id'))->filter()->unique()->values();
        $memberships = Membership::withTrashed()
            ->with(['offer', 'student' => fn ($q) => $q->withTrashed()->with(['school', 'class'])])
            ->whereIn('id', $membershipIds)
            ->get()
            ->keyBy('id');

        [$held, $hasRows, $unattributed] = self::attribute($earned->whereNotNull('invoice_id'), $records);

        $service = new TeacherMembershipPaymentService;
        $rows = collect();

        foreach ($records as $record) {
            $invoice = $invoices->get($record->invoice_id);

            if (! $invoice) {
                continue;
            }

            $membership = $memberships->get($record->membership_id) ?? $memberships->get($invoice->membership_id);
            $recordHeld = round($held[$record->id] ?? 0.0, 2);
            $total = round((float) ($record->total_teacher_amount ?? 0), 2);

            // The rate only shapes a partial first month; resolve it the way the
            // payout path does, and only when it matters.
            $pct = ($invoice->includePartialMonth && (float) $invoice->partialMonthAmount > 0)
                ? $service->recordPercentage($record)
                : (float) ($record->teacher_percentage ?? 0);

            $shares = PayoutSchedule::split($total, (array) ($record->selected_months ?? []), $invoice, $pct);
            $base = self::baseRow($record, $invoice, $membership);

            // Same test as the payout path: a pre-ledger invoice is settled against
            // the ledger only once the ledger holds what its record claims.
            $preLedger = $ledgerStart && $invoice->created_at && $invoice->created_at->lt($ledgerStart);
            $settles = ! $preLedger
                || (($hasRows[$record->id] ?? false) && round((float) $record->total_paid_to_teacher, 2) <= $recordHeld + 0.01);

            if (! $settles) {
                // History: paid before the ledger existed (the opening balance holds it).
                foreach ($shares as $month => $share) {
                    $rows->push(self::monthRow($base, $month, $share, self::HISTORY, count($shares)));
                }

                if (abs($recordHeld) >= 0.01) {
                    $rows->push(self::adjustmentRow($base, max(array_keys($shares) ?: [$currentMonth]), $recordHeld, 'Mouvements du registre sur une facture antérieure'));
                }

                continue;
            }

            $kept = ! $record->is_active || $invoice->deleted_at;

            if ($kept && abs($recordHeld) < 0.01) {
                continue; // reversed: nothing earned, nothing to show
            }

            $dueMonths = array_filter($shares, fn ($m) => $m <= $currentMonth, ARRAY_FILTER_USE_KEY);
            $due = round(array_sum($dueMonths), 2);
            $gap = round($recordHeld - $due, 2);

            $pastStatus = match (true) {
                (bool) $invoice->deleted_at => self::KEPT_DELETED,
                ! $record->is_active => self::KEPT_REMOVED,
                $gap < -0.01 => self::OWED,
                default => self::PAID,
            };

            foreach ($shares as $month => $share) {
                if ($month <= $currentMonth) {
                    $rows->push(self::monthRow($base, $month, $share, $pastStatus, count($shares)));
                } elseif (! $kept) {
                    $rows->push(self::monthRow($base, $month, $share, self::UPCOMING, count($shares)));
                }
            }

            if (abs($gap) >= 0.01) {
                $rows->push(self::adjustmentRow(
                    $base,
                    $dueMonths ? max(array_keys($dueMonths)) : $currentMonth,
                    $gap,
                    // The month row already says « Reste dû »; this line is the missing money.
                    $gap > 0 ? 'Reçu en plus du calendrier' : 'Pas encore crédité au portefeuille'
                ));
            }
        }

        $other = $earned->whereNull('invoice_id')
            ->map(fn ($e) => self::movement($e, null))
            ->merge(collect($unattributed)->map(fn ($e) => self::movement($e, 'Non rattaché à un enregistrement de paiement')))
            ->sortBy('date')
            ->values()
            ->all();

        $payouts = $cash->map(fn ($e) => self::movement($e, null))->values()->all();

        $earnedToDate = round($rows->where('counted', true)->sum('teacher_amount') + collect($other)->sum('amount'), 2);
        $paidOut = round(-collect($payouts)->sum('amount'), 2);
        $balance = round($earnedToDate - $paidOut, 2);
        $wallet = round((float) $teacher->wallet, 2);

        return [
            'rows' => $rows->values(),
            'other' => $other,
            'payouts' => $payouts,
            'anomalies' => self::anomalies($teacher, $records, $invoices, $memberships, $held, $ledgerStart),
            'summary' => [
                'earned_to_date' => $earnedToDate,
                'paid_out' => $paidOut,
                'balance' => $balance,
                'wallet' => $wallet,
                'reconciles' => abs($balance - $wallet) < 0.01,
                'upcoming' => round($rows->where('status', self::UPCOMING)->sum('teacher_amount'), 2),
                'owed' => round(-$rows->where('status', self::ADJUSTMENT)->where('teacher_amount', '<', 0)
                    ->where('record_active', true)->where('invoice_deleted', false)
                    ->sum('teacher_amount'), 2),
                'other' => round(collect($other)->sum('amount'), 2),
            ],
        ];
    }

    /**
     * Attribute every invoice-linked ledger row to exactly one payout record of
     * this teacher — or to none (unattributed) — so nothing is counted twice or lost.
     *
     * One record on the invoice: all the teacher's rows for it. Several (one
     * teacher, two subjects): by record id, else by the normalised subject.
     *
     * @return array{0: array<int, float>, 1: array<int, bool>, 2: array<int, TeacherWalletEntry>}
     */
    private static function attribute(Collection $entries, Collection $records): array
    {
        $held = [];
        $hasRows = [];
        $unattributed = [];
        $byInvoice = $records->groupBy('invoice_id');

        foreach ($entries->groupBy('invoice_id') as $invoiceId => $rows) {
            $candidates = $byInvoice->get($invoiceId, collect());

            foreach ($rows as $entry) {
                $record = match (true) {
                    $candidates->count() === 1 => $candidates->first(),
                    $candidates->isEmpty() => null,
                    default => $candidates->firstWhere('id', $entry->payment_record_id)
                        ?? self::onlyMatch($candidates, (string) $entry->teacher_subject),
                };

                if (! $record) {
                    $unattributed[] = $entry;

                    continue;
                }

                $held[$record->id] = ($held[$record->id] ?? 0.0) + (float) $entry->amount;
                $hasRows[$record->id] = true;
            }
        }

        return [$held, $hasRows, $unattributed];
    }

    private static function onlyMatch(Collection $candidates, string $subject): ?TeacherMembershipPayment
    {
        $subject = OfferPercentages::normalise($subject);
        $matches = $candidates->filter(fn ($r) => OfferPercentages::normalise((string) $r->teacher_subject) === $subject);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * What the statement cannot show as earnings but someone must look at:
     *  - missing_record: the teacher is on a paid invoice's membership for a subject
     *    the invoice billed, yet nobody holds a payout record for it;
     *  - billed_subject_unassigned: an active record whose teacher/subject is no
     *    longer on the membership still has money due (CRC 8128).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function anomalies(Teacher $teacher, Collection $records, Collection $invoices, Collection $recordMemberships, array $held, ?Carbon $ledgerStart): array
    {
        $anomalies = [];
        $id = (string) $teacher->id;

        $assignedTo = Membership::query()
            ->where(fn ($q) => $q->whereJsonContains('teachers', [['teacherId' => $id]])
                ->orWhereJsonContains('teachers', [['teacherId' => (int) $id]]))
            ->with(['invoices.offer', 'student'])
            ->get();

        // Every payout record on those invoices, any teacher — one query.
        $holdersByInvoice = TeacherMembershipPayment::whereIn('invoice_id', $assignedTo->flatMap->invoices->pluck('id'))
            ->get(['id', 'invoice_id', 'teacher_subject'])
            ->groupBy('invoice_id');

        foreach ($assignedTo as $membership) {
            $subject = collect($membership->teachers)->first(fn ($t) => is_array($t) && (string) ($t['teacherId'] ?? '') === $id)['subject'] ?? '';
            $normalised = OfferPercentages::normalise((string) $subject);

            foreach ($membership->invoices as $invoice) {
                if ((float) $invoice->amountPaid <= 0 || ($ledgerStart && $invoice->created_at && $invoice->created_at->lt($ledgerStart))) {
                    continue;
                }

                $pct = OfferPercentages::forSubject($invoice->offer, $subject);

                if ($pct === null) {
                    continue; // the invoice never billed this subject (offer changed after billing)
                }

                $holders = $holdersByInvoice->get($invoice->id, collect())
                    ->filter(fn ($r) => OfferPercentages::normalise((string) $r->teacher_subject) === $normalised);

                if ($holders->isEmpty()) {
                    $anomalies[] = [
                        'kind' => 'missing_record',
                        'invoice_id' => $invoice->id,
                        'student_name' => trim(($membership->student->firstName ?? '').' '.($membership->student->lastName ?? '')),
                        'subject' => $subject,
                        'expected' => round((float) $invoice->amountPaid * $pct / 100, 2),
                        'label' => 'Facture payée sans enregistrement de paiement pour cette matière',
                    ];
                }
            }
        }

        foreach ($records->where('is_active', true) as $record) {
            $invoice = $invoices->get($record->invoice_id);
            $membership = $recordMemberships->get($record->membership_id);

            if (! $invoice || $invoice->deleted_at || ! $membership || $membership->trashed()) {
                continue;
            }

            $key = TeacherMembershipPaymentService::teacherKey($record->teacher_id, $record->teacher_subject);
            $assigned = collect(is_array($membership->teachers) ? $membership->teachers : [])
                ->contains(fn ($t) => is_array($t) && TeacherMembershipPaymentService::teacherKey($t['teacherId'] ?? null, $t['subject'] ?? '') === $key);

            $due = PayoutSchedule::dueAt((float) $record->total_teacher_amount, (array) ($record->selected_months ?? []), $invoice, (float) ($record->teacher_percentage ?? 0), now()->format('Y-m'));

            if (! $assigned && ($held[$record->id] ?? 0.0) < $due - 0.01) {
                $anomalies[] = [
                    'kind' => 'billed_subject_unassigned',
                    'invoice_id' => $invoice->id,
                    'student_name' => trim(($membership->student->firstName ?? '').' '.($membership->student->lastName ?? '')),
                    'subject' => $record->teacher_subject,
                    'expected' => round($due - ($held[$record->id] ?? 0.0), 2),
                    'label' => 'Matière facturée mais l’enseignant n’est plus sur l’adhésion',
                ];
            }
        }

        return $anomalies;
    }

    /** @return array<string, mixed> */
    private static function baseRow(TeacherMembershipPayment $record, Invoice $invoice, ?Membership $membership): array
    {
        $student = $membership?->student;
        $billDate = $invoice->billDate ? Carbon::parse($invoice->billDate)->format('Y-m-d') : null;

        return [
            'record_id' => $record->id,
            'invoice_id' => $invoice->id,
            'membership_id' => $invoice->membership_id,
            'student_id' => $invoice->student_id,
            'student_name' => $student ? trim($student->firstName.' '.$student->lastName) : 'Inconnu',
            'student_class' => $student?->class?->name ?? 'Unknown',
            'student_school' => $student?->school?->name ?? 'Unknown',
            'schoolId' => $student?->schoolId,
            'subject' => $record->teacher_subject,
            'invoiceBillDate' => $billDate,
            'months' => $invoice->months,
            'creationDate' => $invoice->creationDate,
            'created_at' => $invoice->created_at,
            'totalAmount' => $invoice->totalAmount,
            'amountPaid' => $invoice->amountPaid,
            'rest' => $invoice->rest,
            'offer_id' => $invoice->offer_id,
            'offer_name' => $invoice->offer?->offer_name,
            'endDate' => $invoice->endDate,
            'includePartialMonth' => $invoice->includePartialMonth,
            'partialMonthAmount' => $invoice->partialMonthAmount,
            'membership_deleted' => (bool) $membership?->deleted_at,
            'membership_deleted_at' => $membership?->deleted_at,
            'invoice_deleted' => (bool) $invoice->deleted_at,
            'record_active' => (bool) $record->is_active,
        ];
    }

    /** @return array<string, mixed> */
    private static function monthRow(array $base, string $month, float $amount, string $status, int $totalMonths): array
    {
        return $base + [
            'id' => $base['record_id'].'_'.$month,
            'billDate' => $month.'-01',
            'month_display' => date('m-Y', strtotime($month.'-01')),
            'teacher_amount' => round($amount, 2),
            'months_count' => 1,
            'total_months' => $totalMonths,
            'status' => $status,
            'counted' => ! in_array($status, self::NOT_COUNTED, true),
            'is_month_paid' => in_array($status, [self::PAID, self::KEPT_REMOVED, self::KEPT_DELETED], true),
            'label' => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function adjustmentRow(array $base, string $month, float $amount, string $label): array
    {
        return $base + [
            'id' => $base['record_id'].'_adj',
            'billDate' => $month.'-01',
            'month_display' => date('m-Y', strtotime($month.'-01')),
            'teacher_amount' => round($amount, 2),
            'months_count' => 0,
            'total_months' => 0,
            'status' => self::ADJUSTMENT,
            'counted' => true,
            'is_month_paid' => $amount > 0,
            'label' => $label,
        ];
    }

    /** @return array<string, mixed> */
    private static function movement(TeacherWalletEntry $entry, ?string $label): array
    {
        return [
            'id' => $entry->id,
            'date' => optional($entry->created_at)->format('Y-m-d'),
            'reason' => $entry->reason,
            'amount' => round((float) $entry->amount, 2),
            'invoice_id' => $entry->invoice_id,
            'note' => $label ? trim($label.' — '.$entry->note, ' —') : $entry->note,
        ];
    }

    /** @return array<int, string> */
    private static function cashOutReasons(): array
    {
        $cashOut = [TeacherWalletEntry::REASON_PAYOUT];

        if (defined(TeacherWalletEntry::class.'::REASON_PAYOUT_LAST_YEAR')) {
            $cashOut[] = TeacherWalletEntry::REASON_PAYOUT_LAST_YEAR;
        }

        return $cashOut;
    }
}
