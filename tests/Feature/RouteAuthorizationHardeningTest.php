<?php

use App\Exceptions\AccessDeniedException;
use App\Models\Assistant;
use App\Models\Classes;
use App\Models\Invoice;
use App\Models\Level;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Support\SchoolScope;

/*
 * Regression cover for the authorization holes found in the 2026-08-07 audit.
 *
 * Two distinct classes of bug are pinned here:
 *
 *   1. ROUTE LEVEL (audit §5.1) — the students / invoices / memberships resources were
 *      registered inside `Route::middleware('auth')` with nothing else, while the
 *      `schools` resource on the very next line correctly chained AdminMiddleware. Any
 *      authenticated teacher could DELETE any school's invoices, and invoice deletion
 *      reverses teacher wallet credits, so it destroyed money rather than only data.
 *
 *   2. OBJECT LEVEL (audit §5.2, §5.3) — the surviving routes took an id from the URL and
 *      findOrFail()ed it, so an assistant scoped to one school could read any student or
 *      assistant in the product by walking ids.
 *
 * Plus the structural fix that makes both stick: §5.5.
 */

/** A school with one class and one student. */
function hardeningSchoolWithStudent(): array
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

function hardeningTeacherUser(School $school, ?Classes $class = null): User
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

function hardeningAssistantUser(School $school): User
{
    $email = fake()->unique()->safeEmail();
    $user = User::factory()->create(['role' => 'assistant', 'email' => $email]);
    $assistant = Assistant::factory()->create(['email' => $email]);
    $assistant->schools()->attach($school->id);

    return $user;
}

// ---------------------------------------------------------------------------
// §5.5 — the structural fix
// ---------------------------------------------------------------------------

test('an access denial is not swallowed by a generic catch (\Exception) block', function () {
    // THE bug that made every other authorization fix untrustworthy. 17 of ~20 controllers
    // wrap their body in `try { ... } catch (\Exception $e) { redirect()->back(); }`, and
    // abort(403) throws HttpException, which extends \Exception. A denial therefore became
    // a harmless 302 that looked exactly like success.
    //
    // AccessDeniedException extends \Error instead, so it cannot be caught here.
    $caught = false;

    try {
        SchoolScope::authorizeRole(['admin'], User::factory()->create(['role' => 'teacher']));
    } catch (\Exception $e) {
        $caught = true;
    } catch (AccessDeniedException $e) {
        // expected — reaches the framework handler in a real request
    }

    expect($caught)->toBeFalse();
});

test('an access denial still renders as a real 403 with the right status', function () {
    $denied = new AccessDeniedException('nope');

    expect($denied)->toBeInstanceOf(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface::class)
        ->and($denied->getStatusCode())->toBe(403)
        // Laravel's handler catches \Throwable, and DB::transaction() rolls back on
        // \Throwable too, so nothing else regresses by throwing from the \Error side.
        ->and($denied)->toBeInstanceOf(\Throwable::class)
        ->and($denied)->not->toBeInstanceOf(\Exception::class);
});

// ---------------------------------------------------------------------------
// §5.1 — route-level role gating
// ---------------------------------------------------------------------------

test('a teacher cannot delete an invoice', function () {
    [$school, $class, $student] = hardeningSchoolWithStudent();
    $teacherUser = hardeningTeacherUser($school, $class);

    $offer = Offer::factory()->create();
    $membership = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
    ]);
    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
    ]);

    $this->actingAs($teacherUser)
        ->delete("/invoices/{$invoice->id}")
        ->assertForbidden();

    // Still there. Deletion reverses wallet credits, so a swallowed denial here
    // would have moved real money.
    expect(Invoice::whereKey($invoice->id)->exists())->toBeTrue();
});

test('a teacher cannot delete a student', function () {
    [$school, $class, $student] = hardeningSchoolWithStudent();

    $this->actingAs(hardeningTeacherUser($school, $class))
        ->delete("/students/{$student->id}")
        ->assertForbidden();

    expect(Student::whereKey($student->id)->exists())->toBeTrue();
});

test('a teacher cannot update a membership', function () {
    [$school, $class, $student] = hardeningSchoolWithStudent();
    $offer = Offer::factory()->create();
    $membership = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
    ]);

    $this->actingAs(hardeningTeacherUser($school, $class))
        ->put("/memberships/{$membership->id}", [
            'student_id' => $student->id,
            'offer_id' => $offer->id,
            'teachers' => [],
        ])
        ->assertForbidden();
});

