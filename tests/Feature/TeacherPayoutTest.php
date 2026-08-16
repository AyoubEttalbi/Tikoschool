<?php

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Services\TeacherMembershipPaymentService;

/*
 * Invariants for the teacher payout engine.
 *
 * Paying a student invoice credits `teachers.wallet`, which is later disbursed as cash.
 * There is no ledger, so a wrong credit is effectively unrecoverable — these tests are the
 * safety net for the arithmetic.
 */

/**
 * Build an invoice + membership + payment record for one teacher.
 *
 * @param  array<string>  $months  e.g. ['2025-09', '2025-10', '2025-11']
 */
function makePayableInvoice(array $months, float $total = 300.0, ?float $paid = null, int $percentage = 50): array
{
    $paid ??= $total;

    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $student = Student::factory()->create();

    // The offer carries the subject => percentage split the payout engine reads.
    $offer = Offer::factory()->create([
        'price' => $total,
        'subjects' => ['Math'],
        'percentage' => ['Math' => $percentage],
    ]);

    $membership = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'payment_status' => 'paid',
        'is_active' => true,
        'teachers' => [[
            'teacherId' => $teacher->id,
            'subject' => 'Math',
        ]],
    ]);

    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'totalAmount' => $total,
        'amountPaid' => $paid,
        'rest' => $total - $paid,
        'months' => count($months),
        'selected_months' => $months,
        'billDate' => now()->startOfMonth(),
        'creationDate' => now(),
        'endDate' => now()->addMonths(count($months)),
        'includePartialMonth' => false,
    ]);

    return [$teacher, $student, $membership, $invoice];
}

/** The shape InvoiceController passes to the payout service after validation. */
function validatedPayloadFor(Invoice $invoice): array
{
    return [
        'membership_id' => $invoice->membership_id,
        'student_id' => $invoice->student_id,
        'months' => $invoice->months,
        'selected_months' => $invoice->selected_months,
        'billDate' => $invoice->billDate,
        'totalAmount' => (float) $invoice->totalAmount,
        'amountPaid' => (float) $invoice->amountPaid,
        'rest' => (float) $invoice->rest,
        'includePartialMonth' => (bool) $invoice->includePartialMonth,
        'partialMonthAmount' => (float) ($invoice->partialMonthAmount ?? 0),
    ];
}

test('a fully paid multi-month invoice never leaves months queued for the monthly cron', function () {
    // Months MUST straddle the current month. The engine only ever queues FUTURE months in
    // months_rest_not_paid_yet, so an all-past fixture would pass trivially without
    // exercising the double-pay path at all.
    [$teacher, , , $invoice] = makePayableInvoice([
        now()->format('Y-m'),
        now()->addMonth()->format('Y-m'),
        now()->addMonths(2)->format('Y-m'),
    ]);

    $service = new TeacherMembershipPaymentService;
    $service->processInvoicePayment($invoice, validatedPayloadFor($invoice));
    $service->reconcilePaidMonthsForInvoice($invoice);

    $records = TeacherMembershipPayment::where('invoice_id', $invoice->id)->get();

    foreach ($records as $record) {
        $paidInFull = (float) $record->total_paid_to_teacher >= (float) $record->total_teacher_amount
            && (float) $record->total_teacher_amount > 0;

        if ($paidInFull) {
            // THE REGRESSION THIS GUARDS: reconcile credits the FULL commission up front but
            // used to leave months_rest_not_paid_yet populated. The monthly cron then matched
            // those months and credited monthly_teacher_amount AGAIN — 167% for a 3-month
            // invoice, 200% when the current month was not selected.
            expect($record->months_rest_not_paid_yet ?? [])->toBe(
                [],
                'A fully-paid record still has unpaid months queued; the monthly cron would double-pay it.'
            );
        }
    }

    // Guard against the test silently passing because nothing was created.
    expect($records)->not->toBeEmpty('No payout records were created — the fixture is wrong.');
});

test('a teacher is never paid more than their computed commission', function () {
    [$teacher, , , $invoice] = makePayableInvoice([
        now()->format('Y-m'),
        now()->addMonth()->format('Y-m'),
    ]);

    $service = new TeacherMembershipPaymentService;
    $service->processInvoicePayment($invoice, validatedPayloadFor($invoice));
    $service->reconcilePaidMonthsForInvoice($invoice);

    foreach (TeacherMembershipPayment::where('invoice_id', $invoice->id)->get() as $record) {
        expect((float) $record->total_paid_to_teacher)
            ->toBeLessThanOrEqual((float) $record->total_teacher_amount + 0.01);
    }
});

