<?php

use App\Models\Invoice;
use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;
use App\Services\TeacherMembershipPaymentService;

/*
 * The monthly payout cron selects records on withUnpaidCurrentMonth() — whether a month
 * is still listed in months_rest_not_paid_yet. It never asked whether the teacher had
 * ALREADY been paid in full.
 *
 * A record can reach that state legitimately: an invoice edit recalculates the total, or
 * reconcilePaidMonthsForInvoice() pays the whole commission up front, while the month
 * queue is left populated. The cron then pays again when one of those months arrives.
 *
 * The ledger does NOT protect against this. Its idempotency key is
 * (teacher, invoice, month, reason, subject), and a credit for a month never previously
 * paid is a genuinely new row — not a duplicate.
 *
 * Found live on production: invoice 842, three teachers, each owed 270 and already paid
 * 270, each still carrying 2026-09 and 2026-10 at 90/month. 540 was queued to be paid a
 * second time over the following two cron runs.
 */

function payoutRecord(float $owed, float $paid, array $queuedMonths, float $monthly): array
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
        'selected_months' => $queuedMonths,
        'months_rest_not_paid_yet' => $queuedMonths,
        'payment_percentage' => 100,
        'is_active' => true,
    ]);

    return [$teacher, $record];
}

test('a record already paid in full is not paid again by the monthly cron', function () {
    // Production invoice 842 reproduced exactly.
    $month = now()->format('Y-m');
    [$teacher, $record] = payoutRecord(owed: 270, paid: 270, queuedMonths: [$month], monthly: 90);

    (new TeacherMembershipPaymentService)->processMonthlyPayments($month);

    $teacher->refresh();
    $record->refresh();

    expect((float) $teacher->wallet)->toBe(0.0, 'The cron paid a teacher who was already settled.')
        ->and((float) $record->total_paid_to_teacher)->toBe(270.0)
        // The stale month is cleared, so the record stops re-presenting itself every run.
        ->and($record->months_rest_not_paid_yet ?? [])->not->toContain($month);
});

test('a record with money genuinely outstanding is still paid', function () {
    // The guard must not break the normal case.
    $month = now()->format('Y-m');
    [$teacher, $record] = payoutRecord(owed: 270, paid: 90, queuedMonths: [$month], monthly: 90);

    (new TeacherMembershipPaymentService)->processMonthlyPayments($month);

    $teacher->refresh();
    $record->refresh();

    expect((float) $teacher->wallet)->toBe(90.0)
        ->and((float) $record->total_paid_to_teacher)->toBe(180.0)
        ->and(TeacherWalletEntry::where('teacher_id', $teacher->id)->sum('amount'))->toEqual(90.0);
});

test('the final month never pays more than the outstanding balance', function () {
    // Owed 270, already paid 220, but monthly_teacher_amount still says 90.
    // Paying 90 would take the teacher to 310 — 40 more than they earned.
    $month = now()->format('Y-m');
    [$teacher, $record] = payoutRecord(owed: 270, paid: 220, queuedMonths: [$month], monthly: 90);

    (new TeacherMembershipPaymentService)->processMonthlyPayments($month);

    $teacher->refresh();
    $record->refresh();

    expect((float) $teacher->wallet)->toBe(50.0, 'The payout overshot the outstanding balance.')
        ->and((float) $record->total_paid_to_teacher)->toBe(270.0);
});

test('a teacher is never paid more than their total commission across many cron runs', function () {
    // The invariant that matters, exercised end to end: run the cron repeatedly over
    // every queued month and the teacher must never exceed what they earned.
    $months = [
        now()->format('Y-m'),
        now()->addMonth()->format('Y-m'),
        now()->addMonths(2)->format('Y-m'),
    ];

    [$teacher] = payoutRecord(owed: 270, paid: 0, queuedMonths: $months, monthly: 90);

    $service = new TeacherMembershipPaymentService;

    foreach ($months as $m) {
        $service->processMonthlyPayments($m);
        $service->processMonthlyPayments($m);   // a retry / overlapping run
    }

    $teacher->refresh();

    expect((float) $teacher->wallet)->toBe(270.0);
});
