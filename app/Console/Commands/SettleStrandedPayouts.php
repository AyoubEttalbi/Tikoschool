<?php

namespace App\Console\Commands;

use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;
use App\Services\TeacherWalletService;
use App\Support\LedgerShortfall;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pay teachers the commission they earned but can no longer receive.
 *
 *   php artisan payouts:settle-stranded            # DRY RUN — shows what it would do
 *   php artisan payouts:settle-stranded --apply    # actually credits wallets
 *
 * WHAT "STRANDED" MEANS, AND WHY IT IS NOT THE SAME AS payouts:audit's SHORTFALL
 * -----------------------------------------------------------------------------
 * `payouts:audit` reports shortfall = SUM(total_teacher_amount - total_paid_to_teacher).
 * That total mixes two things that must NOT be treated the same way:
 *
 *   NOT YET DUE   Months still listed in months_rest_not_paid_yet. The monthly cron will
 *                 pay these on the 1st of their month. Crediting them now is paying a
 *                 teacher EARLY — an overpayment, not a correction.
 *
 *   STRANDED      The gap that exceeds everything still scheduled. No future month will
 *                 ever deliver it, so without this command the teacher simply never gets
 *                 it. This is the real historical drift.
 *
 * On the dataset this was written against, a 1,800.00 reported shortfall was 240.00 not
 * yet due and 1,560.00 genuinely stranded. Settling the whole 1,800 would have overpaid
 * by 240.
 *
 * SAFETY
 * ------
 * - Dry run by default. --apply is required to move money.
 * - Every credit goes through TeacherWalletService, so it lands in the append-only ledger
 *   and `wallet:check` still reconciles afterwards.
 * - Idempotent per payout record: the ledger's unique key covers
 *   (teacher, invoice, month, reason, subject) and this uses a fixed month marker with
 *   REASON_ADJUSTMENT, so running it twice credits once. Re-run it safely.
 * - Records whose invoice no longer exists are SKIPPED by default: an orphaned record is
 *   usually a deleted invoice whose wallet credit was already reversed, so paying it
 *   would hand back money that was deliberately taken away. --include-orphans overrides.
 */
class SettleStrandedPayouts extends Command
{
    /**
     * Written into teacher_wallet_entries.month to make this command's credits idempotent.
     *
     * MUST stay at most 7 characters — that column is varchar(7), sized for 'YYYY-MM'.
     * A longer value is silently rejected by MySQL as "Data too long", which would abort
     * the settlement partway through.
     */
    private const SETTLEMENT_MARKER = 'SETTLED';

    protected $signature = 'payouts:settle-stranded
                            {--apply : Actually credit wallets. Without this the command only reports.}
                            {--include-orphans : Also settle records whose invoice has been deleted (see the class docblock).}
                            {--threshold=0.01 : Ignore gaps smaller than this}';

    protected $description = 'Credit teachers the earned commission that no future month will ever deliver';

    public function handle(TeacherWalletService $wallet): int
    {
        $apply = (bool) $this->option('apply');
        $includeOrphans = (bool) $this->option('include-orphans');
        $threshold = (float) $this->option('threshold');

        $rows = TeacherMembershipPayment::query()
            ->where('is_active', true)
            ->whereColumn('total_paid_to_teacher', '<', 'total_teacher_amount')
            ->get();

        $settlements = [];
        $notYetDue = 0.0;
        $orphanSkipped = 0;
        $orphanAmount = 0.0;

        $liveInvoiceIds = DB::table('invoices')->whereNull('deleted_at')->pluck('id')->flip();

        foreach ($rows as $record) {
            $gap = round((float) $record->total_teacher_amount - (float) $record->total_paid_to_teacher, 2);

            if ($gap <= $threshold) {
                continue;
            }

            $pending = is_array($record->months_rest_not_paid_yet) ? $record->months_rest_not_paid_yet : [];
            $scheduled = round((float) $record->monthly_teacher_amount * count($pending), 2);
            $stranded = round($gap - $scheduled, 2);

            $notYetDue += min($gap, max($scheduled, 0));

            if ($stranded <= $threshold) {
                continue;
            }

            $isOrphan = $record->invoice_id !== null && ! isset($liveInvoiceIds[$record->invoice_id]);

            if ($isOrphan && ! $includeOrphans) {
                $orphanSkipped++;
                $orphanAmount += $stranded;

                continue;
            }

            $settlements[] = [
                'record' => $record,
                'stranded' => $stranded,
                'orphan' => $isOrphan,
            ];
        }

        $total = round(array_sum(array_column($settlements, 'stranded')), 2);

        $this->info($apply ? 'SETTLING stranded teacher payouts.' : 'DRY RUN — no wallet will be changed. Add --apply to settle.');
        $this->newLine();

        $this->table(['Metric', 'Value'], [
            ['Records with a gap',              $rows->count()],
            ['Not yet due (cron will pay)',     number_format($notYetDue, 2)],
            ['Orphaned records skipped',        $orphanSkipped],
            ['  -> amount skipped',             number_format($orphanAmount, 2)],
            ['Records to settle',               count($settlements)],
            ['TOTAL TO CREDIT',                 number_format($total, 2)],
        ]);

        if ($settlements === []) {
            $this->newLine();
            $this->info('Nothing stranded. No action needed.');

            return self::SUCCESS;
        }

        // Per-teacher summary — this is what a human actually checks before approving.
        $byTeacher = [];
        foreach ($settlements as $s) {
            $id = $s['record']->teacher_id;
            $byTeacher[$id] = ($byTeacher[$id] ?? 0) + $s['stranded'];
        }

        $names = Teacher::whereIn('id', array_keys($byTeacher))
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($t) => [$t->id => trim("{$t->first_name} {$t->last_name}")]);

