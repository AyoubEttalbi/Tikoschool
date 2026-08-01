<?php

use App\Models\Assistant;
use App\Models\Classes;
use App\Models\Level;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;

/*
 * Object-level authorization.
 *
 * The app has no policies; scoping is enforced by App\Support\SchoolScope at the call sites
 * that need it. These tests pin the rules down so a refactor cannot quietly reopen the
 * horizontal-access holes (any teacher reading any student's record by changing an id).
 */

/** Build a school with one class and one student. */
function makeSchoolWithStudent(): array
{
    $school = School::factory()->create();
    $level = Level::factory()->create();
    $class = Classes::factory()->create(['school_id' => $school->id, 'level_id' => $level->id]);
    $student = Student::factory()->create([
        'schoolId' => $school->id,
        'classId' => $class->id,
        'levelId' => $level->id,
    ]);

    return [$school, $class, $student];
}

/** Create a teacher User bound to the given school and class. */
function makeTeacherUser(School $school, ?Classes $class = null): User
{
    $email = fake()->unique()->safeEmail();
    $user = User::factory()->create(['role' => 'teacher', 'email' => $email]);
    $teacher = Teacher::factory()->create(['email' => $email]);
    $teacher->schools()->attach($school->id);
    if ($class) {
        $teacher->classes()->attach($class->id);
    }

    return $user;
}

/** Create an assistant User assigned to the given school. */
function makeAssistantUser(School $school): User
{
    $email = fake()->unique()->safeEmail();
    $user = User::factory()->create(['role' => 'assistant', 'email' => $email]);
    $assistant = Assistant::factory()->create(['email' => $email]);
    $assistant->schools()->attach($school->id);

    return $user;
}

test('a teacher cannot read the performance page of a student they do not teach', function () {
    [$schoolA, $classA, $studentA] = makeSchoolWithStudent();
    [$schoolB] = makeSchoolWithStudent();

    // Teacher belongs to school B, and teaches nothing in school A.
    $outsider = makeTeacherUser($schoolB);

    $this->actingAs($outsider)
        ->get("/students/{$studentA->id}/performance")
        ->assertForbidden();
});

test('a teacher can read the performance page of their own student', function () {
    [$school, $class, $student] = makeSchoolWithStudent();

    $teacherUser = makeTeacherUser($school, $class);

    $this->actingAs($teacherUser)
        ->get("/students/{$student->id}/performance")
        ->assertSuccessful();
});

test('an admin can read any student performance page', function () {
    [, , $student] = makeSchoolWithStudent();
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->get("/students/{$student->id}/performance")
        ->assertSuccessful();
});

test('a teacher cannot rewrite a grade for a class they do not teach', function () {
    [$schoolA, $classA, $studentA] = makeSchoolWithStudent();
    [$schoolB] = makeSchoolWithStudent();
    $subject = \App\Models\Subject::factory()->create();

    $outsider = makeTeacherUser($schoolB);

    $this->actingAs($outsider)
        ->post('/results/update-grade', [
            'student_id' => $studentA->id,
            'subject_id' => $subject->id,
            'class_id' => $classA->id,
            'grade_field' => 'grade1',
            'value' => '20',
        ])
        ->assertForbidden();

    expect(\App\Models\Result::where('student_id', $studentA->id)->exists())->toBeFalse();
});

test('a teacher cannot read a school they are not assigned to', function () {
    [$schoolA] = makeSchoolWithStudent();
    [$schoolB] = makeSchoolWithStudent();

    $outsider = makeTeacherUser($schoolB);

    $this->actingAs($outsider)->get("/schools/{$schoolA->id}")->assertForbidden();
});

test('only admins may delete a school', function () {
    [$school] = makeSchoolWithStudent();
    $teacherUser = makeTeacherUser($school);

    $this->actingAs($teacherUser)->delete("/schools/{$school->id}");

    expect(School::whereKey($school->id)->exists())->toBeTrue();
});

