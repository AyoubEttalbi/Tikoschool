<?php

use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;

/*
 * SUITE — assurance lifecycle between student profile and assurance invoices.
 *
 * The profile reads assurance from the student COLUMNS (assurance flag +
 * assuranceAmount), while the invoice card derives from live bills. Deleting
 * an assurance bill must therefore sync the columns back — otherwise the
 * profile stays "Oui / 90 DH" and the next student save resurrects the bill
 * through the update flow's find-or-create branch.
 */

function makeAssuredStudent(float $amount = 90.0): array
{
    $school = App\Models\School::factory()->create();
    $admin = User::factory()->create(['role' => 'admin']);
    $level = App\Models\Level::factory()->create();
    $class = App\Models\Classes::factory()->create(['level_id' => $level->id, 'school_id' => $school->id]);

    $student = Student::factory()->create([
        'schoolId' => $school->id,
        'levelId' => $level->id,
        'classId' => $class->id,
        'assurance' => 1,
        'assuranceAmount' => $amount,
    ]);

    $invoice = Invoice::factory()->create([
        'type' => 'assurance',
        'assurance_amount' => $amount,
        'membership_id' => null,
        'offer_id' => null,
        'student_id' => $student->id,
        'amountPaid' => $amount,
        'totalAmount' => $amount,
        'rest' => 0,
        'billDate' => now()->startOfMonth(),
        'creationDate' => now(),
        'months' => 1,
    ]);

    return compact('school', 'admin', 'student', 'invoice');
}

test('deleting the sole assurance invoice resets the student flags', function () {
    $f = makeAssuredStudent();
    extract($f);

    $this->actingAs($admin)->delete("/invoices/{$invoice->id}")->assertRedirect();

    expect($invoice->fresh()->trashed())->toBeTrue('Bill voided.')
        ->and((int) $student->fresh()->assurance)->toBe(0, 'Flag follows the bills, not the other way round.')
        ->and($student->fresh()->assuranceAmount)->toBeNull('No bill left means no amount.');
});

test('deleting one of two assurance invoices keeps the latest', function () {
    $f = makeAssuredStudent(90.0);
    extract($f);

    $newer = Invoice::factory()->create([
        'type' => 'assurance',
        'assurance_amount' => 120.0,
        'membership_id' => null,
        'offer_id' => null,
        'student_id' => $student->id,
        'amountPaid' => 120.0,
        'totalAmount' => 120.0,
        'rest' => 0,
        'billDate' => now()->startOfMonth(),
        'creationDate' => now()->addHour(),
        'months' => 1,
    ]);

    $this->actingAs($admin)->delete("/invoices/{$invoice->id}")->assertRedirect();

    expect((int) $student->fresh()->assurance)->toBe(1, 'A live bill remains.')
        ->and((float) $student->fresh()->assuranceAmount)->toBe(120.0, 'Amount follows the latest live bill.');

    $newer->delete();
});

test('deleting a normal invoice leaves student assurance flags untouched', function () {
    $f = makeAssuredStudent();
    extract($f);

    $other = Invoice::factory()->create([
        'membership_id' => null,
        'student_id' => $student->id,
        'offer_id' => null,
        'totalAmount' => 200.0,
        'amountPaid' => 0.0,
        'rest' => 200.0,
        'months' => 1,
        'selected_months' => [now()->format('Y-m')],
        'billDate' => now()->startOfMonth(),
        'endDate' => now()->addMonth(),
        'includePartialMonth' => false,
    ]);

    $this->actingAs($admin)->delete("/invoices/{$other->id}")->assertRedirect();

    expect((int) $student->fresh()->assurance)->toBe(1)
        ->and((float) $student->fresh()->assuranceAmount)->toBe(90.0);
});

test('student page reflects voided assurance everywhere', function () {
    // The profile reads assurance from TWO sources: the invoice-derived card
    // (heals itself) and the student columns (stayed stale, and a stale flag 1
    // plus any later save resurrected the bill through update find-or-create).
    $f = makeAssuredStudent();
    extract($f);

    $this->actingAs($admin)->delete("/invoices/{$invoice->id}")->assertRedirect();

    $this->actingAs($admin)->get("/students/{$student->id}")->assertOk()->assertInertia(
        fn (Inertia\Testing\AssertableInertia $page) => $page
            ->where('student.assurance', 0)
            ->where('student.assurance_paid', false)
            ->where('student.assurance_invoice', null)
    );
});

test('assurance amount submitted as text validates like a number', function () {
    // The spinner-free input posts strings. Server must accept numeric
    // strings and reject garbage — same contract as type=number gave.
    $school = App\Models\School::factory()->create();
    $admin = User::factory()->create(['role' => 'admin']);
    $level = App\Models\Level::factory()->create();
    $class = App\Models\Classes::factory()->create(['level_id' => $level->id, 'school_id' => $school->id]);
    $base = [
        'dateOfBirth' => '2015-04-12',
        'billingDate' => '2026-09-01',
        'guardianName' => 'Parent',
        'levelId' => $level->id,
        'classId' => $class->id,
        'schoolId' => $school->id,
        'status' => 'active',
    ];

    $this->actingAs($admin)->post('/students', $base + [
        'firstName' => 'Text',
        'lastName' => 'Amount',
        'assurance' => 1,
        'assuranceAmount' => '90.50',
    ])->assertRedirect();
    expect(Student::where('firstName', 'Text')->exists())->toBeTrue('Numeric string accepted.');

    $this->actingAs($admin)->post('/students', $base + [
        'firstName' => 'Garbage',
        'lastName' => 'Amount',
        'assurance' => 1,
        'assuranceAmount' => 'abc',
    ])->assertRedirect();
    expect(Student::where('firstName', 'Garbage')->exists())->toBeFalse('Garbage rejected.');
});
