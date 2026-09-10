<?php

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\InvoicePricingService;

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

    $priced = (new InvoicePricingService)->price(
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

    $priced = (new InvoicePricingService)->price(
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
    $priced = (new InvoicePricingService)->price($membership, [], true, '2025-09-10');

    expect($priced['partialMonthAmount'])->toBe(200.0)
        ->and($priced['totalAmount'])->toBe(200.0);
});

test('billing on the last day of the month charges no partial amount', function () {
    [, , $membership] = makePricedMembership(300);

    $priced = (new InvoicePricingService)->price($membership, [], true, '2025-09-30');

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

    // A second post with a *plausible* client partial (300, passes the lte guard)
    // must still be overwritten by the server-computed rounded value (300/30*29 = 290).
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
        'partialMonthAmount' => 300,
    ]);

    expect((float) Invoice::latest('id')->first()->partialMonthAmount)->toBe(290.0);
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

/*
 * Tikoschool customization (see CUSTOMIZATIONS.md): the school keeps no coin
 * change, so the partial-month charge is rounded to the nearest multiple of 5 DH:
 * 120,121,122 -> 120 ; 123..127 -> 125 ; 128,129 -> 130.
 * Stored rows are never migrated, and updating an invoice without touching its
 * billing inputs keeps its stored price — only newly computed prices change.
 */

test('the partial month rounds to the nearest 5 DH — client examples', function () {
    // Billed 2025-09-15: 15 of 30 days remain, so raw = price / 2 exactly.
    [, , $m242] = makePricedMembership(242);
    [, , $m288] = makePricedMembership(288);
    [, , $m292] = makePricedMembership(292);

    $pricing = new InvoicePricingService;

    // 121 -> 120, 144 -> 145, 146 -> 145
    expect($pricing->price($m242, [], true, '2025-09-15')['partialMonthAmount'])->toBe(120.0)
        ->and($pricing->price($m288, [], true, '2025-09-15')['partialMonthAmount'])->toBe(145.0)
        ->and($pricing->price($m292, [], true, '2025-09-15')['partialMonthAmount'])->toBe(145.0);
});

test('the partial month rounds 7 down to 5 and 8 up', function () {
    // October has 31 days; billed Oct 15 -> 16 remain.
    // 304*16/31 = 156.9 -> 155 ; 306*16/31 = 157.9 -> 160
    [, , $m304] = makePricedMembership(304);
    [, , $m306] = makePricedMembership(306);

    $pricing = new InvoicePricingService;

    expect($pricing->price($m304, [], true, '2025-10-15')['partialMonthAmount'])->toBe(155.0)
        ->and($pricing->price($m306, [], true, '2025-10-15')['partialMonthAmount'])->toBe(160.0);
});

test('a rounded partial month flows into the invoice total', function () {
    // September, billed on the 7th: 250/30*23 = 191.67 -> 190
    [, , $membership] = makePricedMembership(250);

    $priced = (new InvoicePricingService)->price($membership, [], true, '2025-09-07');

    expect($priced['partialMonthAmount'])->toBe(190.0)
        ->and($priced['totalAmount'])->toBe(190.0);
});

test('a tiny raw charge floors at one 5 DH coin instead of going free', function () {
    // 30 DH offer, billed Sep 29: 1 day left, raw = 1 -> 5 (never 0 while days remain).
    [, , $cheap] = makePricedMembership(30);

    expect((new InvoicePricingService)->price($cheap, [], true, '2025-09-29')['partialMonthAmount'])->toBe(5.0);
});

test('a free offer stays free', function () {
    [, , $free] = makePricedMembership(0);

    expect((new InvoicePricingService)->price($free, [], true, '2025-09-07')['partialMonthAmount'])->toBe(0.0);
});

test('the price quote endpoint applies the 5 DH floor exactly like store()', function () {
    // 30 DH offer billed Sep 29: raw = 1 -> floored to 5, preview and quote must agree.
    [, $student, $membership] = makePricedMembership(30);
    $admin = User::factory()->create(['role' => 'admin']);

    $quote = $this->actingAs($admin)->postJson('/invoices/price', [
        'membership_id' => $membership->id,
        'selected_months' => [],
        'includePartialMonth' => true,
        'billDate' => '2025-09-29',
    ])->assertSuccessful()->json();

    expect((float) $quote['partialMonthAmount'])->toBe(5.0);
});

