<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Trust only proxies on the loopback/private ranges (the nginx container on the
        // compose bridge network, or a local nginx in a native deploy).
        //
        // This was `at: '*'`, which trusted ANY client's X-Forwarded-For header. Combined
        // with nginx's `set_real_ip_from`, that made $request->ip() fully caller-controlled
        // — defeating rate limiting and poisoning every IP-based audit trail.
        //
        // If you put Cloudflare or another CDN in front, add its published ranges here.
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    ->withSchedule(function (\Illuminate\Console\Scheduling\Schedule $schedule) {
        // Carries a scheduled task's failure out of the process.
        //
        // Cron invokes `schedule:run >> /dev/null 2>&1`, so a command's exit code and
        // output are both thrown away. Without this hook a money-touching task could
        // fail every night and no human or system would ever be told. Logging always
        // works; the heartbeat ping is optional and only fires when configured, so this
        // needs no mail transport (MAIL_MAILER is still `log`).
        $reportScheduledFailure = function (string $command): void {
            \Illuminate\Support\Facades\Log::error("Scheduled task failed: {$command}", [
                'command' => $command,
                'hint' => 'See storage/logs/schedule.log for the command output.',
            ]);

            $heartbeat = config('monitoring.heartbeat_url');

            if (! $heartbeat) {
                return;
            }

            // Never let a monitoring outage break the scheduler itself.
            try {
                \Illuminate\Support\Facades\Http::timeout(5)
                    ->post($heartbeat, ['command' => $command, 'status' => 'failed']);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Heartbeat ping failed', [
                    'command' => $command,
                    'error' => $e->getMessage(),
                ]);
            }
        };

        // NOTE: every task below carries ->withoutOverlapping(). These commands mutate
        // teacher wallets and membership state; a slow run overlapping the next tick, or a
        // manual run racing the scheduled one, would credit the same month twice.
        // ->onOneServer() additionally guards against running more than one app container.

        // Schedule the membership expiration command to run daily
        $schedule->command('memberships:update-payment-status')
            ->daily()
            ->withoutOverlapping()
            ->onOneServer();

        // Schedule the membership stats update to run daily at 1 AM
        $schedule->command('memberships:update-stats --all')
            ->dailyAt('01:00')
            ->withoutOverlapping()
            ->onOneServer();

        // Weekly monitoring: Fix any membership inconsistencies every Sunday at 2 AM
        $schedule->command('memberships:fix-end-dates')
            ->weekly()->sundays()->at('02:00')
            ->withoutOverlapping()
            ->onOneServer();

        // Nightly assertion that every wallet still reconciles against the ledger.
        //
        // The non-zero exit code alone reached NOBODY: cron runs `schedule:run` with
        // `>> /dev/null 2>&1`, so both the exit status and everything the command printed
        // were discarded, and nothing hooked the failure. A wallet could drift from the
        // ledger every night for a year in silence. Three channels now carry it out:
        //   1. the command itself Log::error()s the drift (see CheckWalletLedger),
        //   2. ->appendOutputTo() keeps the human-readable table on disk,
        //   3. ->onFailure() logs and, if SCHEDULE_HEARTBEAT_URL is set, pings a monitor.
        $schedule->command('wallet:check')
            ->dailyAt('04:30')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/schedule.log'))
            ->onFailure(fn () => $reportScheduledFailure('wallet:check'));

        /*
         * The notification recovery sweep: release what the system never attempted, retry
         * recent failures, abandon what has gone stale.
         *
         * EVERY MINUTE, and the cadence is the feature.
         *
         * A message held during an outage is released only by this sweep. At the previous
         * half-hourly cadence somebody could reconnect the school phone, watch the screen
         * say "Connectée", and still be staring at "En pause" twenty-nine minutes later.
         * Nothing was broken and there was no way to know that from the outside — which
         * makes it indistinguishable from broken, and that is what people report. Recovery
         * now lands within a minute of the phone coming back.
         *
         * Affordable because the command leaves immediately on one indexed EXISTS query
         * when there is nothing held, failed or stranded: no gateway probe, no rows loaded,
         * nothing appended to schedule.log. A quiet minute costs a single query.
         */
        $schedule->command('notifications:retry-failed')
            ->everyMinute()
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/schedule.log'))
            ->onFailure(fn () => $reportScheduledFailure('notifications:retry-failed'));

        /*
         * Is the notification pipeline still moving at all?
         *
         * Read-only, and the only watcher for the one failure mode that produces no error
         * anywhere: a queue with no worker. Rows are created, marked pending, and never
         * picked up — nothing fails, nothing retries, every screen reports success, and the
         * first sign is a parent who was never told. supervisord.conf restarts a crashed
         * worker, but a worker that crash-loops, or a `--queue` flag lost in a config edit,
         * leaves no trace at all. Hourly is enough: the check is three counts.
         */
        $schedule->command('notifications:health')
            ->hourly()
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/schedule.log'))
            ->onFailure(fn () => $reportScheduledFailure('notifications:health'));

        /*
         * Guardian numbers that reach nobody. Read-only, exits non-zero when it finds an
         * unusable number — a number that LOOKS present and silently delivers to nobody is
         * the worst failure this feature has, because every screen shows it as fine.
         */
        $schedule->command('whatsapp:audit-numbers --active')
            ->weeklyOn(1, '06:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/schedule.log'))
            ->onFailure(fn () => $reportScheduledFailure('whatsapp:audit-numbers'));

        // Read-only payout audit, kept as a daily signal. It never writes.
        // (`payments:check-consistency --fix` remains deliberately DISABLED: it repairs
        // toward the current payout formula, so enabling it before the amount model is
        // corrected would cement the wrong numbers.)
        $schedule->command('payouts:audit')
            ->dailyAt('05:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/schedule.log'))
            ->onFailure(fn () => $reportScheduledFailure('payouts:audit'));

        // The monthly payout run moves real money — a silent failure there is the most
        // expensive one in the system.
        $schedule->command('teachers:process-monthly-payments')
            ->monthlyOn(1, '02:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->appendOutputTo(storage_path('logs/schedule.log'))
            ->onFailure(fn () => $reportScheduledFailure('teachers:process-monthly-payments'));

        // Clean up old stats monthly on the 1st at 3 AM.
        // ->name() is REQUIRED before ->withoutOverlapping() on a closure task; Laravel has
        // no other way to derive the mutex key and throws a LogicException at boot without it.
        $schedule->call(function () {
            $service = new \App\Services\MembershipStatsService;
            $service->cleanupOldStats(5); // Keep 5 years of stats
        })->name('memberships:cleanup-old-stats')
            ->monthlyOn(1, '03:00')
            ->withoutOverlapping()
            ->onOneServer();
    })
    ->create();
