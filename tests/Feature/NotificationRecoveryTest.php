<?php

use App\Jobs\SendOutboundMessage;
use App\Models\Attendance;
use App\Models\Classes;
use App\Models\OutboundMessage;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\OutboundMessageService;
use App\Support\WhatsAppGateway;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * THE SCENARIOS THAT ACTUALLY HAPPEN
 *
 * Not "what if the gateway returns a 418" — the ordinary Tuesday failures:
 *
 *   a student misses Maths and French on the same day
 *   two teachers save their registers at the same moment
 *   somebody unlinks the phone, or the gateway is down from Friday to Tuesday
 *   a guardian's number has been dead for a year
 *   a job dies mid-flight and its row sits pending forever
 *
 * Each of these ends with a parent messaged twice, or not messaged at all. Both are worse
 * than an exception, because neither leaves a trace anybody looks at.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-08-10 11:00:00');
    Cache::flush();
    config()->set('whatsapp.driver', 'log');   // 'log' means the gateway gate is n/a
    config()->set('whatsapp.max_age_days', 5);
    config()->set('whatsapp.max_number_failures', 4);
});

afterEach(fn () => Carbon::setTestNow());

function recStudent(array $overrides = []): Student
{
    return Student::factory()->create($overrides + [
        'status' => 'active',
        'guardianNumber' => '0612345678',
    ]);
}

function absenceIn(Student $student, string $subject, ?Teacher $teacher = null): Attendance
{
    return Attendance::create([
        'student_id' => $student->id,
        'classId' => Classes::factory()->create()->id,
        'date' => '2026-08-10',
        'status' => 'absent',
        'subject' => $subject,
        'teacher_id' => ($teacher ?? Teacher::factory()->create())->id,
        'recorded_by' => User::factory()->create(['role' => 'admin'])->id,
    ]);
}

function svc(): OutboundMessageService
{
    return app(OutboundMessageService::class);
}

/**
 * Point the gateway gate at a fake that reports the given state.
 *
 * A CLOSURE, and read through a live variable, because Http::fake() MERGES stubs rather
 * than replacing them: calling it a second time leaves the first pattern registered and
 * first-match-wins, so a test that reconnects the gateway silently keeps answering
 * "logged_out". Reading $GLOBALS at request time means the latest call always wins.
 *
 * @param  int  $sendStatus  What the send endpoint returns — 201 accepted, 400 rejected.
 */
function gatewayReports(string $state, int $sendStatus = 201): void
{
    config()->set('whatsapp.driver', 'evolution');
    config()->set('whatsapp.evolution.api_key', 'k');
    config()->set('whatsapp.evolution.base_url', 'http://gw.test');
    WhatsAppGateway::forget();

    $GLOBALS['gw_state'] = $state;
    $GLOBALS['gw_send'] = $sendStatus;

    Http::fake(fn ($request) => str_contains($request->url(), '/health')
        ? Http::response(['state' => $GLOBALS['gw_state']], 200)
        : Http::response(['key' => ['id' => 'X']], $GLOBALS['gw_send']));
}

// ------------------------------------------------ one message per absence, on purpose --

it('sends two messages when a student misses two different lessons', function () {
    // Maths at 09:00 and French at 14:00 are two absences and the guardian is told about
    // both. An earlier version collapsed them into one; that was wrong.
    Queue::fake();
    $student = recStudent();

    $maths = svc()->createForAbsence(absenceIn($student, 'Maths'));
    $french = svc()->createForAbsence(absenceIn($student, 'Français'));

    expect($maths->status)->toBe(OutboundMessage::STATUS_PENDING)
        ->and($french->status)->toBe(OutboundMessage::STATUS_PENDING)
        ->and($maths->id)->not->toBe($french->id);

    Queue::assertPushed(SendOutboundMessage::class, 2);
});

