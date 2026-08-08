<?php

use App\Jobs\SendOutboundMessage;
use App\Models\OutboundMessage;
use App\Support\WhatsApp;
use App\Support\WhatsAppPacer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/*
 * THE GATEWAY CALL AND THE PACING
 *
 * Two things, and the second matters more. The gateway call has to report what actually
 * happened — including whether a failure is worth retrying. The pacing has to hold,
 * because a self-hosted gateway drives a real WhatsApp account with nothing in front of
 * it: if this is wrong the consequence is not a red test, it is the school's number being
 * banned, which no redeploy fixes.
 *
 * The message-level behaviour (dedup, snapshots, skipped rows) is in
 * OutboundMessageTest.
 */

beforeEach(function () {
    Cache::flush();
    WhatsAppPacer::reset();

    config()->set('whatsapp.driver', 'evolution');
    config()->set('whatsapp.evolution.base_url', 'http://127.0.0.1:8080');
    config()->set('whatsapp.evolution.api_key', 'test-key');
    config()->set('whatsapp.evolution.instance', 'tikoschool');
    config()->set('whatsapp.country_code', '212');
    config()->set('whatsapp.min_seconds_between', 8);
    config()->set('whatsapp.jitter_seconds', 0);   // deterministic in tests
    config()->set('whatsapp.daily_cap', 250);

    /*
     * Registered FIRST, and it matters.
     *
     * SendOutboundMessage asks the gateway whether it is linked before spending anything,
     * so every job test makes a /health call as well as a send. Http::fake() MERGES stubs
     * and the first registered pattern wins, so putting this here means each test's own
     * `'*' => ...` stub still governs the send while health always answers "connected".
     */
    Http::fake(['*/health' => Http::response(['state' => 'open'], 200)]);
});

/** Assert on the SEND, ignoring the health check that now precedes it. */
function assertNoSendAttempt(): void
{
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'sendText'));
}

/** A pending row ready for the job to pick up. */
function pendingMessage(array $overrides = []): OutboundMessage
{
    $student = App\Models\Student::factory()->create([
        'status' => 'active',
        'guardianNumber' => '0612345678',
    ]);

    return OutboundMessage::create($overrides + [
        'school_id' => $student->schoolId,
        'student_id' => $student->id,
        'idempotency_key' => 'test:'.uniqid('', true),
        'type' => OutboundMessage::TYPE_ABSENCE,
        'channel' => OutboundMessage::CHANNEL_WHATSAPP,
        'recipient' => '212612345678',
        'message' => 'Bonjour',
        'status' => OutboundMessage::STATUS_PENDING,
    ]);
}

// ---------------------------------------------------------------- the gateway call ----

it('posts to the Evolution endpoint with the key and the normalised number', function () {
    Http::fake(['*' => Http::response(['key' => ['id' => 'MSG123']], 201)]);

    $result = WhatsApp::send('0612345678', 'Bonjour');

    expect($result->success)->toBeTrue()
        ->and($result->messageId)->toBe('MSG123');

    Http::assertSent(function ($request) {
        return $request->url() === 'http://127.0.0.1:8080/message/sendText/tikoschool'
            && $request->method() === 'POST'
            && $request->header('apikey')[0] === 'test-key'
            && $request['number'] === '212612345678'
            && $request['text'] === 'Bonjour';
    });
});

it('reports a gateway rejection as a failure instead of claiming success', function () {
    // A notification system that says "sent" when nothing was sent is worse than one that
    // errors: the school believes the parent was told.
    Http::fake(['*' => Http::response(['message' => 'instance not connected'], 500)]);

    expect(WhatsApp::send('0612345678', 'Bonjour')->success)->toBeFalse();
});

it('classifies failures so a hopeless one is not retried for hours', function (int $status, bool $permanent) {
    Http::fake(['*' => Http::response(['message' => 'nope'], $status)]);

    expect(WhatsApp::send('0612345678', 'Bonjour')->permanent)->toBe($permanent);
})->with([
    'bad request / rejected recipient' => [400, true],
    'bad api key' => [401, true],
    'forbidden' => [403, true],
    'wrong instance' => [404, true],
    'unprocessable' => [422, true],
    'gateway error' => [500, false],
    'bad gateway' => [502, false],
    'unavailable' => [503, false],
]);

it('treats an unreachable gateway as worth retrying', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));

    $result = WhatsApp::send('0612345678', 'Bonjour');

    expect($result->success)->toBeFalse()
        ->and($result->permanent)->toBeFalse();
});

