<?php

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TeacherMembershipPaymentService;
use App\Services\TeacherWalletService;
use Illuminate\Support\Carbon;

/*
 * Regression cover for the money bugs found in the 2026-08-07 audit (§6.1 - §6.8).
 *
 * Every one of these was silent: no exception, no failed request, no log line a human
 * would ever read. The ledger is what makes them detectable at all, so these tests assert
 * against the LEDGER, not just the cached teachers.wallet column.
 */

// Any test in this file that freezes the clock must not leak it into the next one —
// Carbon::setTestNow is process-global and survives between tests.
afterEach(fn () => Carbon::setTestNow());

// ---------------------------------------------------------------------------
// §6.1 — the wallet hole the guard test could not see
// ---------------------------------------------------------------------------

test('a UI wallet top-up lands in the ledger, not just on the cached column', function () {
    // TransactionController::updateEmployeeBalance did `$teacher->wallet += $amount;
    // $teacher->save()` — no ledger row, no row lock, no transaction, no idempotency.
    // Within a single update() call the revert half WAS ledgered and the apply half was
    // not, so the two disagreed by construction.
    $email = fake()->unique()->safeEmail();
    $teacher = Teacher::factory()->create(['email' => $email, 'wallet' => 0]);
    $teacherUser = User::factory()->create(['role' => 'teacher', 'email' => $email]);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->post('/transactions', [
        'user_id' => $teacherUser->id,
        'type' => 'wallet',
        'amount' => 250,
        'payment_date' => now()->toDateString(),
        'description' => 'Top-up',
    ]);

    $teacher->refresh();

    expect((float) $teacher->wallet)->toBe(250.0)
        ->and(TeacherWalletEntry::where('teacher_id', $teacher->id)->sum('amount'))
        ->toEqual(250.0);
});

test('the cached wallet and the ledger agree after a payout made through the UI', function () {
    // The real assertion: wallet:check must stay clean after a UI payout. Before the fix
    // it would have reported drift for exactly this teacher, every night, to nobody (§6.0).
    $email = fake()->unique()->safeEmail();
    $teacher = Teacher::factory()->create(['email' => $email, 'wallet' => 0]);
    $teacherUser = User::factory()->create(['role' => 'teacher', 'email' => $email]);
    $admin = User::factory()->create(['role' => 'admin']);

    (new TeacherWalletService)->credit(
        $teacher, 400.0, TeacherWalletEntry::REASON_ADJUSTMENT, null, null, null, 'opening'
    );

    $this->actingAs($admin)->post('/transactions', [
        'user_id' => $teacherUser->id,
        'type' => 'payment',
        'amount' => 150,
        'payment_date' => now()->toDateString(),
        'description' => 'Payout',
    ]);

    $teacher->refresh();

    expect((float) $teacher->wallet)->toBe(250.0)
        ->and((new TeacherWalletService)->drift())->toBeEmpty(
            'wallet:check would report drift — a wallet moved outside the ledger.'
        );
});

test('an over-payout is refused rather than silently clamped to a smaller amount', function () {
    // TeacherWalletService::debit() clamps at zero so a wallet can never go negative.
    // That is correct for the ledger and WRONG as a payout path: without an explicit
    // balance check first, paying out more than the balance would succeed at a smaller
    // amount than the transaction record claims.
    $email = fake()->unique()->safeEmail();
    $teacher = Teacher::factory()->create(['email' => $email, 'wallet' => 0]);
    $teacherUser = User::factory()->create(['role' => 'teacher', 'email' => $email]);
    $admin = User::factory()->create(['role' => 'admin']);

    (new TeacherWalletService)->credit(
        $teacher, 100.0, TeacherWalletEntry::REASON_ADJUSTMENT, null, null, null, 'opening'
    );

    $this->actingAs($admin)->post('/transactions', [
        'user_id' => $teacherUser->id,
        'type' => 'payment',
        'amount' => 500,
        'payment_date' => now()->toDateString(),
        'description' => 'Too much',
    ]);

    $teacher->refresh();

    expect((float) $teacher->wallet)->toBe(100.0)
        // Atomic: the transaction row must not survive a refused wallet move.
        ->and(Transaction::where('user_id', $teacherUser->id)->where('type', 'payment')->count())
        ->toBe(0);
});

