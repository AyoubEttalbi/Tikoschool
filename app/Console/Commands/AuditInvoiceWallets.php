<?php

namespace App\Console\Commands;

use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Per-(teacher, invoice) reconciliation: payment-record totals vs ledger net.
 *
 * wallet:check proves the cached wallet equals the ledger, and payouts:audit
 * proves the payout formula — but neither sees money the ledger itself got
 * wrong. Prod Sept 2026, invoice 7027: one credit (+100), two reversals
 * (−100, −100). Cached wallet and ledger agreed at −100 while the record said
 * the teacher was whole, and nothing flagged it.
 *
 *   php artisan wallet:audit-invoices                 # all teachers, read-only
 *   php artisan wallet:audit-invoices --teacher=2     # one teacher
 *   php artisan wallet:audit-invoices --tolerance=0.1 # DH
 *
 * Comparator is the record's total_paid_to_teacher against the attributed
 * ledger net (same teacher + invoice, cash payouts excluded — a payout is
 * money handed over, orthogonal to what the invoice still holds). Slices are
 * per subject (record identity is teacher + invoice + subject); a group-level
 * check per (teacher, invoice) catches skew the slices miss. Engine rounds
 * percentages to 2 decimals before multiplying while the gains display uses
 * full precision, so a small tolerance is structural, not sloppiness.
 *
 * Direction covered: records vs ledger. Ledger rows with no surviving record
 * (e.g. credits whose record was hard-deleted) are invisible here by design —
 * wallet:check owns the ledger side.
 *
 * Read-only and manual-only by design: run it after deploys and whenever the
 * earnings displays disagree with wallets. Not on the scheduler — it reports,
 * a human decides.
 */
class AuditInvoiceWallets extends Command
{
    protected $signature = 'wallet:audit-invoices
                            {--teacher= : Only audit one teacher id}
                            {--tolerance=0.05 : Ignore absolute differences at or below this many DH}';

    protected $description = 'Reconcile per-invoice payment records against the wallet ledger';

    public function handle(): int
    {
        $tolerance = max(0.0, (float) $this->option('tolerance'));

        // One grouped read: per-record SUM queries would be N+1 on prod.
        // Payout reasons are cash handed over, orthogonal to what the invoice
        // still holds. Kept dynamic: not every project defines every reason.
        $cashOut = [TeacherWalletEntry::REASON_PAYOUT];

        if (defined(TeacherWalletEntry::class.'::REASON_PAYOUT_LAST_YEAR')) {
            $cashOut[] = TeacherWalletEntry::REASON_PAYOUT_LAST_YEAR;
        }

        // Sliced per (teacher, invoice, subject): record identity includes the
        // subject, so a healthy two-subject invoice must not flag. The '' key
        // collects legacy rows whose subject was never stamped.
        $slicesByInvoice = TeacherWalletEntry::query()
            ->select('teacher_id', 'invoice_id', 'teacher_subject', DB::raw('SUM(amount) as net'))
            ->whereNotNull('invoice_id')
            ->whereNotIn('reason', $cashOut)
            ->when($this->option('teacher'), fn ($q, $id) => $q->where('teacher_id', $id))
            ->groupBy('teacher_id', 'invoice_id', 'teacher_subject')
            ->get()
            ->groupBy(fn ($row) => $row->teacher_id.'.'.$row->invoice_id);

        // Rows with no attributed entries are only suspicious on invoices born
        // after the ledger started recording: older rows predate it entirely.
        $ledgerStart = TeacherWalletEntry::min('created_at');

        $records = TeacherMembershipPayment::query()
            ->with(['invoice' => fn ($q) => $q->withTrashed()->select('id', 'created_at'), 'teacher:id'])
            ->when($this->option('teacher'), fn ($q, $id) => $q->where('teacher_id', $id))
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($record) => $record->teacher_id.'.'.$record->invoice_id);

        $flags = [];

        foreach ($records as $groupKey => $group) {
            /** @var \Illuminate\Support\Collection<int, \App\Models\TeacherMembershipPayment> $group */
            $slices = $slicesByInvoice->get($groupKey, collect());
            $poolNet = round((float) $slices->sum('net'), 2);
            $poolTotal = round((float) $group->sum('total_paid_to_teacher'), 2);

            if ($slices->isEmpty()) {
                // Nothing was ever at stake — clean, not missing.
                if (abs($poolTotal) <= $tolerance) {
                    continue;
                }

                if ($ledgerStart && $group->first()->invoice && $group->first()->invoice->created_at > $ledgerStart) {
                    $flags[] = [
                        $group->first()->teacher_id,
                        $group->first()->invoice_id,
                        number_format($poolTotal, 2),
                        '0.00',
                        'no-ledger-activity',
                    ];
                }

                continue;
            }

            // Pool reconciles: money is right, per-subject attribution is
            // cosmetic — no rows. Otherwise one group row plus per-slice
            // detail (a second independent flag for the same dirhams would
            // double-count the incident).
            if (abs($poolTotal - $poolNet) <= $tolerance) {
                continue;
            }

            $flags[] = [
                $group->first()->teacher_id,
                $group->first()->invoice_id,
                number_format($poolTotal, 2),
                number_format($poolNet, 2),
                number_format(round($poolTotal - $poolNet, 2), 2),
            ];

            foreach ($slices as $slice) {
                $record = $group->firstWhere('teacher_subject', $slice->teacher_subject);

                $flags[] = [
                    $group->first()->teacher_id,
                    $group->first()->invoice_id,
                    $record ? number_format((float) ($record->total_paid_to_teacher ?? 0), 2) : '—',
                    number_format((float) $slice->net, 2),
                    $record
                        ? 'detail ('.$slice->teacher_subject.')'
                        : 'orphan credit ('.$slice->teacher_subject.')',
                ];
            }
        }

        if ($flags === []) {
            $this->info('All invoice payment records reconcile against the ledger.');

            return self::SUCCESS;
        }

        Log::error('wallet:audit-invoices — payment records disagree with the ledger', [
            'flagged' => count($flags),
            'tolerance' => $tolerance,
        ]);

        $this->error(count($flags).' record(s) disagree with the ledger:');
        $this->table(
            ['Teacher', 'Invoice', 'Record total', 'Ledger net', 'Diff / status'],
            $flags
        );

        return self::FAILURE;
    }
}
