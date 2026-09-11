<?php

namespace App\Console\Commands;

use App\Models\Teacher;
use App\Models\TeacherWalletEntry;
use App\Services\TeacherWalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Move a teacher's wallet by hand — the sanctioned replacement for tinker.
 *
 * Manual corrections (over-reversal repairs, write-off recoveries) used to go
 * through `tinker`, where idempotency depended on the operator remembering the
 * exact key. This command is a thin wrapper over TeacherWalletService, so the
 * row lock, the zero floor and the unique idempotency key all still apply.
 *
 *   php artisan wallet:adjust --teacher=2 --amount=100 --invoice=7027 --subject=FR --note=why
 *   php artisan wallet:adjust --teacher=2 --amount=100 --debit --note=why --confirm
 *
 * Dry-run unless --confirm is passed. A repeated identical run is REFUSED
 * (non-zero exit) instead of double-applied — which is why --invoice is
 * required: NULL-invoice rows are exempt from the idempotency key by design,
 * so an unattributed hand movement could never be deduplicated. Reason is restricted to
 * manual.adjustment: anything else goes through its own flow. Month defaults
 * to the current month so the idempotency key is stable — pass an explicit
 * month for backdated repairs, never NULL (NULLs are distinct in MySQL and
 * would exempt the row from deduplication).
 */
class AdjustTeacherWallet extends Command
{
    protected $signature = 'wallet:adjust
                            {--teacher= : Teacher id (required)}
                            {--amount= : DH, positive number (required)}
                            {--debit : Take money instead of giving it}
                            {--reason=manual.adjustment : Ledger reason (allowlisted)}
                            {--invoice= : Invoice id the movement belongs to}
                            {--record= : Payment record id, when there is one}
                            {--month= : Accrual month YYYY-MM (defaults to current month)}
                            {--subject= : Subject for the idempotency key}
                            {--note= : Human reason, recorded on the ledger row}
                            {--confirm : Without this flag nothing is written}';

    protected $description = 'Credit or debit a teacher wallet by hand (dry-run unless --confirm)';

    public function handle(TeacherWalletService $wallet): int
    {
        if (! ctype_digit((string) $this->option('teacher'))) {
            $this->error('Teacher id must be a positive integer.');

            return self::FAILURE;
        }

        $teacher = Teacher::find((int) $this->option('teacher'));

        if (! $teacher) {
            $this->error('Unknown teacher id '.$this->option('teacher').'.');

            return self::FAILURE;
        }

        if (! is_numeric($this->option('amount'))) {
            $this->error('Amount must be a number.');

            return self::FAILURE;
        }

        $amount = round((float) $this->option('amount'), 2);

        if ($amount <= 0 || $amount > 9999999.99) {
            $this->error('Amount must be between 0.01 and 9999999.99.');

            return self::FAILURE;
        }

        if ($this->option('reason') !== TeacherWalletEntry::REASON_ADJUSTMENT) {
            $this->error('Reason is restricted to '.TeacherWalletEntry::REASON_ADJUSTMENT.'.');

            return self::FAILURE;
        }

        // Invoice is required: NULL-invoice rows are exempt from the idempotency
        // key (MySQL treats NULLs as distinct), so an unattributed movement
        // could never be deduplicated — and unattributed hand money is the
        // legacy mess this command exists to stop creating.
        if (! ctype_digit((string) $this->option('invoice'))) {
            $this->error('Invoice id is required and must be a positive integer.');

            return self::FAILURE;
        }

        $invoice = \App\Models\Invoice::find((int) $this->option('invoice'));

        if (! $invoice) {
            $this->error('Unknown invoice id '.$this->option('invoice').'.');

            return self::FAILURE;
        }

        // Subject is key material AND the ledger slice key: normalise it the
        // same way every other path does (OfferPercentages::normalise,
        // lowercase). Uppercasing here while the service stores raw spelling
        // split one subject into two keys — the idempotency key missed and
        // the reversal-cap slice fell back to the whole-invoice sum.
        $subject = $this->option('subject') !== null
            ? \App\Support\OfferPercentages::normalise((string) $this->option('subject'))
            : null;

        // The teacher must be linked to the invoice at the SUBJECT level when
        // one is given: a Math record must not justify PC money (the new PC
        // teacher was already paid — see LedgerShortfall::isAssigned). Without
        // a subject, any record or any current roster spot links them.
        $records = \App\Models\TeacherMembershipPayment::where('invoice_id', $invoice->id)
            ->where('teacher_id', $teacher->id)
            ->get();

        $subjectLinked = $subject !== null && $subject !== ''
            ? $records->contains(fn ($r) => \App\Support\OfferPercentages::normalise((string) ($r->teacher_subject ?? '')) === $subject)
            : $records->isNotEmpty();

        if (! $subjectLinked) {
            $membership = \App\Models\Membership::withTrashed()->find($invoice->membership_id);
            $rostered = $membership && is_array($membership->teachers)
                && collect($membership->teachers)->contains(fn ($t) => (int) ($t['teacherId'] ?? 0) === (int) $teacher->id
                    && ($subject === null || $subject === '' || \App\Support\OfferPercentages::normalise((string) ($t['subject'] ?? '')) === $subject));

            if (! $rostered) {
                $scope = ($subject !== null && $subject !== '') ? " for subject '{$subject}'" : '';
                $this->error("Teacher {$teacher->id} has no record and no roster spot on invoice {$invoice->id}{$scope} — refused.");

                return self::FAILURE;
            }
        }

        $recordId = $this->option('record');

        if ($recordId !== null && ! ctype_digit((string) $recordId)) {
            $this->error('Record id must be a positive integer.');

            return self::FAILURE;
        }

        // A --record must belong to THIS teacher and THIS invoice: otherwise
        // the ledger row points at someone else's record and per-invoice
        // audits go blind on it. A --subject must agree with the record's
        // own subject, or the row lands in a slice the monitors never join
        // back to this record.
        if ($recordId !== null) {
            $record = \App\Models\TeacherMembershipPayment::find((int) $recordId);

            if (! $record || (int) $record->teacher_id !== (int) $teacher->id || (int) $record->invoice_id !== (int) $invoice->id) {
                $this->error("Record {$recordId} does not belong to teacher {$teacher->id} on invoice {$invoice->id} — refused.");

                return self::FAILURE;
            }

            if ($subject !== null && $subject !== ''
                && \App\Support\OfferPercentages::normalise((string) ($record->teacher_subject ?? '')) !== $subject) {
                $this->error("Record {$recordId} is for '{$record->teacher_subject}', not '{$subject}' — refused.");

                return self::FAILURE;
            }

            // No --subject with a --record: inherit the record's own subject.
            // A subject-less adjustment against a Math record would land in
            // the '' slice, which no per-subject audit joins back to it.
            if ($subject === null || $subject === '') {
                $subject = \App\Support\OfferPercentages::normalise((string) ($record->teacher_subject ?? ''));
            }
        }

        $month = $this->option('month') ?: now()->format('Y-m');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $this->error('Month must look like YYYY-MM with a real month 01-12.');

            return self::FAILURE;
        }

