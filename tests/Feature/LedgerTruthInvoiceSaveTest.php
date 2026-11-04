<?php

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherWalletEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * Through the real controller: only a save that changes money settles wallets.
 * A plain re-save — even after the monthly job paid the month, even after the
 * offer rate moved — writes no ledger row (prod CRC 7502, Sept-5 re-save class).
 */

afterEach(fn () => Carbon::setTestNow());

function saveFixture(): array
{
    \App\Models\Level::first() ?? \App\Models\Level::factory()->create();
    $admin = User::factory()->create(['role' => 'admin']);
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $student = Student::factory()->create();
    $offer = Offer::factory()->create(['price' => 300, 'subjects' => ['Math'], 'percentage' => ['Math' => 20]]);
    $membership = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'payment_status' => 'pending',
        'is_active' => true,
        'end_date' => null,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);

    return compact('admin', 'teacher', 'student', 'offer', 'membership');
}

function invoicePayload(array $f, string $month, float $paid): array
{
    return [
        'membership_id' => $f['membership']->id,
        'student_id' => $f['student']->id,
        'months' => 1,
        'selected_months' => [$month],
        'billDate' => $month.'-01',
        'creationDate' => $month.'-01',
        'endDate' => Carbon::parse($month.'-01')->endOfMonth()->toDateString(),
        'totalAmount' => 300,
        'amountPaid' => $paid,
        'rest' => 300 - $paid,
        'includePartialMonth' => false,
    ];
}

test('a no-change re-save after the monthly job moves no money', function () {
    Carbon::setTestNow('2026-09-25 21:40:00');
    $f = saveFixture();
    $this->actingAs($f['admin'])->post('/invoices', invoicePayload($f, '2026-10', 300))->assertSessionHasNoErrors();
    $invoice = Invoice::where('membership_id', $f['membership']->id)->firstOrFail();

    Carbon::setTestNow('2026-10-01 02:00:00');
    (new App\Services\TeacherMembershipPaymentService)->processMonthlyPayments('2026-10');
    expect(round((float) $f['teacher']->fresh()->wallet, 2))->toBe(60.0);

    Carbon::setTestNow('2026-10-06 16:58:00');
    $rows = TeacherWalletEntry::count();
    $this->actingAs($f['admin'])->put("/invoices/{$invoice->id}", invoicePayload($f, '2026-10', 300))->assertSessionHasNoErrors();

    expect(round((float) $f['teacher']->fresh()->wallet, 2))->toBe(60.0)
        ->and(TeacherWalletEntry::count())->toBe($rows);
});

test('a no-change re-save after a rate change does not re-rate the invoice', function () {
    Carbon::setTestNow('2026-09-10 10:00:00');
    $f = saveFixture();
    $this->actingAs($f['admin'])->post('/invoices', invoicePayload($f, '2026-09', 300))->assertSessionHasNoErrors();
    $invoice = Invoice::where('membership_id', $f['membership']->id)->firstOrFail();
    expect(round((float) $f['teacher']->fresh()->wallet, 2))->toBe(60.0);

    $f['offer']->update(['percentage' => ['Math' => 10]]);

    Carbon::setTestNow('2026-10-20 10:00:00');
    $rows = TeacherWalletEntry::count();
    $this->actingAs($f['admin'])->put("/invoices/{$invoice->id}", invoicePayload($f, '2026-09', 300))->assertSessionHasNoErrors();

    expect(round((float) $f['teacher']->fresh()->wallet, 2))->toBe(60.0, 'A plain re-save never debits a teacher.')
        ->and(TeacherWalletEntry::count())->toBe($rows);
});

test('a top-up through the controller after the monthly job pays only the difference', function () {
    Carbon::setTestNow('2026-09-29 21:13:00');
    $f = saveFixture();
    $this->actingAs($f['admin'])->post('/invoices', invoicePayload($f, '2026-10', 250))->assertSessionHasNoErrors();
    $invoice = Invoice::where('membership_id', $f['membership']->id)->firstOrFail();

    Carbon::setTestNow('2026-10-01 02:00:00');
    (new App\Services\TeacherMembershipPaymentService)->processMonthlyPayments('2026-10');
    expect(round((float) $f['teacher']->fresh()->wallet, 2))->toBe(50.0);

    Carbon::setTestNow('2026-10-03 17:47:00');
    $this->actingAs($f['admin'])->put("/invoices/{$invoice->id}", invoicePayload($f, '2026-10', 300))->assertSessionHasNoErrors();

    expect(round((float) $f['teacher']->fresh()->wallet, 2))->toBe(60.0, '20% of 300 — not 50 + 60.');
});
