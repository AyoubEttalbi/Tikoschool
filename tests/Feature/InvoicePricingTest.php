<?php

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\InvoicePricingService;
use Carbon\Carbon;

/*
 * The invoice money fields must be decided by the SERVER.
 *
 * The pro-rata formula used to live only in resources/js/Components/forms/InvoicesFrom.jsx
 * and InvoiceController stored whatever the browser posted, so a crafted request could set
 * any price — and mint teacher wallet credit, since commission is a percentage of the invoice.
 */

function makePricedMembership(float $monthlyPrice = 300.0): array
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
        'is_active' => true,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);

    return [$teacher, $student, $membership];
}

test('the service prices whole months at the offer price', function () {
    [, , $membership] = makePricedMembership(300);

    $priced = (new InvoicePricingService())->price(
        $membership,
        ['2025-09', '2025-10', '2025-11'],
        false,
        '2025-09-01'
    );

    expect($priced['totalAmount'])->toBe(900.0)
        ->and($priced['monthsCount'])->toBe(3)
        ->and($priced['partialMonthAmount'])->toBe(0.0);
});

test('a non-consecutive month selection charges no full months', function () {
    [, , $membership] = makePricedMembership(300);

    $priced = (new InvoicePricingService())->price(
        $membership,
        ['2025-09', '2025-12'],
        false,
        '2025-09-01'
    );

    expect($priced['monthsAreConsecutive'])->toBeFalse()
        ->and($priced['monthsCount'])->toBe(0)
        ->and($priced['totalAmount'])->toBe(0.0);
});

test('the partial month is charged pro-rata for the remaining days', function () {
    [, , $membership] = makePricedMembership(300);

    // 30-day month, billed on the 10th -> 20 remaining days -> 300/30*20 = 200
    $priced = (new InvoicePricingService())->price($membership, [], true, '2025-09-10');

    expect($priced['partialMonthAmount'])->toBe(200.0)
        ->and($priced['totalAmount'])->toBe(200.0);
});

test('billing on the last day of the month charges no partial amount', function () {
    [, , $membership] = makePricedMembership(300);

    $priced = (new InvoicePricingService())->price($membership, [], true, '2025-09-30');

    expect($priced['partialMonthAmount'])->toBe(0.0);
});

test('a client cannot inflate the invoice total', function () {
    [, $student, $membership] = makePricedMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 1,
        'selected_months' => ['2025-09'],
        'billDate' => '2025-09-01',
        'creationDate' => '2025-09-01',
        // The attack: claim a far higher total than the offer supports.
        'totalAmount' => 999999,
        'amountPaid' => 999999,
        'rest' => 0,
        'includePartialMonth' => false,
    ]);

    $invoice = Invoice::latest('id')->first();

    expect($invoice)->not->toBeNull()
        ->and((float) $invoice->totalAmount)->toBe(300.0)
        ->and((float) $invoice->amountPaid)->toBe(300.0);
});

test('a client cannot mint teacher wallet credit via partialMonthAmount', function () {
    [$teacher, $student, $membership] = makePricedMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 1,
        'selected_months' => ['2025-09'],
        'billDate' => '2025-09-01',
        'creationDate' => '2025-09-01',
        'totalAmount' => 300,
        'amountPaid' => 300,
        'rest' => 0,
        'includePartialMonth' => true,
        // The attack: an unbounded partial amount flows into the commission calculation.
        'partialMonthAmount' => 999999,
    ]);

    $teacher->refresh();

    // 50% of anything the offer can legitimately produce is far below this.
    expect((float) $teacher->wallet)->toBeLessThan(100000.0);
});

test('a discount is honoured but recorded', function () {
    [, $student, $membership] = makePricedMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 1,
        'selected_months' => ['2025-09'],
        'billDate' => '2025-09-01',
        'creationDate' => '2025-09-01',
        'totalAmount' => 250, // legitimate downward adjustment
        'amountPaid' => 250,
        'rest' => 0,
        'includePartialMonth' => false,
    ]);

    $invoice = Invoice::latest('id')->first();

    expect((float) $invoice->totalAmount)->toBe(250.0);
});

test('rest is always derived from total minus paid', function () {
    [, $student, $membership] = makePricedMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 1,
        'selected_months' => ['2025-09'],
        'billDate' => '2025-09-01',
        'creationDate' => '2025-09-01',
        'totalAmount' => 300,
        'amountPaid' => 100,
        // A deliberately inconsistent `rest` — the server must ignore it.
        'rest' => 0,
        'includePartialMonth' => false,
    ]);

    $invoice = Invoice::latest('id')->first();

    expect((float) $invoice->rest)->toBe(200.0);
});

test('the price quote endpoint agrees with what store() charges', function () {
    [, $student, $membership] = makePricedMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    $quote = $this->actingAs($admin)->postJson('/invoices/price', [
        'membership_id' => $membership->id,
        'selected_months' => ['2025-09', '2025-10'],
        'includePartialMonth' => false,
        'billDate' => '2025-09-01',
    ])->assertSuccessful()->json();

    $this->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 2,
        'selected_months' => ['2025-09', '2025-10'],
        'billDate' => '2025-09-01',
        'creationDate' => '2025-09-01',
        'totalAmount' => $quote['totalAmount'],
        'amountPaid' => $quote['totalAmount'],
        'rest' => 0,
        'includePartialMonth' => false,
    ]);

    $invoice = Invoice::latest('id')->first();

    expect((float) $invoice->totalAmount)->toBe((float) $quote['totalAmount']);
});
