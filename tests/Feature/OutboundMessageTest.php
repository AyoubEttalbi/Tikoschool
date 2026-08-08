<?php

use App\Jobs\SendOutboundMessage;
use App\Models\Attendance;
use App\Models\Classes;
use App\Models\OutboundMessage;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\OutboundMessageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/*
 * THE NOTIFICATION RECORD
 *
 * Everything here is about what gets written down and what refuses to be written twice.
 * The behaviour that matters most to the school is the one nobody thinks to ask for: a
 * decision NOT to send has to leave a row, or the question "why was this parent never
 * told?" has no answer.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-08-10 11:00:00');   // inside the notify window
    config()->set('whatsapp.driver', 'log');
});

afterEach(fn () => Carbon::setTestNow());

function absentStudent(array $overrides = []): Student
{
    return Student::factory()->create($overrides + [
        'status' => 'active',
        'guardianNumber' => '0612345678',
    ]);
}

function absenceFor(Student $student): Attendance
{
    $class = Classes::factory()->create();
    $teacher = Teacher::factory()->create();

    return Attendance::create([
        'student_id' => $student->id,
        'classId' => $class->id,
        'date' => '2026-08-10',
        'status' => 'absent',
        'subject' => 'Maths',
        'teacher_id' => $teacher->id,
        'recorded_by' => User::factory()->create(['role' => 'admin'])->id,
    ]);
}

function service(): OutboundMessageService
{
    return app(OutboundMessageService::class);
}

// ------------------------------------------------------------------- the happy path ---

it('records a pending message and queues it', function () {
    Queue::fake();
    $student = absentStudent();

    $message = service()->createForAbsence(absenceFor($student));

    expect($message->status)->toBe(OutboundMessage::STATUS_PENDING)
        ->and($message->recipient)->toBe('212612345678')   // normalised, not raw
        ->and($message->student_id)->toBe($student->id)
        ->and($message->channel)->toBe('whatsapp');

    Queue::assertPushed(SendOutboundMessage::class);
});

it('snapshots the rendered message rather than a reference to the template', function () {
    Queue::fake();
    $student = absentStudent(['firstName' => 'Ahmed', 'lastName' => 'Benali']);

    $message = service()->createForAbsence(absenceFor($student));

    // The body is stored, in full, at the moment it was decided. Rewording the Blade file
    // tomorrow must not rewrite what this parent was actually told.
    expect($message->message)->toContain('Ahmed Benali')
        ->toContain('Maths')
        ->and(mb_strlen($message->message))->toBeGreaterThan(100);
});

// ---------------------------------------------------------------------- idempotency ---

it('does not create a second message when the same sheet is saved again', function () {
    /*
     * THE regression. AttendanceController::store() dispatches from outside the
     * create/update branch, so re-saving a class sheet to fix one typo re-messaged every
     * absent student's parent in that class. The attendance row's id is stable across
     * re-saves, so keying on it makes the second save a no-op.
     */
    Queue::fake();
    $attendance = absenceFor(absentStudent());

    $first = service()->createForAbsence($attendance);
    $second = service()->createForAbsence($attendance);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and(OutboundMessage::count())->toBe(1);

    Queue::assertPushed(SendOutboundMessage::class, 1);
});

it('allows only one manual notice per student per day', function () {
    // The realistic duplicate: staff press the button again because the page gave them no
    // feedback. A parent getting the same absence notice four times is worse than none.
    Queue::fake();
    $student = absentStudent();

    expect(service()->createManual($student))->not->toBeNull()
        ->and(service()->createManual($student))->toBeNull();

    expect(OutboundMessage::count())->toBe(1);
});

it('allows a manual notice again the next day', function () {
    Queue::fake();
    $student = absentStudent();
    service()->createManual($student);

    Carbon::setTestNow('2026-08-11 11:00:00');

    expect(service()->createManual($student))->not->toBeNull()
        ->and(OutboundMessage::count())->toBe(2);
});

it('keeps separate students separate', function () {
    Queue::fake();

    service()->createManual(absentStudent());
    service()->createManual(absentStudent());

    expect(OutboundMessage::count())->toBe(2);
});

// -------------------------------------------------------------- skips leave a trace ---