it('treats an unusable number as permanent and never calls the gateway', function () {
    Http::fake();

    $result = WhatsApp::send('abc', 'Bonjour');

    expect($result->success)->toBeFalse()
        ->and($result->permanent)->toBeTrue();
    Http::assertNothingSent();
});

it('refuses to send when the API key is missing', function () {
    config()->set('whatsapp.evolution.api_key', null);
    Http::fake();

    // Permanent: retrying a misconfiguration for hours buries the line an operator needs.
    expect(WhatsApp::send('0612345678', 'Bonjour')->permanent)->toBeTrue();
    Http::assertNothingSent();
});

it('never reaches the network on the log driver', function () {
    config()->set('whatsapp.driver', 'log');
    Http::fake();

    expect(WhatsApp::send('0612345678', 'Bonjour')->success)->toBeTrue();
    Http::assertNothingSent();
});

// ------------------------------------------------------------ number normalisation ----

it('normalises every shape a guardian number is stored in', function (string $raw, ?string $expected) {
    // Guardian numbers in this database are 0612…, +212 612…, 212612… and a few with
    // dashes. An unnormalised number does not error — it delivers to nobody.
    expect(WhatsApp::normalise($raw))->toBe($expected);
})->with([
    ['0612345678', '212612345678'],
    ['+212612345678', '212612345678'],
    ['212612345678', '212612345678'],
    ['00212612345678', '212612345678'],
    ['+212 612-345-678', '212612345678'],
    ['612345678', '212612345678'],
    ['', null],
    ['abc', null],
    ['123', null],          // too short to be anyone
]);

// -------------------------------------------------------------------- the pacing ------

it('allows the very first message immediately', function () {
    expect(WhatsAppPacer::secondsUntilAllowed())->toBe(0);
});

it('makes the next message wait the configured gap', function () {
    WhatsAppPacer::reserveSlot();

    expect(WhatsAppPacer::secondsUntilAllowed())->toBe(8);
});

it('re-queues rather than sleeping while holding the lock', function () {
    // The old job slept inside the lock. A wait longer than the lock TTL let a second
    // worker through and both sent at the same instant — the exact burst the throttle
    // exists to prevent.
    Http::fake(['*' => Http::response([], 201)]);
    WhatsAppPacer::reserveSlot();

    $message = pendingMessage();
    $job = (new SendOutboundMessage($message->id))->withFakeQueueInteractions();

    $job->handle();

    // Not an exact delay: the pacer measures with time(), which Carbon's test clock does
    // not freeze, so a second spent on the health call makes the remaining wait 7 rather
    // than 8. What matters is that it went back in the line instead of sending.
    $job->assertReleased();
    expect(WhatsAppPacer::secondsUntilAllowed())->toBeGreaterThan(0);
    assertNoSendAttempt();
    expect($message->fresh()->status)->toBe(OutboundMessage::STATUS_PENDING);
});

it('sends, records the outcome on the row, and moves the clock', function () {
    Http::fake(['*' => Http::response(['key' => ['id' => 'ABC']], 201)]);

    $message = pendingMessage();
    (new SendOutboundMessage($message->id))->handle();

    $message->refresh();

    expect($message->status)->toBe(OutboundMessage::STATUS_SENT)
        ->and($message->provider_message_id)->toBe('ABC')
        ->and($message->sent_at)->not->toBeNull()
        ->and($message->attempts)->toBe(1)
        ->and(WhatsAppPacer::secondsUntilAllowed())->toBe(8);
});

it('gives up immediately on a permanent failure', function () {
    // Retrying a rejected recipient for six hours is both pointless and a way to get the
    // account flagged.
    Http::fake(['*' => Http::response(['message' => 'invalid number'], 400)]);

    $message = pendingMessage();
    $job = (new SendOutboundMessage($message->id))->withFakeQueueInteractions();

    $job->handle();
    $job->assertFailed();

    $message->refresh();
    expect($message->status)->toBe(OutboundMessage::STATUS_FAILED)
        ->and($message->last_error)->toContain('400');
});

it('throws on a transient failure so the backoff applies', function () {
    Http::fake(['*' => Http::response([], 503)]);

    $message = pendingMessage();

    expect(fn () => (new SendOutboundMessage($message->id))->handle())
        ->toThrow(RuntimeException::class);

    // Still pending: a gateway restart must not burn the message.
    expect($message->fresh()->status)->toBe(OutboundMessage::STATUS_PENDING);
});

