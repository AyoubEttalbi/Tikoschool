<?php

use App\Jobs\SendOutboundMessage;
use App\Models\Assistant;
use App\Models\Attendance;
use App\Models\Classes;
use App\Models\OutboundMessage;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\OutboundMessageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/*
 * THE APPROVAL GATE
 *
 * The register records; a person sends. These tests pin the seam between the two:
 *
 *   a teacher's saved sheet must reach the outbound_messages table (rendered, keyed,
 *   deduplicated) and MUST NOT dispatch anything
 *
 *   releasing — one row from the absence log's button, or the whole day at once — is
 *   the only thing that moves a notice from `awaiting_approval` to `pending`
 *
 *   correcting the sheet (present, late, deleted) withdraws the waiting notice rather
 *   than leaving a landmine for the end-of-day release
 *
 *   the recovery sweep, which exists to rescue messages from machine failures, must
 *   never rescue one from a human decision
 */

beforeEach(function () {
    Carbon::setTestNow('2026-08-10 11:00:00');
    Queue::fake();
    config()->set('whatsapp.driver', 'log');
    config()->set('whatsapp.max_age_days', 5);
});

afterEach(fn () => Carbon::setTestNow());

function approvalStudent(array $overrides = []): Student
{
    return Student::factory()->create($overrides + [
        'status' => 'active',
        'guardianNumber' => '0612345678',
    ]);
}

function approvalClass(?School $school = null): Classes
{
    return Classes::factory()->create([
        'school_id' => ($school ?? School::factory()->create())->id,
    ]);
}

/**
 * Save a sheet through the real route — the register's own path, with its validation,
 * its transaction and its school checks, not the service called directly.
 *
 * The teacher matters: attendance rows are keyed by (student, date, class, teacher,
 * subject), so a re-save must name the SAME teacher or it is a different lesson and
 * the correction path never finds the row it is supposed to fix.
 */
function saveSheet(User $actor, Classes $class, array $rows, string $date = '2026-08-10', ?Teacher $teacher = null)
{
    return test()->actingAs($actor)->post(route('attendances.store'), [
        'class_id' => $class->id,
        'date' => $date,
        'teacher_id' => ($teacher ?? Teacher::factory()->create())->id,
        'attendances' => $rows,
    ]);
}

function absentRow(Student $student, string $subject = 'Maths'): array
{
    return ['student_id' => $student->id, 'status' => 'absent', 'reason' => null, 'subject' => $subject];
}

// ------------------------------------------------ the register records, a person sends --

it('records absent notices without dispatching anything', function () {
    $class = approvalClass();
    $student = approvalStudent(['classId' => $class->id]);

    saveSheet(User::factory()->create(['role' => 'admin']), $class, [absentRow($student)])
        ->assertSessionHasNoErrors();

    $notice = OutboundMessage::sole();

    expect($notice->status)->toBe(OutboundMessage::STATUS_AWAITING_APPROVAL)
        ->and($notice->attendance_id)->toBe(Attendance::sole()->id);

    // The whole point: a wrong checkbox on the register page costs a row, not a parent.
    Queue::assertNotPushed(SendOutboundMessage::class);
});

it('still records skips immediately — the parent has no number either way', function () {
    $class = approvalClass();
    $student = approvalStudent(['classId' => $class->id, 'guardianNumber' => null]);

    saveSheet(User::factory()->create(['role' => 'admin']), $class, [absentRow($student)])
        ->assertSessionHasNoErrors();

    $notice = OutboundMessage::sole();

    expect($notice->status)->toBe(OutboundMessage::STATUS_SKIPPED)
        ->and($notice->skip_reason)->toBe(OutboundMessage::SKIP_NO_NUMBER);
});

it('tells the saver the notices await validation', function () {
    $class = approvalClass();
    $student = approvalStudent(['classId' => $class->id]);

    $response = saveSheet(User::factory()->create(['role' => 'admin']), $class, [absentRow($student)]);

    // The flash is the only feedback a teacher gets that nothing was sent. A plain
    // "saved" would leave them believing the parents had been told.
    $response->assertSessionHas('success');
    expect(session('success'))->toContain('validation');
});

