<?php

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Student;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

/*
 * THE "Statut d'adhésion" FILTER ON /students
 *
 * The filter and the badge it filters on were computed from different data: the badge
 * from invoice money (calculateMembershipPaymentStatus), the filter from the
 * memberships.payment_status enum. They agreed often enough for "Payé" to look correct
 * and disagreed everywhere else.
 *
 * The contract these tests pin down is the one an admin actually assumes when they use
 * the control: the rows that come back are the rows that were showing that badge, and a
 * student appears under exactly one of the three filters.
 */

/** A student with one membership carrying a single invoice for the given amounts. */
function studentBilled(float $due, float $paid): Student
{
    $student = Student::factory()->create();
    $membership = Membership::factory()->create(['student_id' => $student->id]);

    Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'totalAmount' => $due,
        'amountPaid' => $paid,
        'rest' => max(0, $due - $paid),
    ]);

    return $student;
}

/** Ids returned by /students under the given filter. */
function idsUnder(?string $status): array
{
    $query = $status === null ? [] : ['membership_status' => $status];

    $response = test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('students.index', $query));

    $response->assertOk();

    $ids = [];
    $response->assertInertia(function (AssertableInertia $page) use (&$ids) {
        foreach ($page->toArray()['props']['students']['data'] as $row) {
            $ids[] = $row['id'];
        }
    });

    return $ids;
}

it('returns a fully paid student under "Payé" only', function () {
    $student = studentBilled(due: 500, paid: 500);

    expect(idsUnder('paid'))->toContain($student->id)
        ->and(idsUnder('unpaid'))->not->toContain($student->id)
        ->and(idsUnder('rest'))->not->toContain($student->id);
});

it('returns a student who has paid nothing under "Non payé" only', function () {
    $student = studentBilled(due: 500, paid: 0);

    expect(idsUnder('unpaid'))->toContain($student->id)
        ->and(idsUnder('paid'))->not->toContain($student->id)
        ->and(idsUnder('rest'))->not->toContain($student->id);
});

it('returns a part-paid student under "Partiel" only', function () {
    // The case the old filter could never return: payment_status has no 'rest' value, so
    // this student was invisible under "Partiel" no matter how much was outstanding.
    $student = studentBilled(due: 500, paid: 200);

    expect(idsUnder('rest'))->toContain($student->id)
        ->and(idsUnder('paid'))->not->toContain($student->id)
        ->and(idsUnder('unpaid'))->not->toContain($student->id);
});

it('treats a membership with no invoice at all as unpaid', function () {
    $student = Student::factory()->create();
    Membership::factory()->create(['student_id' => $student->id]);

    expect(idsUnder('unpaid'))->toContain($student->id)
        ->and(idsUnder('paid'))->not->toContain($student->id)
        ->and(idsUnder('rest'))->not->toContain($student->id);
});

it('keeps a student with no memberships out of all three filters', function () {
    // This is the regression. A student who never enrolled has no payment state, but the
    // old predicate listed them under BOTH "Non payé" and "Partiel" — the same row under
    // two filters that are supposed to be mutually exclusive. The badge says "Aucune".
    $student = Student::factory()->create();

    expect(idsUnder('paid'))->not->toContain($student->id)
        ->and(idsUnder('unpaid'))->not->toContain($student->id)
        ->and(idsUnder('rest'))->not->toContain($student->id)
        ->and(idsUnder(null))->toContain($student->id);
});

it('lets one unpaid membership outrank a paid one, as the badge does', function () {
    // Badge priority is unpaid > partial > paid: the row shows "1 non payée", so the row
    // has to come back under "Non payé" and nowhere else.
    $student = studentBilled(due: 500, paid: 500);
    $second = Membership::factory()->create(['student_id' => $student->id]);
    Invoice::factory()->create([
        'membership_id' => $second->id,
        'student_id' => $student->id,
        'totalAmount' => 300,
        'amountPaid' => 0,
        'rest' => 300,
    ]);

    expect(idsUnder('unpaid'))->toContain($student->id)
        ->and(idsUnder('paid'))->not->toContain($student->id)
        ->and(idsUnder('rest'))->not->toContain($student->id);
});

it('ignores a deleted invoice when deciding the status', function () {
    // The badge skips soft-deleted rows; the SQL has to as well, or deleting the invoice
    // that recorded the payment leaves the student looking paid.
    $student = studentBilled(due: 500, paid: 500);
    Invoice::where('student_id', $student->id)->first()->delete();

    expect(idsUnder('unpaid'))->toContain($student->id)
        ->and(idsUnder('paid'))->not->toContain($student->id);
});

it('applies no membership filter for "Tous"', function () {
    $paid = studentBilled(due: 500, paid: 500);
    $none = Student::factory()->create();

    $all = idsUnder('all');

    expect($all)->toContain($paid->id)->toContain($none->id);
});
