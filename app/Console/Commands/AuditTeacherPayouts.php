<?php

namespace App\Console\Commands;

use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY audit of the teacher payout ledger.
 *
 * Run this BEFORE changing any payout formula. Once the formula changes you can no
 * longer distinguish the fix's effect from historical drift, so this snapshot is the
 * only baseline you will get.
 *
 *   php artisan payouts:audit
 *   php artisan payouts:audit --csv=storage/app/payout-audit.csv
 */
class AuditTeacherPayouts extends Command
{
    protected $signature = 'payouts:audit
                            {--csv= : Write the per-teacher breakdown to this CSV path}
                            {--threshold=0.01 : Ignore discrepancies smaller than this}';

    protected $description = 'Read-only report of teacher payout discrepancies (overpaid, underpaid, orphaned, negative wallets)';

    public function handle(): int
    {
        $threshold = (float) $this->option('threshold');

        $this->info('Auditing teacher payouts — this command does not write to the database.');
        $this->newLine();

        // ---- 1. Records paid more than their own computed commission -------------
        $overpaidRows = TeacherMembershipPayment::query()
            ->select('teacher_id')
            ->selectRaw('COUNT(*) as records')
            ->selectRaw('SUM(total_paid_to_teacher - total_teacher_amount) as excess')
            ->whereColumn('total_paid_to_teacher', '>', 'total_teacher_amount')
            ->groupBy('teacher_id')
            ->havingRaw('SUM(total_paid_to_teacher - total_teacher_amount) > ?', [$threshold])
            ->get();

        // ---- 2. Records still owed money ----------------------------------------
        $underpaidRows = TeacherMembershipPayment::query()
            ->select('teacher_id')
            ->selectRaw('COUNT(*) as records')
            ->selectRaw('SUM(total_teacher_amount - total_paid_to_teacher) as shortfall')
            ->where('is_active', true)
            ->whereColumn('total_paid_to_teacher', '<', 'total_teacher_amount')
            ->groupBy('teacher_id')
            ->havingRaw('SUM(total_teacher_amount - total_paid_to_teacher) > ?', [$threshold])
            ->get();

        // ---- 3. Fully paid but still queued for the monthly cron ----------------
        // These are the rows the monthly cron would credit AGAIN.
        // NOTE: months_rest_not_paid_yet is a native MySQL JSON column, so `!= '[]'` does
        // NOT reliably exclude empty arrays — use JSON_LENGTH.
        $doublePayExposure = TeacherMembershipPayment::query()
            ->where('is_active', true)
            ->whereColumn('total_paid_to_teacher', '>=', 'total_teacher_amount')
            ->where('total_teacher_amount', '>', 0)
            ->whereRaw('JSON_LENGTH(COALESCE(months_rest_not_paid_yet, JSON_ARRAY())) > 0')
            ->get(['id', 'teacher_id', 'invoice_id', 'monthly_teacher_amount', 'months_rest_not_paid_yet']);

        $exposureAmount = $doublePayExposure->sum(
            fn ($r) => (float) $r->monthly_teacher_amount * count($r->months_rest_not_paid_yet ?? [])
        );

        // ---- 4. Orphaned records (invoice deleted or missing) -------------------
        $orphaned = TeacherMembershipPayment::query()
            ->whereNotNull('invoice_id')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('invoices')
                ->whereColumn('invoices.id', 'teacher_membership_payments.invoice_id')
                ->whereNull('invoices.deleted_at'))
            ->count();

        // ---- 5. Duplicate records for the same (invoice, teacher, subject) ------
        $duplicates = TeacherMembershipPayment::query()
            ->select('invoice_id', 'teacher_id', 'teacher_subject')
            ->selectRaw('COUNT(*) as copies')
            ->groupBy('invoice_id', 'teacher_id', 'teacher_subject')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        // ---- 6. Negative or suspicious wallets ----------------------------------
        $negativeWallets = Teacher::where('wallet', '<', 0)->get(['id', 'first_name', 'last_name', 'wallet']);

        // ---- 7. Ledger-vs-record shortfalls (Sept 2026 class) -------------------
        // A record says the teacher was paid X for a live invoice; the ledger must
        // agree. Catches reactivated-without-money rows and reversed-never-restored
        // rows that sections 1-6 structurally cannot see. @see LedgerShortfall.
        $shortfalls = \App\Support\LedgerShortfall::find($threshold);
        $shortfallTotal = round((float) $shortfalls->sum('shortfall'), 2);