it('re-saving the sheet neither duplicates nor releases the notices', function () {
    $class = approvalClass();
    $student = approvalStudent(['classId' => $class->id]);
    $actor = User::factory()->create(['role' => 'admin']);
    $teacher = Teacher::factory()->create();

    saveSheet($actor, $class, [absentRow($student)], teacher: $teacher)->assertSessionHasNoErrors();
    saveSheet($actor, $class, [absentRow($student)], teacher: $teacher)->assertSessionHasNoErrors();

    expect(OutboundMessage::count())->toBe(1)
        ->and(OutboundMessage::sole()->status)->toBe(OutboundMessage::STATUS_AWAITING_APPROVAL);

    Queue::assertNotPushed(SendOutboundMessage::class);
});

// -------------------------------------------------------------------- the release --

it('releases the whole day at once', function () {
    $class = approvalClass();
    $maths = approvalStudent(['classId' => $class->id]);
    $french = approvalStudent(['classId' => $class->id]);
    $admin = User::factory()->create(['role' => 'admin']);

    saveSheet($admin, $class, [absentRow($maths), absentRow($french, 'Français')])
        ->assertSessionHasNoErrors();

    test()->actingAs($admin)
        ->post(route('absence.notifications.release'), ['date' => '2026-08-10'])
        ->assertSessionHas('success');

    expect(OutboundMessage::where('status', OutboundMessage::STATUS_PENDING)->count())->toBe(2);

    Queue::assertPushed(SendOutboundMessage::class, 2);
});

it('releases nothing when the day has nothing waiting', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    test()->actingAs($admin)
        ->post(route('absence.notifications.release'), ['date' => '2026-08-10'])
        ->assertSessionHas('warning');

    Queue::assertNotPushed(SendOutboundMessage::class);
});

it('refuses the release to a teacher', function () {
    test()->actingAs(User::factory()->create(['role' => 'teacher']))
        ->post(route('absence.notifications.release'), ['date' => '2026-08-10'])
        ->assertForbidden();
});

it('scopes the release to the assistant’s schools', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $classA = approvalClass($schoolA);
    $classB = approvalClass($schoolB);
    $inA = approvalStudent(['classId' => $classA->id, 'schoolId' => $schoolA->id]);
    $inB = approvalStudent(['classId' => $classB->id, 'schoolId' => $schoolB->id]);

    $assistantUser = User::factory()->create(['role' => 'assistant', 'email' => 'scope@example.com']);
    $assistant = Assistant::factory()->create(['email' => 'scope@example.com']);
    $assistant->schools()->attach($schoolA->id);

    // The admin saves both schools' sheets — the register is open to every role.
    $admin = User::factory()->create(['role' => 'admin']);
    saveSheet($admin, $classA, [absentRow($inA)])->assertSessionHasNoErrors();
    saveSheet($admin, $classB, [absentRow($inB)])->assertSessionHasNoErrors();

    test()->actingAs($assistantUser)
        ->post(route('absence.notifications.release'), ['date' => '2026-08-10'])
        ->assertSessionHas('success');

    expect(OutboundMessage::where('student_id', $inA->id)->sole()->status)
        ->toBe(OutboundMessage::STATUS_PENDING)
        ->and(OutboundMessage::where('student_id', $inB->id)->sole()->status)
        ->toBe(OutboundMessage::STATUS_AWAITING_APPROVAL);

    Queue::assertPushed(SendOutboundMessage::class, 1);
});

it('releases one waiting notice when the notify button is pressed', function () {
    $class = approvalClass();
    $student = approvalStudent(['classId' => $class->id]);
    $admin = User::factory()->create(['role' => 'admin']);

    saveSheet($admin, $class, [absentRow($student)])->assertSessionHasNoErrors();

    // The per-row "Envoyer WhatsApp" on an awaiting row is the approval, not a duplicate.
    test()->actingAs($admin)
        ->post(route('absence.notify', $student->id), ['attendance_id' => Attendance::sole()->id])
        ->assertSessionHas('success');

    expect(OutboundMessage::sole()->status)->toBe(OutboundMessage::STATUS_PENDING)
        ->and(OutboundMessage::count())->toBe(1);

    Queue::assertPushed(SendOutboundMessage::class, 1);
});