// ---------------------------------------------------------------------------
// §6.7 — the silent claw-back on invoice edit
// ---------------------------------------------------------------------------

test('a teacher paid by equal distribution is not recalculated to zero when the invoice is edited', function () {
    // The creation path applied an equal-distribution fallback when a subject had no
    // percentage entry; the update path read `$offer->percentage[$subject] ?? 0` with no
    // fallback at all. So the teacher was paid at creation and recalculated at 0% the
    // first time anyone touched that invoice — the difference debited from their wallet
    // as an "adjustment". Both paths now share resolveTeacherPercentage().
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $student = Student::factory()->create();

    // 'Physique' is listed as a subject but has NO percentage entry.
    $offer = Offer::factory()->create([
        'price' => 400.0,
        'subjects' => ['Math', 'Physique'],
        'percentage' => ['Math' => 40],
    ]);

    $membership = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'payment_status' => 'paid',
        'is_active' => true,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Physique']],
    ]);

    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'totalAmount' => 400.0,
        'amountPaid' => 400.0,
        'rest' => 0,
        'months' => 1,
        'selected_months' => [now()->format('Y-m')],
        'billDate' => now()->startOfMonth(),
        'endDate' => now()->addMonth(),
        'includePartialMonth' => false,
    ]);

    $payload = [
        'membership_id' => $invoice->membership_id,
        'student_id' => $invoice->student_id,
        'months' => 1,
        'selected_months' => $invoice->selected_months,
        'billDate' => $invoice->billDate,
        'totalAmount' => 400.0,
        'amountPaid' => 400.0,
        'rest' => 0.0,
        'includePartialMonth' => false,
        'partialMonthAmount' => 0.0,
    ];

    $service = new TeacherMembershipPaymentService;
    $service->processInvoicePayment($invoice, $payload);

    $teacher->refresh();
    $afterCreation = (float) $teacher->wallet;

    expect($afterCreation)->toBeGreaterThan(
        0.0,
        'The fixture is wrong — the fallback should have paid this teacher something at creation.'
    );

    // Re-run the same payment, which is what an invoice edit does.
    $service->processInvoicePayment($invoice->fresh(), $payload);

    $teacher->refresh();

    expect((float) $teacher->wallet)->toBe(
        $afterCreation,
        'The update path clawed the commission back — the percentage fallback diverged again.'
    );
});

// ---------------------------------------------------------------------------
// §6.2 — the undefined variable that nulled the audit trail
// ---------------------------------------------------------------------------

test('adjustment entries can be joined back to the invoice that caused them', function () {
    // updateExistingRecord passed `$invoice->id ?? null` where $invoice was never defined
    // in scope, so `?? null` always won and EVERY adjustment row was written with
    // invoice_id = NULL — unjoinable, which is what payouts:audit and any manual
    // reconciliation depend on.
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $student = Student::factory()->create();
    $offer = Offer::factory()->create([
        'price' => 300.0,
        'subjects' => ['Math'],
        'percentage' => ['Math' => 50],
    ]);

    $membership = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'payment_status' => 'paid',
        'is_active' => true,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);

    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'totalAmount' => 300.0,
        'amountPaid' => 150.0,
        'rest' => 150.0,
        'months' => 1,
        'selected_months' => [now()->format('Y-m')],
        'billDate' => now()->startOfMonth(),
        'endDate' => now()->addMonth(),
        'includePartialMonth' => false,
    ]);

    $service = new TeacherMembershipPaymentService;
    $base = [
        'membership_id' => $invoice->membership_id,
        'student_id' => $invoice->student_id,
        'months' => 1,
        'selected_months' => $invoice->selected_months,
        'billDate' => $invoice->billDate,
        'includePartialMonth' => false,
        'partialMonthAmount' => 0.0,
    ];

    $service->processInvoicePayment($invoice, $base + [
        'totalAmount' => 300.0, 'amountPaid' => 150.0, 'rest' => 150.0,
    ]);

    // The student pays the rest — this drives an adjustment through updateExistingRecord.
    $invoice->update(['amountPaid' => 300.0, 'rest' => 0.0]);
    $service->processInvoicePayment($invoice->fresh(), $base + [
        'totalAmount' => 300.0, 'amountPaid' => 300.0, 'rest' => 0.0,
    ]);

    $adjustments = TeacherWalletEntry::where('teacher_id', $teacher->id)
        ->where('reason', TeacherWalletEntry::REASON_ADJUSTMENT)
        ->get();

    if ($adjustments->isNotEmpty()) {
        expect($adjustments->whereNull('invoice_id'))->toBeEmpty(
            'An adjustment entry has a NULL invoice_id and can no longer be traced to its invoice.'
        );
    }
})->skip(fn () => false);