test('a discount can never leave partial above total', function () {
    // 250 DH offer billed 2025-09-07: server partial = 190, school discounts to 150.
    [, $student, $membership] = makePricedMembership(250);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 0,
        'selected_months' => [],
        'billDate' => '2025-09-07',
        'creationDate' => '2025-09-07',
        'totalAmount' => 150,
        'amountPaid' => 150,
        'rest' => 0,
        'includePartialMonth' => true,
    ]);

    $invoice = Invoice::latest('id')->first();

    expect((float) $invoice->totalAmount)->toBe(150.0)
        ->and((float) $invoice->partialMonthAmount)->toBeLessThanOrEqual(150.0);
});

test('a stale client total below the rounded server price becomes a discount, not an overpay', function () {
    // Server partial = 190 for this billing date; a stale bundle posts its old 144 preview.
    [, $student, $membership] = makePricedMembership(250);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 0,
        'selected_months' => [],
        'billDate' => '2025-09-07',
        'creationDate' => '2025-09-07',
        'totalAmount' => 144,
        'amountPaid' => 144,
        'rest' => 0,
        'includePartialMonth' => true,
        'partialMonthAmount' => 144,
    ]);

    $invoice = Invoice::latest('id')->first();

    expect((float) $invoice->totalAmount)->toBe(144.0)
        ->and((float) $invoice->partialMonthAmount)->toBeLessThanOrEqual(144.0);
});

test('editing an old invoice without touching billing inputs keeps its stored price', function () {
    // Legacy row from before the rounding: unrounded 192 partial, fully paid.
    [$teacher, $student, $membership] = makePricedMembership(250);
    $admin = User::factory()->create(['role' => 'admin']);

    $invoice = Invoice::create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'offer_id' => $membership->offer_id,
        'months' => 0,
        'selected_months' => [],
        'billDate' => '2025-09-07',
        'creationDate' => '2025-09-07',
        'totalAmount' => 192,
        'amountPaid' => 192,
        'rest' => 0,
        'includePartialMonth' => true,
        'partialMonthAmount' => 192,
    ]);

    // A fresh computation would give 190 — the stored 192 must survive the edit.
    $this->actingAs($admin)->put("/invoices/{$invoice->id}", [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 0,
        'selected_months' => [],
        'billDate' => '2025-09-07',
        'totalAmount' => 192,
        'amountPaid' => 192,
        'rest' => 0,
        'includePartialMonth' => true,
        'partialMonthAmount' => 192,
    ])->assertRedirect();

    expect((float) $invoice->fresh()->partialMonthAmount)->toBe(192.0)
        ->and((float) $invoice->fresh()->totalAmount)->toBe(192.0)
        ->and((float) $teacher->fresh()->wallet)->toBe(96.0, 'Commission stays 50% of the stored 192.');
});

test('the price quote endpoint rounds partials exactly like store()', function () {
    [, $student, $membership] = makePricedMembership(250);
    $admin = User::factory()->create(['role' => 'admin']);

    $quote = $this->actingAs($admin)->postJson('/invoices/price', [
        'membership_id' => $membership->id,
        'selected_months' => [],
        'includePartialMonth' => true,
        'billDate' => '2025-09-07',
    ])->assertSuccessful()->json();

    expect((float) $quote['partialMonthAmount'])->toBe(190.0);

    $this->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 0,
        'selected_months' => [],
        'billDate' => '2025-09-07',
        'creationDate' => '2025-09-07',
        'totalAmount' => $quote['totalAmount'],
        'amountPaid' => $quote['totalAmount'],
        'rest' => 0,
        'includePartialMonth' => true,
    ]);

    $invoice = Invoice::latest('id')->first();

    expect((float) $invoice->partialMonthAmount)->toBe((float) $quote['partialMonthAmount']);
});
