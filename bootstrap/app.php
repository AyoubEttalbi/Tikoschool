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

        // Schedule teacher monthly payments to run on the 1st of each month at 2 AM.
        // This is the one that pays real money — the guards matter most here.
        $schedule->command('teachers:process-monthly-payments')
            ->monthlyOn(1, '02:00')
            ->withoutOverlapping()
            ->onOneServer();

        // Weekly monitoring: Fix any membership inconsistencies every Sunday at 2 AM
        $schedule->command('memberships:fix-end-dates')
            ->weekly()->sundays()->at('02:00')
            ->withoutOverlapping()
            ->onOneServer();

        // Nightly assertion that every wallet still reconciles against the ledger.
        // Exits non-zero on drift so a monitored scheduler surfaces it.
        $schedule->command('wallet:check')
            ->dailyAt('04:30')
            ->withoutOverlapping()
            ->onOneServer();

        // Read-only payout audit, kept as a daily signal. It never writes.
        // (`payments:check-consistency --fix` remains deliberately DISABLED: it repairs
        // toward the current payout formula, so enabling it before the amount model is
        // corrected would cement the wrong numbers.)
        $schedule->command('payouts:audit')
            ->dailyAt('05:00')
            ->withoutOverlapping()
            ->onOneServer();

        // Clean up old stats monthly on the 1st at 3 AM.
        // ->name() is REQUIRED before ->withoutOverlapping() on a closure task; Laravel has
        // no other way to derive the mutex key and throws a LogicException at boot without it.
        $schedule->call(function () {
            $service = new \App\Services\MembershipStatsService();
            $service->cleanupOldStats(5); // Keep 5 years of stats
        })->name('memberships:cleanup-old-stats')
            ->monthlyOn(1, '03:00')
            ->withoutOverlapping()
            ->onOneServer();
    })
    ->create();