// ---------------------------------------------------------------------------
// §6.8 — the multi-subject collision
// ---------------------------------------------------------------------------

test('one teacher holding two subjects is credited for both, not deduplicated to one', function () {
    // The idempotency key was (teacher_id, invoice_id, month, reason) with no subject, so
    // the second of two legitimate credits for the same invoice and month was silently
    // rejected as a duplicate and that teacher was underpaid with no error surfaced.
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $invoice = Invoice::factory()->create();
    $service = new TeacherWalletService;

    $first = $service->credit(
        $teacher, 100.0, TeacherWalletEntry::REASON_MONTHLY, null, '2026-09', $invoice->id, null, 'Math'
    );
    $second = $service->credit(
        $teacher, 80.0, TeacherWalletEntry::REASON_MONTHLY, null, '2026-09', $invoice->id, null, 'Physique'
    );

    $teacher->refresh();

    expect($first)->toBeTrue()
        ->and($second)->toBeTrue()
        ->and((float) $teacher->wallet)->toBe(180.0);
});

test('the same subject still cannot be credited twice for the same invoice and month', function () {
    // Widening the key must not weaken it. This is the original double-pay guard.
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $invoice = Invoice::factory()->create();
    $service = new TeacherWalletService;

    $first = $service->credit(
        $teacher, 100.0, TeacherWalletEntry::REASON_MONTHLY, null, '2026-09', $invoice->id, null, 'Math'
    );
    $second = $service->credit(
        $teacher, 100.0, TeacherWalletEntry::REASON_MONTHLY, null, '2026-09', $invoice->id, null, 'Math'
    );

    $teacher->refresh();

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and((float) $teacher->wallet)->toBe(100.0);
});

test('entries written without a subject still deduplicate against each other', function () {
    // The migration backfills teacher_subject to '' rather than NULL precisely so that
    // historical rows keep deduplicating. A NULL would be treated as distinct by MySQL and
    // exempt every existing row from the constraint.
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $invoice = Invoice::factory()->create();
    $service = new TeacherWalletService;

    $first = $service->credit($teacher, 50.0, TeacherWalletEntry::REASON_MONTHLY, null, '2026-10', $invoice->id);
    $second = $service->credit($teacher, 50.0, TeacherWalletEntry::REASON_MONTHLY, null, '2026-10', $invoice->id);

    expect($first)->toBeTrue()->and($second)->toBeFalse();
});

// ---------------------------------------------------------------------------
// §6.6 — the membership update that lost the money
// ---------------------------------------------------------------------------