it('still refuses to send the SAME absence twice', function () {
    // Re-saving one register to fix a typo must not re-message anyone.
    Queue::fake();
    $attendance = absenceIn(recStudent(), 'Maths');

    expect(svc()->createForAbsence($attendance))->not->toBeNull()
        ->and(svc()->createForAbsence($attendance))->toBeNull()
        ->and(OutboundMessage::count())->toBe(1);
});

// ----------------------------------------------------------------------- the train ----

it('queues both teachers\' registers into one line and sends them one at a time', function () {
    /*
     * The train. Teacher A saves a register with three absentees; teacher B saves theirs
     * while A's messages are still going out. Every message joins the same queue, in
     * order, and the pacer lets exactly one through at a time — B's list is appended, not
     * dropped and not sent in parallel.
     */
    Queue::fake();
    $teacherA = Teacher::factory()->create();
    $teacherB = Teacher::factory()->create();

    foreach (range(1, 3) as $i) {
        svc()->createForAbsence(absenceIn(recStudent(), 'Maths', $teacherA));
    }

    // B saves while A's are still queued.
    foreach (range(1, 2) as $i) {
        svc()->createForAbsence(absenceIn(recStudent(), 'Physique', $teacherB));
    }

    expect(OutboundMessage::where('status', OutboundMessage::STATUS_PENDING)->count())->toBe(5);
    Queue::assertPushed(SendOutboundMessage::class, 5);
});

it('lets only one message through per pacing slot', function () {
    // The "one at a time" half of the train: the second job in the same instant does not
    // send, it goes back in the line.
    gatewayReports('open');
    config()->set('whatsapp.min_seconds_between', 8);
    config()->set('whatsapp.jitter_seconds', 0);

    $first = svc()->createForAbsence(absenceIn(recStudent(), 'Maths'));
    $second = svc()->createForAbsence(absenceIn(recStudent(), 'Maths'));

    (new SendOutboundMessage($first->id))->handle();

    $job = (new SendOutboundMessage($second->id))->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased();
    expect($first->fresh()->status)->toBe(OutboundMessage::STATUS_SENT)
        ->and($second->fresh()->status)->toBe(OutboundMessage::STATUS_PENDING);
});

// -------------------------------------------------------- the phone gets unlinked -----

it('holds messages instead of failing them when WhatsApp is disconnected', function () {
    /*
     * Somebody taps "Log out" in Linked Devices. Every send would fail with an ordinary
     * error, burn its retry budget against a gateway that cannot accept anything, and end
     * up permanently failed — an entire evening of absences lost because of a tap.
     */
    gatewayReports('logged_out');
    $message = svc()->createForAbsence(absenceIn(recStudent(), 'Maths'));

    (new SendOutboundMessage($message->id))->handle();

    $message->refresh();
    expect($message->status)->toBe(OutboundMessage::STATUS_HELD)
        ->and($message->hold_reason)->toBe('gateway_disconnected')
        ->and($message->held_since)->not->toBeNull()
        // The whole point: nothing was spent.
        ->and($message->attempts)->toBe(0)
        ->and($message->reason())->toBe('En attente : WhatsApp est déconnecté');
});

it('holds when the gateway process is not answering at all', function () {
    config()->set('whatsapp.driver', 'evolution');
    config()->set('whatsapp.evolution.api_key', 'k');
    WhatsAppGateway::forget();
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('refused'));

    $message = svc()->createForAbsence(absenceIn(recStudent(), 'Maths'));
    (new SendOutboundMessage($message->id))->handle();

    expect($message->fresh()->status)->toBe(OutboundMessage::STATUS_HELD)
        ->and($message->fresh()->hold_reason)->toBe('gateway_unreachable');
});

it('sends the held backlog once the phone is linked again', function () {
    gatewayReports('logged_out');
    $message = svc()->createForAbsence(absenceIn(recStudent(), 'Maths'));
    (new SendOutboundMessage($message->id))->handle();
    expect($message->fresh()->status)->toBe(OutboundMessage::STATUS_HELD);

    // Somebody scans the QR.
    gatewayReports('open');

    test()->artisan('notifications:retry-failed')->assertSuccessful();

    // The queue runs synchronously under test, so a released message goes all the way —
    // which is the stronger assertion: released AND delivered, not merely re-queued.
    expect($message->fresh()->status)->toBe(OutboundMessage::STATUS_SENT)
        ->and($message->fresh()->hold_reason)->toBeNull();
});

