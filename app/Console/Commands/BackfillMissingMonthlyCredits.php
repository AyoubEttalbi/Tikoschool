<?php

namespace App\Console\Commands;

use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;
use App\Services\TeacherWalletService;
use App\Support\LedgerShortfall;
use App\Support\OfferPercentages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Restore monthly credits a tracker record claims but the ledger never saw.
 *
 * Born from the October-2026 stale-scheduler incident: a second scheduler wrote
 * wallet increments with no ledger rows and marked tracker records settled, so
 * `wallet:check --repair` alone would have robbed teachers of real earnings
 * (their records read paid, the ledger holds nothing). This command writes the
 * missing schedule.monthly rows FIRST; the wallet reconcile comes after.
 *
 *   php artisan wallet:backfill-monthly                 # DRY RUN (default)
 *   php artisan wallet:backfill-monthly --apply         # write the rows
 *   php artisan wallet:backfill-monthly --teacher=2     # scope to one teacher
 *
 * A month qualifies only when ALL of these hold:
 *  - the tracker record is active, assigned to the teacher's current roster,
 *    and its invoice is live (trashed invoices never earn),
 *  - the invoice itself is fully paid by the family (no paying teachers from
 *    uncollected bills),
 *  - the service month has arrived and the tracker treats it as settled,
 *  - the ledger holds NOTHING for (teacher, invoice, month, subject): not a
 *    positive balance, and not a net-zero reversal either (a reversed month
 *    was settled by the reversal and is reported, never re-credited),
 *  - the invoice-level ledger net does not already cover what the tracker
 *    says was paid (reconcile top-ups carry month = NULL and are invisible to
 *    a per-month sum — the invoice-level check sees them),
 *  - the invoice was born after the ledger started recording (era guard:
 *    pre-ledger history has no rows by design and must not be "fixed"),
 *  - the invoice is a plain full-month shape (partial-month splits are
 *    reported for a human instead of guessed).
 *
 * The written amount is the record's monthly share capped at the outstanding
 * balance (owed minus invoice-level ledger net), recomputed inside the write
 * transaction from a freshly locked record — never trusted from the scan.
 *
 * Paid-total normalisation (apply only): for fully-matured, non-partial
 * records whose paid exceeds the ledger figure (and whose paid has not moved
 * since the scan), paid is reset to the ledger figure. An inflated paid makes
 * the monthly guard skip months the teacher was never given.
 *
 * Re-runs are no-ops: every written row carries the standard idempotency key,
 * so a duplicate run is refused by the database, not applied twice.
 */
class BackfillMissingMonthlyCredits extends Command
{
    protected $signature = 'wallet:backfill-monthly
                            {--teacher= : Only process one teacher id}
                            {--apply : Actually write rows. Without this the command only reports.}';

    protected $description = 'Write schedule.monthly ledger rows for settled tracker months the ledger never saw (moves real money — review dry-run first)';

    private const TOLERANCE = 0.01;

    /** Cash handed over: orthogonal to what an invoice still holds. */
    private function cashOutReasons(): array
    {
        $reasons = [TeacherWalletEntry::REASON_PAYOUT];

        if (defined(TeacherWalletEntry::class.'::REASON_PAYOUT_LAST_YEAR')) {
            $reasons[] = TeacherWalletEntry::REASON_PAYOUT_LAST_YEAR;
        }

        return $reasons;
    }

