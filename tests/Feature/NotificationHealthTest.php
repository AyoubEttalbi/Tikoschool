<?php

use App\Models\OutboundMessage;
use App\Models\Student;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
 * THE FAILURE THAT LOOKS LIKE SUCCESS
 *
 * Everything else in this system is loud. A bad number fails, a dead gateway holds, an old
 * notice expires — all of them land on the notifications screen with a reason attached.
 *
 * A queue with no worker is silent. Rows are created, marked pending, and never claimed.
 * `failed_jobs` stays empty because nothing failed. `attempts` stays 0 because nothing was
 * attempted. Every screen reports success and no parent is told. It shipped once already,
 * and the only reason anyone found it was a person asking why they got no message.
 *
 * These tests pin the one signal that separates "busy" from "dead".
 */

beforeEach(function () {
    Carbon::setTestNow('2026-08-10 11:00:00');
    Cache::flush();
    config()->set('whatsapp.driver', 'log');
    config()->set('whatsapp.daily_cap', 250);
    DB::table('jobs')->delete();
});

afterEach(fn () => Carbon::setTestNow());

/** A row in the `jobs` table, claimable `$minutesAgo` minutes ago and never picked up. */
function waitingJob(int $minutesAgo, string $queue = 'whatsapp', ?int $reservedAt = null): void
{
    DB::table('jobs')->insert([
        'queue' => $queue,
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SendOutboundMessage']),
        'attempts' => 0,
        'reserved_at' => $reservedAt,
        'available_at' => now()->subMinutes($minutesAgo)->timestamp,
        'created_at' => now()->subMinutes($minutesAgo)->timestamp,
    ]);
}

/** A notification row for a real student, so the school foreign key resolves. */
function healthMessage(string $key, array $attributes): OutboundMessage
{
    $student = Student::factory()->create();

    return OutboundMessage::create($attributes + [
        'student_id' => $student->id,
        'school_id' => $student->schoolId,
        'type' => 'absence',
        'channel' => 'whatsapp',
        'idempotency_key' => $key,
        'recipient' => '212612345678',
        'message' => 'x',
    ]);
}

it('passes when there is nothing waiting', function () {
    expect(Artisan::call('notifications:health'))->toBe(0);
});

it('fails when a claimable job has gone unclaimed', function () {
    waitingJob(minutesAgo: 45);

    expect(Artisan::call('notifications:health'))->toBe(1);
    expect(Artisan::output())->toContain('Nothing is consuming the `whatsapp` queue');
});

/*
 * The distinction the whole check rests on.
 *
 * A healthy backlog also has jobs waiting — two hundred of them, draining at one every
 * twelve seconds. It must not alarm. It does not, because the pacer RELEASES each job with
 * a fresh delay rather than leaving it sitting: under a working worker the oldest claimable
 * job is always seconds old, however long the queue is.
 */
it('stays quiet for a large but moving backlog', function () {
    for ($i = 0; $i < 200; $i++) {
        waitingJob(minutesAgo: 0);
    }

    expect(Artisan::call('notifications:health'))->toBe(0);
});

it('ignores jobs a worker is already holding', function () {
    waitingJob(minutesAgo: 45, reservedAt: now()->subMinutes(1)->timestamp);

    expect(Artisan::call('notifications:health'))->toBe(0);
});

it('ignores other queues', function () {
    waitingJob(minutesAgo: 45, queue: 'default');

    expect(Artisan::call('notifications:health'))->toBe(0);
});

it('fails when notices have been pending far past their due time', function () {
    healthMessage('health:stranded', [
        'status' => OutboundMessage::STATUS_PENDING,
        'scheduled_at' => now()->subHours(5),
    ]);

    expect(Artisan::call('notifications:health'))->toBe(1);
    expect(Artisan::output())->toContain('pending for over three hours');
});

it('fails when a held backlog has outlasted the outage window', function () {
    healthMessage('health:held', [
        'status' => OutboundMessage::STATUS_HELD,
        'hold_reason' => 'gateway_down',
        'held_since' => now()->subHours(9),
    ]);

    expect(Artisan::call('notifications:health'))->toBe(1);
    expect(Artisan::output())->toContain('held for');
});

it('tolerates a short outage without alarming', function () {
    healthMessage('health:held-brief', [
        'status' => OutboundMessage::STATUS_HELD,
        'hold_reason' => 'gateway_down',
        'held_since' => now()->subHour(),
    ]);

    expect(Artisan::call('notifications:health'))->toBe(0);
});

it('changes nothing it looks at', function () {
    waitingJob(minutesAgo: 45);

    $message = healthMessage('health:readonly', [
        'status' => OutboundMessage::STATUS_PENDING,
        'scheduled_at' => now()->subHours(5),
    ]);

    Artisan::call('notifications:health');

    expect($message->fresh()->status)->toBe(OutboundMessage::STATUS_PENDING)
        ->and($message->fresh()->attempts)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('is registered on the schedule', function () {
    // The Schedule is empty until a console command boots it, so a bare assertion here
    // would pass without ever loading bootstrap/app.php's withSchedule() closure.
    Artisan::call('schedule:list');

    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->map(fn ($e) => $e->command)
        ->filter();

    expect($events->contains(fn ($c) => str_contains($c, 'notifications:health')))->toBeTrue();
});
