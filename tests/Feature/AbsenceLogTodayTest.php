<?php

use App\Models\Assistant;
use App\Models\Attendance;
use App\Models\Classes;
use App\Models\Level;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * THE ABSENCE LOG AND TODAY'S REGISTER.
 *
 * Reported: the absence log does not show data recorded on the current day.
 * These tests pin the endpoint behaviour for TODAY specifically.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-08-24 15:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function logAssistant(): array
{
    $school = School::factory()->create();
    $level = Level::factory()->create();
    $class = Classes::factory()->create(['school_id' => $school->id, 'level_id' => $level->id]);
    $student = Student::factory()->create(['schoolId' => $school->id, 'classId' => $class->id, 'levelId' => $level->id]);

    $email = fake()->unique()->safeEmail();
    $user = User::factory()->create(['role' => 'assistant', 'email' => $email]);
    $assistant = Assistant::factory()->create(['email' => $email]);
    $assistant->schools()->attach($school->id);

    // The local schema requires a recorded_by and a teacher_id on every row.
    $teacherEmail = fake()->unique()->safeEmail();
    \App\Models\Teacher::factory()->create(['email' => $teacherEmail]);

    return [$user, $school, $class, $student, $teacherEmail];
}

it('returns absences recorded today when filtering on today', function () {
    [$user,,, $student, $teacherEmail] = logAssistant();

    Attendance::create([
        'student_id' => $student->id,
        'classId' => $student->classId,
        'date' => Carbon::today()->toDateString(),
        'status' => 'absent',
        'reason' => 'Maladie',
        'subject' => 'Math',
        'recorded_by' => $user->id,
        'teacher_id' => \App\Models\Teacher::where('email', $teacherEmail)->value('id'),
    ]);

    $json = $this->actingAs($user)
        ->get('/api/absence-log?date='.Carbon::today()->toDateString())
        ->assertOk()
        ->json();

    expect(count($json['data']['data']))->toBe(1)
        ->and($json['data']['data'][0]['student_name'])->toContain($student->firstName);
});

it('returns absences recorded today in the unfiltered newest-first log', function () {
    [$user,,, $student, $teacherEmail] = logAssistant();
    $teacherId = \App\Models\Teacher::where('email', $teacherEmail)->value('id');

    Attendance::create([
        'student_id' => $student->id,
        'classId' => $student->classId,
        'date' => Carbon::today()->toDateString(),
        'status' => 'late',
        'reason' => null,
        'subject' => 'Arabe',
        'recorded_by' => $user->id,
        'teacher_id' => $teacherId,
    ]);
    Attendance::create([
        'student_id' => $student->id,
        'classId' => $student->classId,
        'date' => Carbon::today()->subDays(3)->toDateString(),
        'status' => 'absent',
        'reason' => null,
        'subject' => 'Math',
        'recorded_by' => $user->id,
        'teacher_id' => $teacherId,
    ]);

    $json = $this->actingAs($user)
        ->get('/api/absence-log')
        ->assertOk()
        ->json();

    expect($json['data']['data'][0]['date'])->toBe(Carbon::today()->toDateString());
});

/*
 * THE JOURNAL OPENS ON TODAY.
 *
 * The log defaults to the current day and every awaiting counter (log page,
 * menu badge seed, 60s poll) counts today's register only — yesterday's
 * unreleased notices must not inflate today's number.
 *
 * Setup reuses the real register path (saveSheet / approvalStudent /
 * approvalClass / absentRow from AbsenceApprovalTest): one awaiting notice
 * for today (2026-08-24, the frozen now) and one for 2026-08-21.
 */

function todayNotices(): array
{
    $admin = User::factory()->create(['role' => 'admin']);
    $school = School::factory()->create();
    $level = Level::factory()->create();
    $class = Classes::factory()->create(['school_id' => $school->id, 'level_id' => $level->id]);

    $makeStudent = fn () => Student::factory()->create([
        'status' => 'active',
        'guardianNumber' => '0612345678',
        'schoolId' => $school->id,
        'classId' => $class->id,
        'levelId' => $level->id,
    ]);

    // Through the real register route, like the register page posts it.
    $saveDay = function (Student $student, string $date) use ($admin, $class) {
        test()->actingAs($admin)->post(route('attendances.store'), [
            'class_id' => $class->id,
            'date' => $date,
            'teacher_id' => \App\Models\Teacher::factory()->create()->id,
            'attendances' => [
                ['student_id' => $student->id, 'status' => 'absent', 'reason' => null, 'subject' => 'Maths'],
            ],
        ])->assertSessionHasNoErrors();
    };

    $saveDay($makeStudent(), '2026-08-24');
    $saveDay($makeStudent(), '2026-08-21');

    return [$admin];
}

it('scopes the unfiltered log awaiting count to today', function () {
    [$admin] = todayNotices();

    $json = $this->actingAs($admin)
        ->get('/api/absence-log')
        ->assertOk()
        ->json();

    expect($json['awaiting'])->toBe(1);
});

it('seeds the menu badge with today only', function () {
    [$admin] = todayNotices();

    $page = test()->actingAs($admin)->get(route('absence.log.page'))->assertOk()->inertiaPage();

    expect($page['props']['pendingNoticesCount'])->toBe(1);
});

it('refreshes the badge poll with today only', function () {
    [$admin] = todayNotices();

    test()->actingAs($admin)
        ->getJson('/unread-count')
        ->assertOk()
        ->assertJsonPath('pending_notices', 1);
});

it('answers the page-load request with the requested day only', function () {
    [$admin] = todayNotices();

    // What AbsenceLog.jsx fetches on mount: ?date=<today>.
    $today = $this->actingAs($admin)
        ->get('/api/absence-log?date=2026-08-24')
        ->assertOk()
        ->json();

    expect($today['awaiting'])->toBe(1)
        ->and(count($today['data']['data']))->toBe(1);

    $older = $this->actingAs($admin)
        ->get('/api/absence-log?date=2026-08-21')
        ->assertOk()
        ->json();

    expect($older['awaiting'])->toBe(1)
        ->and(count($older['data']['data']))->toBe(1);
});

it('keeps the explicit 7-day window covering both days', function () {
    [$admin] = todayNotices();

    $json = $this->actingAs($admin)
        ->get('/api/absence-log?period=last_7_days')
        ->assertOk()
        ->json();

    expect($json['awaiting'])->toBe(2)
        ->and(count($json['data']['data']))->toBe(2);
});
