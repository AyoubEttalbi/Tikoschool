<?php

use App\Models\Invoice;
use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;
use App\Services\TeacherWalletService;

/*
 * payouts:settle-stranded pays teachers commission they earned but can no longer receive.
 *
 * The dangerous mistake this guards against is settling the WHOLE reported shortfall.
 * payouts:audit's shortfall mixes real drift with months the monthly cron has not reached
 * yet — paying those now is an overpayment dressed up as a correction.
 */

/** A payout record owing $gap, with $pendingMonths still queued for the cron. */
function strandedRecord(float $owed, float $paid, array $pendingMonths = [], float $monthly = 0): array
{
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $student = App\Models\Student::factory()->create();
    $membership = App\Models\Membership::factory()->create([
        'student_id' => $student->id,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);
    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
    ]);

    $record = TeacherMembershipPayment::create([
        'teacher_id' => $teacher->id,
        'invoice_id' => $invoice->id,
        'student_id' => $invoice->student_id,
        'membership_id' => $membership->id,
        'teacher_subject' => 'Math',
        'teacher_percentage' => 50,
        'total_teacher_amount' => $owed,
        'total_paid_to_teacher' => $paid,
        'monthly_teacher_amount' => $monthly,
        'selected_months' => ['2026-01'],
        'months_rest_not_paid_yet' => $pendingMonths,
        'payment_percentage' => 100,
        'is_active' => true,
    ]);

    return [$teacher, $invoice, $record];
}

test('a dry run reports the amount but moves no money', function () {
    [$teacher] = strandedRecord(owed: 300, paid: 0);

    $this->artisan('payouts:settle-stranded')->assertSuccessful();

    $teacher->refresh();

    expect((float) $teacher->wallet)->toBe(0.0)
        ->and(TeacherWalletEntry::where('teacher_id', $teacher->id)->count())->toBe(0);
});

test('--apply credits the stranded amount through the ledger', function () {
    [$teacher, , $record] = strandedRecord(owed: 300, paid: 0);

    $this->artisan('payouts:settle-stranded --apply')->assertSuccessful();

    $teacher->refresh();
    $record->refresh();

    expect((float) $teacher->wallet)->toBe(300.0)
        // Through the ledger, not around it — wallet:check must still reconcile.
        ->and((float) TeacherWalletEntry::where('teacher_id', $teacher->id)->sum('amount'))->toBe(300.0)
        // And the record is updated, or the next audit reports the same gap forever.
        ->and((float) $record->total_paid_to_teacher)->toBe(300.0)
        ->and((new TeacherWalletService)->drift())->toBeEmpty();
});

test('months the cron has not reached yet are NOT settled', function () {
    // THE important test. Owed 300, paid 0, but 2 months x 100 are still queued —
    // the cron will deliver 200 of that gap on schedule. Only 100 is genuinely stranded.
    [$teacher] = strandedRecord(owed: 300, paid: 0, pendingMonths: ['2026-02', '2026-03'], monthly: 100);

    $this->artisan('payouts:settle-stranded --apply')->assertSuccessful();

    $teacher->refresh();

    expect((float) $teacher->wallet)->toBe(
        100.0,
        'Settling the full gap would pay the teacher early for months the cron still owes them.'
    );
});

test('a record whose scheduled months cover the whole gap is left completely alone', function () {
    [$teacher] = strandedRecord(owed: 300, paid: 0, pendingMonths: ['2026-02', '2026-03', '2026-04'], monthly: 100);

    $this->artisan('payouts:settle-stranded --apply')->assertSuccessful();

    $teacher->refresh();

    expect((float) $teacher->wallet)->toBe(0.0);
});

test('running it twice does not pay twice', function () {
    // The ledger's unique key is the backstop, but the month marker has to be STABLE for
    // it to work — using now()->format('Y-m') would let a re-run next month pay again.
    [$teacher] = strandedRecord(owed: 300, paid: 0);

    $this->artisan('payouts:settle-stranded --apply')->assertSuccessful();
    $this->artisan('payouts:settle-stranded --apply')->assertSuccessful();

    $teacher->refresh();

    expect((float) $teacher->wallet)->toBe(300.0)
        ->and(TeacherWalletEntry::where('teacher_id', $teacher->id)->count())->toBe(1);
});

test('records for a deleted invoice are skipped unless explicitly included', function () {
    // An orphaned record usually means the invoice was deleted and its wallet credit was
    // already reversed. Paying it would hand back money that was deliberately taken away.
    [$teacher, $invoice] = strandedRecord(owed: 300, paid: 0);
    $invoice->delete();

    $this->artisan('payouts:settle-stranded --apply')->assertSuccessful();

    $teacher->refresh();
    expect((float) $teacher->wallet)->toBe(0.0);

    $this->artisan('payouts:settle-stranded --apply --include-orphans')->assertSuccessful();

    $teacher->refresh();
    expect((float) $teacher->wallet)->toBe(300.0);
});

test('an inactive record is never settled', function () {
    [$teacher, , $record] = strandedRecord(owed: 300, paid: 0);
    $record->update(['is_active' => false]);

    $this->artisan('payouts:settle-stranded --apply')->assertSuccessful();

    $teacher->refresh();

    expect((float) $teacher->wallet)->toBe(0.0);
});

test('a fully paid record is not settled', function () {
    [$teacher] = strandedRecord(owed: 300, paid: 300);

    $this->artisan('payouts:settle-stranded --apply')->assertSuccessful();

    $teacher->refresh();

    expect((float) $teacher->wallet)->toBe(0.0);
});
