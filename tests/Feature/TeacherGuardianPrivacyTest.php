<?php

use App\Models\Assistant;
use App\Models\Classes;
use App\Models\Level;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
 * Guardian privacy — the parent's phone number and name (students.guardianNumber /
 * students.guardianName) are admin + assistant data. Teachers must never receive
 * them, in any payload, on any route:
 *
 *   - /students           list rows (transformStudentData) — keys absent, not null
 *   - /classes/{id}       roster rows (mapStudentsForClass) — keys absent
 *   - /students/{id}      student fiche — already 403 for teachers (StudentsController::show)
 *   - /students/{id}/download-pdf — the PDF prints guardianNumber, so it carries the
 *                         same teacher 403 as show(); SchoolScope alone would let
 *                         a teacher through for students in their classes.
 *   - ?search=06...      guardian fields leave the LIKE clauses for teachers too,
 *                         otherwise the hidden column is only one enumeration probe away.
 *
 * Also pins the /classes/{id} profile-image fix: rows must carry the accessor-resolved
 * URL (/profile-images/students/<hash>.webp), not the raw storage path that
 * DB::table used to hand the frontend.
 */

function privacySchoolWithStudent(): array
{
    $school = School::factory()->create();
    $level = Level::factory()->create();
    $class = Classes::factory()->create(['school_id' => $school->id, 'level_id' => $level->id]);
    $student = Student::factory()->create([
        'schoolId' => $school->id,
        'classId' => $class->id,
        'levelId' => $level->id,
        'status' => 'active',
        'guardianNumber' => '0612345678',
        'guardianName' => 'Tuteur Tiko',
    ]);

    return [$school, $class, $student];
}

function privacyTeacherUser(School $school, Classes $class, Student $student): User
{
    $email = fake()->unique()->safeEmail();
    $user = User::factory()->create(['role' => 'teacher', 'email' => $email]);
    $teacher = Teacher::factory()->create(['email' => $email]);
    $teacher->schools()->attach($school->id);
    $teacher->classes()->attach($class->id);

    // Teacher visibility on /students and /classes/{id} is membership-based: the
    // memberships.teachers JSON must name this teacher for the student to appear.
    Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => Offer::factory()->create()->id,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math', 'amount' => 100]],
    ]);

    return $user;
}

function privacyAssistantUser(School $school): User
{
    $email = fake()->unique()->safeEmail();
    $user = User::factory()->create(['role' => 'assistant', 'email' => $email]);
    $assistant = Assistant::factory()->create(['email' => $email]);
    $assistant->schools()->attach($school->id);

    return $user;
}

// ---------------------------------------------------------------------------
// /students — list payload
// ---------------------------------------------------------------------------

test('a teacher payload on /students has no guardian keys at all', function () {
    [$school, $class, $student] = privacySchoolWithStudent();
    $teacherUser = privacyTeacherUser($school, $class, $student);

    $response = $this->actingAs($teacherUser)->get('/students');
    $response->assertOk();

    $rows = $response->inertiaPage()['props']['students']['data'] ?? null;
    expect($rows)->not->toBeNull()->and(count($rows))->toBeGreaterThan(0);

    $row = collect($rows)->firstWhere('id', $student->id);
    expect($row)->not->toBeNull()
        // Absent, not null — a null would mean the value still reaches the serializer.
        ->and($row)->not->toHaveKey('guardianNumber')
        ->and($row)->not->toHaveKey('guardianName')
        ->and($row)->toHaveKey('phone')
        ->and($row['phone'])->toBe($student->phoneNumber);
});

test('an admin still sees guardian fields on /students', function () {
    [$school, $class, $student] = privacySchoolWithStudent();
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get('/students');
    $response->assertOk();

    $rows = $response->inertiaPage()['props']['students']['data'];
    $row = collect($rows)->firstWhere('id', $student->id);

    expect($row)->not->toBeNull()
        ->and($row['guardianNumber'])->toBe('0612345678')
        ->and($row['guardianName'])->toBe('Tuteur Tiko');
});

