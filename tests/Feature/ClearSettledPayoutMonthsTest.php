<?php

use App\Models\Invoice;
use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;

/*
 * payouts:clear-settled-months drains the pending-month queue on records that are already
 * paid in full. It must never move money — it only removes the trigger that would make the
 * monthly cron pay a settled record again.
 */

function settledRecord(float $owed, float $paid, array $queuedMonths, float $monthly = 90): array
{
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $invoice = Invoice::factory()->create();

    $record = TeacherMembershipPayment::create([
        'teacher_id' => $teacher->id,
        'invoice_id' => $invoice->id,
        'student_id' => $invoice->student_id,
        'membership_id' => $invoice->membership_id,
        'teacher_subject' => 'Math',
        'teacher_percentage' => 50,
        'total_teacher_amount' => $owed,
        'total_paid_to_teacher' => $paid,
        'monthly_teacher_amount' => $monthly,
        'selected_months' => $queuedMonths,
        'months_rest_not_paid_yet' => $queuedMonths,
        'payment_percentage' => 100,
        'is_active' => true,
    ]);

    return [$teacher, $record];
}

test('a dry run changes nothing', function () {
    [$teacher, $record] = settledRecord(owed: 270, paid: 270, queuedMonths: ['2026-09', '2026-10']);

    $this->artisan('payouts:clear-settled-months')->assertSuccessful();

    $record->refresh();
    $teacher->refresh();

    expect($record->months_rest_not_paid_yet)->toBe(['2026-09', '2026-10'])
        ->and((float) $teacher->wallet)->toBe(0.0);
});

test('--apply clears the queue on a settled record and moves no money', function () {
    // Production invoice 842 reproduced.
    [$teacher, $record] = settledRecord(owed: 270, paid: 270, queuedMonths: ['2026-09', '2026-10']);

    $this->artisan('payouts:clear-settled-months --apply')->assertSuccessful();

    $record->refresh();
    $teacher->refresh();

    expect($record->months_rest_not_paid_yet)->toBe([])
        ->and((float) $record->total_paid_to_teacher)->toBe(270.0)
        ->and((float) $teacher->wallet)->toBe(0.0, 'This command must never move money.')
        ->and(TeacherWalletEntry::where('teacher_id', $teacher->id)->count())->toBe(0);
});

test('a record with money still outstanding keeps its queued months', function () {
    // The months are the mechanism that DELIVERS the outstanding balance. Clearing them
    // here would strand the teacher's money — the exact bug settle-stranded exists to fix.
    [, $record] = settledRecord(owed: 270, paid: 90, queuedMonths: ['2026-09', '2026-10']);

    $this->artisan('payouts:clear-settled-months --apply')->assertSuccessful();

    $record->refresh();

    expect($record->months_rest_not_paid_yet)->toBe(['2026-09', '2026-10']);
});

test('an inactive record is left alone', function () {
    [, $record] = settledRecord(owed: 270, paid: 270, queuedMonths: ['2026-09']);
    $record->update(['is_active' => false]);

    $this->artisan('payouts:clear-settled-months --apply')->assertSuccessful();

    $record->refresh();

    expect($record->months_rest_not_paid_yet)->toBe(['2026-09']);
});

test('running it twice is harmless', function () {
    [, $record] = settledRecord(owed: 270, paid: 270, queuedMonths: ['2026-09']);

    $this->artisan('payouts:clear-settled-months --apply')->assertSuccessful();
    $this->artisan('payouts:clear-settled-months --apply')->assertSuccessful();

    $record->refresh();

    expect($record->months_rest_not_paid_yet)->toBe([]);
});
