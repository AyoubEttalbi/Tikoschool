<?php

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
 * THE "ENVOYER WHATSAPP" BUTTON
 *
 * The manual send from the absence log. It has three outcomes and for a long time the
 * screen showed the same green checkmark for all three:
 *
 *   queued          the only one that was true
 *   duplicate       already notified for this pupil and subject today — nothing created
 *   skipped         no usable guardian number, or the guardian opted out
 *
 * The server distinguished them correctly the whole time; it flashed `warning` and `error`
 * that no page rendered. So the tests below assert the FLASH KEY, not just the row count —
 * a refusal that reaches no screen is the bug, not the refusal itself.
 *
 * The subject is load-bearing too. A pupil absent from Maths and French on one day must
 * produce two notices, and the button omitted the subject entirely, so the second row's
 * button hashed the same empty string and refused as a duplicate of the first.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-08-10 11:00:00');
    Queue::fake();
    config()->set('whatsapp.driver', 'log');
});

afterEach(fn () => Carbon::setTestNow());

function notifiableStudent(array $overrides = []): Student
{
    return Student::factory()->create($overrides + [
        'status' => 'active',
        'guardianNumber' => '0612345678',
    ]);
}

function notifyingAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function absenceRow(Student $student, string $subject, string $date = '2026-08-10'): Attendance
{
    return Attendance::create([
        'student_id' => $student->id,
        'classId' => Classes::factory()->create()->id,
        'date' => $date,
        'status' => 'absent',
        'subject' => $subject,
        'teacher_id' => Teacher::factory()->create()->id,
        'recorded_by' => User::factory()->create(['role' => 'admin'])->id,
    ]);
}

/*
 * THE DUPLICATE THE BUTTON USED TO SEND
 *
 * The register reports an absence automatically. Somebody then opens the absence log, sees
 * a plain green "Envoyer WhatsApp" with no indication it had already gone out, and presses
 * it. The manual path used its own key — pupil + today + subject — which never collided
 * with the register's key, so a real parent received the same notice twice.
 *
 * Both paths now key on the absence itself, so the second one is refused.
 */
it('refuses to re-report an absence the register already reported', function () {
    $student = notifiableStudent();
    $absence = absenceRow($student, 'Mathématiques');

    app(OutboundMessageService::class)->createForAbsence($absence);

    test()->actingAs(notifyingAdmin())
        ->post(route('absence.notify', $student->id), ['attendance_id' => $absence->id])
        ->assertRedirect()
        ->assertSessionMissing('success')
        ->assertSessionHas('warning');

    expect(OutboundMessage::where('student_id', $student->id)->count())->toBe(1);
});

it('reports two absences on the same day separately', function () {
    $student = notifiableStudent();
    $maths = absenceRow($student, 'Mathématiques');
    $french = absenceRow($student, 'Français');

    test()->actingAs(notifyingAdmin())
        ->post(route('absence.notify', $student->id), ['attendance_id' => $maths->id])
        ->assertSessionHas('success');

    test()->actingAs(notifyingAdmin())
        ->post(route('absence.notify', $student->id), ['attendance_id' => $french->id])
        ->assertSessionHas('success');

    expect(OutboundMessage::where('student_id', $student->id)->count())->toBe(2);
});

it('records which absence the notice was about', function () {
    $student = notifiableStudent();
    $absence = absenceRow($student, 'Mathématiques');

    test()->actingAs(notifyingAdmin())
        ->post(route('absence.notify', $student->id), ['attendance_id' => $absence->id]);

    expect(OutboundMessage::sole()->attendance_id)->toBe($absence->id);
});

/*
 * $student is authorised; an id posted in the request body is not. Without the ownership
 * check, any absence in the database could be attached to a pupil the caller can see.
 */
it('will not attach another pupil\'s absence to this one', function () {
    $student = notifiableStudent();
    $other = notifiableStudent();
    $absence = absenceRow($other, 'Mathématiques');

    test()->actingAs(notifyingAdmin())
        ->post(route('absence.notify', $student->id), ['attendance_id' => $absence->id])
        ->assertNotFound();

    expect(OutboundMessage::count())->toBe(0);
});

it('tells the absence log what each parent already knows', function () {
    $student = notifiableStudent();
    $reported = absenceRow($student, 'Mathématiques');
    $untouched = absenceRow($student, 'Français');

    app(OutboundMessageService::class)->createForAbsence($reported);

    $summary = OutboundMessage::summaryForAttendances([$reported->id, $untouched->id]);

    expect($summary)->toHaveKey($reported->id)
        ->and($summary[$reported->id]['status'])->toBe(OutboundMessage::STATUS_PENDING)
        ->and($summary)->not->toHaveKey($untouched->id);
});

it('queues a notice and says so', function () {
    $student = notifiableStudent();

    test()->actingAs(notifyingAdmin())
        ->post(route('absence.notify', $student->id), ['subject' => 'Mathématiques'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(OutboundMessage::where('student_id', $student->id)->count())->toBe(1);
});

it('refuses a second send for the same subject and says WHY', function () {
    $student = notifiableStudent();

    test()->actingAs(notifyingAdmin())
        ->post(route('absence.notify', $student->id), ['subject' => 'Mathématiques']);

    // The second click. Nothing is created — and the reason must reach the screen, because
    // a silent no-op is indistinguishable from a successful send.
    test()->actingAs(notifyingAdmin())
        ->post(route('absence.notify', $student->id), ['subject' => 'Mathématiques'])
        ->assertRedirect()
        ->assertSessionMissing('success')
        ->assertSessionHas('warning');

    expect(OutboundMessage::where('student_id', $student->id)->count())->toBe(1);
});

/*
 * The rule the school actually stated: two subjects on one day means two messages to the
 * parent. The register already did this; the manual button did not, because it sent no
 * subject at all and every send hashed the same empty value.
 */
it('sends a second notice for a different subject on the same day', function () {
    $student = notifiableStudent();

    test()->actingAs(notifyingAdmin())
        ->post(route('absence.notify', $student->id), ['subject' => 'Mathématiques'])
        ->assertSessionHas('success');

    test()->actingAs(notifyingAdmin())
        ->post(route('absence.notify', $student->id), ['subject' => 'Français'])
        ->assertSessionHas('success');

    expect(OutboundMessage::where('student_id', $student->id)->count())->toBe(2);
});

it('reports a guardian who cannot be reached instead of reporting success', function () {
    $student = notifiableStudent(['guardianNumber' => null]);

    test()->actingAs(notifyingAdmin())
        ->post(route('absence.notify', $student->id), ['subject' => 'Mathématiques'])
        ->assertRedirect()
        ->assertSessionMissing('success')
        ->assertSessionHas('error');

    // The skip is recorded rather than dropped: "we chose not to send, and here is why" is
    // the single most useful row on the notifications screen.
    $message = OutboundMessage::where('student_id', $student->id)->sole();

    expect($message->status)->toBe(OutboundMessage::STATUS_SKIPPED);
});

it('rejects a subject long enough to be a payload', function () {
    $student = notifiableStudent();

    test()->actingAs(notifyingAdmin())
        ->post(route('absence.notify', $student->id), ['subject' => str_repeat('a', 200)])
        ->assertSessionHasErrors('subject');

    expect(OutboundMessage::where('student_id', $student->id)->count())->toBe(0);
});