test('an assistant still sees guardian fields on /students', function () {
    [$school, $class, $student] = privacySchoolWithStudent();
    $assistant = privacyAssistantUser($school);

    $response = $this->actingAs($assistant)->get('/students');
    $response->assertOk();

    $rows = $response->inertiaPage()['props']['students']['data'];
    $row = collect($rows)->firstWhere('id', $student->id);

    expect($row)->not->toBeNull()
        ->and($row['guardianNumber'])->toBe('0612345678')
        ->and($row['guardianName'])->toBe('Tuteur Tiko');
});

// ---------------------------------------------------------------------------
// /classes/{id} — roster payload
// ---------------------------------------------------------------------------

test('a teacher payload on the class roster has no guardian keys and a resolved profile image', function () {
    Storage::fake('profile-images');
    [$school, $class, $student] = privacySchoolWithStudent();
    $teacherUser = privacyTeacherUser($school, $class, $student);

    // A managed profile image in raw storage form — exactly what DB::table used to leak raw.
    Student::whereKey($student->id)->update(['profile_image' => 'students/'.str_repeat('a', 40).'.webp']);

    $response = $this->actingAs($teacherUser)->get("/classes/{$class->id}");
    $response->assertOk();

    $rows = $response->inertiaPage()['props']['students'] ?? null;
    expect($rows)->not->toBeNull()->and(count($rows))->toBeGreaterThan(0);

    $row = collect($rows)->firstWhere('id', $student->id);
    expect($row)->not->toBeNull()
        ->and($row)->not->toHaveKey('guardianNumber')
        ->and($row)->not->toHaveKey('guardianName')
        // The bug fix: accessor-resolved URL, never the raw "students/<hex>.webp" path.
        ->and($row['profile_image'])->toContain('/profile-images/students/')
        ->and($row['profile_image'])->toContain(str_repeat('a', 40).'.webp')
        ->and($row['profile_image'])->not->toBe('students/'.str_repeat('a', 40).'.webp');
});

test('an admin class roster keeps guardian fields and resolves profile images', function () {
    Storage::fake('profile-images');
    [$school, $class, $student] = privacySchoolWithStudent();
    $admin = User::factory()->create(['role' => 'admin']);

    Student::whereKey($student->id)->update(['profile_image' => 'students/'.str_repeat('b', 40).'.webp']);

    $response = $this->actingAs($admin)->get("/classes/{$class->id}");
    $response->assertOk();

    $rows = $response->inertiaPage()['props']['students'];
    $row = collect($rows)->firstWhere('id', $student->id);

    expect($row)->not->toBeNull()
        ->and($row['guardianNumber'])->toBe('0612345678')
        ->and($row['guardianName'])->toBe('Tuteur Tiko')
        ->and($row['profile_image'])->toContain('/profile-images/students/');
});

test('a class roster with no image sends null, and a legacy cloudinary URL passes through', function () {
    // The factory defaults profile_image to a placeholder URL, so pin the two
    // shapes that actually matter with an explicit empty value.
    [$school, $class] = privacySchoolWithStudent();
    $student = Student::factory()->create([
        'schoolId' => $school->id,
        'classId' => $class->id,
        'levelId' => $class->level_id,
        'status' => 'active',
        'profile_image' => null,
    ]);
    $admin = User::factory()->create(['role' => 'admin']);

    // No image → null (renders the placeholder, never a broken <img>).
    $response = $this->actingAs($admin)->get("/classes/{$class->id}");
    $row = collect($response->inertiaPage()['props']['students'])->firstWhere('id', $student->id);
    expect($row['profile_image'])->toBeNull();

    // Legacy Cloudinary URL → untouched, it is already a renderable absolute URL.
    $legacy = 'https://res.cloudinary.com/demo/image/upload/v1/students/old.jpg';
    Student::whereKey($student->id)->update(['profile_image' => $legacy]);

    $response = $this->actingAs($admin)->get("/classes/{$class->id}");
    $row = collect($response->inertiaPage()['props']['students'])->firstWhere('id', $student->id);
    expect($row['profile_image'])->toBe($legacy);
});