it('sends exactly once when the same notice is validated twice', function () {
    $class = approvalClass();
    $student = approvalStudent(['classId' => $class->id]);
    $admin = User::factory()->create(['role' => 'admin']);

    saveSheet($admin, $class, [absentRow($student)])->assertSessionHasNoErrors();

    // Two hydrated copies of the same row, as two parallel requests would hold. The
    // guarded UPDATE — not the in-memory check — is what must decide.
    $service = app(OutboundMessageService::class);
    $first = OutboundMessage::sole();
    $second = OutboundMessage::find($first->id);

    expect($service->release($first))->toBeTrue()
        ->and($service->release($second))->toBeFalse();

    Queue::assertPushed(SendOutboundMessage::class, 1);
});

it('honours a guardian opt-out that happened between the register and the approval', function () {
    $class = approvalClass();
    $student = approvalStudent(['classId' => $class->id]);
    $admin = User::factory()->create(['role' => 'admin']);

    saveSheet($admin, $class, [absentRow($student)])->assertSessionHasNoErrors();

    // The parent asks to stop receiving notices during the day. The release must not
    // send what the snapshot was willing to send this morning.
    $student->forceFill(['notifyGuardian' => false])->saveQuietly();

    $service = app(OutboundMessageService::class);
    expect($service->release(OutboundMessage::sole()))->toBeFalse();

    $notice = OutboundMessage::sole()->refresh();

    expect($notice->status)->toBe(OutboundMessage::STATUS_SKIPPED)
        ->and($notice->skip_reason)->toBe(OutboundMessage::SKIP_OPTED_OUT);

    Queue::assertNotPushed(SendOutboundMessage::class);
});

// ------------------------------------------------------------- correcting mistakes --

it('cancels the waiting notice when the student is marked present on re-save', function () {
    $class = approvalClass();
    $student = approvalStudent(['classId' => $class->id]);
    $actor = User::factory()->create(['role' => 'admin']);
    $teacher = Teacher::factory()->create();

    saveSheet($actor, $class, [absentRow($student)], teacher: $teacher)->assertSessionHasNoErrors();

    // The teacher notices the wrong checkbox and fixes it. This must be enough — the
    // reviewer should not have to know a notice was minted and withdraw it by hand.
    saveSheet($actor, $class, [
        ['student_id' => $student->id, 'status' => 'present', 'reason' => null, 'subject' => 'Maths'],
    ], teacher: $teacher)->assertSessionHasNoErrors();

    $notice = OutboundMessage::sole();

    expect($notice->status)->toBe(OutboundMessage::STATUS_SKIPPED)
        ->and($notice->skip_reason)->toBe(OutboundMessage::SKIP_CANCELLED);

    Queue::assertNotPushed(SendOutboundMessage::class);
});

it('cancels the waiting notice when a re-save downgrades absent to late', function () {
    $class = approvalClass();
    $student = approvalStudent(['classId' => $class->id]);
    $actor = User::factory()->create(['role' => 'admin']);
    $teacher = Teacher::factory()->create();

    saveSheet($actor, $class, [absentRow($student)], teacher: $teacher)->assertSessionHasNoErrors();
    saveSheet($actor, $class, [
        ['student_id' => $student->id, 'status' => 'late', 'reason' => null, 'subject' => 'Maths'],
    ], teacher: $teacher)->assertSessionHasNoErrors();

    $notice = OutboundMessage::sole();

    expect($notice->status)->toBe(OutboundMessage::STATUS_SKIPPED)
        ->and($notice->skip_reason)->toBe(OutboundMessage::SKIP_CANCELLED);
});

it('cancels the waiting notice when the absence row is deleted outright', function () {
    $class = approvalClass();
    $student = approvalStudent(['classId' => $class->id]);
    $admin = User::factory()->create(['role' => 'admin']);

    saveSheet($admin, $class, [absentRow($student)])->assertSessionHasNoErrors();

    test()->actingAs($admin)
        ->delete(route('attendances.destroy', Attendance::sole()->id))
        ->assertRedirect();

    $notice = OutboundMessage::sole();

    expect($notice->status)->toBe(OutboundMessage::STATUS_SKIPPED)
        ->and($notice->skip_reason)->toBe(OutboundMessage::SKIP_CANCELLED);
});