it('leaves held messages held while the gateway is still down', function () {
    // Releasing into a dead gateway would re-hold every row and churn the queue for
    // nothing.
    gatewayReports('logged_out');
    $message = svc()->createForAbsence(absenceIn(recStudent(), 'Maths'));
    (new SendOutboundMessage($message->id))->handle();

    test()->artisan('notifications:retry-failed')->assertSuccessful();

    expect($message->fresh()->status)->toBe(OutboundMessage::STATUS_HELD);
});

// ------------------------------------------- an outage that lasts longer than a day ---

it('survives a four-day outage without losing anything inside the window', function () {
    /*
     * THE case a two-day retry window silently loses. The gateway goes down on Friday and
     * nobody notices until Tuesday. A window measured in days would discard Friday's and
     * Saturday's absences — the exact outage this system exists to survive. Held rows are
     * released regardless of how long the outage lasted; only AGE retires them.
     */
    gatewayReports('logged_out');
    $message = svc()->createForAbsence(absenceIn(recStudent(), 'Maths'));
    (new SendOutboundMessage($message->id))->handle();

    Carbon::setTestNow('2026-08-14 09:00:00');   // four days later
    gatewayReports('open');

    test()->artisan('notifications:retry-failed')->assertSuccessful();

    // Four days of outage, and Friday's absence still reaches the parent on Tuesday.
    expect($message->fresh()->status)->toBe(OutboundMessage::STATUS_SENT);
});

it('abandons a notice that has gone stale rather than surprising a parent', function () {
    // Past max_age_days, telling a parent about it stops being useful. Recorded as
    // expired, not deleted — the absence still happened.
    gatewayReports('logged_out');
    $message = svc()->createForAbsence(absenceIn(recStudent(), 'Maths'));
    (new SendOutboundMessage($message->id))->handle();

    Carbon::setTestNow('2026-08-20 09:00:00');   // ten days later
    gatewayReports('open');

    test()->artisan('notifications:retry-failed')->assertSuccessful();

    $message->refresh();
    expect($message->status)->toBe(OutboundMessage::STATUS_EXPIRED)
        ->and($message->reason())->toBe('Trop ancien pour être envoyé');
});

// ------------------------------------------------------------- a number that is dead --

it('stops chasing a guardian number that keeps failing', function () {
    /*
     * Every attempt against a number that will never work costs a pacing slot a working
     * number could have used. After a few consecutive failures the student goes on a list
     * for somebody to fix instead of being retried into the void every day.
     */
    $student = recStudent(['guardianNumberFailures' => 4]);
    $message = svc()->createForAbsence(absenceIn($student, 'Maths'));

    gatewayReports('open');
    (new SendOutboundMessage($message->id))->handle();

    $message->refresh();
    expect($message->status)->toBe(OutboundMessage::STATUS_SKIPPED)
        ->and($message->skip_reason)->toBe(OutboundMessage::SKIP_UNREACHABLE_NUMBER)
        ->and($message->reason())->toBe('Numéro injoignable après plusieurs essais — à corriger');

    Http::assertNothingSent();
});

it('counts a rejected recipient against the number', function () {
    gatewayReports('open', sendStatus: 400);   // 400 = recipient rejected, permanent

    $student = recStudent();
    $message = svc()->createForAbsence(absenceIn($student, 'Maths'));

    (new SendOutboundMessage($message->id))->withFakeQueueInteractions()->handle();

    expect($student->fresh()->guardianNumberFailures)->toBe(1);
});

