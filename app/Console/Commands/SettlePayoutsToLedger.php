<?php

namespace App\Console\Commands;

use App\Models\TeacherMembershipPayment;
use App\Services\TeacherMembershipPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One-off correction for the Nov 2026 wallet audit: settle every post-ledger
 * payout record to ledger truth with the same rules the live paths now use.
 *
 * What it fixes (see TeacherMembershipPaymentService::ledgerRepair()):
 *   - over-credits from edits after the monthly job paid the month, and from
 *     lower-then-raise edits on past-month invoices;
 *   - money left on deleted invoices whose reversal under-counted (no subject);
 *   - removed teachers still holding a month their replacement was paid for;
 *   - shortfalls (a kept teacher reversed and never paid back).
 *
 * Over-credits come back as `manual.adjustment` ("Ajustement" on the teacher's
 * page). When the money was already paid out in cash the wallet goes negative:
 * a recorded debt the next commissions absorb. Nothing is asked back in cash.
 *
 *   php artisan payouts:settle-to-ledger             # dry run, writes nothing
 *   php artisan payouts:settle-to-ledger --confirm   # take a DB backup first
 */
class SettlePayoutsToLedger extends Command
{
    protected $signature = 'payouts:settle-to-ledger
                            {--invoice= : Only this invoice id}
                            {--confirm : Write the corrections (dry run otherwise)}';

    protected $description = 'Settle payout records and wallets to ledger truth (dry-run unless --confirm)';

    public function handle(TeacherMembershipPaymentService $service): int
    {
        $apply = (bool) $this->option('confirm');

        // One run at a time: two overlapping --confirm runs would both read the
        // same "held" and correct it twice.
        $lock = Cache::lock('payouts:settle-to-ledger', 1800);

        if (! $lock->get()) {
            $this->error('Another payouts:settle-to-ledger run is in progress.');

            return self::FAILURE;
        }

        try {
            $ids = TeacherMembershipPayment::query()
                ->when($this->option('invoice'), fn ($q, $id) => $q->where('invoice_id', (int) $id))
                ->whereNotNull('invoice_id')
                ->orderBy('invoice_id')
                ->orderBy('id')
                ->pluck('id');

            // Each record is re-read under a row lock inside its own transaction, so
            // what it holds is measured and corrected atomically — a monthly run or
            // an invoice edit landing mid-repair cannot be corrected a second time.
            $rows = $ids->map(fn ($id) => DB::transaction(function () use ($id, $service, $apply) {
                $record = TeacherMembershipPayment::whereKey($id)->lockForUpdate()->first();

                return $record ? $service->ledgerRepair($record, $apply) : null;
            }))->filter()->values();
        } finally {
            $lock->release();
        }

        if ($rows->isEmpty()) {
            $this->info('Every post-ledger payout record already matches the ledger.');

            return self::SUCCESS;
        }

        $this->table(
            ['Record', 'Teacher', 'Invoice', 'Subject', 'Case', 'Owed', 'Held', 'Due', 'Move'],
            $rows->map(fn ($r) => [
                $r['record_id'], $r['teacher_id'], $r['invoice_id'], $r['subject'], $r['case'],
                number_format($r['owed'], 2), number_format($r['held'], 2),
                number_format($r['due'], 2), number_format($r['delta'], 2),
            ])->all()
        );

        $recover = round((float) $rows->where('delta', '<', 0)->sum('delta'), 2);
        $pay = round((float) $rows->where('delta', '>', 0)->sum('delta'), 2);

        $this->line('Recovered from wallets: '.number_format(abs($recover), 2).' DH');
        $this->line('Paid to wallets:        '.number_format($pay, 2).' DH');

        if (! $apply) {
            $this->info('Dry run — nothing written. Take a backup, then re-run with --confirm.');

            return self::SUCCESS;
        }

        Log::warning('payouts:settle-to-ledger applied', [
            'records' => $rows->count(),
            'recovered' => $recover,
            'paid' => $pay,
        ]);

        $this->info('Applied '.$rows->count().' correction(s).');

        return self::SUCCESS;
    }
}