it('leaves a cancelled notice cancelled when the day is released', function () {
    $class = approvalClass();
    $wrong = approvalStudent(['classId' => $class->id]);
    $right = approvalStudent(['classId' => $class->id]);
    $actor = User::factory()->create(['role' => 'admin']);
    $teacher = Teacher::factory()->create();

    saveSheet($actor, $class, [absentRow($wrong), absentRow($right)], teacher: $teacher)
        ->assertSessionHasNoErrors();

    // The fix: the wrong absence becomes present, withdrawing its notice.
    saveSheet($actor, $class, [
        ['student_id' => $wrong->id, 'status' => 'present', 'reason' => null, 'subject' => 'Maths'],
    ], teacher: $teacher)->assertSessionHasNoErrors();

    test()->actingAs($actor)
        ->post(route('absence.notifications.release'), ['date' => '2026-08-10'])
        ->assertSessionHas('success');

    expect(OutboundMessage::where('student_id', $wrong->id)->sole()->status)
        ->toBe(OutboundMessage::STATUS_SKIPPED)
        ->and(OutboundMessage::where('student_id', $right->id)->sole()->status)
        ->toBe(OutboundMessage::STATUS_PENDING);

    Queue::assertPushed(SendOutboundMessage::class, 1);
});

// ------------------------------------- the sweep leaves human decisions alone --

it('never releases a waiting notice, even with the gateway ready', function () {
    $class = approvalClass();
    $student = approvalStudent(['classId' => $class->id]);

    saveSheet(User::factory()->create(['role' => 'admin']), $class, [absentRow($student)])
        ->assertSessionHasNoErrors();

    // The log driver reports the gateway as sendable, so nothing below is held back by
    // an unavailable service — the only thing that could release the row is a bug.
    test()->artisan('notifications:retry-failed')->assertSuccessful();

    expect(OutboundMessage::sole()->status)->toBe(OutboundMessage::STATUS_AWAITING_APPROVAL);

    Queue::assertNotPushed(SendOutboundMessage::class);
});

it('expires a waiting notice nobody approved within the window', function () {
    $class = approvalClass();
    $student = approvalStudent(['classId' => $class->id]);

    saveSheet(User::factory()->create(['role' => 'admin']), $class, [absentRow($student)])
        ->assertSessionHasNoErrors();

    Carbon::setTestNow('2026-08-17 11:00:00');   // max_age_days = 5

    test()->artisan('notifications:retry-failed')->assertSuccessful();

    expect(OutboundMessage::sole()->status)->toBe(OutboundMessage::STATUS_EXPIRED);

    Queue::assertNotPushed(SendOutboundMessage::class);
});

// ---------------------------------------------------------------- the admin screen --

it('counts, filters and offers to send waiting notices on the notifications screen', function () {
    $class = approvalClass();
    $student = approvalStudent(['classId' => $class->id]);

    saveSheet(User::factory()->create(['role' => 'admin']), $class, [absentRow($student)])
        ->assertSessionHasNoErrors();

    $response = test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('notifications.index', ['status' => 'awaiting_approval', 'date' => '2026-08-10']))
        ->assertOk();

    $page = $response->inertiaPage();
    $row = collect($page['props']['messages']['data'])->first();

    expect($page['props']['counts']['awaitingApproval'])->toBe(1)
        ->and($row['status'])->toBe('awaiting_approval')
        ->and($row['canRetry'])->toBeTrue();
});

// ---------------------------------------------------------------- the absence log --

it('lists a day\'s records latest first', function () {
    $class = approvalClass();
    $student = approvalStudent(['classId' => $class->id]);
    $admin = User::factory()->create(['role' => 'admin']);

    // The date filter fixes the day; reverse id order must then present the most
    // recently recorded row first, last sheet first.
    Attendance::create([
        'student_id' => $student->id, 'classId' => $class->id,
        'date' => '2026-08-10', 'status' => 'late', 'recorded_by' => $admin->id, 'subject' => 'A',
        'teacher_id' => Teacher::factory()->create()->id,
    ]);
    Attendance::create([
        'student_id' => $student->id, 'classId' => $class->id,
        'date' => '2026-08-10', 'status' => 'absent', 'recorded_by' => $admin->id, 'subject' => 'B',
        'teacher_id' => Teacher::factory()->create()->id,
    ]);

    $response = test()->actingAs($admin)
        ->getJson(route('absence.log.data', ['date' => '2026-08-10']))
        ->assertOk();

    expect(collect($response->json('data.data'))->pluck('subject')->all())->toBe(['B', 'A']);
});

