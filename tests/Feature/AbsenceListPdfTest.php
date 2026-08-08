<?php

use App\Models\Attendance;
use App\Models\Classes;
use App\Models\Membership;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;

/*
 * /absence-list/download
 *
 * The route had two independent ways to fail, and they masked each other: it called
 * cal_days_in_month() (ext-calendar, absent from the production image) and it rendered a
 * ~35-column grid through dompdf under whatever memory_limit the pool happened to have.
 *
 * The extension dependency is guarded at the source level in ArchitectureTest — the test
 * runner has ext-calendar, so nothing here could catch it. These tests cover the rest:
 * the route produces a real PDF, and it does so for the months where an off-by-one in the
 * day count would show.
 */

/** A class taught by one teacher, with `$count` active students enrolled under them. */
function absenceListFixture(int $count = 3): array
{
    $teacher = Teacher::factory()->create();
    $class = Classes::factory()->create();

    for ($i = 0; $i < $count; $i++) {
        $student = Student::factory()->create([
            'classId' => $class->id,
            'status' => 'active',
        ]);

        Membership::factory()->create([
            'student_id' => $student->id,
            'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Maths']],
        ]);
    }

    return [$teacher, $class];
}

function downloadAbsenceList(Teacher $teacher, Classes $class, string $date)
{
    return test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('absence-list.download', [
            'teacher_id' => $teacher->id,
            'class_id' => $class->id,
            'date' => $date,
        ]));
}

it('downloads a PDF of the absence list', function () {
    [$teacher, $class] = absenceListFixture();

    $response = downloadAbsenceList($teacher, $class, '2026-08-01');

    $response->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    // Not just a 200 with an empty body: assert it really is a PDF document.
    expect($response->getContent())
        ->toStartWith('%PDF');
})->group('pdf');

it('builds the right number of day columns for every month length', function (string $date, int $days) {
    // cal_days_in_month() is gone; this is the behaviour it was there to provide. February
    // in a leap year is the case a naive replacement gets wrong.
    [$teacher, $class] = absenceListFixture(1);

    $response = downloadAbsenceList($teacher, $class, $date);
    $response->assertOk();

    expect(\Carbon\Carbon::parse($date)->daysInMonth)->toBe($days);
})->with([
    ['2026-02-01', 28],
    ['2028-02-01', 29],   // leap year
    ['2026-04-15', 30],
    ['2026-08-01', 31],
])->group('pdf');

it('renders a class whose students have absences recorded', function () {
    [$teacher, $class] = absenceListFixture(2);
    $recorder = User::factory()->create(['role' => 'admin']);

    foreach (Student::where('classId', $class->id)->get() as $student) {
        Attendance::create([
            'student_id' => $student->id,
            'classId' => $class->id,
            'date' => '2026-08-14',
            'status' => 'absent',
            'subject' => 'Maths',
            'teacher_id' => $teacher->id,
            'recorded_by' => $recorder->id,
        ]);
    }

    $response = downloadAbsenceList($teacher, $class, '2026-08-01');

    $response->assertOk();
    expect($response->getContent())->toStartWith('%PDF');
})->group('pdf');

it('renders a full class inside the memory budget', function () {
    // The guarantee, not a guess. This is what actually crashed: at the stock 128 M limit
    // the render died on the first real class. The controller now raises its own ceiling
    // to 384 M, sized from measurements — a fixed ~80 MB for the font plus ~0.75 MB per
    // student row. If a new column, a style change or a dompdf upgrade moves that curve,
    // this fails here rather than on a school laptop at the start of term.
    [$teacher, $class] = absenceListFixture(40);

    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage(true);

    downloadAbsenceList($teacher, $class, '2026-08-01')->assertOk();  // 31 days, widest

    $renderMb = (memory_get_peak_usage(true) - $before) / 1048576;

    // Measured at ~114 MB for 40 students. 200 leaves room for drift without letting a
    // real regression through; the ceiling itself is 384 MB.
    expect($renderMb)->toBeLessThan(200.0);
})->group('pdf');

it('refuses an oversized roster instead of running into the memory ceiling', function () {
    // The backstop. Beyond the tested envelope the route must fail readably, not fatally
    // — a PHP memory exhaustion is not catchable, so it has to be headed off before it.
    $limit = (new ReflectionClass(App\Http\Controllers\AttendanceController::class))
        ->getConstant('PDF_MAX_STUDENTS');

    [$teacher, $class] = absenceListFixture($limit + 1);

    $response = downloadAbsenceList($teacher, $class, '2026-08-01');

    $response->assertStatus(413);
    // Opened in a new tab, so the body IS the error message — there is no page behind it
    // to render a flash on.
    expect($response->getContent())->toContain('Liste trop longue');
})->group('pdf')->group('slow');

it('refuses an unknown class', function () {
    [$teacher] = absenceListFixture(1);

    test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('absence-list.download', [
            'teacher_id' => $teacher->id,
            'class_id' => 999999,
            'date' => '2026-08-01',
        ]))
        ->assertSessionHasErrors('class_id');
});

it('is closed to teachers', function () {
    [$teacher, $class] = absenceListFixture(1);

    test()->actingAs(User::factory()->create(['role' => 'teacher']))
        ->get(route('absence-list.download', [
            'teacher_id' => $teacher->id,
            'class_id' => $class->id,
            'date' => '2026-08-01',
        ]))
        ->assertForbidden();
});
