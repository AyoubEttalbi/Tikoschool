<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Driver
    |--------------------------------------------------------------------------
    |
    | Which gateway actually delivers a message.
    |
    |   evolution  Self-hosted Evolution API (or any gateway with the same
    |              POST /message/sendText/{instance} shape). Free to run.
    |   wasender   The paid hosted service this app used first. Kept so the
    |              switch is reversible without a deploy.
    |   log        Writes the message to the Laravel log and returns success.
    |              The right default for local work — no phone, no cost, and no
    |              risk of messaging a real parent from a dev machine.
    |   null       Silently discards. For tests and for pausing sends.
    |
    */
    'driver' => env('WHATSAPP_DRIVER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | Pacing (this is the anti-ban control)
    |--------------------------------------------------------------------------
    |
    | Unofficial gateways drive a real WhatsApp account. Bursts of identical
    | automated messages are what gets a number banned, and NOTHING upstream
    | throttles for you — the pacing has to happen here or it does not happen.
    |
    | min_seconds_between  Hard floor between two sends, process-wide, enforced
    |                      with a cache lock so concurrent queue workers cannot
    |                      each send at the same instant.
    | jitter_seconds       Random extra delay. A message every exactly N seconds
    |                      is a machine signature; a human-ish spread is not.
    | daily_cap            Refuse to send more than this in a rolling day. A bug
    |                      that dispatches one job per student per absence can
    |                      empty a whole roster into WhatsApp in minutes.
    |
    */
    'min_seconds_between' => (int) env('WHATSAPP_MIN_SECONDS', 8),
    'jitter_seconds' => (int) env('WHATSAPP_JITTER_SECONDS', 4),
    'daily_cap' => (int) env('WHATSAPP_DAILY_CAP', 250),

    /*
     * Consecutive failures before a guardian's number is treated as dead and put on the
     * "à corriger" list instead of retried. Each attempt against a number that will never
     * work costs a pacing slot a working number could have used. 0 disables the breaker.
     */
    'max_number_failures' => (int) env('WHATSAPP_MAX_NUMBER_FAILURES', 4),

    /*
     * How stale a notice may get before it is abandoned as `expired`.
     *
     * This is NOT the retry window — a message HELD because the gateway was down has not
     * been tried at all, and losing four days of absences because nobody scanned a QR code
     * on Friday is exactly the failure this system exists to prevent. It is the point at
     * which telling a parent about an absence stops being useful. The message carries its
     * own date, so a late delivery is still accurate; a week later it is just confusing.
     */
    'max_age_days' => (int) env('WHATSAPP_MAX_AGE_DAYS', 5),

    /*
    |--------------------------------------------------------------------------
    | Evolution API
    |--------------------------------------------------------------------------
    |
    | base_url  Where the gateway listens. Keep it on a private network or
    |           bound to localhost — the API key is the only thing standing
    |           between the internet and the school's WhatsApp account.
    | instance  Evolution calls one logged-in number an "instance".
    |
    */
    'evolution' => [
        'base_url' => env('EVOLUTION_API_URL', 'http://127.0.0.1:8080'),
        'api_key' => env('EVOLUTION_API_KEY'),
        'instance' => env('EVOLUTION_INSTANCE', 'tikoschool'),
        'timeout' => (int) env('EVOLUTION_TIMEOUT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Default country
    |--------------------------------------------------------------------------
    |
    | Guardian numbers are stored inconsistently: 0612..., +212612..., 212612...
    | and some with spaces. WhatsApp addresses are digits with a country code and
    | no plus, so every number is normalised through one place before sending.
    |
    */
    'country_code' => env('WHATSAPP_COUNTRY_CODE', '212'),

];