it('filters the log by status', function () {
    $class = approvalClass();
    $student = approvalStudent(['classId' => $class->id]);
    $admin = User::factory()->create(['role' => 'admin']);

    Attendance::create([
        'student_id' => $student->id, 'classId' => $class->id,
        'date' => '2026-08-10', 'status' => 'late', 'recorded_by' => $admin->id,
        'teacher_id' => Teacher::factory()->create()->id, 'subject' => 'Late subj',
    ]);
    Attendance::create([
        'student_id' => $student->id, 'classId' => $class->id,
        'date' => '2026-08-10', 'status' => 'absent', 'recorded_by' => $admin->id,
        'teacher_id' => Teacher::factory()->create()->id, 'subject' => 'Absent subj',
    ]);

    $absentOnly = test()->actingAs($admin)
        ->getJson(route('absence.log.data', ['date' => '2026-08-10', 'status' => 'absent']))
        ->assertOk();

    expect(collect($absentOnly->json('data.data'))->pluck('status')->all())->toBe(['absent'])
        ->and($absentOnly->json('data.total'))->toBe(1);

    $lateOnly = test()->actingAs($admin)
        ->getJson(route('absence.log.data', ['date' => '2026-08-10', 'status' => 'late']))
        ->assertOk();

    expect(collect($lateOnly->json('data.data'))->pluck('status')->all())->toBe(['late']);
});

// ------------------------------------------------------------------ the menu badge --

function badgePage(User $actor, string $routeName): array
{
    $page = test()->actingAs($actor)->get(route($routeName))->assertOk()->inertiaPage();

    return $page['props'];
}

it('badges the menu with the awaiting count for an admin', function () {
    $class = approvalClass();
    $student = approvalStudent(['classId' => $class->id]);

    saveSheet(User::factory()->create(['role' => 'admin']), $class, [absentRow($student)])
        ->assertSessionHasNoErrors();

    $props = badgePage(User::factory()->create(['role' => 'admin']), 'absence.log.page');

    expect($props['pendingNoticesCount'])->toBe(1);
});

it('scopes the badge to the assistant\'s schools', function () {
    $schoolA = School::factory()->create();
    $schoolB = School::factory()->create();

    $classA = approvalClass($schoolA);
    $classB = approvalClass($schoolB);
    $inA = approvalStudent(['classId' => $classA->id, 'schoolId' => $schoolA->id]);
    $inB = approvalStudent(['classId' => $classB->id, 'schoolId' => $schoolB->id]);

    $admin = User::factory()->create(['role' => 'admin']);
    saveSheet($admin, $classA, [absentRow($inA)])->assertSessionHasNoErrors();
    saveSheet($admin, $classB, [absentRow($inB)])->assertSessionHasNoErrors();

    $assistantUser = User::factory()->create(['role' => 'assistant', 'email' => 'badge@example.com']);
    $assistant = Assistant::factory()->create(['email' => 'badge@example.com']);
    $assistant->schools()->attach($schoolA->id);

    $props = badgePage($assistantUser, 'absence.log.page');

    expect($props['pendingNoticesCount'])->toBe(1);
});

it('hides the badge from teachers', function () {
    $props = badgePage(User::factory()->create(['role' => 'teacher']), 'dashboard');

    expect($props['pendingNoticesCount'] ?? 0)->toBe(0);
});

it('refreshes the badge through the unread-count poll', function () {
    $class = approvalClass();
    $student = approvalStudent(['classId' => $class->id]);

    saveSheet(User::factory()->create(['role' => 'admin']), $class, [absentRow($student)])
        ->assertSessionHasNoErrors();

    $admin = User::factory()->create(['role' => 'admin']);

    test()->actingAs($admin)
        ->getJson('/unread-count')
        ->assertOk()
        ->assertJsonPath('pending_notices', 1);

    // The release empties it, so the next poll can clear the badge without a reload.
    test()->actingAs($admin)
        ->post(route('absence.notifications.release'), ['date' => '2026-08-10'])
        ->assertSessionHas('success');

    test()->actingAs($admin)
        ->getJson('/unread-count')
        ->assertOk()
        ->assertJsonPath('pending_notices', 0);
});