it('forgives the number as soon as one message gets through', function () {
    // A guardian who was unreachable last week and answers today must not stay on the list.
    gatewayReports('open');
    $student = recStudent(['guardianNumberFailures' => 2]);
    $message = svc()->createForAbsence(absenceIn($student, 'Maths'));

    (new SendOutboundMessage($message->id))->handle();

    expect($message->fresh()->status)->toBe(OutboundMessage::STATUS_SENT)
        ->and($student->fresh()->guardianNumberFailures)->toBe(0);
});

it('forgives the number when somebody corrects it', function () {
    // Without this reset, fixing the typo would not be enough — the student would stay on
    // the list and their guardian would never be messaged again. A worse bug than the one
    // the counter solves.
    $student = recStudent(['guardianNumberFailures' => 4]);

    $student->update(['guardianNumber' => '0699887766']);

    expect($student->fresh()->guardianNumberFailures)->toBe(0);
});

it('does not reset the counter on an unrelated edit', function () {
    $student = recStudent(['guardianNumberFailures' => 3]);

    $student->update(['firstName' => 'Nouveau']);

    expect($student->fresh()->guardianNumberFailures)->toBe(3);
});

// ------------------------------------------------------------------ dead jobs ---------

it('rescues a message stranded in pending by a dead worker', function () {
    // The container restarted between reserving a slot and recording the outcome. The row
    // sat pending forever and nothing ever looked at it again.
    Queue::fake();
    $message = svc()->createForAbsence(absenceIn(recStudent(), 'Maths'));
    $message->forceFill(['scheduled_at' => now()->subHours(5)])->save();

    test()->artisan('notifications:retry-failed')->assertSuccessful();

    expect($message->fresh()->status)->toBe(OutboundMessage::STATUS_PENDING);
    Queue::assertPushed(SendOutboundMessage::class, 2);   // the original, then the rescue
});

it('does not disturb a message that is simply waiting for the morning', function () {
    // Created at 22:15, scheduled for 09:00 — pending on purpose, not stranded.
    Queue::fake();
    Carbon::setTestNow('2026-08-10 22:15:00');
    $message = svc()->createForAbsence(absenceIn(recStudent(), 'Maths'));

    Carbon::setTestNow('2026-08-10 23:30:00');
    test()->artisan('notifications:retry-failed')->assertSuccessful();

    $message->refresh();
    expect($message->status)->toBe(OutboundMessage::STATUS_PENDING)
        ->and($message->failed_at)->toBeNull();
});

it('retries a recent real failure', function () {
    Queue::fake();
    $message = svc()->createForAbsence(absenceIn(recStudent(), 'Maths'));
    $message->update([
        'status' => OutboundMessage::STATUS_FAILED,
        'failed_at' => now()->subHours(2),
        'last_error' => 'passerelle injoignable',
    ]);

    test()->artisan('notifications:retry-failed')->assertSuccessful();

    expect($message->fresh()->status)->toBe(OutboundMessage::STATUS_PENDING)
        ->and($message->fresh()->last_error)->toBeNull();
});

it('does not re-drive an old failure forever', function () {
    // A failure is evidence — usually a bad number. Re-driving week-old evidence just
    // burns slots that working numbers need.
    Queue::fake();
    $message = svc()->createForAbsence(absenceIn(recStudent(), 'Maths'));
    $message->update(['status' => OutboundMessage::STATUS_FAILED, 'failed_at' => now()]);
    $message->forceFill(['created_at' => now()->subDays(3)])->save();

    test()->artisan('notifications:retry-failed --failed-days=2')->assertSuccessful();

    expect($message->fresh()->status)->toBe(OutboundMessage::STATUS_FAILED);
});

it('never re-queues something already delivered', function () {
    Queue::fake();
    $message = svc()->createForAbsence(absenceIn(recStudent(), 'Maths'));
    $message->update(['status' => OutboundMessage::STATUS_SENT, 'sent_at' => now()]);

    test()->artisan('notifications:retry-failed')->assertSuccessful();

    expect($message->fresh()->status)->toBe(OutboundMessage::STATUS_SENT);
    Queue::assertPushed(SendOutboundMessage::class, 1);   // only the original
});

