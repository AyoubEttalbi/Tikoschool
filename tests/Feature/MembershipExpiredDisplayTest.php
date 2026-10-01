<?php

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;

/*
 * EXPIRED DISPLAYS AS UNPAID, AND EXPIRED CAN BE RENEWED.
 *
 * An expired membership that was fully paid still counts as "paid" by pure
 * money math — but the school treats an ended period as needing payment
 * (renewal), so every display counts it as unpaid:
 *
 *   - the student-list "Statut" badge and its filter (server counts),
 *   - the membership card (client badge — same rule, mirrored in JSX),
 *   - the invoice form lists expired memberships with fully-paid history
 *     as "À renouveler"; never-billed expired junk stays hidden.
 */

function expiredScenario(float $monthlyPrice = 300.0): array
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
        // Pinned: the factory randomizes end_date and store() only overwrites
        // it when the posted date is later — renewal assertions need determinism.
        'end_date' => null,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);

    return [$teacher, $student, $membership];
}

function expiredPostInvoice($t, User $admin, Student $student, Membership $membership, string $month): void
{
    $price = (float) $membership->offer->price;
    // The form always posts endDate (last day of the last selected month);
    // the membership's end_date follows it, which the renewal test asserts.
    $endDate = \Illuminate\Support\Carbon::parse($month.'-01')->endOfMonth()->toDateString();

    $response = $t->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 1,
        'selected_months' => [$month],
        'billDate' => $month.'-01',
        'creationDate' => $month.'-01',
        'endDate' => $endDate,
        'totalAmount' => $price,
        'amountPaid' => $price,
        'rest' => 0,
        'includePartialMonth' => false,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();
}

function expiredExpire(Membership $membership): void
{
    // Exactly what memberships:update-payment-status writes.
    $membership->update(['payment_status' => 'expired', 'is_active' => false]);
}

/** Ids returned by /students under the given filter (same contract as StudentStatusFilterTest). */
function expiredIdsUnder(?string $status): array
{
    $query = $status === null ? [] : ['membership_status' => $status];

    $response = test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('students.index', $query));

    $response->assertOk();

    $ids = [];
    $response->assertInertia(function (\Inertia\Testing\AssertableInertia $page) use (&$ids) {
        foreach ($page->toArray()['props']['students']['data'] as $row) {
            $ids[] = $row['id'];
        }
    });

    return $ids;
}

test('an expired fully-paid membership counts as unpaid on the list', function () {
    [, $student, $membership] = expiredScenario(300);
    $admin = User::factory()->create(['role' => 'admin']);

    expiredPostInvoice($this, $admin, $student, $membership, '2026-09');
    expiredExpire($membership->fresh());

    // Shows under "unpaid"...
    expect(expiredIdsUnder('unpaid'))->toContain($student->id);

    // ...and no longer under "paid".
    expect(expiredIdsUnder('paid'))->not->toContain($student->id);
});

test('an expired owing membership stays unpaid, never paid', function () {
    [, $student, $membership] = expiredScenario(300);
    $admin = User::factory()->create(['role' => 'admin']);

    expiredPostInvoice($this, $admin, $student, $membership, '2026-09');

    // Partial top-up edit, then expire: money still owed.
    $invoice = Invoice::where('membership_id', $membership->id)->first();
    $this->actingAs($admin)->put("/invoices/{$invoice->id}", [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 1,
        'selected_months' => ['2026-09'],
        'billDate' => '2026-09-01',
        'totalAmount' => 300,
        'amountPaid' => 150,
        'rest' => 150,
        'includePartialMonth' => false,
    ])->assertRedirect();
    expiredExpire($membership->fresh());

    expect(expiredIdsUnder('unpaid'))->toContain($student->id)
        ->and(expiredIdsUnder('paid'))->not->toContain($student->id);
});

test('renewal billing on an expired membership revives it', function () {
    [, $student, $membership] = expiredScenario(300);
    $admin = User::factory()->create(['role' => 'admin']);

    expiredPostInvoice($this, $admin, $student, $membership, '2026-09');
    expiredExpire($membership->fresh());

    expiredPostInvoice($this, $admin, $student, $membership, '2026-10');

    $membership->refresh();

    expect($membership->payment_status)->toBe('paid')
        ->and((int) $membership->is_active)->toBe(1)
        ->and(substr((string) $membership->end_date, 0, 7))->toBe('2026-10')
        ->and(Invoice::where('membership_id', $membership->id)->count())->toBe(2);
});