test('deleting an OLD multi-month invoice does not claw back the whole wallet', function () {
    // The deadline rule: a full reversal is only allowed within REVERSAL_DEADLINE_DAYS of
    // the PAYMENT. After that only the UNEARNED future months may be clawed back.
    //
    // Three months (past / current / future), reconciled so the teacher has been paid in
    // full. That is what separates the two behaviours: the correct path reverses only the
    // single future month, the buggy path reverses the entire paid-to-date balance.
    $lastMonth = now()->subMonth()->format('Y-m');
    $thisMonth = now()->format('Y-m');
    $nextMonth = now()->addMonth()->format('Y-m');

    [$teacher, , , $invoice] = makePayableInvoice([$lastMonth, $thisMonth, $nextMonth]);

    $service = new TeacherMembershipPaymentService;
    $service->processInvoicePayment($invoice, validatedPayloadFor($invoice));
    $service->reconcilePaidMonthsForInvoice($invoice);

    // Backdate the PAYMENT well past the window — that is what the rule measures now.
    // billDate is included only to keep the "old invoice" shape the comment describes.
    $invoice->update(['billDate' => now()->subDays(90), 'last_payment_date' => now()->subDays(90)]);
    $invoice->refresh();

    $record = TeacherMembershipPayment::where('invoice_id', $invoice->id)->firstOrFail();
    $paidToTeacher = (float) $record->total_paid_to_teacher;

    $teacher->refresh();
    $walletBefore = (float) $teacher->wallet;

    $service->reverseInvoicePayments($invoice);

    $teacher->refresh();
    $reversed = $walletBefore - (float) $teacher->wallet;

    // THE REGRESSION THIS GUARDS: now()->diffInDays($past) is NEGATIVE in Carbon 3, so
    // `$daysSinceBilling <= 10` was always true and deleting ANY old invoice reversed the
    // teacher's ENTIRE paid-to-date balance instead of only the unearned future months.
    expect($reversed)->toBeLessThan(
        $paidToTeacher,
        'An invoice past the 10-day window reversed the full paid-to-date amount; '
        .'the billing-date comparison is signed the wrong way round.'
    );
});

test('a wallet is never driven negative by a reversal', function () {
    [$teacher, , , $invoice] = makePayableInvoice(['2025-09']);

    $service = new TeacherMembershipPaymentService;
    $service->processInvoicePayment($invoice, validatedPayloadFor($invoice));

    // Force the wallet to zero, then reverse — the reversal must clamp, not go negative.
    $teacher->update(['wallet' => 0]);
    $service->reverseInvoicePayments($invoice);

    $teacher->refresh();

    // A negative wallet permanently blocks payouts (TransactionController refuses wallet <= 0).
    expect((float) $teacher->wallet)->toBeGreaterThanOrEqual(0.0);
});

test('an offer cannot allocate more than 100% across its teachers', function () {
    $admin = App\Models\User::factory()->create(['role' => 'admin']);
    $level = App\Models\Level::factory()->create();

    // 80 + 70 = 150% of every student payment.
    $this->actingAs($admin)
        ->from('/offers')
        ->post('/offers', [
            'offer_name' => 'Over-allocated offer',
            'price' => 300,
            'levelId' => $level->id,
            'subjects' => ['Math', 'Physique'],
            'percentage' => ['Math' => 80, 'Physique' => 70],
        ])
        ->assertSessionHasErrors('percentage');

    expect(Offer::where('offer_name', 'Over-allocated offer')->exists())->toBeFalse();
});

test('an offer allocating exactly 100% is accepted', function () {
    $admin = App\Models\User::factory()->create(['role' => 'admin']);
    $level = App\Models\Level::factory()->create();

    $this->actingAs($admin)->post('/offers', [
        'offer_name' => 'Balanced offer',
        'price' => 300,
        'levelId' => $level->id,
        'subjects' => ['Math', 'Physique'],
        'percentage' => ['Math' => 60, 'Physique' => 40],
    ]);

    expect(Offer::where('offer_name', 'Balanced offer')->exists())->toBeTrue();
});

test('a teacher with no percentage left to allocate is paid nothing, not an invented share', function () {
    // {"Math": 100, "Physique": 0} with two teachers used to pay 100% + (100/2)% = 150%.
    $teacherA = Teacher::factory()->create(['wallet' => 0]);
    $teacherB = Teacher::factory()->create(['wallet' => 0]);
    $student = Student::factory()->create();

    $offer = Offer::factory()->create([
        'price' => 300,
        'subjects' => ['Math', 'Physique'],
        'percentage' => ['Math' => 100, 'Physique' => 0],
    ]);

    $membership = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'payment_status' => 'paid',
        'is_active' => true,
        'teachers' => [
            ['teacherId' => $teacherA->id, 'subject' => 'Math'],
            ['teacherId' => $teacherB->id, 'subject' => 'Physique'],
        ],
    ]);

    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'totalAmount' => 300,
        'amountPaid' => 300,
        'rest' => 0,
        'months' => 1,
        'selected_months' => [now()->format('Y-m')],
        'billDate' => now()->startOfMonth(),
        'includePartialMonth' => false,
    ]);

    (new TeacherMembershipPaymentService)
        ->processInvoicePayment($invoice, validatedPayloadFor($invoice));

    $teacherA->refresh();
    $teacherB->refresh();

    $totalPaidOut = (float) $teacherA->wallet + (float) $teacherB->wallet;

    expect($totalPaidOut)->toBeLessThanOrEqual(300.0)
        ->and((float) $teacherB->wallet)->toBe(0.0);
});

test('a zero-total invoice does not raise DivisionByZeroError', function () {
    [, , , $invoice] = makePayableInvoice(['2025-09'], total: 0.0, paid: 0.0);

    $service = new TeacherMembershipPaymentService;

    // DivisionByZeroError extends Error, not Exception, so it would escape every
    // `catch (\Exception)` in the call chain and surface as a 500.
    $service->reconcilePaidMonthsForInvoice($invoice);

    expect(true)->toBeTrue();
});