// ---------------------------------------------------------------------------
// /students/{id}/download-pdf — the fiche PDF prints guardianNumber
// ---------------------------------------------------------------------------

test('a teacher cannot export the PDF of a student they teach', function () {
    // SchoolScope alone would allow this teacher — the student is in a class they
    // teach — so without the role block the PDF with the guardian number ships.
    [$school, $class, $student] = privacySchoolWithStudent();
    $teacherUser = privacyTeacherUser($school, $class, $student);

    $this->actingAs($teacherUser)
        ->get("/students/{$student->id}/download-pdf")
        ->assertForbidden();
});

test('an assistant can still export the PDF of a student at their own school', function () {
    [$school, $class, $student] = privacySchoolWithStudent();
    $assistant = privacyAssistantUser($school);

    $this->actingAs($assistant)
        ->get("/students/{$student->id}/download-pdf")
        ->assertSuccessful();
});

test('a teacher cannot read the student fiche page either', function () {
    // The 403 the PDF block is aligned with — pinned so the pair cannot drift apart.
    [$school, $class, $student] = privacySchoolWithStudent();
    $teacherUser = privacyTeacherUser($school, $class, $student);

    $this->actingAs($teacherUser)
        ->get("/students/{$student->id}")
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// ?search= — enumeration probe
// ---------------------------------------------------------------------------

test('a teacher cannot find a student by searching the guardian phone', function () {
    [$school, $class, $student] = privacySchoolWithStudent();
    $teacherUser = privacyTeacherUser($school, $class, $student);

    $response = $this->actingAs($teacherUser)->get('/students?search=0612345678');
    $response->assertOk();

    // The row set must not contain the student — the guardian LIKE clauses are
    // gone for teachers. (The search term itself is echoed in the URL/props, so
    // an assertDontSee on the digits would be meaningless here.)
    $rows = $response->inertiaPage()['props']['students']['data'];
    expect(collect($rows)->firstWhere('id', $student->id))->toBeNull();
});

test('a teacher cannot find a student by searching the guardian name', function () {
    [$school, $class, $student] = privacySchoolWithStudent();
    $teacherUser = privacyTeacherUser($school, $class, $student);

    $response = $this->actingAs($teacherUser)->get('/students?search=Tuteur');
    $response->assertOk();

    $rows = $response->inertiaPage()['props']['students']['data'];
    expect(collect($rows)->firstWhere('id', $student->id))->toBeNull();
});

test('an admin still finds a student by guardian phone search', function () {
    [$school, $class, $student] = privacySchoolWithStudent();
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->get('/students?search=0612345678');
    $response->assertOk();

    $rows = $response->inertiaPage()['props']['students']['data'];
    expect(collect($rows)->firstWhere('id', $student->id))->not->toBeNull();
});

test('a teacher can still search by student name and massar code', function () {
    // The gate must not break the working case for the fields teachers are allowed to.
    [$school, $class, $student] = privacySchoolWithStudent();
    $teacherUser = privacyTeacherUser($school, $class, $student);

    $response = $this->actingAs($teacherUser)->get('/students?search='.$student->firstName);
    $response->assertOk();
    $rows = $response->inertiaPage()['props']['students']['data'];
    expect(collect($rows)->firstWhere('id', $student->id))->not->toBeNull();

    $response = $this->actingAs($teacherUser)->get('/students?search='.$student->massarCode);
    $response->assertOk();
    $rows = $response->inertiaPage()['props']['students']['data'];
    expect(collect($rows)->firstWhere('id', $student->id))->not->toBeNull();
});
