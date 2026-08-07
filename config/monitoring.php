<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Scheduled-task failure heartbeat
    |--------------------------------------------------------------------------
    |
    | Optional. When set, bootstrap/app.php POSTs to this URL whenever a
    | money-touching scheduled command exits non-zero (wallet:check drift,
    | a failed payout run, a failed payout audit).
    |
    | Any endpoint that accepts a POST works — Healthchecks.io, Better Stack,
    | Cronitor, or your own webhook. Leave unset and failures are still logged
    | to storage/logs/laravel.log; the ping is the channel that reaches a phone.
    |
    | This is read through config() rather than env() on purpose: env() returns
    | null once config:cache has run, which the production entrypoint always does.
    |
    */
    'heartbeat_url' => env('SCHEDULE_HEARTBEAT_URL'),

    /*
    |--------------------------------------------------------------------------
    | Dashboard membership-stats cache window, in minutes
    |--------------------------------------------------------------------------
    |
    | How long a membership_monthly_stats row is trusted before the dashboard
    | recomputes it. See MembershipStatsService::getOrCreateMonthlyStats().
    |
    | Set to 0 to always recompute (the old behaviour).
    |
    */
    'stats_cache_minutes' => env('STATS_CACHE_MINUTES', 10),
];
