<?php

use App\Models\OutboundMessage;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

/*
 * THE SCREEN THAT ANSWERS "WAS THIS PARENT TOLD?"
 *
 * Two things are load-bearing here. First, a skipped notice has to be visible — a missing
 * guardian number looks exactly like success from every other screen in the app, which is
 * how a school ends up believing a parent was contacted. Second, the guardian's phone
 * number and the message body must NOT reach the browser: this is the highest-PII surface
 * in the product and staff need the status, not a child's absence notice.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-08-10 11:00:00');
    Queue::fake();
});

afterEach(fn () => Carbon::setTestNow());

function messageRow(array $overrides = []): OutboundMessage
{
    $student = Student::factory()->create([
        'status' => 'active',
        'guardianNumber' => '0612345678',
    ]);

    return OutboundMessage::create($overrides + [
        'school_id' => $student->schoolId,
        'student_id' => $student->id,
        'idempotency_key' => 'page:'.uniqid('', true),
        'type' => OutboundMessage::TYPE_ABSENCE,
        'channel' => OutboundMessage::CHANNEL_WHATSAPP,
        'recipient' => '212612345678',
        'message' => 'Notice complète avec le nom de l\'enfant',
        'status' => OutboundMessage::STATUS_SENT,
        'sent_at' => now(),
    ]);
}

it('lists today\'s notifications for an admin', function () {
    messageRow();

    test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Menu/NotificationsPage')
            ->has('messages.data', 1)
            ->where('counts.sent', 1)
        );
});

it('never sends the guardian number or the message body to the browser', function () {
    messageRow();

    test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $row = $page->toArray()['props']['messages']['data'][0];

            expect($row)->not->toHaveKey('recipient')
                ->and($row)->not->toHaveKey('message')
                ->and($row)->toHaveKey('status');
        })
        // Belt and braces: the number must not appear ANYWHERE in the rendered payload,
        // including somewhere I did not think to look.
        ->assertDontSee('212612345678');
});

it('shows a skipped notice with a readable reason', function () {
    messageRow([
        'status' => OutboundMessage::STATUS_SKIPPED,
        'skip_reason' => OutboundMessage::SKIP_NO_NUMBER,
        'recipient' => null,
        'message' => null,
        'sent_at' => null,
    ]);

    test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('notifications.index', ['status' => 'skipped']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('messages.data', 1)
            ->where('messages.data.0.reason', 'Aucun numéro de tuteur enregistré')
            ->where('counts.skipped', 1)
        );
});

it('filters by status', function () {
    messageRow();
    messageRow(['status' => OutboundMessage::STATUS_FAILED, 'sent_at' => null, 'failed_at' => now()]);

    test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('notifications.index', ['status' => 'failed']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('messages.data', 1)
            ->where('messages.data.0.status', 'failed')
            // Counts are for the whole day, not the filtered slice, so the tabs do not
            // change meaning depending on which tab is open.
            ->where('counts.sent', 1)
            ->where('counts.failed', 1)
        );
});

it('does not show yesterday under today', function () {
    // created_at is not fillable, so it has to be forced after the insert — passing it to
    // create() silently does nothing and the row lands under today.
    $old = messageRow(['sent_at' => now()->subDay()]);
    $old->forceFill(['created_at' => now()->subDay()])->save();

    test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('messages.data', 0));
});

it('is closed to teachers', function () {
    test()->actingAs(User::factory()->create(['role' => 'teacher']))
        ->get(route('notifications.index'))
        ->assertForbidden();
});

it('is closed to guests', function () {
    test()->get(route('notifications.index'))->assertRedirect(route('login'));
});

it('re-queues a failed notice', function () {
    $message = messageRow([
        'status' => OutboundMessage::STATUS_FAILED,
        'sent_at' => null,
        'failed_at' => now(),
        'last_error' => 'boom',
    ]);

    test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->post(route('notifications.retry', $message->id))
        ->assertRedirect();

    $message->refresh();

    expect($message->status)->toBe(OutboundMessage::STATUS_PENDING)
        ->and($message->last_error)->toBeNull()
        // The same row, not a new one: one logical notice stays one row for its whole
        // life, which is what the idempotency key exists to guarantee.
        ->and(OutboundMessage::count())->toBe(1);
});

it('refuses to re-send something already delivered', function () {
    $message = messageRow();

    test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->post(route('notifications.retry', $message->id))
        ->assertRedirect();

    expect($message->fresh()->status)->toBe(OutboundMessage::STATUS_SENT);
});

it('does not let a teacher re-send anything', function () {
    $message = messageRow(['status' => OutboundMessage::STATUS_FAILED, 'sent_at' => null]);

    test()->actingAs(User::factory()->create(['role' => 'teacher']))
        ->post(route('notifications.retry', $message->id))
        ->assertForbidden();

    expect($message->fresh()->status)->toBe(OutboundMessage::STATUS_FAILED);
});

it('refuses to re-drive a message that is still queued', function () {
    /*
     * Rejecting only SENT let a PENDING row be re-driven — and a pending row already has
     * a job waiting for it. A second job for the same id would pass the still-pending
     * guard at the top of the job, because neither had changed the status yet, and the
     * parent received the notice twice.
     */
    $message = messageRow([
        'status' => OutboundMessage::STATUS_PENDING,
        'sent_at' => null,
        'scheduled_at' => now(),
    ]);

    Queue::assertNothingPushed();

    test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->post(route('notifications.retry', $message->id))
        ->assertRedirect();

    Queue::assertNothingPushed();
    expect($message->fresh()->status)->toBe(OutboundMessage::STATUS_PENDING);
});