it('records a student with no guardian number instead of dropping them', function () {
    // This was a Log::warning nobody reads. An unreachable parent is a fact the school
    // needs on a screen.
    Queue::fake();
    $student = absentStudent(['guardianNumber' => null]);

    $message = service()->createForAbsence(absenceFor($student));

    expect($message->status)->toBe(OutboundMessage::STATUS_SKIPPED)
        ->and($message->skip_reason)->toBe(OutboundMessage::SKIP_NO_NUMBER)
        ->and($message->recipient)->toBeNull()
        ->and($message->reason())->toBe('Aucun numéro de tuteur enregistré');

    Queue::assertNothingPushed();
});

it('records a guardian number that cannot be used', function () {
    Queue::fake();
    $student = absentStudent(['guardianNumber' => '12']);

    $message = service()->createForAbsence(absenceFor($student));

    expect($message->status)->toBe(OutboundMessage::STATUS_SKIPPED)
        ->and($message->skip_reason)->toBe(OutboundMessage::SKIP_UNNORMALISABLE);

    Queue::assertNothingPushed();
});

it('honours the per-student off switch', function () {
    Queue::fake();
    $student = absentStudent(['notifyGuardian' => false]);

    $message = service()->createForAbsence(absenceFor($student));

    expect($message->status)->toBe(OutboundMessage::STATUS_SKIPPED)
        ->and($message->skip_reason)->toBe(OutboundMessage::SKIP_OPTED_OUT);

    Queue::assertNothingPushed();
});

it('does not send the same skipped notice twice either', function () {
    Queue::fake();
    $attendance = absenceFor(absentStudent(['guardianNumber' => null]));

    service()->createForAbsence($attendance);
    service()->createForAbsence($attendance);

    expect(OutboundMessage::count())->toBe(1);
});

// -------------------------------------------------------------------- quiet hours -----

it('sends immediately during the school day', function () {
    Queue::fake();
    Carbon::setTestNow('2026-08-10 11:00:00');

    $message = service()->createForAbsence(absenceFor(absentStudent()));

    expect($message->scheduled_at->format('H:i'))->toBe('11:00');
});

it('holds a late-evening absence until the next morning', function () {
    // A class marked absent at 22:15 must not put a message on a parent's phone at
    // midnight, and must not quietly expire inside the retry window either.
    Queue::fake();
    Carbon::setTestNow('2026-08-10 22:15:00');

    $message = service()->createForAbsence(absenceFor(absentStudent()));

    expect($message->scheduled_at->format('Y-m-d H:i'))->toBe('2026-08-11 09:00');
});

it('holds an early-morning absence until the window opens', function () {
    Queue::fake();
    Carbon::setTestNow('2026-08-10 06:30:00');

    $message = service()->createForAbsence(absenceFor(absentStudent()));

    expect($message->scheduled_at->format('Y-m-d H:i'))->toBe('2026-08-10 09:00');
});

// ------------------------------------------------------------------------- retry ------

it('reuses the same row when a failed message is re-driven', function () {
    // One logical notice stays one row for its whole life. Minting a new key with a nonce
    // would defeat the idempotency it exists for.
    Queue::fake();
    $message = service()->createForAbsence(absenceFor(absentStudent()));
    $message->update(['status' => OutboundMessage::STATUS_FAILED, 'last_error' => 'boom']);

    expect(service()->retry($message->fresh()))->toBeTrue();

    $message->refresh();
    expect($message->status)->toBe(OutboundMessage::STATUS_PENDING)
        ->and($message->last_error)->toBeNull()
        ->and(OutboundMessage::count())->toBe(1);
});

it('refuses to re-send something already delivered', function () {
    Queue::fake();
    $message = service()->createForAbsence(absenceFor(absentStudent()));
    $message->update(['status' => OutboundMessage::STATUS_SENT, 'sent_at' => now()]);

    expect(service()->retry($message->fresh()))->toBeFalse();
});

// ------------------------------------------------------------------- security ---------