test('non-admins are kept out of admin-only pages', function () {
    [$school] = makeSchoolWithStudent();
    $teacherUser = makeTeacherUser($school);

    foreach (['/transactions', '/offers', '/announcements', '/assistants', '/othersettings'] as $uri) {
        $this->actingAs($teacherUser)->get($uri)->assertRedirect('/dashboard');
    }
});

test('an impersonated session does not inherit admin rights', function () {
    [$school, $class] = makeSchoolWithStudent();
    $teacherUser = makeTeacherUser($school, $class);
    $otherTeacher = Teacher::factory()->create();

    // Simulate an active impersonation: acting as the teacher, with an admin id recorded.
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($teacherUser)
        ->withSession(['admin_user_id' => $admin->id])
        ->get("/teachers/{$otherTeacher->id}")
        ->assertForbidden();
});

test('an assistant can open the teacher list the sidebar offers them', function () {
    // Menu.jsx has always shown "Enseignants" to assistants while the middleware denied them,
    // so the nav pointed at a 403.
    [$school] = makeSchoolWithStudent();
    $assistantUser = makeAssistantUser($school);

    $this->actingAs($assistantUser)->get('/teachers')->assertOk();
});

test('the teacher list only shows an assistant their own schools', function () {
    [$mySchool] = makeSchoolWithStudent();
    [$otherSchool] = makeSchoolWithStudent();

    $mine = Teacher::factory()->create(['first_name' => 'Inscope']);
    $mine->schools()->attach($mySchool->id);
    $theirs = Teacher::factory()->create(['first_name' => 'Outofscope']);
    $theirs->schools()->attach($otherSchool->id);

    $assistantUser = makeAssistantUser($mySchool);

    $this->actingAs($assistantUser)
        ->get('/teachers')
        ->assertOk()
        ->assertSee('Inscope')
        ->assertDontSee('Outofscope');
});

test('an assistant still cannot open an individual teacher profile', function () {
    // Profiles carry wallet balances, invoices and payout history.
    [$school] = makeSchoolWithStudent();
    $teacher = Teacher::factory()->create();
    $teacher->schools()->attach($school->id);

    $this->actingAs(makeAssistantUser($school))
        ->get("/teachers/{$teacher->id}")
        ->assertForbidden();
});

test('a teacher can open their own profile', function () {
    // Guards against `(string) $model` — Eloquent's __toString() returns toJson(), so
    // comparing the route model to an id compares a JSON blob to a number and never matches.
    [$school, $class] = makeSchoolWithStudent();
    $teacherUser = makeTeacherUser($school, $class);
    $ownTeacher = Teacher::where('email', $teacherUser->email)->firstOrFail();

    $this->actingAs($teacherUser)
        ->get("/teachers/{$ownTeacher->id}")
        ->assertOk();
});

test('a teacher cannot open a different teacher profile', function () {
    [$school, $class] = makeSchoolWithStudent();
    $teacherUser = makeTeacherUser($school, $class);
    $someoneElse = Teacher::factory()->create();

    $this->actingAs($teacherUser)
        ->get("/teachers/{$someoneElse->id}")
        ->assertForbidden();
});

test('an impersonated teacher can still open their OWN profile', function () {
    // "View as" is meant to show the admin exactly what that teacher sees — and what a
    // teacher sees includes their own profile page. Denying this makes the feature useless:
    // the admin lands on /dashboard as the teacher and cannot reach the page they went to
    // look at.
    [$school, $class] = makeSchoolWithStudent();
    $teacherUser = makeTeacherUser($school, $class);
    $ownTeacher = Teacher::where('email', $teacherUser->email)->firstOrFail();
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($teacherUser)
        ->withSession(['admin_user_id' => $admin->id])
        ->get("/teachers/{$ownTeacher->id}")
        ->assertOk();
});

test('switch-back refuses when the stored id is not an admin', function () {
    [$school] = makeSchoolWithStudent();
    $teacherUser = makeTeacherUser($school);
    $notAnAdmin = User::factory()->create(['role' => 'assistant']);

    $this->actingAs($teacherUser)
        ->withSession(['admin_user_id' => $notAnAdmin->id])
        ->post('/admin/switch-back')
        ->assertForbidden();

    expect(auth()->id())->toBe($teacherUser->id);
});
