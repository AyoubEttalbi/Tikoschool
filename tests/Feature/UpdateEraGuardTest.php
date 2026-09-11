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
use Carbon\Carbon;

/*
 * The Sept-5 hiba session (−750 DH across 11 live 2025–26 invoices): editing a
 * membership reprocessed every invoice, and updateExistingRecord() recomputed
 * the "immediate for the current month" against wall-clock now() — zero for an
 * old invoice — against stale creation-era totals, debiting the full share
 * with no dialog, no confirm and no invoice-update log row.
 *
 * The era guard: when the current month is nowhere in the invoice's months,
 * there is no current-month money to (re)compute — the old immediate stands
 * and the delta is zero.
 */

afterEach(fn () => Carbon::setTestNow());

function makeEraMembership(): array
{
    \App\Models\Level::first() ?? \App\Models\Level::factory()->create();
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
        'payment_status' => 'pending',
        'is_active' => true,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);

    return [$teacher, $student, $membership, $offer];
}

function billEraInvoice(Membership $membership, Student $student, string $billDate, array $months, float $paid = 300.0): Invoice
{
    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'offer_id' => $membership->offer_id,
        'billDate' => $billDate,
        'creationDate' => $billDate,
        'months' => count($months),
        'selected_months' => $months,
        'totalAmount' => 300,
        'amountPaid' => $paid,
        'rest' => round(300 - $paid, 2),
        'includePartialMonth' => false,
        'partialMonthAmount' => null,
        'last_payment_date' => Carbon::parse($billDate),
    ]);

    (new TeacherMembershipPaymentService)->processInvoicePayment($invoice, [
        'totalAmount' => 300,
        'amountPaid' => $paid,
        'rest' => round(300 - $paid, 2),
        'includePartialMonth' => false,
        'partialMonthAmount' => 0,
    ]);

    return $invoice;
}

function reprocessEraInvoice(Invoice $invoice): void
{
    (new TeacherMembershipPaymentService)->processInvoicePayment($invoice->fresh(), [
        'totalAmount' => (float) $invoice->totalAmount,
        'amountPaid' => (float) $invoice->amountPaid,
        'rest' => (float) $invoice->rest,
        'includePartialMonth' => (bool) $invoice->includePartialMonth,
        'partialMonthAmount' => (float) ($invoice->partialMonthAmount ?? 0),
    ]);
}

test('re-saving an old paid invoice moves no money', function () {
    [$teacher, $student, $membership] = makeEraMembership();

    // Bill and pay while October 2025 is "now": immediate 150 credited.
    Carbon::setTestNow(Carbon::parse('2025-10-15 10:00:00'));
    $invoice = billEraInvoice($membership, $student, '2025-10-01', ['2025-10']);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(150.0);

    // Months later, a plain re-save (the Sept-5 bulk retagging shape).
    Carbon::setTestNow(Carbon::parse('2026-09-05 19:43:11'));
    $before = TeacherWalletEntry::count();
    reprocessEraInvoice($invoice);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(150.0, 'No current-month money exists on a 2025 invoice.')
        ->and(TeacherWalletEntry::count())->toBe($before, 'Not even ledger noise.');
});

test('paying more on an old invoice still reaches the teacher', function () {
    [$teacher, $student, $membership] = makeEraMembership();

    Carbon::setTestNow(Carbon::parse('2025-10-15 10:00:00'));
    $invoice = billEraInvoice($membership, $student, '2025-10-01', ['2025-10'], paid: 100.0);

    // Back-pay path, not the immediate: 100 of 300 paid -> 50 held.
    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(50.0);

    Carbon::setTestNow(Carbon::parse('2026-09-05 19:43:11'));
    $invoice->update(['amountPaid' => 300.0, 'rest' => 0.0]);
    reprocessEraInvoice($invoice);
    (new TeacherMembershipPaymentService)->reconcilePaidMonthsForInvoice($invoice->fresh());

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(150.0, 'Late payment on an old invoice must still pay out.');
});

test('adding the current month to an old invoice credits it', function () {
    [$teacher, $student, $membership] = makeEraMembership();

    Carbon::setTestNow(Carbon::parse('2025-10-15 10:00:00'));
    $invoice = billEraInvoice($membership, $student, '2025-10-01', ['2025-10']);

    Carbon::setTestNow(Carbon::parse('2026-09-05 19:43:11'));
    $invoice->update([
        'selected_months' => ['2025-10', '2026-09'],
        'months' => 2,
        'totalAmount' => 600,
        'amountPaid' => 600,
        'rest' => 0.0,
    ]);
    reprocessEraInvoice($invoice);
    // The controller reconciles whenever the paid amount moved.
    (new TeacherMembershipPaymentService)->reconcilePaidMonthsForInvoice($invoice->fresh());

    expect(round((float) $teacher->fresh()->wallet, 2))->toBeGreaterThan(
        150.0, 'A newly added current month is new money and must be credited.'
    );
});