it('does not message a guardian again when a student is toggled present and absent', function () {
    /*
     * Marking a student PRESENT deletes their attendance row outright, so a
     * present-then-absent toggle used to insert a brand new row with a brand new id,
     * mint a brand new idempotency key, and message the guardian all over again. No
     * second school and no bug needed — one checkbox, flipped back and forth.
     */
    Queue::fake();
    $student = absentStudent();
    $attendance = absenceFor($student);

    service()->createForAbsence($attendance);
    expect(OutboundMessage::count())->toBe(1);

    // The delete-and-recreate the controller performs on a present→absent toggle.
    $attributes = $attendance->only(['student_id', 'classId', 'date', 'status', 'subject', 'teacher_id', 'recorded_by']);
    $attendance->delete();
    $recreated = Attendance::create($attributes);

    expect($recreated->id)->not->toBe($attendance->id);      // genuinely a new row
    expect(service()->createForAbsence($recreated))->toBeNull();
    expect(OutboundMessage::count())->toBe(1);
});

it('strips WhatsApp formatting from free text before it reaches a parent', function () {
    // Blade escapes HTML, which is irrelevant — nothing here is HTML. WhatsApp renders
    // *bold* and links, so unvalidated staff text could dress arbitrary content up as an
    // official school notice.
    Queue::fake();
    $student = absentStudent();

    $message = service()->createManual($student, [
        'subject' => '*URGENT* payez ici_ ~maintenant~',
        'date' => now(),
    ]);

    // str_contains(), not ->not->toContain(): Pest routes the negated form to the
    // ITERABLE matcher, so on a string it raises InvalidExpectationValue rather than
    // asserting anything. Exactly the kind of assertion that looks like it passed.
    expect(str_contains($message->message, '*URGENT*'))->toBeFalse()
        ->and(str_contains($message->message, '~maintenant~'))->toBeFalse()
        ->and($message->message)->toContain('URGENT payez ici maintenant');
});

it('refuses a class sheet containing a student from another school', function () {
    /*
     * store() authorised the CLASS but validated each student only with
     * `exists:students,id` — existence somewhere in the product, not permission. A
     * teacher scoped to school A could put a school-B student_id into the same POST that
     * saves their own sheet and have the app write a fabricated absence AND send a real
     * WhatsApp message, naming that child, to their guardian.
     */
    $schoolA = App\Models\School::factory()->create();
    $schoolB = App\Models\School::factory()->create();

    $assistantUser = User::factory()->create(['role' => 'assistant', 'email' => 'a@example.com']);
    $assistant = App\Models\Assistant::factory()->create(['email' => 'a@example.com']);
    $assistant->schools()->attach($schoolA->id);

    $class = Classes::factory()->create(['school_id' => $schoolA->id]);
    $teacher = Teacher::factory()->create();
    $outsider = absentStudent(['schoolId' => $schoolB->id, 'classId' => $class->id]);

    session(['school_id' => $schoolA->id]);

    test()->actingAs($assistantUser)->post(route('attendances.store'), [
        'class_id' => $class->id,
        'date' => '2026-08-10',
        'teacher_id' => $teacher->id,
        'attendances' => [
            ['student_id' => $outsider->id, 'status' => 'absent', 'reason' => null, 'subject' => 'Maths'],
        ],
    ])->assertForbidden();

    expect(Attendance::count())->toBe(0)
        ->and(OutboundMessage::count())->toBe(0);
});

// ------------------------------------------------------------------ the controller ----

it('queues one notice per absent student when a sheet is saved', function () {
    Queue::fake();
    $admin = User::factory()->create(['role' => 'admin']);
    $class = Classes::factory()->create();
    $teacher = Teacher::factory()->create();
    $student = absentStudent(['classId' => $class->id]);

    $payload = [
        'class_id' => $class->id,
        'date' => '2026-08-10',
        'teacher_id' => $teacher->id,
        'attendances' => [
            ['student_id' => $student->id, 'status' => 'absent', 'reason' => null, 'subject' => 'Maths'],
        ],
    ];

    test()->actingAs($admin)->post(route('attendances.store'), $payload)->assertSessionHasNoErrors();
    expect(OutboundMessage::count())->toBe(1);

    // Saving the identical sheet again — the correcting-a-typo case. This is the bug:
    // the dispatch sat outside the create/update branch, so the second save re-messaged
    // every absent parent in the class.
    test()->actingAs($admin)->post(route('attendances.store'), $payload)->assertSessionHasNoErrors();
    expect(OutboundMessage::count())->toBe(1);
});

