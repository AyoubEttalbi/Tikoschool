<?php

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;
use App\Services\TeacherMembershipPaymentService;
use Carbon\Carbon;
use Spatie\Activitylog\Models\Activity;

/*
 * Two audit surfaces for the wallet:
 *
 * 1. wallet:audit-invoices — per (teacher, invoice), record totals vs ledger
 *    net. Catches the 7027 class (double reversal) that wallet:check cannot
 *    see, because the cached wallet and the ledger agree there.
 * 2. Offer percentage edits land in the activity log with old/new values, so
 *    the next "gains moved but nobody touched an invoice" takes one query.
 */

function makeAuditInvoice(float $paid = 300.0): array
{
    // Level names are unique from a pool of two — reuse the row.
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

    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'billDate' => Carbon::now(),
        'creationDate' => Carbon::now(),
        'months' => 1,
        'selected_months' => [$month],
        'totalAmount' => 300,
        'amountPaid' => $paid,
        'rest' => round(300 - $paid, 2),
        'includePartialMonth' => false,
        'partialMonthAmount' => null,
        'last_payment_date' => Carbon::now(),
    ]);

    (new TeacherMembershipPaymentService)->processInvoicePayment($invoice, [
        'totalAmount' => 300,
        'amountPaid' => $paid,
        'rest' => round(300 - $paid, 2),
        'includePartialMonth' => false,
        'partialMonthAmount' => 0,
    ]);

    return [$teacher, $invoice];
}

test('the audit passes a healthy invoice', function () {
    [$teacher] = makeAuditInvoice();

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(150.0);

    $this->artisan('wallet:audit-invoices')->assertExitCode(0);
});

test('the audit stays clean on unpaid and healed invoices', function () {
    // A paid invoice seeds the ledger (so ledgerStart exists), then an unpaid
    // one (0/0 record, no entries) and a reversed-then-healed one must not flag.
    [$teacher] = makeAuditInvoice();
    [, $unpaid] = makeAuditInvoice(paid: 0.0);
    [$healedTeacher, $healed] = makeAuditInvoice();

    (new \App\Services\TeacherMembershipPaymentService)->reverseInvoicePayments($healed->fresh());

    expect(round((float) $healedTeacher->fresh()->wallet, 2))->toBe(0.0);

    $this->artisan('wallet:audit-invoices')->assertExitCode(0);
});

test('the audit flags a double-reversed invoice', function () {
    [$teacher, $invoice] = makeAuditInvoice();

    // A stale record against an emptied invoice: billed +150, fully reversed
    // to a 0.00 net, record totals left at 150 (prod 7027 shape).
    TeacherWalletEntry::create([
        'teacher_id' => $teacher->id,
        'invoice_id' => $invoice->id,
        'reason' => TeacherWalletEntry::REASON_REVERSAL,
        'amount' => -150.0,
        'teacher_subject' => 'Math',
        'note' => 'test double reversal',
    ]);
    Teacher::whereKey($teacher->id)->update(['wallet' => -150.0]);
    TeacherMembershipPayment::where('invoice_id', $invoice->id)
        ->where('teacher_id', $teacher->id)
        ->update(['total_paid_to_teacher' => 150.0, 'immediate_wallet_amount' => 150.0, 'is_active' => false]);

    $this->artisan('wallet:audit-invoices')
        ->assertExitCode(1)
        ->expectsOutputToContain((string) $invoice->id);
});

test('editing an offer percentage writes an audit row with old and new', function () {
    \App\Models\Level::factory()->create();
    $offer = Offer::factory()->create([
        'price' => 300.0,
        'subjects' => ['Math'],
        'percentage' => ['Math' => 50],
    ]);

    $offer->update(['percentage' => ['Math' => 60]]);

    $logged = Activity::where('subject_type', Offer::class)
        ->where('subject_id', $offer->id)
        ->latest('id')
        ->first();

    expect($logged)->not->toBeNull('An offer percentage edit must leave a trace.')
        ->and(json_encode($logged->properties))->toContain('50')
        ->and(json_encode($logged->properties))->toContain('60');
});

test('renaming an offer without touching percentages writes no audit row', function () {
    \App\Models\Level::factory()->create();
    $offer = Offer::factory()->create([
        'price' => 300.0,
        'subjects' => ['Math'],
        'percentage' => ['Math' => 50],
    ]);

    $offer->update(['offer_name' => $offer->offer_name.' v2']);

    expect(Activity::where('subject_type', Offer::class)
        ->where('subject_id', $offer->id)->count())->toBe(0);
});