test('renewal cannot double-bill the expired month', function () {
    [, $student, $membership] = expiredScenario(300);
    $admin = User::factory()->create(['role' => 'admin']);

    expiredPostInvoice($this, $admin, $student, $membership, '2026-09');
    expiredExpire($membership->fresh());

    $price = (float) $membership->offer->price;

    $response = $this->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 1,
        'selected_months' => ['2026-09'],
        'billDate' => '2026-09-01',
        'creationDate' => '2026-09-01',
        'totalAmount' => $price,
        'amountPaid' => $price,
        'rest' => 0,
        'includePartialMonth' => false,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('error');

    expect(Invoice::where('membership_id', $membership->id)->count())->toBe(1);
});

test('the create payload lists only renewable expired memberships', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    // Renewable: expired, fully paid.
    [, $renewed, $renewable] = expiredScenario(300);
    expiredPostInvoice($this, $admin, $renewed, $renewable, '2026-09');
    expiredExpire($renewable->fresh());

    // Owing: expired, partially paid.
    [, , $owing] = expiredScenario(300);
    expiredPostInvoice($this, $admin, $owing->student, $owing, '2026-09');
    $invoice = Invoice::where('membership_id', $owing->id)->first();
    $this->actingAs($admin)->put("/invoices/{$invoice->id}", [
        'membership_id' => $owing->id,
        'student_id' => $owing->student_id,
        'months' => 1,
        'selected_months' => ['2026-09'],
        'billDate' => '2026-09-01',
        'endDate' => '2026-09-30',
        'totalAmount' => 300,
        'amountPaid' => 150,
        'rest' => 150,
        'includePartialMonth' => false,
    ])->assertRedirect();
    expiredExpire($owing->fresh());

    // Junk: expired, never billed.
    [, , $junk] = expiredScenario(300);
    expiredExpire($junk->fresh());

    $ids = collect(
        $this->actingAs($admin)->get(route('invoices.create'))->assertOk()->inertiaPage()['props']['StudentMemberships']
    )->pluck('id')->all();

    expect($ids)->toContain($renewable->id)
        ->and($ids)->not->toContain($owing->id)
        ->and($ids)->not->toContain($junk->id);
});

test('an expired partial-money member is unpaid, never partiel', function () {    [, $student, $membership] = expiredScenario(300);
    $admin = User::factory()->create(['role' => 'admin']);

    expiredPostInvoice($this, $admin, $student, $membership, '2026-09');

    $invoice = Invoice::where('membership_id', $membership->id)->first();
    $this->actingAs($admin)->put("/invoices/{$invoice->id}", [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 1,
        'selected_months' => ['2026-09'],
        'billDate' => '2026-09-01',
        'endDate' => '2026-09-30',
        'totalAmount' => 300,
        'amountPaid' => 150,
        'rest' => 150,
        'includePartialMonth' => false,
    ])->assertRedirect();
    expiredExpire($membership->fresh());

    // Pins the contract: expired-owing is collected via top-up of the existing
    // invoice (update path), not via "Partiel" nor the renewal dropdown.
    expect(expiredIdsUnder('unpaid'))->toContain($student->id)
        ->and(expiredIdsUnder('rest'))->not->toContain($student->id)
        ->and(expiredIdsUnder('paid'))->not->toContain($student->id);
});

test('an assurance-only expired membership is not renewable', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    [, $student, $membership] = expiredScenario(300);

    expiredPostInvoice($this, $admin, $student, $membership, '2026-09');

    // Relabel the only invoice as assurance: monthly history is now empty.
    Invoice::where('membership_id', $membership->id)->update(['type' => 'assurance']);
    expiredExpire($membership->fresh());

    $ids = collect(
        $this->actingAs($admin)->get(route('invoices.create'))->assertOk()->inertiaPage()['props']['StudentMemberships']
    )->pluck('id')->all();

    expect($ids)->not->toContain($membership->id);
});
