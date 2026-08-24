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
