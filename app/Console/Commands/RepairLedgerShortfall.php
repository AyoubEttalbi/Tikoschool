<?php

namespace App\Console\Commands;

use App\Models\Teacher;
use App\Models\TeacherWalletEntry;
use App\Services\TeacherWalletService;
use App\Support\LedgerShortfall;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repair proven ledger-vs-record shortfalls (prod Sept 2026 class).
 *
 *   php artisan payouts:repair-shortfall                 # dry run: print the plan
 *   php artisan payouts:repair-shortfall --apply         # credit assigned rows
 *   php artisan payouts:repair-shortfall --apply --only=2,6
 *
 * DRY RUN IS THE DEFAULT. --apply only credits rows whose teacher is STILL
 * assigned to the membership (AUTO). Removed-teacher rows (REVIEW) are never
 * auto-credited: paying them would pay the same hours twice, since the
 * replacement teacher was already credited — that is a staffing decision.
 *
 * Once the staffing decision is "they never taught it", --deactivate-review
 * retires those zombie rows (active + paid-in-full, wallet short) to inactive
 * without moving a dirham, so no monitor or future reprocessing ever trusts
 * them again.
 *
 * Each credit carries (teacher, invoice, month, repair.shortfall, subject), so a
 * re-run is refused by the idempotency key instead of paying twice. TAKE A BACKUP
 * BEFORE --apply.
 */
class RepairLedgerShortfall extends Command
{
    protected $signature = 'payouts:repair-shortfall
                            {--apply : Actually credit the wallets (default is a dry run)}
                            {--deactivate-review : Retire REVIEW (removed-teacher) rows to inactive without moving money}
                            {--only= : Comma-separated teacher ids to restrict the run to}
                            {--force : Skip the confirmation prompt}
                            {--threshold=0.01 : Ignore discrepancies smaller than this}';

    protected $description = 'Dry-run (default) or apply repair credits for ledger-vs-record shortfalls';

    public function handle(TeacherWalletService $wallet): int
    {
        $threshold = (float) $this->option('threshold');
        $only = $this->option('only')
            ? array_map('intval', explode(',', (string) $this->option('only')))
            : null;

        $rows = LedgerShortfall::find($threshold)
            ->when($only, fn ($c) => $c->whereIn('teacher_id', $only))
            ->values();

        if ($rows->isEmpty()) {
            $this->info('No ledger-vs-record shortfalls. Nothing to repair.');

            return self::SUCCESS;
        }

        $names = Teacher::withTrashed()->whereIn('id', $rows->pluck('teacher_id')->unique())
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($t) => [$t->id => trim("{$t->first_name} {$t->last_name}")]);

        $this->table(
            ['Record', 'Teacher', 'Invoice', 'Subject', 'Claim', 'Ledger', 'Short', 'Action'],
            $rows->map(fn ($s) => [
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

        $auto = $rows->where('assigned', true);
        $review = $rows->where('assigned', false);

        $this->line('AUTO total: '.number_format((float) $auto->sum('shortfall'), 2).' DH over '.$auto->count().' rows.');
        $this->line('REVIEW total (never auto-credited): '.number_format((float) $review->sum('shortfall'), 2).' DH over '.$review->count().' rows.');

        if (! $this->option('apply') && ! $this->option('deactivate-review')) {
            $this->newLine();
            $this->info('Dry run — no wallets touched. Re-run with --apply to credit the AUTO rows.');

            return self::SUCCESS;
        }

        $failed = 0;

        if ($this->option('apply')) {
            if ($auto->isEmpty()) {
                $this->info('No AUTO rows to credit.');
            } else {
                if (! $this->option('force') && ! $this->confirm('Credit '.$auto->count().' row(s) for '.number_format((float) $auto->sum('shortfall'), 2).' DH total? A backup must exist.', false)) {
                    return self::FAILURE;
                }

                $applied = 0;
                $skipped = 0;

                foreach ($auto as $row) {
                    $result = $this->creditRow($wallet, $row);

                    match ($result) {
                        'applied' => $applied++,
                        'skipped' => $skipped++,
                        default => $failed++,
                    };
                }

                $this->newLine();
                $this->info("Applied: {$applied}, already-repaired (skipped): {$skipped}, failed: {$failed}.");
            }
        }

        if (! $this->option('deactivate-review')) {
            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        }

        $deactivateExit = $this->deactivateReview($review);

        return ($failed > 0 || $deactivateExit !== self::SUCCESS) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Credit one AUTO row. Returns 'applied', 'skipped' (idempotency key — the
     * safe re-run path) or 'failed'.
     */
    private function creditRow(TeacherWalletService $wallet, array $row): string
    {
        $record = $row['record'];
        $teacher = Teacher::withTrashed()->find($row['teacher_id']);

        if (! $teacher) {
            $this->error("Record {$record->id}: teacher {$row['teacher_id']} not found.");

            return 'failed';
        }

        // Deterministic, non-null month: the idempotency key treats NULL as
        // distinct, so a null month would exempt the repair from dedup.
        $selected = $record->selected_months ?? [];
        $month = $selected[0] ?? ($record->invoice?->billDate
            ? $record->invoice->billDate->format('Y-m')
            : now()->format('Y-m'));

        try {
            $credited = DB::transaction(function () use ($wallet, $teacher, $row, $record, $month) {
                $ok = $wallet->credit(
                    $teacher,
                    $row['shortfall'],
                    TeacherWalletEntry::REASON_REPAIR,
                    $record->id,
                    $month,
                    $row['invoice_id'],
                    'repair ledger-vs-record shortfall (record '.$record->id.')',
                    $row['subject'] ?? ''
                );

                // Shape B rows are inactive on a live invoice the teacher is
                // still assigned to: the credit makes them whole, so they come
                // back active instead of lingering dead-but-owed.
                if ($ok && ! $record->is_active) {
                    $record->update(['is_active' => true]);
                }

                return $ok;
            });
        } catch (\Throwable $e) {
            $this->error("Record {$record->id}: ".$e->getMessage());

            return 'failed';
        }

        return $credited ? 'applied' : 'skipped';
    }

    /**
     * Retire removed-teacher zombie rows: active + paid-in-full on the record,
     * short in the wallet, teacher no longer assigned. No money moves — the
     * staffing decision ("they never taught it") is the caller's.
     */
    private function deactivateReview($review): int
    {
        $targets = $review->filter(fn ($row) => $row['record']->is_active)->values();
        $alreadyDead = $review->count() - $targets->count();

        if ($targets->isEmpty()) {
            $this->info("No active REVIEW rows to retire ({$alreadyDead} already dead).");

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(
            "Retire {$targets->count()} REVIEW row(s) to inactive WITHOUT moving money? A backup must exist.", false
        )) {
            return self::FAILURE;
        }

        $retired = 0;

        foreach ($targets as $row) {
            $record = $row['record'];
            $record->update(['is_active' => false]);
            $retired++;

            \Illuminate\Support\Facades\Log::info('Repair retired a removed-teacher zombie record', [
                'record_id' => $record->id,
                'teacher_id' => $row['teacher_id'],
                'invoice_id' => $row['invoice_id'],
                'subject' => $row['subject'],
                'unpaid_shortfall_left_dead' => $row['shortfall'],
            ]);
        }

        $this->info("Retired: {$retired} ({$alreadyDead} already dead, left alone).");

        return self::SUCCESS;
    }
}