it('changes nothing on a dry run', function () {
    Queue::fake();
    $message = svc()->createForAbsence(absenceIn(recStudent(), 'Maths'));
    $message->update(['status' => OutboundMessage::STATUS_FAILED, 'failed_at' => now()]);

    test()->artisan('notifications:retry-failed --dry-run')->assertSuccessful();

    expect($message->fresh()->status)->toBe(OutboundMessage::STATUS_FAILED);
});

it('is registered on the schedule', function () {
    // The command existing is worth nothing if nothing runs it.
    //
    // The Artisan::call is REQUIRED: withSchedule() registers on Artisan::starting(), so
    // resolving Schedule straight from the container returns an EMPTY event list and every
    // assertion below passes vacuously. ScheduledAlarmTest documents the same trap.
    Illuminate\Support\Facades\Artisan::call('schedule:list');

    $commands = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->map(fn ($e) => $e->command ?? '')
        ->implode(' ');

    expect($commands)->toContain('notifications:retry-failed')
        ->and($commands)->toContain('whatsapp:audit-numbers');
});

/*
 * THE HALF HOUR OF LOOKING BROKEN
 *
 * A held message is released only by this sweep. At a half-hourly cadence somebody could
 * reconnect the school phone, watch the screen say "Connectée", and still be staring at
 * "En pause" twenty-nine minutes later — with no way to tell a slow system from a broken
 * one. Reported as "the message is not being sent automatically", which is exactly what it
 * looks like.
 *
 * Two things have to hold for the fix to be safe: it must release promptly once the
 * gateway is usable, and a run with nothing to do must be cheap enough to repeat every
 * minute forever.
 */
it('releases a held message as soon as the gateway is usable', function () {
    config()->set('whatsapp.driver', 'evolution');
    config()->set('whatsapp.evolution.api_key', 'k');
    Cache::flush();
    Http::fake(['*' => Http::response(['state' => 'open'], 200)]);

    $student = recStudent();
    $message = OutboundMessage::create([
        'school_id' => $student->schoolId,
        'student_id' => $student->id,
        'idempotency_key' => 'recovery:reconnect:'.uniqid('', true),
        'type' => OutboundMessage::TYPE_ABSENCE,
        'channel' => OutboundMessage::CHANNEL_WHATSAPP,
        'recipient' => '212612345678',
        'message' => 'x',
        'status' => OutboundMessage::STATUS_HELD,
        'hold_reason' => 'gateway_disconnected',
        'held_since' => now()->subMinutes(3),
    ]);

    Queue::fake();

    Illuminate\Support\Facades\Artisan::call('notifications:retry-failed');

    expect($message->fresh()->status)->toBe(OutboundMessage::STATUS_PENDING)
        ->and($message->fresh()->hold_reason)->toBeNull();

    Queue::assertPushed(SendOutboundMessage::class);
});

it('costs nothing when there is nothing to recover', function () {
    config()->set('whatsapp.driver', 'evolution');
    config()->set('whatsapp.evolution.api_key', 'k');
    Cache::flush();

    // A sent row is not recoverable, so this is the ordinary quiet minute.
    $student = recStudent();
    OutboundMessage::create([
        'school_id' => $student->schoolId,
        'student_id' => $student->id,
        'idempotency_key' => 'recovery:quiet:'.uniqid('', true),
        'type' => OutboundMessage::TYPE_ABSENCE,
        'channel' => OutboundMessage::CHANNEL_WHATSAPP,
        'recipient' => '212612345678',
        'message' => 'x',
        'status' => OutboundMessage::STATUS_SENT,
        'sent_at' => now(),
    ]);

    Http::fake(fn () => throw new RuntimeException('the gateway must not be probed on a quiet run'));

    expect(Illuminate\Support\Facades\Artisan::call('notifications:retry-failed'))->toBe(0);
    expect(Illuminate\Support\Facades\Artisan::output())->toBe('');
});