        // ---- Report -------------------------------------------------------------
        $this->table(['Metric', 'Value'], [
            ['Teachers overpaid',                    $overpaidRows->count()],
            ['Total excess paid',                    number_format((float) $overpaidRows->sum('excess'), 2)],
            ['Teachers underpaid',                   $underpaidRows->count()],
            ['Total shortfall owed',                 number_format((float) $underpaidRows->sum('shortfall'), 2)],
            ['Records exposed to double-pay',        $doublePayExposure->count()],
            ['  -> amount the next cron would pay',  number_format($exposureAmount, 2)],
            ['Orphaned records (invoice gone)',      $orphaned],
            ['Duplicate (invoice,teacher,subject)',  $duplicates->count()],
            ['Teachers with a negative wallet',      $negativeWallets->count()],
            ['Ledger-vs-record shortfall rows',      $shortfalls->count()],
            ['  -> shortfall still owed',            number_format($shortfallTotal, 2)],
        ]);

        if ($negativeWallets->isNotEmpty()) {
            $this->newLine();
            $this->warn('Negative wallets block ALL payouts for these teachers:');
            $this->table(['ID', 'Name', 'Wallet'], $negativeWallets->map(fn ($t) => [
                $t->id, trim("{$t->first_name} {$t->last_name}"), $t->wallet,
            ])->all());
        }

        if ($duplicates->isNotEmpty()) {
            $this->newLine();
            $this->warn('Duplicates must be resolved before a unique constraint can be added:');
            $this->table(['Invoice', 'Teacher', 'Subject', 'Copies'], $duplicates->take(25)->map(fn ($d) => [
                $d->invoice_id, $d->teacher_id, $d->teacher_subject, $d->copies,
            ])->all());
            if ($duplicates->count() > 25) {
                $this->line('  ... and '.($duplicates->count() - 25).' more.');
            }
        }

        if ($shortfalls->isNotEmpty()) {
            $this->newLine();
            $this->warn('Ledger-vs-record shortfalls: the record claims money the ledger never paid.');
            $this->warn('AUTO = teacher still assigned (safe to repair). REVIEW = removed teacher (staffing decision).');
            $names = Teacher::withTrashed()->whereIn('id', $shortfalls->pluck('teacher_id')->unique())
                ->get(['id', 'first_name', 'last_name'])
                ->mapWithKeys(fn ($t) => [$t->id => trim("{$t->first_name} {$t->last_name}")]);
            $this->table(
                ['Record', 'Teacher', 'Invoice', 'Subject', 'Claim', 'Ledger', 'Short', 'Action'],
                $shortfalls->take(50)->map(fn ($s) => [
                    $s['record']->id,
                    $names[$s['teacher_id']] ?? ('#'.$s['teacher_id']),
                    $s['invoice_id'],
                    $s['subject'],
                    number_format($s['claim'], 2),
                    number_format($s['ledger'], 2),
                    number_format($s['shortfall'], 2),
                    $s['assigned'] ? 'AUTO' : 'REVIEW',
                ])->all()
            );
            if ($shortfalls->count() > 50) {
                $this->line('  ... and '.($shortfalls->count() - 50).' more.');
            }
        }

        if ($path = $this->option('csv')) {
            $this->writeCsv($path, $overpaidRows, $underpaidRows);
            $this->newLine();
            $this->info("Per-teacher breakdown written to {$path}");
        }

        $this->newLine();
        $this->line('Keep this output. It is the baseline for measuring any payout correction.');

        return self::SUCCESS;
    }

    private function writeCsv(string $path, $overpaid, $underpaid): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $names = Teacher::whereIn('id', $overpaid->pluck('teacher_id')->merge($underpaid->pluck('teacher_id')))
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($t) => [$t->id => trim("{$t->first_name} {$t->last_name}")]);

        $handle = fopen($path, 'w');
        fputcsv($handle, ['teacher_id', 'teacher_name', 'kind', 'records', 'amount']);

        foreach ($overpaid as $row) {
            fputcsv($handle, [$row->teacher_id, $names[$row->teacher_id] ?? '?', 'overpaid', $row->records, round((float) $row->excess, 2)]);
        }
        foreach ($underpaid as $row) {
            fputcsv($handle, [$row->teacher_id, $names[$row->teacher_id] ?? '?', 'underpaid', $row->records, round((float) $row->shortfall, 2)]);
        }

        fclose($handle);
    }
}
