<?php

namespace App\Services;

use App\Models\Teacher;
use App\Models\TeacherWalletEntry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only sanctioned way to move a teacher's wallet.
 *
 * Every movement writes a ledger row and updates the cached `teachers.wallet` projection in
 * the same transaction, with a row lock so concurrent invoice payments cannot interleave a
 * read-modify-write.
 *
 * `credit()` is idempotent per (payment_record_id, month, reason): a repeat call is rejected
 * by a database unique constraint rather than silently paying twice. That is the structural
 * fix for the class of bug where the reconcile step and the monthly cron both credited the
 * same month.
 */
class TeacherWalletService
{
    /**
     * Credit a teacher. Returns false when the movement was already recorded.
     */
    public function credit(
        Teacher $teacher,
        float $amount,
        string $reason,
        ?int $paymentRecordId = null,
        ?string $month = null,
        ?int $invoiceId = null,
        ?string $note = null,
        ?string $teacherSubject = null
    ): bool {
        return $this->move($teacher, abs($amount), $reason, $paymentRecordId, $month, $invoiceId, $note, $teacherSubject);
    }

    /**
     * Debit a teacher. Clamped so the wallet can never go negative — a negative balance
     * permanently blocks payouts elsewhere in the app.
     */
    public function debit(
        Teacher $teacher,
        float $amount,
        string $reason,
        ?int $paymentRecordId = null,
        ?string $month = null,
        ?int $invoiceId = null,
        ?string $note = null,
        ?string $teacherSubject = null
    ): bool {
        return $this->move($teacher, -abs($amount), $reason, $paymentRecordId, $month, $invoiceId, $note, $teacherSubject);
    }

    private function move(
        Teacher $teacher,
        float $signedAmount,
        string $reason,
        ?int $paymentRecordId,
        ?string $month,
        ?int $invoiceId,
        ?string $note,
        ?string $teacherSubject = null
    ): bool {
        $signedAmount = round($signedAmount, 2);

        if ($signedAmount === 0.0) {
            return false;
        }

        // '' rather than null: teacher_subject is part of the unique idempotency key, and
        // MySQL treats NULLs as distinct — a null here would exempt the row from the
        // constraint entirely, which is the opposite of what the key is for.
        // See 2026_08_07_120000_add_teacher_subject_to_wallet_ledger_idempotency.
        $teacherSubject = (string) ($teacherSubject ?? '');

        return DB::transaction(function () use ($teacher, $signedAmount, $reason, $paymentRecordId, $month, $invoiceId, $note, $teacherSubject) {
            // Lock the row so two concurrent payments cannot both read the same balance.
            $locked = Teacher::whereKey($teacher->id)->lockForUpdate()->first();
            if (! $locked) {
                return false;
            }

            $before = round((float) $locked->wallet, 2);
            $applied = $signedAmount;

            // Never drive the balance below zero.
            if ($applied < 0 && $before + $applied < 0) {
                $applied = -$before;
            }

            if ($applied === 0.0) {
                return false;
            }

            $after = round($before + $applied, 2);

            try {
                TeacherWalletEntry::create([
                    'teacher_id' => $locked->id,
                    'invoice_id' => $invoiceId,
                    'payment_record_id' => $paymentRecordId,
                    'month' => $month,
                    'teacher_subject' => $teacherSubject,
                    'amount' => $applied,
                    'balance_after' => $after,
                    'reason' => $reason,
                    'note' => $note,
                    'created_by' => Auth::id(),
                ]);
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Already recorded — this is the idempotency guard doing its job.
                Log::info('Wallet movement skipped: already recorded', [
                    'teacher_id' => $locked->id,
                    'payment_record_id' => $paymentRecordId,
                    'month' => $month,
                    'reason' => $reason,
                ]);

                return false;
            }

            // forceFill, not update(): `wallet` was removed from Teacher::$fillable so that
            // no stray update($request->all()) can move it behind the ledger's back. This
            // service is the one place allowed to write it, and says so explicitly.
            $locked->forceFill(['wallet' => $after])->save();
            $teacher->setAttribute('wallet', $after);

            return true;
        });
    }

    /** What the wallet SHOULD be, from the ledger. */
    public function ledgerBalance(Teacher $teacher): float
    {
        return round((float) TeacherWalletEntry::where('teacher_id', $teacher->id)->sum('amount'), 2);
    }

    /**
     * Teachers whose cached wallet disagrees with their ledger.
     *
     * @return \Illuminate\Support\Collection<int, object{teacher_id:int, wallet:float, ledger:float, drift:float}>
     */
    public function drift(): \Illuminate\Support\Collection
    {
        return Teacher::query()
            ->leftJoin('teacher_wallet_entries as e', 'e.teacher_id', '=', 'teachers.id')
            ->groupBy('teachers.id', 'teachers.wallet')
            ->havingRaw('ABS(teachers.wallet - COALESCE(SUM(e.amount), 0)) > 0.01')
            ->select([
                'teachers.id as teacher_id',
                'teachers.wallet as wallet',
                DB::raw('COALESCE(SUM(e.amount), 0) as ledger'),
                DB::raw('teachers.wallet - COALESCE(SUM(e.amount), 0) as drift'),
            ])
            ->get();
    }

    /**
     * Record a one-off opening balance so existing wallets reconcile against the new ledger.
     * Safe to run repeatedly: the idempotency key stops it double-posting.
     */
    public function recordOpeningBalance(Teacher $teacher): bool
    {
        $wallet = round((float) $teacher->wallet, 2);

        if ($wallet === 0.0) {
            return false;
        }

        $alreadySeeded = TeacherWalletEntry::where('teacher_id', $teacher->id)
            ->where('reason', TeacherWalletEntry::REASON_ADJUSTMENT)
            ->where('note', 'opening balance')
            ->exists();

        if ($alreadySeeded) {
            return false;
        }

        TeacherWalletEntry::create([
            'teacher_id' => $teacher->id,
            'amount' => $wallet,
            'balance_after' => $wallet,
            'reason' => TeacherWalletEntry::REASON_ADJUSTMENT,
            'note' => 'opening balance',
        ]);

        return true;
    }
}
