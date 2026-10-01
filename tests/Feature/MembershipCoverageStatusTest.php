<?php

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * MEMBERSHIP STATUS IS COVERAGE, NOT CASH MOMENT.
 *
 * Single rule (App\Support\MembershipStatus): paid iff a live MONTHLY invoice
 * is fully paid AND its coverage reaches today or beyond; otherwise pending.
 * Birth (creation → pending) and death (void-all → expired, nightly end_date
 * pass) keep their writers. "Today" is frozen per test — the rule must hold
 * on any date, not just the day the suite runs.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-05 10:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function coverageMembership(float $monthlyPrice = 300.0): array
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
        'is_active' => false,
        'end_date' => null,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);

    return [$teacher, $student, $membership];
}

function coveragePost($t, User $admin, Student $student, Membership $membership, array $months, ?float $paid = null): void
{
    $price = (float) $membership->offer->price;
    $total = $price * count($months);
    $paid ??= $total;
    $first = $months[0];
    $last = $months[count($months) - 1];

    $response = $t->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => count($months),
        'selected_months' => $months,
        'billDate' => $first.'-01',
        'creationDate' => $first.'-01',
        'endDate' => Carbon::parse($last.'-01')->endOfMonth()->toDateString(),
        'totalAmount' => $total,
        'amountPaid' => $paid,
        'rest' => $total - $paid,
        'includePartialMonth' => false,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
}

test('paying September late in October reads pending, not paid', function () {
    [, $student, $membership] = coverageMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    coveragePost($this, $admin, $student, $membership, ['2026-09']);

    $membership->refresh();

    // Cash arrived in full — but September already passed.
    expect($membership->payment_status)->toBe('pending')
        ->and((int) $membership->is_active)->toBe(0);
});

test('prepaying October in September reads paid', function () {
    Carbon::setTestNow('2026-09-15 10:00:00');

    [, $student, $membership] = coverageMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    coveragePost($this, $admin, $student, $membership, ['2026-10']);

    $membership->refresh();

    expect($membership->payment_status)->toBe('paid')
        ->and((int) $membership->is_active)->toBe(1);
});

test('paying October in October reads paid', function () {
    [, $student, $membership] = coverageMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    coveragePost($this, $admin, $student, $membership, ['2026-10']);

    $membership->refresh();

    expect($membership->payment_status)->toBe('paid');
});

test('voiding October with paid September standing reads pending in October', function () {
    [, $student, $membership] = coverageMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    coveragePost($this, $admin, $student, $membership, ['2026-09']);
    coveragePost($this, $admin, $student, $membership, ['2026-10']);

    $october = Invoice::where('membership_id', $membership->id)->orderBy('id', 'desc')->first();
    $this->actingAs($admin)->delete("/invoices/{$october->id}")->assertRedirect();

    $membership->refresh();

    expect($membership->payment_status)->toBe('pending')
        ->and(substr((string) $membership->end_date, 0, 7))->toBe('2026-09');
});

test('voiding the only invoice reads expired', function () {
    [, $student, $membership] = coverageMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    coveragePost($this, $admin, $student, $membership, ['2026-10']);

    $invoice = Invoice::where('membership_id', $membership->id)->first();
    $this->actingAs($admin)->delete("/invoices/{$invoice->id}")->assertRedirect();

    expect($membership->fresh()->payment_status)->toBe('expired');
});

test('a paid multi-month invoice covers every month up to its end', function () {
    [, $student, $membership] = coverageMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    coveragePost($this, $admin, $student, $membership, ['2026-09', '2026-10', '2026-11']);

    expect($membership->fresh()->payment_status)->toBe('paid');

    Carbon::setTestNow('2026-12-05 10:00:00');

    // Same rows, December eyes: coverage over → pending on next recompute.
    // Recompute path: any update re-resolves (touch amountPaid to trigger).
    $invoice = Invoice::where('membership_id', $membership->id)->first();
    $this->actingAs($admin)->put("/invoices/{$invoice->id}", [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 3,
        'selected_months' => ['2026-09', '2026-10', '2026-11'],
        'billDate' => '2026-09-01',
        'endDate' => '2026-11-30',
        'totalAmount' => 900,
        'amountPaid' => 900,
        'rest' => 0,
        'includePartialMonth' => false,
    ])->assertRedirect();

    expect($membership->fresh()->payment_status)->toBe('pending');
});

test('a partial payment reads pending while it covers today', function () {
    [, $student, $membership] = coverageMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    coveragePost($this, $admin, $student, $membership, ['2026-10'], paid: 150.0);

    $membership->refresh();

    expect($membership->payment_status)->toBe('pending')
        ->and((int) $membership->is_active)->toBe(0);
});

test('an assurance-only history never reads paid', function () {    [, $student, $membership] = coverageMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    coveragePost($this, $admin, $student, $membership, ['2026-10']);

    Invoice::where('membership_id', $membership->id)->update(['type' => 'assurance']);

    // Force a recompute through the update path with unchanged months.
    $invoice = Invoice::where('membership_id', $membership->id)->first();
    $this->actingAs($admin)->put("/invoices/{$invoice->id}", [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 1,
        'selected_months' => ['2026-10'],
        'billDate' => '2026-10-01',
        'endDate' => '2026-10-31',
        'totalAmount' => 300,
        'amountPaid' => 300,
        'rest' => 0,
        'includePartialMonth' => false,
    ])->assertRedirect();

    expect($membership->fresh()->payment_status)->toBe('pending');
});

test('voiding leaves the period on paid coverage, never on an unpaid survivor', function () {
    [, $student, $membership] = coverageMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    // September fully paid, October half-paid: period must stay September.
    coveragePost($this, $admin, $student, $membership, ['2026-09']);
    coveragePost($this, $admin, $student, $membership, ['2026-10'], paid: 150.0);

    $october = Invoice::where('membership_id', $membership->id)->orderBy('id', 'desc')->first();

    // Void September: the unpaid October remainder must not donate its period.
    $september = Invoice::where('membership_id', $membership->id)->orderBy('id')->first();
    $this->actingAs($admin)->delete("/invoices/{$september->id}")->assertRedirect();

    $membership->refresh();

    expect($membership->payment_status)->toBe('pending')
        ->and($membership->end_date)->toBeNull();
    expect($october->fresh()->deleted_at)->toBeNull();
});

test('narrowing months on update shrinks the period to the paid remainder', function () {
    Carbon::setTestNow('2026-09-15 10:00:00');

    [, $student, $membership] = coverageMembership(300);
    $admin = User::factory()->create(['role' => 'admin']);

    coveragePost($this, $admin, $student, $membership, ['2026-09', '2026-10']);

    expect(substr((string) $membership->fresh()->end_date, 0, 7))->toBe('2026-10');

    $invoice = Invoice::where('membership_id', $membership->id)->first();
    $this->actingAs($admin)->put("/invoices/{$invoice->id}", [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 1,
        'selected_months' => ['2026-09'],
        'billDate' => '2026-09-01',
        'endDate' => '2026-10-31',
        'totalAmount' => 300,
        'amountPaid' => 300,
        'rest' => 0,
        'includePartialMonth' => false,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $membership->refresh();

    // Paid September kept, October dropped: period follows the money down.
    expect($membership->payment_status)->toBe('paid')
        ->and(substr((string) $membership->end_date, 0, 7))->toBe('2026-09');
});