it('does not offer a retry button on a queued or delivered row', function () {
    messageRow(['status' => OutboundMessage::STATUS_PENDING, 'sent_at' => null]);

    test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('notifications.index', ['status' => 'pending']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('messages.data.0.canRetry', false));
});

it('does not message the guardian of a student who has left', function () {
    /*
     * OutboundMessage::student() is withTrashed() so the row outlives the student — it is
     * the record of what their guardian was told. Without it the retry path died with a
     * TypeError instead of explaining itself.
     *
     * Making the student reachable then exposed the real question: an archived student
     * must not generate NEW notices. Re-driving one now records why instead of messaging
     * the guardian of a child who no longer attends.
     */
    $message = messageRow(['status' => OutboundMessage::STATUS_FAILED, 'sent_at' => null]);
    $message->student->delete();

    $admin = User::factory()->create(['role' => 'admin']);

    test()->actingAs($admin)->get(route('notifications.index'))->assertOk();
    test()->actingAs($admin)
        ->post(route('notifications.retry', $message->id))
        ->assertRedirect();

    Queue::assertNothingPushed();

    $message->refresh();
    expect($message->status)->toBe(OutboundMessage::STATUS_SKIPPED)
        ->and($message->skip_reason)->toBe(OutboundMessage::SKIP_STUDENT_ARCHIVED);
});

it('is closed to assistants now that it carries the QR code', function () {
    // Tighter than the notify button assistants may press: the QR on this screen is a
    // CREDENTIAL — whoever scans it links their own device to the school's WhatsApp
    // account and can read every conversation on it.
    test()->actingAs(User::factory()->create(['role' => 'assistant']))
        ->get(route('notifications.index'))
        ->assertForbidden();
});

it('reports the gateway state and the queue depth', function () {
    config()->set('whatsapp.driver', 'log');

    test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('gateway.state', 'disabled')
            ->where('gateway.driver', 'log')
            ->has('queue.waiting')
            ->has('queue.failedJobs')
        );
});

it('says the gateway is unreachable rather than breaking the page', function () {
    // The screen that exists to tell you the gateway is down must not itself go down when
    // the gateway is down.
    config()->set('whatsapp.driver', 'evolution');
    config()->set('whatsapp.evolution.api_key', 'k');
    Illuminate\Support\Facades\Http::fake(
        fn () => throw new Illuminate\Http\Client\ConnectionException('refused')
    );

    test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('gateway.state', 'unreachable')
            ->where('gateway.qr', null)
        );
});

it('never puts the gateway api key in the page props', function () {
    config()->set('whatsapp.driver', 'evolution');
    config()->set('whatsapp.evolution.api_key', 'super-secret-key');
    Illuminate\Support\Facades\Http::fake(['*' => Illuminate\Support\Facades\Http::response(['state' => 'open', 'qr' => null], 200)]);

    test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('notifications.index'))
        ->assertOk()
        ->assertDontSee('super-secret-key');
});