        $this->newLine();
        $this->table(
            ['Teacher', 'Name', 'Amount'],
            collect($byTeacher)->map(fn ($amount, $id) => [
                $id, $names[$id] ?? '?', number_format(round($amount, 2), 2),
            ])->values()->all()
        );

        if (! $apply) {
            $this->newLine();
            $this->line('Re-run with --apply to credit these wallets.');

            return self::SUCCESS;
        }

        // ---- apply ----------------------------------------------------------
        $credited = 0;
        $skippedDuplicate = 0;
        $movedTotal = 0.0;

        foreach ($settlements as $s) {
            /** @var TeacherMembershipPayment $record */
            $record = $s['record'];
            $amount = $s['stranded'];

            $teacher = Teacher::find($record->teacher_id);

            if (! $teacher) {
                $this->warn("record {$record->id}: teacher {$record->teacher_id} not found — skipped");

                continue;
            }

            DB::transaction(function () use ($wallet, $teacher, $record, $amount, &$credited, &$skippedDuplicate, &$movedTotal) {
                // Roster gate (prod Sept 2026): settling pays whoever holds the
                // record, and records outlive roster removals. A removed teacher's
                // gap is not stranded earnings — it is money that must not move
                // without a human decision. Mirror RepairLedgerShortfall, not the
                // old settle-everything behavior.
                if (! LedgerShortfall::isAssigned($record)) {
                    Log::warning('payouts:settle-stranded skipped unassigned teacher', [
                        'record_id' => $record->id,
                        'teacher_id' => $record->teacher_id,
                        'invoice_id' => $record->invoice_id,
                    ]);

                    return;
                }

                // Fixed marker rather than now()->format('Y-m'): it makes the idempotency
                // key stable across runs, so re-running this command in a different month
                // cannot credit the same record a second time.
                //
                // `month` is varchar(7) to hold 'YYYY-MM'. self::SETTLEMENT_MARKER is
                // exactly 7 characters and can never collide with a real month.
                $ok = $wallet->credit(
                    $teacher,
                    $amount,
                    TeacherWalletEntry::REASON_ADJUSTMENT,
                    $record->id,
                    self::SETTLEMENT_MARKER,
                    $record->invoice_id,
                    "stranded commission settled (payout record {$record->id})",
                    $record->teacher_subject
                );

                if (! $ok) {
                    $skippedDuplicate++;

                    return;
                }

                // Keep the payout record consistent with the money that just moved, or the
                // next audit reports the same gap again.
                $record->increment('total_paid_to_teacher', $amount);

                $credited++;
                $movedTotal += $amount;
            });
        }

        Log::info('payouts:settle-stranded applied', [
            'records_credited' => $credited,
            'records_already_settled' => $skippedDuplicate,
            'total_credited' => round($movedTotal, 2),
        ]);

        $this->newLine();
        $this->info("Credited {$credited} record(s), total ".number_format($movedTotal, 2).'.');

        if ($skippedDuplicate > 0) {
            $this->line("{$skippedDuplicate} record(s) were already settled by a previous run — skipped.");
        }

        $this->newLine();
        $this->line('Run `php artisan wallet:check` to confirm the ledger still reconciles.');

        return self::SUCCESS;
    }
}