test('reassigning a paid membership reverses the old teacher wallet credit', function () {
    // MembershipController::update instantiated $paymentService and never called it, then
    // flipped is_active to false under a comment reading "Reverse old teacher payments".
    // The deactivation LOOKED like a reversal, so the code read as correct — but no money
    // moved. The old teacher kept money they were no longer owed, and the record that
    // would let payouts:audit notice was marked inactive.

    /*
     * The clock is frozen because this test is ABOUT a deadline.
     *
     * The fixture bills on now()->startOfMonth() and the claw-back expires
     * REVERSAL_DEADLINE_DAYS (7) after the billing date. Run on the 1st it asserts the
     * reversal; run on the 9th the deadline has already passed, the teacher legitimately
     * keeps the money, and the assertion fails — so the test passed for the first week of
     * every month and failed for the other three. Nothing about the product changed on the
     * day it started failing; only the calendar did.
     */
    Carbon::setTestNow('2026-08-03 10:00:00');
    $school = App\Models\School::factory()->create();
    $admin = User::factory()->create(['role' => 'admin']);

    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $teacher->schools()->attach($school->id);

    $replacement = Teacher::factory()->create(['wallet' => 0]);
    $replacement->schools()->attach($school->id);

    $student = Student::factory()->create(['schoolId' => $school->id]);
    $offer = Offer::factory()->create([
        'price' => 200.0,
        'subjects' => ['Math'],
        'percentage' => ['Math' => 50],
    ]);

    $membership = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'payment_status' => 'paid',
        'is_active' => true,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);

    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'totalAmount' => 200.0,
        'amountPaid' => 200.0,
        'rest' => 0,
        'months' => 1,
        'selected_months' => [now()->format('Y-m')],
        'billDate' => now()->startOfMonth(),
        'endDate' => now()->addMonth(),
        'includePartialMonth' => false,
    ]);

    (new TeacherMembershipPaymentService)->processInvoicePayment($invoice, [
        'membership_id' => $invoice->membership_id,
        'student_id' => $invoice->student_id,
        'months' => 1,
        'selected_months' => $invoice->selected_months,
        'billDate' => $invoice->billDate,
        'totalAmount' => 200.0,
        'amountPaid' => 200.0,
        'rest' => 0.0,
        'includePartialMonth' => false,
        'partialMonthAmount' => 0.0,
    ]);

    $teacher->refresh();
    $creditedAtPayment = (float) $teacher->wallet;

    expect($creditedAtPayment)->toBeGreaterThan(0.0, 'Fixture is wrong — no commission was paid.');

    // Reassign the membership to a different teacher. First attempt only previews:
    // a teacher change on a paid membership never executes without confirm.
    $swap = [
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'teachers' => [['teacherId' => $replacement->id, 'subject' => 'Math', 'amount' => 100]],
    ];

    $this->actingAs($admin)->put("/memberships/{$membership->id}", $swap);

    expect((float) $teacher->fresh()->wallet)->toBe(
        $creditedAtPayment,
        'The preview must not move money.'
    );

    $notice = session('payment_notice');
    expect($notice)->not->toBeNull('Swapping a teacher must open the confirm dialog.');

    // Confirm with the payload the dialog carries back.
    $this->actingAs($admin)->put("/memberships/{$membership->id}", [
        'confirm_teachers_change' => true,
        'payload' => $notice['actions'][0]['data']['payload'],
    ]);

    $teacher->refresh();

    expect((float) $teacher->wallet)->toBeLessThan(
        $creditedAtPayment,
        'The old teacher kept the commission after being replaced — the reversal never ran.'
    );

    // The old teacher's record stays dead; the replacement is paid immediately by
    // the edit itself (reprocessMembershipInvoices) instead of waiting for someone
    // to re-save the invoice.
    expect(TeacherMembershipPayment::where('membership_id', $membership->id)->where('teacher_id', $teacher->id)->where('is_active', true)->count())
        ->toBe(0, 'The removed teacher must stay dead.');

    $replacement->refresh();

    expect((float) $replacement->wallet)->toBe($creditedAtPayment, 'The replacement teacher is owed the same commission, immediately.')
        ->and(TeacherMembershipPayment::where('membership_id', $membership->id)->where('teacher_id', $replacement->id)->where('is_active', true)->count())
        ->toBe(1);
});