test('current-month invoices recompute exactly as before', function () {
    [$teacher, $student, $membership] = makeEraMembership();

    $month = Carbon::now()->format('Y-m');
    $invoice = billEraInvoice($membership, $student, Carbon::now()->toDateString(), [$month], paid: 100.0);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(50.0);

    $invoice->update(['amountPaid' => 300.0, 'rest' => 0.0]);
    reprocessEraInvoice($invoice);
    (new TeacherMembershipPaymentService)->reconcilePaidMonthsForInvoice($invoice->fresh());

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(150.0);
});

test('an adjustment debit cannot exceed what the invoice still holds', function () {
    [$teacher, $student, $membership] = makeEraMembership();

    $month = Carbon::now()->format('Y-m');
    $invoice = billEraInvoice($membership, $student, Carbon::now()->toDateString(), [$month]);

    // Fund from elsewhere so the clamp, not the zero floor, is what binds.
    Teacher::whereKey($teacher->id)->update(['wallet' => 1000.0]);

    // Prior manual claw-back: 100 of the 150 still held.
    TeacherWalletEntry::create([
        'teacher_id' => $teacher->id,
        'invoice_id' => $invoice->id,
        'reason' => TeacherWalletEntry::REASON_ADJUSTMENT,
        'amount' => -50.0,
        'teacher_subject' => 'Math',
        'note' => 'test prior claw-back',
    ]);
    Teacher::whereKey($teacher->id)->update(['wallet' => 950.0]);

    // Correct the payment down to zero: uncapped this debits 150, capped 100.
    $invoice->update(['amountPaid' => 0.0, 'rest' => 300.0]);
    reprocessEraInvoice($invoice);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(850.0);
});

test('a subject respelled across edits does not fork a second pay chain', function () {
    \App\Models\Level::first() ?? \App\Models\Level::factory()->create();
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
        'payment_status' => 'pending',
        'is_active' => true,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);

    $month = Carbon::now()->format('Y-m');
    $invoice = billEraInvoice($membership, $student, Carbon::now()->toDateString(), [$month]);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(150.0);

    // Secretary retypes the subject with stray spaces; the lookup must still match.
    $membership->update(['teachers' => [['teacherId' => $teacher->id, 'subject' => ' math ']]]);
    $before = TeacherWalletEntry::count();
    reprocessEraInvoice($invoice);

    expect(TeacherMembershipPayment::where('invoice_id', $invoice->id)
        ->where('teacher_id', $teacher->id)->count())->toBe(1, 'One teacher, one record.')
        ->and(round((float) $teacher->fresh()->wallet, 2))->toBe(150.0, 'No second immediate for a respelling.')
        ->and(TeacherWalletEntry::count())->toBe($before);
});

test('an expired membership teacher swap still runs the money path', function () {
    \App\Models\Level::first() ?? \App\Models\Level::factory()->create();
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
        'payment_status' => 'expired',
        'is_active' => true,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);

    $month = Carbon::now()->format('Y-m');
    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'totalAmount' => 200.0,
        'amountPaid' => 200.0,
        'rest' => 0,
        'months' => 1,
        'selected_months' => [$month],
        'billDate' => now()->startOfMonth(),
        'endDate' => now()->addMonth(),
        'includePartialMonth' => false,
        'last_payment_date' => now(),
    ]);

    (new TeacherMembershipPaymentService)->processInvoicePayment($invoice, [
        'totalAmount' => 200.0,
        'amountPaid' => 200.0,
        'rest' => 0.0,
        'includePartialMonth' => false,
        'partialMonthAmount' => 0.0,
    ]);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(100.0);

    // Swap on an expired membership: the money path must run, starting with
    // the confirm dialog — never a silent plain save.
    $this->actingAs($admin)->put("/memberships/{$membership->id}", [
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'teachers' => [['teacherId' => $replacement->id, 'subject' => 'Math', 'amount' => 100]],
    ])->assertRedirect();

    expect(session()->has('payment_notice'))->toBeTrue('An expired status must not disarm the money path.');

    $this->actingAs($admin)->put("/memberships/{$membership->id}", [
        'confirm_teachers_change' => true,
        'payload' => [
            'student_id' => $student->id,
            'offer_id' => $offer->id,
            'teachers' => [['teacherId' => $replacement->id, 'subject' => 'Math', 'amount' => 100]],
        ],
    ])->assertRedirect();

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(0.0, 'Old teacher reversed on confirm.')
        ->and(round((float) $replacement->fresh()->wallet, 2))->toBe(100.0, 'Replacement credited on confirm.');
});
