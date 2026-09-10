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

/*
 * Prod Sept 2026, invoice 7027 (Centre): one credit (+100), two reversals
 * (−100, −100). The second reversal fired from stale record totals on an
 * already-reversed record and left the teacher at −100 on a live paid invoice.
 *
 * These tests pin the ledger-truth guard: a reversal may never take back more
 * than the wallet still holds for that (teacher, invoice).
 */

class GuardScenario
{
    public Teacher $teacher;

    public Student $student;

    public Membership $membership;

    public Invoice $invoice;

    public function __construct(float $monthlyPrice = 300.0)
    {
        $this->teacher = Teacher::factory()->create(['wallet' => 0]);
        $this->student = Student::factory()->create();
        $offer = Offer::factory()->create([
            'price' => $monthlyPrice,
            'subjects' => ['Math'],
            'percentage' => ['Math' => 50],
        ]);

        $this->membership = Membership::factory()->create([
            'student_id' => $this->student->id,
            'offer_id' => $offer->id,
            'payment_status' => 'pending',
            'is_active' => true,
            'teachers' => [['teacherId' => $this->teacher->id, 'subject' => 'Math']],
        ]);
    }

    /** Bill one month, fully paid today — mirrors InvoiceController::store(). */
    public function bill(float $paid = 300.0): self
    {
        $month = Carbon::now()->format('Y-m');

        $this->invoice = Invoice::factory()->create([
            'membership_id' => $this->membership->id,
            'student_id' => $this->student->id,
            'offer_id' => $this->membership->offer_id,
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

        (new TeacherMembershipPaymentService)->processInvoicePayment($this->invoice, [
            'totalAmount' => 300,
            'amountPaid' => $paid,
            'rest' => round(300 - $paid, 2),
            'includePartialMonth' => false,
            'partialMonthAmount' => 0,
        ]);

        return $this;
    }

    public function wallet(): float
    {
        return round((float) $this->teacher->fresh()->wallet, 2);
    }

    public function reversalCount(): int
    {
        return TeacherWalletEntry::where('teacher_id', $this->teacher->id)
            ->where('invoice_id', $this->invoice->id)
            ->where('reason', TeacherWalletEntry::REASON_REVERSAL)
            ->count();
    }
}

test('a second reversal against stale record totals takes nothing', function () {
    $s = (new GuardScenario)->bill();

    expect($s->wallet())->toBe(150.0);

    $service = new TeacherMembershipPaymentService;
    $service->reverseInvoicePayments($s->invoice->fresh());

    expect($s->wallet())->toBe(0.0)
        ->and($s->reversalCount())->toBe(1);

    // Simulate the pre-fix staleness (prod 7027): totals still read fully paid
    // even though the money is gone. The wallet itself is funded from other
    // invoices — exactly why prod kept debiting instead of hitting the
    // wallet-empty guard.
    Teacher::whereKey($s->teacher->id)->update(['wallet' => 1000.0]);
    TeacherMembershipPayment::where('invoice_id', $s->invoice->id)
        ->where('teacher_id', $s->teacher->id)
        ->update(['total_paid_to_teacher' => 150.0, 'immediate_wallet_amount' => 150.0, 'is_active' => true]);

    $outcome = $service->reverseInvoicePayments($s->invoice->fresh());

    expect($s->wallet())->toBe(1000.0, 'The wallet must not fund a repeat reversal.')
        ->and($s->reversalCount())->toBe(1, 'No second reversal row may be written.')
        ->and((float) ($outcome['total_reversed'] ?? 0))->toBe(0.0)
        ->and($outcome['skipped'] ?? [])->toHaveCount(1, 'The refusal must be reported, not silent.')
        ->and(($outcome['skipped'][0]['reason'] ?? null))->toBe('already_reversed');

    $record = TeacherMembershipPayment::where('invoice_id', $s->invoice->id)
        ->where('teacher_id', $s->teacher->id)
        ->first();

    expect((float) $record->total_paid_to_teacher)->toBe(0.0, 'Skip must heal the stale totals or the audit flags forever.')
        ->and((float) $record->immediate_wallet_amount)->toBe(0.0);
});

test('a reversal with no attributed ledger rows still fires (legacy path)', function () {
    // Pre-ledger rows have record totals but no entries at all — the guard
    // must not convert their legitimate reversal into a silent skip.
    $s = (new GuardScenario)->bill(paid: 0.0);

    TeacherWalletEntry::where('teacher_id', $s->teacher->id)->delete();
    TeacherMembershipPayment::where('invoice_id', $s->invoice->id)->delete();

    // Legacy shape: totals say paid, ledger knows nothing, wallet funded
    // outside the ledger (opening balance era).
    Teacher::whereKey($s->teacher->id)->update(['wallet' => 500.0]);
    TeacherMembershipPayment::create([
        'teacher_id' => $s->teacher->id,
        'membership_id' => $s->membership->id,
        'invoice_id' => $s->invoice->id,
        'student_id' => $s->student->id,
        'selected_months' => [Carbon::now()->format('Y-m')],
        'months_rest_not_paid_yet' => [],
        'total_teacher_amount' => 150.0,
        'monthly_teacher_amount' => 150.0,
        'payment_percentage' => 100.0,
        'teacher_subject' => 'Math',
        'teacher_percentage' => 50.0,
        'immediate_wallet_amount' => 150.0,
        'total_paid_to_teacher' => 150.0,
        'is_active' => true,
    ]);

    (new TeacherMembershipPaymentService)->reverseInvoicePayments($s->invoice->fresh());

    expect($s->wallet())->toBe(350.0);
});

test('a reversal is capped at what the wallet still holds for the invoice', function () {
    $s = (new GuardScenario)->bill();

    expect($s->wallet())->toBe(150.0);

    // A prior manual claw-back against the same invoice: only 100 of the 150
    // is still held, even though the record totals still read 150.
    TeacherWalletEntry::create([
        'teacher_id' => $s->teacher->id,
        'invoice_id' => $s->invoice->id,
        'reason' => TeacherWalletEntry::REASON_ADJUSTMENT,
        'amount' => -50.0,
        'teacher_subject' => 'Math',
        'note' => 'test claw-back',
    ]);
    Teacher::whereKey($s->teacher->id)->update(['wallet' => 100.0]);

    $outcome = (new TeacherMembershipPaymentService)->reverseInvoicePayments($s->invoice->fresh());

    expect($s->wallet())->toBe(0.0)
        ->and(count($outcome['applied'] ?? []))->toBe(1, 'The capped remainder still reverses normally.')
        ->and(($outcome['skipped'] ?? []))->toBe([])
        ->and((float) TeacherWalletEntry::where('teacher_id', $s->teacher->id)
            ->where('invoice_id', $s->invoice->id)
            ->where('reason', TeacherWalletEntry::REASON_REVERSAL)
            ->sum('amount'))->toBe(-100.0, 'The reversal must stop at the 100 still held, not the 150 on the record.');
});

test('a stale subject cannot re-fire out of a sibling money pot', function () {
    // One teacher, two subjects, one invoice: Math is reversed, French still
    // holds 100. A pool-scoped cap would let stale Math totals debit French money.
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $student = Student::factory()->create();
    $offer = Offer::factory()->create([
        'price' => 300.0,
        'subjects' => ['Math', 'French'],
        'percentage' => ['Math' => 50, 'French' => 50],
    ]);

    $membership = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'payment_status' => 'pending',
        'is_active' => true,
        'teachers' => [
            ['teacherId' => $teacher->id, 'subject' => 'Math'],
            ['teacherId' => $teacher->id, 'subject' => 'French'],
        ],
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
        'amountPaid' => 300,
        'rest' => 0,
        'includePartialMonth' => false,
        'partialMonthAmount' => null,
        'last_payment_date' => Carbon::now(),
    ]);

    (new TeacherMembershipPaymentService)->processInvoicePayment($invoice, [
        'totalAmount' => 300,
        'amountPaid' => 300,
        'rest' => 0,
        'includePartialMonth' => false,
        'partialMonthAmount' => 0,
    ]);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(300.0);

    $service = new TeacherMembershipPaymentService;
    $service->reverseInvoicePayments($invoice->fresh());

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(0.0);

    // French money comes back through another invoice; Math totals go stale.
    TeacherWalletEntry::create([
        'teacher_id' => $teacher->id,
        'invoice_id' => $invoice->id,
        'reason' => TeacherWalletEntry::REASON_ADJUSTMENT,
        'amount' => 100.0,
        'teacher_subject' => 'French',
        'note' => 'test French remainder',
    ]);
    Teacher::whereKey($teacher->id)->update(['wallet' => 100.0]);
    TeacherMembershipPayment::where('invoice_id', $invoice->id)
        ->where('teacher_id', $teacher->id)
        ->where('teacher_subject', 'Math')
        ->update(['total_paid_to_teacher' => 150.0, 'immediate_wallet_amount' => 150.0, 'is_active' => true]);

    $outcome = $service->reverseInvoicePayments($invoice->fresh());

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(100.0, 'French money must not fund a Math re-reversal.')
        ->and(collect($outcome['skipped'] ?? [])->pluck('subject')->all())->toContain('Math');
});