it('does nothing when the row was already sent', function () {
    // Makes the job safe to run twice, which is what allows at-least-once delivery from
    // the queue without at-least-twice delivery to the parent.
    Http::fake(['*' => Http::response([], 201)]);

    $message = pendingMessage(['status' => OutboundMessage::STATUS_SENT, 'sent_at' => now()]);
    (new SendOutboundMessage($message->id))->handle();

    assertNoSendAttempt();
});

it('does not blow up when the row was deleted', function () {
    Http::fake();

    $message = pendingMessage();
    $id = $message->id;
    $message->delete();

    (new SendOutboundMessage($id))->handle();

    assertNoSendAttempt();
});

it('does not hold the pacing lock across the network call', function () {
    /*
     * The bug this catches, which the release-branch test above could not: the lock was
     * held all the way through $channel->send(). The gateway's HTTP timeout is 20s and
     * the lock TTL is 10s, so a merely SLOW gateway let the lock expire mid-send, a
     * second worker acquired it, saw the same "clear to go" state — because nothing had
     * been recorded yet — and sent at the same moment. The pacer producing the exact
     * burst it exists to prevent.
     *
     * Asserted from inside the fake gateway, at the only instant that matters.
     */
    $lockWasFree = null;
    $slotReserved = null;

    Http::fake(function () use (&$lockWasFree, &$slotReserved) {
        // Mid-send. Another worker must NOT be able to take the lock and go.
        $probe = WhatsAppPacer::lock();
        $lockWasFree = $probe->get();
        if ($lockWasFree) {
            $probe->release();
        }
        // …because the slot was already claimed before the call started.
        $slotReserved = WhatsAppPacer::secondsUntilAllowed() > 0;

        return Http::response(['key' => ['id' => 'X']], 201);
    });

    (new SendOutboundMessage(pendingMessage()->id))->handle();

    expect($lockWasFree)->toBeTrue()      // lock released before the send, as documented
        ->and($slotReserved)->toBeTrue(); // and the slot taken, so nobody else may send
});

it('consumes a pacing slot even when the send fails', function () {
    // Pacing governs how often the gateway is CONTACTED. A failure contacted it just as
    // much as a success did, so it must not free the slot for an immediate retry.
    Http::fake(['*' => Http::response([], 503)]);

    try {
        (new SendOutboundMessage(pendingMessage()->id))->handle();
    } catch (RuntimeException) {
        // expected — transient failures throw so the backoff applies
    }

    expect(WhatsAppPacer::secondsUntilAllowed())->toBe(8);
});

// ------------------------------------------------------------------ the daily cap -----

it('counts the cap from the table, not the cache', function () {
    // The whole point of the cap is that a bug cannot empty the roster into WhatsApp. A
    // counter that resets on cache:clear would not survive a deploy.
    config()->set('whatsapp.daily_cap', 2);

    pendingMessage(['status' => OutboundMessage::STATUS_SENT, 'sent_at' => now()]);
    pendingMessage(['status' => OutboundMessage::STATUS_SENT, 'sent_at' => now()]);

    Cache::flush();

    expect(WhatsAppPacer::sentToday())->toBe(2)
        ->and(WhatsAppPacer::dailyCapReached())->toBeTrue();
});

it('ignores yesterday when counting today', function () {
    pendingMessage(['status' => OutboundMessage::STATUS_SENT, 'sent_at' => now()->subDay()]);

    expect(WhatsAppPacer::sentToday())->toBe(0);
});

it('stops sending once the daily cap is reached', function () {
    config()->set('whatsapp.daily_cap', 1);
    Http::fake(['*' => Http::response([], 201)]);

    pendingMessage(['status' => OutboundMessage::STATUS_SENT, 'sent_at' => now()]);

    $message = pendingMessage();
    $job = (new SendOutboundMessage($message->id))->withFakeQueueInteractions();

    $job->handle();

    $job->assertFailed();
    assertNoSendAttempt();
    expect($message->fresh()->status)->toBe(OutboundMessage::STATUS_FAILED);
});

it('adds jitter so the send pattern is not perfectly periodic', function () {
    // A message every exactly N seconds is a machine signature; the regularity is the tell.
    config()->set('whatsapp.jitter_seconds', 5);

    $seen = collect(range(1, 60))->map(fn () => WhatsAppPacer::jitter())->unique();

    expect($seen->min())->toBeGreaterThanOrEqual(0)
        ->and($seen->max())->toBeLessThanOrEqual(5)
        ->and($seen->count())->toBeGreaterThan(1);
});