        $note = $this->option('note');

        if ($note !== null && mb_strlen($note) > 255) {
            $this->error('Note is capped at 255 characters.');

            return self::FAILURE;
        }

        $direction = $this->option('debit') ? 'DEBIT' : 'CREDIT';
        $before = round((float) $teacher->wallet, 2);

        $this->table(
            ['Teacher', 'Direction', 'Amount', 'Wallet before', 'Invoice', 'Month', 'Subject', 'Note'],
            [[
                $teacher->id.' '.trim($teacher->first_name.' '.$teacher->last_name),
                $direction,
                number_format($amount, 2),
                number_format($before, 2),
                $invoice->id,
                $month,
                $subject ?? '—',
                $note ?? '—',
            ]]
        );

        if (! $this->option('confirm')) {
            $this->info('Dry run — nothing written. Take a backup, then re-run with --confirm to apply.');

            return self::SUCCESS;
        }

        $recordId = $recordId !== null ? (int) $recordId : null;

        $ok = $this->option('debit')
            ? $wallet->debit(
                $teacher,
                $amount,
                TeacherWalletEntry::REASON_ADJUSTMENT,
                $recordId,
                $month,
                $invoice->id,
                $note,
                $subject
            )
            : $wallet->credit(
                $teacher,
                $amount,
                TeacherWalletEntry::REASON_ADJUSTMENT,
                $recordId,
                $month,
                $invoice->id,
                $note,
                $subject
            );

        if (! $ok) {
            $this->error('Refused: this exact movement is already recorded (idempotency key), or it would do nothing.');

            Log::warning('wallet:adjust refused a duplicate/no-op movement', [
                'teacher_id' => $teacher->id,
                'amount' => $amount,
                'debit' => (bool) $this->option('debit'),
                'invoice_id' => $invoice->id,
                'payment_record_id' => $recordId,
                'month' => $month,
                'subject' => $subject,
            ]);

            return self::FAILURE;
        }

        $after = round((float) $teacher->fresh()->wallet, 2);
        $applied = round($after - $before, 2);

        if (abs($applied) < abs($amount)) {
            $this->warn("Clamped at zero: requested {$amount}, applied ".abs($applied).'.');
        }

        $this->info("Applied: wallet {$before} -> {$after}.");

        Log::info('wallet:adjust applied a manual movement', [
            'teacher_id' => $teacher->id,
            'requested' => $this->option('debit') ? -$amount : $amount,
            'applied' => $this->option('debit') ? -$applied : $applied,
            'invoice_id' => $invoice->id,
            'payment_record_id' => $recordId,
            'month' => $month,
            'subject' => $subject,
            'note' => $note,
            'by' => auth()->id(),
        ]);

        return self::SUCCESS;
    }
}