    public function handle(TeacherWalletService $wallet): int
    {
        $apply = (bool) $this->option('apply');
        $nowMonth = now()->format('Y-m');
        $ledgerStart = TeacherWalletEntry::min('created_at');
        $cashOut = $this->cashOutReasons();

        $records = TeacherMembershipPayment::query()
            ->where('is_active', true)
            ->where('total_teacher_amount', '>', 0)
            ->when($this->option('teacher'), fn ($q, $id) => $q->where('teacher_id', $id))
            ->with(['invoice', 'teacher', 'membership'])
            ->orderBy('id')
            ->get();

        if ($records->isEmpty()) {
            $this->info('No active payout records in scope. Nothing to do.');

            return self::SUCCESS;
        }

        // Two batched reads replace per-record SUM storms. Month slices are
        // per normalised subject (record identity includes it); the '' slice
        // collects legacy rows stamped before subjects were normalised.
        $monthSlices = TeacherWalletEntry::query()
            ->select('teacher_id', 'invoice_id', 'month', 'teacher_subject', DB::raw('COUNT(*) as n'), DB::raw('SUM(amount) as net'))
            ->whereNotIn('reason', $cashOut)
            ->when($this->option('teacher'), fn ($q, $id) => $q->where('teacher_id', $id))
            ->whereNotNull('invoice_id')
            ->whereNotNull('month')
            ->groupBy('teacher_id', 'invoice_id', 'month', 'teacher_subject')
            ->get()
            ->groupBy(fn ($row) => implode('.', [$row->teacher_id, $row->invoice_id, $row->month, $row->teacher_subject]));

        $invoiceNets = TeacherWalletEntry::query()
            ->select('teacher_id', 'invoice_id', DB::raw('SUM(amount) as net'))
            ->whereNotIn('reason', $cashOut)
            ->when($this->option('teacher'), fn ($q, $id) => $q->where('teacher_id', $id))
            ->whereNotNull('invoice_id')
            ->groupBy('teacher_id', 'invoice_id')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->teacher_id.'.'.$row->invoice_id => round((float) $row->net, 2)]);

        // Paid snapshot for the normalisation staleness check below.
        $paidAtScan = $records->mapWithKeys(fn ($r) => [$r->id => round((float) $r->total_paid_to_teacher, 2)]);

        $candidates = [];
        $manualReview = [];

        foreach ($records as $record) {
            $invoice = $record->invoice;

            if (! $invoice || $invoice->trashed()) {
                $manualReview[] = [$record->teacher_id, $record->invoice_id, 'tracker record with no live invoice'];

                continue;
            }

            if (! LedgerShortfall::isAssigned($record)) {
                $manualReview[] = [$record->teacher_id, $record->invoice_id, 'teacher no longer holds this subject on the roster'];

                continue;
            }

            if ($ledgerStart && $invoice->created_at && $invoice->created_at < $ledgerStart) {
                continue; // Era guard: pre-ledger history has no rows by design.
            }

            $billTotal = round((float) ($invoice->totalAmount ?? 0), 2);
            $billPaid = round((float) ($invoice->amountPaid ?? 0), 2);

            if ($billTotal <= 0 || $billPaid < $billTotal - self::TOLERANCE) {
                $manualReview[] = [$record->teacher_id, $record->invoice_id, "family bill not fully paid ({$billPaid}/{$billTotal})"];

                continue;
            }

            $partial = (bool) ($invoice->includePartialMonth ?? false) && (float) ($invoice->partialMonthAmount ?? 0) > 0;

            if ($partial) {
                $manualReview[] = [$record->teacher_id, $record->invoice_id, 'partial-month split'];

                continue;
            }

            $selected = is_array($record->selected_months) ? $record->selected_months : [];
            $queued = is_array($record->months_rest_not_paid_yet) ? $record->months_rest_not_paid_yet : [];
            $owed = round((float) $record->total_teacher_amount, 2);
            $invoiceNet = (float) ($invoiceNets->get($record->teacher_id.'.'.$record->invoice_id) ?? 0.0);
            $subjectKey = OfferPercentages::normalise((string) ($record->teacher_subject ?? ''));

            foreach ($selected as $month) {
                if (! $month || $month > $nowMonth) {
                    continue; // Service month has not arrived.
                }

                if (in_array($month, $queued, true)) {
                    continue; // Still queued — the monthly cron owns it.
                }

                $sliceKey = implode('.', [$record->teacher_id, $record->invoice_id, $month, $subjectKey]);
                $legacyKey = implode('.', [$record->teacher_id, $record->invoice_id, $month, '']);
                $slice = $monthSlices->get($sliceKey, collect());
                $legacy = $monthSlices->get($legacyKey, collect());
                $sliceNet = round((float) $slice->sum('net') + (float) $legacy->sum('net'), 2);
                $sliceRows = (int) $slice->sum('n') + (int) $legacy->sum('n');

                if ($sliceRows > 0 && $sliceNet > self::TOLERANCE) {
                    continue; // Ledger already holds this month.
                }

                if ($sliceRows > 0) {
                    // Rows exist but net to ~zero: a reversal settled this
                    // month. Re-crediting would resurrect reversed money.
                    $manualReview[] = [$record->teacher_id, $record->invoice_id, "month {$month} reversed to zero — settled by reversal"];

                    continue;
                }

                if ($invoiceNet >= round((float) $record->total_paid_to_teacher, 2) - self::TOLERANCE) {
                    // The invoice-level net already covers what the tracker
                    // says was paid (reconcile top-ups carry month = NULL and
                    // are invisible to the per-month sum above). Nothing missing.
                    continue;
                }

                $outstanding = round($owed - $invoiceNet, 2);

                if ($outstanding <= self::TOLERANCE) {
                    continue;
                }

                $candidates[] = [
                    'record_id' => $record->id,
                    'teacher_id' => $record->teacher_id,
                    'invoice_id' => $record->invoice_id,
                    'month' => $month,
                    'outstanding' => $outstanding,
                ];
            }
        }

        $this->info($apply
            ? 'Backfilling missing monthly credits. Wallets move.'
            : 'DRY RUN — nothing will be changed. Add --apply to write.');
        $this->newLine();

        if ($candidates !== []) {
            $this->table(
                ['Teacher', 'Invoice', 'Month', 'Outstanding'],
                array_map(fn ($c) => [
                    $c['teacher_id'],
                    $c['invoice_id'],
                    $c['month'],
                    number_format($c['outstanding'], 2),
                ], $candidates)
            );
            $this->newLine();
        }

        if ($manualReview !== []) {
            $this->warn('Needs a human (not backfilled):');
            $this->table(['Teacher', 'Invoice', 'Why'], $manualReview);
            $this->newLine();
        }

        if (! $apply) {
            $this->line('Re-run with --apply to write '.count($candidates).' row(s).');

            return self::SUCCESS;
        }

        $written = 0;

        foreach ($candidates as $candidate) {
            $result = DB::transaction(function () use ($candidate, $wallet, $cashOut) {
                // Revalidate everything inside the write transaction: the scan
                // is unlocked, and an invoice edit or cron run may have landed
                // since. Stale month, changed share, or fresh ledger movement
                // all turn this candidate into a skip.
                $record = TeacherMembershipPayment::whereKey($candidate['record_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $record || ! $record->is_active) {
                    return 'record gone';
                }

                $queued = is_array($record->months_rest_not_paid_yet) ? $record->months_rest_not_paid_yet : [];

                if (in_array($candidate['month'], $queued, true)) {
                    return 'month re-queued since scan';
                }

                $teacher = Teacher::whereKey($record->teacher_id)->lockForUpdate()->first();

                if (! $teacher) {
                    return 'teacher gone';
                }

                $subjectKey = OfferPercentages::normalise((string) ($record->teacher_subject ?? ''));
                $freshRows = (int) TeacherWalletEntry::where('teacher_id', $record->teacher_id)
                    ->where('invoice_id', $record->invoice_id)
                    ->where('month', $candidate['month'])
                    ->whereNotIn('reason', $cashOut)
                    ->where(function ($q) use ($subjectKey) {
                        $q->where('teacher_subject', $subjectKey)->orWhere('teacher_subject', '');
                    })
                    ->count();
                $freshNet = round((float) TeacherWalletEntry::where('teacher_id', $record->teacher_id)
                    ->where('invoice_id', $record->invoice_id)
                    ->where('month', $candidate['month'])
                    ->whereNotIn('reason', $cashOut)
                    ->where(function ($q) use ($subjectKey) {
                        $q->where('teacher_subject', $subjectKey)->orWhere('teacher_subject', '');
                    })
                    ->sum('amount'), 2);

                if ($freshNet > self::TOLERANCE) {
                    return 'ledger moved since scan';
                }

                if ($freshRows > 0) {
                    // A reversal landed between scan and write: rows exist but
                    // net to ~zero. Settled by the reversal — never re-credit.
                    return 'reversed since scan';
                }

                // Re-apply the scan gates against live rows: an invoice
                // trashed, edited, or reassigned since the scan must not be
                // credited on stale facts.
                $invoice = $record->invoice()->withTrashed()->first();

                if (! $invoice || $invoice->trashed()) {
                    return 'invoice gone since scan';
                }

                if (! LedgerShortfall::isAssigned($record->fresh())) {
                    return 'roster changed since scan';
                }

                $billTotal = round((float) ($invoice->totalAmount ?? 0), 2);
                $billPaid = round((float) ($invoice->amountPaid ?? 0), 2);

                if ($billTotal <= 0 || $billPaid < $billTotal - self::TOLERANCE) {
                    return 'family bill no longer fully paid';
                }

                if ((bool) ($invoice->includePartialMonth ?? false) && (float) ($invoice->partialMonthAmount ?? 0) > 0) {
                    return 'turned partial since scan';
                }

                $freshInvoiceNet = round((float) TeacherWalletEntry::where('teacher_id', $record->teacher_id)
                    ->where('invoice_id', $record->invoice_id)
                    ->whereNotIn('reason', $cashOut)
                    ->sum('amount'), 2);

                $share = round((float) $record->monthly_teacher_amount, 2);
                $outstanding = round((float) $record->total_teacher_amount - $freshInvoiceNet, 2);
                $amount = min($share, $outstanding);

                if ($amount <= self::TOLERANCE) {
                    return 'nothing outstanding';
                }

                $credited = $wallet->credit(
                    $teacher,
                    $amount,
                    TeacherWalletEntry::REASON_MONTHLY,
                    $record->id,
                    $candidate['month'],
                    $record->invoice_id,
                    'backfill: settled tracker month the ledger never saw (Oct-2026 stale-scheduler incident)',
                    $record->teacher_subject
                );

                if (! $credited) {
                    return 'already recorded (idempotency key held)';
                }

                Log::info('Backfilled missing monthly credit', [
                    'record_id' => $record->id,
                    'teacher_id' => $record->teacher_id,
                    'invoice_id' => $record->invoice_id,
                    'month' => $candidate['month'],
                    'amount' => $amount,
                ]);

                return true;
            });

            if ($result === true) {
                $written++;
            } else {
                Log::info('Backfill candidate skipped at write time', [
                    'record_id' => $candidate['record_id'],
                    'month' => $candidate['month'],
                    'reason' => $result,
                ]);
            }
        }

        $this->info("Wrote {$written} row(s).");

        // Paid-total normalisation: an inflated paid makes the monthly guard
        // skip months the teacher was never given. Only fully-matured,
        // non-partial records whose paid has not moved since the scan, reset
        // to the freshly read ledger figure.
        $normalised = 0;

        $matured = TeacherMembershipPayment::query()
            ->where('is_active', true)
            ->where('total_teacher_amount', '>', 0)
            ->when($this->option('teacher'), fn ($q, $id) => $q->where('teacher_id', $id))
            ->with(['invoice', 'membership'])
            ->orderBy('id')
            ->get()
            ->filter(function ($record) use ($nowMonth, $ledgerStart, $invoiceNets, $paidAtScan) {
                if (! $record->invoice || $record->invoice->trashed()) {
                    return false;
                }

                if (! LedgerShortfall::isAssigned($record)) {
                    return false;
                }

                if ($ledgerStart && $record->invoice->created_at && $record->invoice->created_at < $ledgerStart) {
                    return false;
                }

                if ((bool) ($record->invoice->includePartialMonth ?? false)
                    && (float) ($record->invoice->partialMonthAmount ?? 0) > 0) {
                    return false;
                }

                $billTotal = round((float) ($record->invoice->totalAmount ?? 0), 2);
                $billPaid = round((float) ($record->invoice->amountPaid ?? 0), 2);

                if ($billTotal <= 0 || $billPaid < $billTotal - self::TOLERANCE) {
                    return false;
                }

                $selected = is_array($record->selected_months) ? $record->selected_months : [];

                if ($selected === []) {
                    return false;
                }

                foreach ($selected as $month) {
                    if ($month && $month > $nowMonth) {
                        return false; // Future months still belong to the cron.
                    }
                }

                // Batched map, not a per-record query (normalisation re-reads
                // the exact figure under lock before writing — see below).
                $held = (float) ($invoiceNets->get($record->teacher_id.'.'.$record->invoice_id) ?? 0.0);

                $paid = round((float) $record->total_paid_to_teacher, 2);

                return $paid > $held + self::TOLERANCE
                    && ($paidAtScan->get($record->id) === null || abs($paid - (float) $paidAtScan->get($record->id)) <= self::TOLERANCE);
            });

        foreach ($matured as $record) {
            DB::transaction(function () use ($record, $cashOut, $paidAtScan, &$normalised) {
                $locked = TeacherMembershipPayment::whereKey($record->id)->lockForUpdate()->first();

                if (! $locked || ! $locked->is_active) {
                    return;
                }

                $paidNow = round((float) $locked->total_paid_to_teacher, 2);
                $scanPaid = $paidAtScan->get($locked->id);

                if ($scanPaid !== null && abs($paidNow - (float) $scanPaid) > self::TOLERANCE) {
                    return; // Moved since the scan — a writer is active; leave it.
                }

                $held = round((float) TeacherWalletEntry::where('teacher_id', $locked->teacher_id)
                    ->where('invoice_id', $locked->invoice_id)
                    ->whereNotIn('reason', $cashOut)
                    ->sum('amount'), 2);

                if ($paidNow <= $held + self::TOLERANCE) {
                    return;
                }

                $locked->update(['total_paid_to_teacher' => $held]);
                $normalised++;

                Log::warning('Normalised inflated tracker paid-total to the ledger figure', [
                    'record_id' => $locked->id,
                    'teacher_id' => $locked->teacher_id,
                    'invoice_id' => $locked->invoice_id,
                    'paid_before' => $paidNow,
                    'paid_after' => $held,
                ]);
            });
        }

        $this->info("Normalised {$normalised} inflated paid-total(s).");
        $this->line('Run `php artisan wallet:check` and `php artisan wallet:audit-invoices` to confirm.');

        return self::SUCCESS;
    }
}
