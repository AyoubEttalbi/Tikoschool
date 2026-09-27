<?php

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;

/*
 * Next-month billing: a membership the student already paid must stay billable.
 *
 * The invoice form used to list only `pending` memberships, so once the current
 * month was fully paid the membership vanished from the "new invoice" dropdown
 * and staff could only edit the existing invoice to add the next month. The
 * backend never forbade a second invoice for a new month — only the UI hid it.
 *
 * Companion rule: without any duplicate-month protection, exposing paid
 * memberships would make double-billing a month trivially easy. store() and
 * update() must therefore reject `selected_months` that overlap another
 * invoice of the same membership.
 */

function nextMonthMembership(float $monthlyPrice = 300.0): array
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

function nextMonthPostInvoice($t, User $admin, Student $student, Membership $membership, string $month): void
{
    $firstDay = $month.'-01';
    $price = (float) $membership->offer->price;

    $response = $t->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 1,
        'selected_months' => [$month],
        'billDate' => $firstDay,
        'creationDate' => $firstDay,
        'totalAmount' => $price,
        'amountPaid' => $price,
        'rest' => 0,
        'includePartialMonth' => false,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
}

test('a paid membership can be billed again for the next month', function () {
    [$teacher, $student, $membership] = nextMonthMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    nextMonthPostInvoice($this, $admin, $student, $membership, '2026-09');

    expect($membership->fresh()->payment_status)->toBe('paid');

    nextMonthPostInvoice($this, $admin, $student, $membership, '2026-10');

    $invoices = Invoice::where('membership_id', $membership->id)->orderBy('id')->get();

    expect($invoices)->toHaveCount(2)
        ->and((float) $invoices[1]->totalAmount)->toBe(300.0)
        ->and((float) $invoices[1]->amountPaid)->toBe(300.0);
});

test('creating an invoice for an already-billed month is rejected', function () {
    [, $student, $membership] = nextMonthMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    nextMonthPostInvoice($this, $admin, $student, $membership, '2026-09');

    $response = $this->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 1,
        'selected_months' => ['2026-09'],
        'billDate' => '2026-09-01',
        'creationDate' => '2026-09-01',
        'totalAmount' => 300,
        'amountPaid' => 300,
        'rest' => 0,
        'includePartialMonth' => false,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('error');

    $message = session('errors')?->first('error') ?? '';
    expect($message)->toContain('déjà facturé');

    expect(Invoice::where('membership_id', $membership->id)->count())->toBe(1);
});

test('updating an invoice onto an already-billed month is rejected', function () {
    [, $student, $membership] = nextMonthMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    nextMonthPostInvoice($this, $admin, $student, $membership, '2026-09');
    nextMonthPostInvoice($this, $admin, $student, $membership, '2026-10');

    $september = Invoice::where('membership_id', $membership->id)->orderBy('id')->first();

    $response = $this->actingAs($admin)->put("/invoices/{$september->id}", [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 1,
        'selected_months' => ['2026-10'],
        'billDate' => '2026-10-01',
        'totalAmount' => 300,
        'amountPaid' => 300,
        'rest' => 0,
        'includePartialMonth' => false,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('error');

    expect($september->fresh()->billDate->format('Y-m-d'))->toBe('2026-09-01');
});

test('updating an invoice while keeping its own months still works', function () {
    [, $student, $membership] = nextMonthMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    nextMonthPostInvoice($this, $admin, $student, $membership, '2026-09');

    $invoice = Invoice::where('membership_id', $membership->id)->first();

    // Partial payment first so amountPaid changes and the update path runs fully.
    $response = $this->actingAs($admin)->put("/invoices/{$invoice->id}", [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 1,
        'selected_months' => ['2026-09'],
        'billDate' => '2026-09-01',
        'totalAmount' => 300,
        'amountPaid' => 150,
        'rest' => 150,
        'includePartialMonth' => false,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    expect((float) $invoice->fresh()->amountPaid)->toBe(150.0);
});

test('voiding an invoice frees its month for rebilling', function () {
    [, $student, $membership] = nextMonthMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    nextMonthPostInvoice($this, $admin, $student, $membership, '2026-09');

    $invoice = Invoice::where('membership_id', $membership->id)->first();

    $this->actingAs($admin)->delete("/invoices/{$invoice->id}")->assertRedirect();

    expect(Invoice::where('membership_id', $membership->id)->count())->toBe(0);

    // The voided September frees the month: rebilling it must succeed.
    nextMonthPostInvoice($this, $admin, $student, $membership, '2026-09');

    expect(Invoice::where('membership_id', $membership->id)->count())->toBe(1);
});

/*
 * A partial-month payment occupies its billDate month like a normal payment:
 * one payment per membership per month, no stacking a full invoice on top of
 * a partial (or vice versa).
 */

function nextMonthPostPartial($t, User $admin, Student $student, Membership $membership, string $billDate): void
{
    $response = $t->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 0,
        'selected_months' => [],
        'billDate' => $billDate,
        'creationDate' => $billDate,
        'totalAmount' => 150,
        'amountPaid' => 150,
        'rest' => 0,
        'includePartialMonth' => true,
        'partialMonthAmount' => 150,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
}

function nextMonthAssertOverlapRejected($response, Membership $membership, int $expectedCount): void
{
    $response->assertRedirect();
    $response->assertSessionHasErrors('error');

    $message = session('errors')?->first('error') ?? '';
    expect($message)->toContain('déjà facturé');

    expect(Invoice::where('membership_id', $membership->id)->count())->toBe($expectedCount);
}

test('a full month cannot be billed over an existing partial month', function () {
    [, $student, $membership] = nextMonthMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    nextMonthPostPartial($this, $admin, $student, $membership, '2026-09-15');

    $response = $this->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 1,
        'selected_months' => ['2026-09'],
        'billDate' => '2026-09-01',
        'creationDate' => '2026-09-01',
        'totalAmount' => 300,
        'amountPaid' => 300,
        'rest' => 0,
        'includePartialMonth' => false,
    ]);

    nextMonthAssertOverlapRejected($response, $membership, 1);
});

test('a partial month cannot be billed over an existing full month', function () {
    [, $student, $membership] = nextMonthMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    nextMonthPostInvoice($this, $admin, $student, $membership, '2026-09');

    $response = $this->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 0,
        'selected_months' => [],
        'billDate' => '2026-09-20',
        'creationDate' => '2026-09-20',
        'totalAmount' => 150,
        'amountPaid' => 150,
        'rest' => 0,
        'includePartialMonth' => true,
        'partialMonthAmount' => 150,
    ]);

    nextMonthAssertOverlapRejected($response, $membership, 1);
});

