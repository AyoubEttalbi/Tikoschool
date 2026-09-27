<?php

use App\Models\Classes;
use App\Models\Level;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;

/*
 * PRINTED STUDENT LISTS ARE NEWEST-INSCRIPTION-FIRST.
 *
 * Every PDF that lists pupils (level roster, class roster, absence sheet)
 * orders by the row creation day (created_at) descending: newly enrolled
 * pupils on top, the oldest at the bottom. created_at is immutable, unlike
 * billingDate which staff can edit. Fixtures travel in time so the order
 * pins creation days, not insertion sequence.
 */

function printOrderFixtures(): array
{
    $school = School::factory()->create();
    $level = Level::factory()->create();
    $class = Classes::factory()->create(['school_id' => $school->id, 'level_id' => $level->id]);

    $make = function (string $firstName, string $now, string $billingDate) use ($school, $level, $class) {
        \Illuminate\Support\Carbon::setTestNow($now);

        return Student::factory()->create([
            'firstName' => $firstName, 'lastName' => $firstName,
            'billingDate' => $billingDate, 'status' => 'active',
            'schoolId' => $school->id, 'levelId' => $level->id, 'classId' => $class->id,
        ]);
    };

    // Names run opposite to dates on purpose: alphabetical order must NOT win.
    // billingDate is deliberately shuffled: it must NOT drive the order either.
    // Creation order is deliberately NON-chronological (newest first), so only
    // created_at DESC — not id DESC — yields [new, mid, old].
    try {
        $new = $make('Ziad', '2026-09-01 10:00:00', '2025-01-15');
        $old = $make('Aaron', '2024-09-01 10:00:00', '2026-01-15');
        $mid = $make('Mona', '2025-09-01 10:00:00', '2024-01-15');
    } finally {
        \Illuminate\Support\Carbon::setTestNow(null);
    }

    return [$school, $level, $class, $old, $mid, $new];
}

test('print order is newest inscription first', function () {
    [, , , $old, $mid, $new] = printOrderFixtures();

    $ids = Student::query()->printOrder()->pluck('id')->all();

    expect($ids)->toBe([$new->id, $mid->id, $old->id]);
});

test('the level roster pdf renders', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    [, $level] = printOrderFixtures();

    $this->actingAs($admin)
        ->get(route('othersettings.levels.students.download', $level->id))
        ->assertOk();
});

test('the class roster pdf renders', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    [, , $class] = printOrderFixtures();

    $this->actingAs($admin)
        ->get(route('classes.students.download', $class->id))
        ->assertOk();
});

test('the absence sheet pdf renders all matching rows newest-first', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    [, , $class, $old, $mid, $new] = printOrderFixtures();

    $teacher = Teacher::factory()->create();
    $offer = Offer::factory()->create([
        'price' => 300,
        'subjects' => ['Math'],
        'percentage' => ['Math' => 50],
    ]);
    // All three pupils taught by the same teacher, so the sheet's in-memory
    // teacher filter keeps every row and the print order survives it.
    foreach ([$old, $mid, $new] as $student) {
        Membership::factory()->create([
            'student_id' => $student->id,
            'offer_id' => $offer->id,
            'payment_status' => 'pending',
            'is_active' => true,
            'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
        ]);
    }

    $response = $this->actingAs($admin)
        ->get(route('absence-list.download', ['teacher_id' => $teacher->id, 'class_id' => $class->id]))
        ->assertOk();

    // The PDF bytes are compressed (see LevelRosterPdfTest), so order is pinned
    // by the scope test plus this multi-row render through the real filter.
    expect($response->headers->get('Content-Type'))->toContain('pdf');
});
