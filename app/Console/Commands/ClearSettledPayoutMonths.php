<?php

namespace App\Console\Commands;

use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Clear queued months from payout records that are already paid in full.
 *
 *   php artisan payouts:clear-settled-months           # DRY RUN
 *   php artisan payouts:clear-settled-months --apply
 *
 * MOVES NO MONEY. It only empties months_rest_not_paid_yet on records where
 * total_paid_to_teacher already covers total_teacher_amount.
 *
 * WHY THIS EXISTS SEPARATELY FROM THE GUARD IN processMonthlyPayments()
 * ---------------------------------------------------------------------
 * That guard stops the cron paying a settled record again, and clears the month as it
 * goes. But it only fires when the cron actually reaches that month — so until then
 * `payouts:audit` keeps reporting the exposure, and the fix depends on the cron running
 * at all. A standing alarm that nobody can clear is an alarm people learn to ignore.
 *
 * This drains the stale queue immediately, so the audit tells the truth and the exposure
 * is gone by construction rather than by scheduled behaviour.
 *
 * A record reaches this state legitimately: an invoice edit recalculates the total, or
 * reconcilePaidMonthsForInvoice() pays the whole commission up front, while the month
 * queue is left populated.
 */
class ClearSettledPayoutMonths extends Command
{
    protected $signature = 'payouts:clear-settled-months
                            {--apply : Actually clear the queues. Without this the command only reports.}';

    protected $description = 'Empty the pending-month queue on payout records that are already paid in full (moves no money)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $records = TeacherMembershipPayment::query()
            ->where('is_active', true)
            ->whereColumn('total_paid_to_teacher', '>=', 'total_teacher_amount')
            ->where('total_teacher_amount', '>', 0)
            ->whereRaw('JSON_LENGTH(COALESCE(months_rest_not_paid_yet, JSON_ARRAY())) > 0')
            ->get();

        $this->info($apply
            ? 'Clearing stale month queues. No wallet is touched.'
            : 'DRY RUN — nothing will be changed. Add --apply to clear.');
        $this->newLine();

        if ($records->isEmpty()) {
            $this->info('No settled record has months still queued. Nothing to do.');

            return self::SUCCESS;
        }

        $names = Teacher::whereIn('id', $records->pluck('teacher_id')->unique())
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($t) => [$t->id => trim("{$t->first_name} {$t->last_name}")]);

        $exposure = 0.0;

        $this->table(
            ['Record', 'Teacher', 'Invoice', 'Owed', 'Paid', 'Monthly', 'Queued months'],
            $records->map(function ($r) use ($names, &$exposure) {
                $months = is_array($r->months_rest_not_paid_yet) ? $r->months_rest_not_paid_yet : [];
                $exposure += round((float) $r->monthly_teacher_amount * count($months), 2);

                return [
                    $r->id,
                    $names[$r->teacher_id] ?? $r->teacher_id,
                    $r->invoice_id ?? '-',
                    round((float) $r->total_teacher_amount, 2),
                    round((float) $r->total_paid_to_teacher, 2),
                    round((float) $r->monthly_teacher_amount, 2),
                    implode(', ', $months),
                ];
            })->all()
        );

        $this->newLine();
        $this->warn('Exposure removed: '.number_format($exposure, 2).' that the cron would otherwise have paid again.');

        if (! $apply) {
            $this->newLine();
            $this->line('Re-run with --apply to clear these queues.');

            return self::SUCCESS;
        }

        $cleared = 0;

        foreach ($records as $record) {
            DB::transaction(function () use ($record, &$cleared) {
                $locked = TeacherMembershipPayment::whereKey($record->id)->lockForUpdate()->first();

                if (! $locked) {
                    return;
                }

                // Re-check under the lock: an invoice edit between the SELECT above and
                // here could have raised total_teacher_amount, making the queued months
                // genuinely owed again.
                if ((float) $locked->total_paid_to_teacher < (float) $locked->total_teacher_amount) {
                    return;
                }

                Log::info('Cleared stale pending months from a settled payout record', [
                    'record_id' => $locked->id,
                    'teacher_id' => $locked->teacher_id,
                    'invoice_id' => $locked->invoice_id,
                    'months_cleared' => $locked->months_rest_not_paid_yet,
                    'total_teacher_amount' => (float) $locked->total_teacher_amount,
                    'total_paid_to_teacher' => (float) $locked->total_paid_to_teacher,
                ]);

                $locked->update(['months_rest_not_paid_yet' => []]);
                $cleared++;
            });
        }

        $this->newLine();
        $this->info("Cleared {$cleared} record(s).");
        $this->line('Run `php artisan payouts:audit` — double-pay exposure should now be 0.');

        return self::SUCCESS;
    }
}
