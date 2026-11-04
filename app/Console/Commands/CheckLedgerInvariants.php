<?php

namespace App\Console\Commands;

use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;
use App\Services\TeacherMembershipPaymentService;
use App\Support\TeacherEarnings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * READ-ONLY: the wallet ledger is the single truth of teacher money. These are
 * the rules that make it so; every one must hold every night.
 *
 *   I1  wallet = Σ ledger                          (a wallet moved outside the ledger)
 *   I2  each payout record holds what is due       (payouts:settle-to-ledger has work)
 *   I3  per invoice: Σ held by teachers ≤ amountPaid (paid out more than the student paid — CRC 7096)
 *   I4  every billed subject has a teacher record   (money nobody was paid — CRC 8128)
 *   I6  Gains Mensuels: Gagné − Versé = Solde        (the statement drifted from the wallet)
 *
 * Nov 2026: each of these was found broken in production by hand. Never again by hand.
 */
class CheckLedgerInvariants extends Command
{
    protected $signature = 'ledger:invariants {--teacher= : Only this teacher id}';

    protected $description = 'Read-only: check that the wallet ledger is the single truth (I1-I4, I6)';

    public function handle(TeacherMembershipPaymentService $service): int
    {
        $failures = [];
        $teachers = Teacher::query()
            ->when($this->option('teacher'), fn ($q, $id) => $q->whereKey((int) $id))
            ->orderBy('id')
            ->get();
        $teacherIds = $teachers->pluck('id');

        // I1 — the cached wallet is the ledger's sum.
        $sums = TeacherWalletEntry::whereIn('teacher_id', $teacherIds)
            ->groupBy('teacher_id')
            ->selectRaw('teacher_id, ROUND(SUM(amount), 2) AS total')
            ->pluck('total', 'teacher_id');

        foreach ($teachers as $teacher) {
            $sum = round((float) ($sums[$teacher->id] ?? 0), 2);

            if (abs($sum - round((float) $teacher->wallet, 2)) >= 0.01) {
                $failures[] = ['I1', "teacher {$teacher->id}", "wallet {$teacher->wallet} ≠ ledger {$sum}"];
            }
        }

        // I2 — every post-ledger payout record already matches the ledger (dry run).
        $records = TeacherMembershipPayment::query()
            ->whereNotNull('invoice_id')
            ->whereIn('teacher_id', $teacherIds)
            ->orderBy('id')
            ->get();

        foreach ($records as $record) {
            $row = $service->ledgerRepair($record, false);

            if ($row) {
                $failures[] = ['I2', "record {$row['record_id']} (invoice {$row['invoice_id']})", "{$row['case']}: holds {$row['held']}, due {$row['due']}"];
            }
        }

        // I3 — teachers never hold more than the student paid for an invoice.
        $cashOut = [TeacherWalletEntry::REASON_PAYOUT];

        if (defined(TeacherWalletEntry::class.'::REASON_PAYOUT_LAST_YEAR')) {
            $cashOut[] = TeacherWalletEntry::REASON_PAYOUT_LAST_YEAR;
        }

        $overPaid = DB::table('teacher_wallet_entries as e')
            ->join('invoices as i', 'i.id', '=', 'e.invoice_id')
            ->whereNotIn('e.reason', $cashOut)
            ->when($this->option('teacher'), fn ($q, $id) => $q->whereIn('e.invoice_id', TeacherWalletEntry::where('teacher_id', (int) $id)->whereNotNull('invoice_id')->select('invoice_id')))
            ->groupBy('e.invoice_id', 'i.amountPaid')
            ->havingRaw('SUM(e.amount) > i.amountPaid + 0.01')
            ->selectRaw('e.invoice_id, ROUND(SUM(e.amount), 2) AS held, i.amountPaid AS paid')
            ->get();

        foreach ($overPaid as $row) {
            $failures[] = ['I3', "invoice {$row->invoice_id}", "teachers hold {$row->held}, the student paid {$row->paid}"];
        }

        // I4 + I6 — the statement: anomalies, and Gagné − Versé = Solde.
        foreach ($teachers as $teacher) {
            $statement = TeacherEarnings::statement($teacher);

            foreach ($statement['anomalies'] as $anomaly) {
                $failures[] = ['I4', "teacher {$teacher->id}, invoice {$anomaly['invoice_id']}", "{$anomaly['kind']} ({$anomaly['subject']}): {$anomaly['expected']} DH"];
            }

            if (! $statement['summary']['reconciles']) {
                $failures[] = ['I6', "teacher {$teacher->id}", "statement balance {$statement['summary']['balance']} ≠ wallet {$statement['summary']['wallet']}"];
            }
        }

        if ($failures === []) {
            $this->info("All ledger invariants hold ({$teachers->count()} teachers, {$records->count()} payout records).");

            return self::SUCCESS;
        }

        Log::error('ledger:invariants — the wallet ledger is not the single truth', [
            'failures' => count($failures),
            'by_rule' => collect($failures)->countBy(0)->all(),
        ]);

        $this->error(count($failures).' invariant failure(s):');
        $this->table(['Rule', 'Where', 'What'], $failures);

        return self::FAILURE;
    }
}