test('two partials in the same month are rejected', function () {
    [, $student, $membership] = nextMonthMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    nextMonthPostPartial($this, $admin, $student, $membership, '2026-09-15');

    $response = $this->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 0,
        'selected_months' => [],
        'billDate' => '2026-09-20',
        'creationDate' => '2026-09-20',
        'totalAmount' => 150,
        'amountPaid' => 150,
        'rest' => 0,
        'includePartialMonth' => true,
        'partialMonthAmount' => 150,
    ]);

    nextMonthAssertOverlapRejected($response, $membership, 1);
});

test('a partial in one month does not block a full invoice in another', function () {
    [, $student, $membership] = nextMonthMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    nextMonthPostPartial($this, $admin, $student, $membership, '2026-09-15');
    nextMonthPostInvoice($this, $admin, $student, $membership, '2026-10');

    expect(Invoice::where('membership_id', $membership->id)->count())->toBe(2);
});

test('updating a full invoice onto a partially-billed month is rejected', function () {
    [, $student, $membership] = nextMonthMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    nextMonthPostPartial($this, $admin, $student, $membership, '2026-09-15');
    nextMonthPostInvoice($this, $admin, $student, $membership, '2026-10');

    $october = Invoice::where('membership_id', $membership->id)->orderBy('id', 'desc')->first();

    $response = $this->actingAs($admin)->put("/invoices/{$october->id}", [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 1,
        'selected_months' => ['2026-09'],
        'billDate' => '2026-09-01',
        'totalAmount' => 300,
        'amountPaid' => 300,
        'rest' => 0,
        'includePartialMonth' => false,
    ]);

    nextMonthAssertOverlapRejected($response, $membership, 2);
});

test('voiding the partial frees the month for a full invoice', function () {
    [, $student, $membership] = nextMonthMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    nextMonthPostPartial($this, $admin, $student, $membership, '2026-09-15');

    $partial = Invoice::where('membership_id', $membership->id)->first();

    $this->actingAs($admin)->delete("/invoices/{$partial->id}")->assertRedirect();

    $this->assertSoftDeleted('invoices', ['id' => $partial->id]);

    nextMonthPostInvoice($this, $admin, $student, $membership, '2026-09');

    expect(Invoice::where('membership_id', $membership->id)->count())->toBe(1);
});

test('the guard is scoped to one membership: same month on another is allowed', function () {
    [, $student, $membership] = nextMonthMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    nextMonthPostInvoice($this, $admin, $student, $membership, '2026-09');

    // Same student, second membership on another offer.
    $offer = Offer::factory()->create([
        'price' => 300,
        'subjects' => ['Math'],
        'percentage' => ['Math' => 50],
    ]);
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $other = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'payment_status' => 'pending',
        'is_active' => true,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);

    nextMonthPostInvoice($this, $admin, $student, $other, '2026-09');

    expect(Invoice::where('student_id', $student->id)->count())->toBe(2);
});
