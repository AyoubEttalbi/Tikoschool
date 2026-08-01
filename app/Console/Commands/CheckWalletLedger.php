<?php

namespace App\Console\Commands;

use App\Models\Teacher;
use App\Services\TeacherWalletService;
use Illuminate\Console\Command;

/**
 * Reconcile every teacher's cached `teachers.wallet` against the append-only ledger.
 *
 *   php artisan wallet:check                  # report drift (read-only)
 *   php artisan wallet:seed-opening-balances  # one-off, see below
 *
 * Run `--seed` ONCE after deploying the ledger migration: existing wallets predate the
 * ledger, so every teacher would otherwise appear to have drifted by their whole balance.
 */
class CheckWalletLedger extends Command
{
    protected $signature = 'wallet:check
                            {--seed : Record a one-off opening-balance entry for wallets that predate the ledger}
                            {--repair : Reset teachers.wallet to the ledger sum (DESTRUCTIVE — take a backup first)}';

    protected $description = 'Reconcile teacher wallets against the append-only wallet ledger';

    public function handle(TeacherWalletService $wallet): int
    {
        if ($this->option('seed')) {
            return $this->seed($wallet);
        }

        $drift = $wallet->drift();

        if ($drift->isEmpty()) {
            $this->info('All teacher wallets reconcile against the ledger.');

            return self::SUCCESS;
        }

        $this->error("{$drift->count()} teacher wallet(s) disagree with the ledger:");
        $this->table(
            ['Teacher', 'Cached wallet', 'Ledger sum', 'Drift'],
            $drift->map(fn ($d) => [
                $d->teacher_id,
                number_format((float) $d->wallet, 2),
                number_format((float) $d->ledger, 2),
                number_format((float) $d->drift, 2),
            ])->all()
        );

        $this->newLine();
        $this->line('Drift means a wallet was changed outside TeacherWalletService, or an');
        $this->line('opening balance was never seeded. Run with --seed if this is the first run.');

        if ($this->option('repair')) {
            if (! $this->confirm('Overwrite teachers.wallet with the ledger sum?', false)) {
                return self::FAILURE;
            }

            foreach ($drift as $d) {
                Teacher::whereKey($d->teacher_id)->update(['wallet' => $d->ledger]);
            }

            $this->info("Repaired {$drift->count()} wallet(s) from the ledger.");

            return self::SUCCESS;
        }

        // Non-zero exit so a scheduled run surfaces as a failure.
        return self::FAILURE;
    }

    private function seed(TeacherWalletService $wallet): int
    {
        $seeded = 0;

        Teacher::query()->where('wallet', '!=', 0)->chunkById(200, function ($teachers) use ($wallet, &$seeded) {
            foreach ($teachers as $teacher) {
                if ($wallet->recordOpeningBalance($teacher)) {
                    $seeded++;
                }
            }
        });

        $this->info("Recorded {$seeded} opening-balance entr" . ($seeded === 1 ? 'y' : 'ies') . '.');
        $this->line('Re-run `php artisan wallet:check` — it should now report no drift.');

        return self::SUCCESS;
    }
}
