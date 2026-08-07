<?php

use App\Models\Teacher;
use App\Models\TeacherWalletEntry;
use App\Services\TeacherWalletService;
use Illuminate\Support\Facades\Log;

/*
 * Audit §6.0 — the alarm that reached nobody.
 *
 * wallet:check reported ledger drift through $this->error() and a non-zero exit code.
 * Cron runs `schedule:run >> /dev/null 2>&1`, so BOTH were discarded; nothing hooked the
 * failure, and the command contained zero Log:: calls. A teacher's wallet could drift from
 * the ledger every night for a year and no human or system would ever be told.
 *
 * The whole of §6 was originally rated against this as a mitigating control. It wasn't one.
 */

test('wallet:check writes the drift to the log, not only to a discarded stdout', function () {
    $teacher = Teacher::factory()->create(['wallet' => 0]);

    (new TeacherWalletService)->credit(
        $teacher, 100.0, TeacherWalletEntry::REASON_ADJUSTMENT, null, null, null, 'opening'
    );

    // Move the cached column behind the ledger's back — exactly what the bypasses in §6.1
    // used to do. A query-builder update skips the model layer, so no ledger row is written.
    Teacher::whereKey($teacher->id)->update(['wallet' => 175.00]);

    Log::spy();

    $exitCode = $this->artisan('wallet:check')->run();

    expect($exitCode)->not->toBe(0, 'wallet:check must exit non-zero on drift.');

    // The log is the channel that actually leaves the process.
    Log::shouldHaveReceived('error')
        ->withArgs(fn ($message, $context = []) => str_contains($message, 'wallet:check')
            && ($context['drifted_teachers'] ?? 0) >= 1)
        ->atLeast()->once();
});

test('wallet:check stays quiet and exits zero when everything reconciles', function () {
    // The alarm must not cry wolf, or it gets ignored and we are back where we started.
    $teacher = Teacher::factory()->create(['wallet' => 0]);

    (new TeacherWalletService)->credit(
        $teacher, 50.0, TeacherWalletEntry::REASON_ADJUSTMENT, null, null, null, 'opening'
    );

    Log::spy();

    $exitCode = $this->artisan('wallet:check')->run();

    expect($exitCode)->toBe(0);

    Log::shouldNotHaveReceived('error');
});

/**
 * The schedule defined by bootstrap/app.php's withSchedule().
 *
 * withSchedule() registers its callback on Artisan::starting(), so the Schedule instance
 * is EMPTY until a console command has actually started. Resolving it straight out of the
 * container returns zero events and every assertion below passes vacuously — which is how
 * a test like this quietly stops testing anything.
 */
function bootedSchedule(): \Illuminate\Console\Scheduling\Schedule
{
    \Illuminate\Support\Facades\Artisan::call('schedule:list');

    return app(\Illuminate\Console\Scheduling\Schedule::class);
}

/** The three commands that move or audit real money. */
function moneyCommands(): array
{
    return ['wallet:check', 'payouts:audit', 'teachers:process-monthly-payments'];
}

test('every money-touching scheduled task carries a failure hook', function () {
    // The structural half of the fix: it is not enough for wallet:check to log. Any
    // scheduled command that moves or audits money must surface its own failure, because
    // cron will not.
    $schedule = bootedSchedule();
    $checked = 0;

    foreach ($schedule->events() as $event) {
        foreach (moneyCommands() as $command) {
            if (! str_contains($event->command ?? '', $command)) {
                continue;
            }

            $checked++;

            // ->onFailure() registers an after-callback guarded on the exit code.
            // Event::$afterCallbacks is protected with no accessor, so reflection is the
            // only way to observe it — and observing it is the whole point: this asserts
            // the hook EXISTS, not merely that the code calling ->onFailure() was written.
            $callbacks = (new ReflectionProperty(
                \Illuminate\Console\Scheduling\Event::class,
                'afterCallbacks'
            ))->getValue($event);

            expect(count($callbacks))->toBeGreaterThan(
                0,
                "Scheduled task '{$command}' has no failure hook — a failure would reach nobody."
            );
        }
    }

    // Guard against the whole test passing because the schedule was empty.
    expect($checked)->toBe(3, "Expected 3 money commands in the schedule, found {$checked}.");
});

test('the scheduler defines each money command exactly once', function () {
    // A stale app/Console/Kernel.php once defined a SECOND, conflicting schedule, and an
    // earlier edit in this same release accidentally registered
    // teachers:process-monthly-payments twice. A money command scheduled twice runs twice.
    $schedule = bootedSchedule();
    $counts = [];

    foreach ($schedule->events() as $event) {
        foreach (moneyCommands() as $command) {
            if (str_contains($event->command ?? '', $command)) {
                $counts[$command] = ($counts[$command] ?? 0) + 1;
            }
        }
    }

    foreach (moneyCommands() as $command) {
        expect($counts[$command] ?? 0)->toBe(
            1,
            "'{$command}' is scheduled ".($counts[$command] ?? 0).' times, expected exactly 1.'
        );
    }
});