it('hides the guardian number and the message body from serialisation', function () {
    // This is now the second-highest-PII table in the app; the admin screen needs the
    // status, not the parent's phone number.
    Queue::fake();
    $message = service()->createForAbsence(absenceFor(absentStudent()));

    $array = $message->toArray();

    expect($array)->not->toHaveKey('recipient')
        ->and($array)->not->toHaveKey('message')
        ->and($array)->toHaveKey('status');
});

// ------------------------------------------------------------------ the message -------

it('brands every branch with the same school name', function () {
    /*
     * `schools` holds BRANCHES — "Tiko school C1", "Tiko school C2" — because that is what
     * the app needs internally to scope students, classes and money. A parent does not
     * know or care which branch row their child sits in; they know the school as Tiko
     * School, and every message must say so however many branches exist.
     */
    Queue::fake();
    config()->set('school.name', 'Tiko School');

    $branch = App\Models\School::factory()->create(['name' => 'Tiko school C2']);
    $student = absentStudent(['schoolId' => $branch->id]);

    $message = service()->createForAbsence(absenceFor($student));

    expect($message->message)->toContain('Tiko School')
        ->and(str_contains($message->message, 'C2'))->toBeFalse();
});

it('uses the branch\'s own phone number, because that one really does differ', function () {
    // The other half of the split: the name is the brand, the phone is the branch — and
    // the phone is the number the parent is being asked to call.
    Queue::fake();

    $branch = App\Models\School::factory()->create([
        'name' => 'Tiko school C2',
        'phone_number' => '05 22 11 22 33',
    ]);
    $student = absentStudent(['schoolId' => $branch->id]);

    $message = service()->createForAbsence(absenceFor($student));

    expect($message->message)->toContain('05 22 11 22 33');
});

it('leaves out the Instagram line when none is configured', function () {
    // Empty means omit, rather than pointing parents at somebody else's account.
    Queue::fake();
    config()->set('school.instagram', '');

    $message = service()->createForAbsence(absenceFor(absentStudent()));

    expect(str_contains($message->message, 'instagram'))->toBeFalse()
        ->and(str_contains($message->message, '📷'))->toBeFalse();
});

it('isolates every Latin run so Arabic punctuation lands on the right side', function () {
    /*
     * Latin names and digits inside Arabic reorder the neutral characters around them:
     * a sentence ending "... بمركز Tiko School." shows the full stop on the WRONG side,
     * and a grouped phone number can render with its groups out of order — worse than
     * ugly, because a parent may dial it.
     *
     * The isolates are invisible, so this is the only way to know they are still there.
     */
    Queue::fake();
    $message = service()->createForAbsence(absenceFor(absentStudent()));
    $body = $message->message;

    $fsi = substr_count($body, "\u{2068}");   // FIRST STRONG ISOLATE
    $pdi = substr_count($body, "\u{2069}");   // POP DIRECTIONAL ISOLATE

    expect($fsi)->toBeGreaterThan(0)
        // Unbalanced isolates are worse than none: an unclosed run swallows the rest of
        // the message into the wrong direction.
        ->and($pdi)->toBe($fsi)
        // And every Arabic line is pinned RTL so a leading emoji cannot flip it.
        ->and(substr_count($body, "\u{200F}"))->toBeGreaterThan(5);
});

it('does not HTML-escape a message that is not HTML', function () {
    /*
     * Blade's default {{ }} escaping is not protection in a plain-text WhatsApp message —
     * it is corruption. It turned the Instagram link's `&` into `&amp;`, and it would have
     * told a guardian their child "O&#039;Brien" was absent.
     *
     * What actually protects this message is plain(), which strips the characters WhatsApp
     * itself renders as formatting.
     */
    Queue::fake();
    config()->set('school.instagram', 'https://example.com/x?a=1&utm_source=qr');

    $student = absentStudent(['firstName' => "O'Brien", 'lastName' => 'Smith']);
    $message = service()->createForAbsence(absenceFor($student));

    expect($message->message)->toContain("O'Brien Smith")
        ->and(str_contains($message->message, '&amp;'))->toBeFalse()
        ->and(str_contains($message->message, '&#039;'))->toBeFalse()
        // plain() must not maul a URL either: it strips '_' for WhatsApp italics, which
        // silently killed utm_source and produced a dead link.
        ->and($message->message)->toContain('utm_source=qr');
});
