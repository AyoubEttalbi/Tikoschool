<?php

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;
use App\Models\User;
use App\Services\TeacherMembershipPaymentService;
use Illuminate\Support\Carbon;

/*
 * THE OCTOBER-2026 LESSON, PINNED.
 *
 * Two schedulers ran the monthly job against the same database (a stale
 * checkout writing wallet increments with no ledger rows). Afterwards several
 * tracker records read "paid in full, nothing queued" while the ledger held
 * nothing for those months. The monthly guard trusted the record and cleared
 * the queue — cementing teacher pay the ledger never saw.
 *
 * Rule from here on: the guard may only treat a record as settled when the
 * LEDGER agrees. Paid-on-paper with no ledger movement stays queued and gets
 * loud, so a human backfills it instead of the cron burying it.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-15 10:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function ledgerGuardPost($t, User $admin, Student $student, Membership $membership, array $months): Invoice
{
    $price = (float) $membership->offer->price;
    $total = $price * count($months);

    $response = $t->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => count($months),
        'selected_months' => $months,
        'billDate' => $months[0].'-01',
        'creationDate' => $months[0].'-01',
        'endDate' => Carbon::parse($months[count($months) - 1].'-01')->endOfMonth()->toDateString(),
        'totalAmount' => $total,
        'amountPaid' => $total,
        'rest' => 0,
        'includePartialMonth' => false,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    return Invoice::where('membership_id', $membership->id)->latest('id')->firstOrFail();
}

function ledgerGuardMembership(float $monthlyPrice = 300.0): array
{
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $student = Student::factory()->create();
    $offer = Offer::factory()->create([
        'price' => $monthlyPrice,
        'subjects' => ['Math'],
        'percentage' => ['Math' => 50],
    ]);
    $membership = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'payment_status' => 'pending',
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);

    return [$teacher, $student, $membership];
}

test('advance-billed october credits nothing in september and settles exactly once in october', function () {
    [$teacher, $student, $membership] = ledgerGuardMembership();
    $admin = User::factory()->create(['role' => 'admin']);

    // Paid in September for October (the business rule: service month owns the credit).
    ledgerGuardPost($this, $admin, $student, $membership, ['2026-10']);

    // September run: the service month has not arrived — hands off.
    (new TeacherMembershipPaymentService)->processMonthlyPayments('2026-09');

    $teacher->refresh();
    expect((float) $teacher->wallet)->toBe(0.0)
        ->and(TeacherWalletEntry::where('teacher_id', $teacher->id)->count())->toBe(0);

    // October run: exactly one schedule credit of the full share (300 x 50%).
    Carbon::setTestNow('2026-10-05 10:00:00');
    (new TeacherMembershipPaymentService)->processMonthlyPayments('2026-10');

    $teacher->refresh();
    $record = TeacherMembershipPayment::where('teacher_id', $teacher->id)->firstOrFail();

    expect((float) $teacher->wallet)->toBe(150.0)
        ->and(TeacherWalletEntry::where('teacher_id', $teacher->id)->where('month', '2026-10')->sum('amount'))->toEqual(150.0)
        ->and((float) $record->total_paid_to_teacher)->toBe(150.0)
        ->and($record->months_rest_not_paid_yet ?? [])->toBe([]);

    // A retry / overlapping run changes nothing.
    (new TeacherMembershipPaymentService)->processMonthlyPayments('2026-10');

    $teacher->refresh();
    expect((float) $teacher->wallet)->toBe(150.0)
        ->and(TeacherWalletEntry::where('teacher_id', $teacher->id)->where('month', '2026-10')->count())->toBe(1);
});

test('a record that reads paid but holds no ledger movement stays queued and loud', function () {
    // The Group-A shape from production: tracker says settled, ledger saw nothing.
    // (An outside writer moved the record; the cron must not cement it.)
    [$teacher, $student, $membership] = ledgerGuardMembership();
    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'billDate' => '2026-10-01',
        'selected_months' => ['2026-10'],
        'totalAmount' => 300,
        'amountPaid' => 300,
        'rest' => 0,
    ]);

    $record = TeacherMembershipPayment::create([
        'teacher_id' => $teacher->id,
        'invoice_id' => $invoice->id,
        'student_id' => $student->id,
        'membership_id' => $membership->id,
        'teacher_subject' => 'Math',
        'teacher_percentage' => 50,
        'total_teacher_amount' => 150.0,
        'total_paid_to_teacher' => 150.0,
        'monthly_teacher_amount' => 150.0,
        'selected_months' => ['2026-10'],
        'months_rest_not_paid_yet' => ['2026-10'],
        'payment_percentage' => 100,
        'is_active' => true,
    ]);

    Carbon::setTestNow('2026-10-05 10:00:00');
    (new TeacherMembershipPaymentService)->processMonthlyPayments('2026-10');

    $teacher->refresh();
    $record->refresh();

    expect((float) $teacher->wallet)->toBe(0.0, 'Nothing was credited, so nothing may be marked settled.')
        ->and((float) $record->total_paid_to_teacher)->toBe(150.0)
        ->and($record->months_rest_not_paid_yet ?? [])->toContain('2026-10');
});