test('the student invoice delete alias carries the same guard as the resource route', function () {
    // A second door to InvoiceController::destroy. Guarding only the resource route
    // would have left this one wide open.
    [$school, $class, $student] = hardeningSchoolWithStudent();
    $offer = Offer::factory()->create();
    $membership = Membership::factory()->create(['student_id' => $student->id, 'offer_id' => $offer->id]);
    $invoice = Invoice::factory()->create(['membership_id' => $membership->id, 'student_id' => $student->id]);

    $this->actingAs(hardeningTeacherUser($school, $class))
        ->delete("/students/invoices/{$invoice->id}")
        ->assertForbidden();

    expect(Invoice::whereKey($invoice->id)->exists())->toBeTrue();
});

test('a teacher cannot enumerate the staff directory', function () {
    $school = School::factory()->create();

    $this->actingAs(hardeningTeacherUser($school))
        ->get('/users')
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// §5.2 — object-level scoping on student reads
// ---------------------------------------------------------------------------

test('an assistant cannot read a student at another school', function () {
    [$schoolA, , $studentA] = hardeningSchoolWithStudent();
    [$schoolB] = hardeningSchoolWithStudent();

    // Scoped to B, reaching for A's student — medical fields, guardian phone, CIN.
    $this->actingAs(hardeningAssistantUser($schoolB))
        ->get("/students/{$studentA->id}")
        ->assertForbidden();
});

test('an assistant cannot export the PDF of a student at another school', function () {
    // downloadPdf had no check of ANY kind — not even the teacher-role block that show()
    // carried, so it was the easier of the two to walk.
    [, , $studentA] = hardeningSchoolWithStudent();
    [$schoolB] = hardeningSchoolWithStudent();

    $this->actingAs(hardeningAssistantUser($schoolB))
        ->get("/students/{$studentA->id}/download-pdf")
        ->assertForbidden();
});

test('an assistant can still read a student at their own school', function () {
    // The guard must not break the working case.
    [$school, , $student] = hardeningSchoolWithStudent();

    $this->actingAs(hardeningAssistantUser($school))
        ->get("/students/{$student->id}")
        ->assertSuccessful();
});

// ---------------------------------------------------------------------------
// §5.3 — assistant profiles
// ---------------------------------------------------------------------------

test('one assistant cannot read another assistant profile', function () {
    $schoolA = School::factory()->create();
    $userA = hardeningAssistantUser($schoolA);
    $other = Assistant::factory()->create();

    // show() returns salary, phone_number, address, unpaid invoices with student names
    // and amounts, recent payments and the activity log. Read was unguarded while PUT and
    // DELETE both chained AdminMiddleware.
    $this->actingAs($userA)
        ->get("/assistants/{$other->id}")
        ->assertForbidden();
});

test('an assistant can read their own profile', function () {
    $school = School::factory()->create();
    $user = hardeningAssistantUser($school);
    $self = Assistant::where('email', $user->email)->firstOrFail();

    $this->actingAs($user)
        ->get("/assistants/{$self->id}")
        ->assertSuccessful();
});

test('a teacher cannot read an assistant profile at all', function () {
    $school = School::factory()->create();
    $assistant = Assistant::factory()->create();

    $this->actingAs(hardeningTeacherUser($school))
        ->get("/assistants/{$assistant->id}")
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// §5.1 — the wallet-fraud chain
// ---------------------------------------------------------------------------

test('an assistant cannot name a teacher from another school on a membership', function () {
    // The payload half of the fraud chain: `teachers.*.teacherId` was validated only as
    // `required|string` — no exists:, no ownership check — and processTeacherPayment then
    // did Teacher::find() on it. Naming yourself on another school's membership and
    // triggering an invoice update credited your own wallet from their student's payment.
    [$schoolA, , $studentA] = hardeningSchoolWithStudent();
    $schoolB = School::factory()->create();

    $foreignTeacher = Teacher::factory()->create();
    $foreignTeacher->schools()->attach($schoolB->id);

    $offer = Offer::factory()->create();
    $membership = Membership::factory()->create([
        'student_id' => $studentA->id,
        'offer_id' => $offer->id,
    ]);

    $this->actingAs(hardeningAssistantUser($schoolA))
        ->put("/memberships/{$membership->id}", [
            'student_id' => $studentA->id,
            'offer_id' => $offer->id,
            'teachers' => [
                ['subject' => 'Math', 'teacherId' => $foreignTeacher->id, 'amount' => 100],
            ],
        ])
        ->assertForbidden();
});
