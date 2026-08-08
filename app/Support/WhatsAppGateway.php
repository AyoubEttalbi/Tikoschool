<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Is the gateway actually able to send right now?
 *
 * This exists because of one failure the app previously could not see. A WhatsApp link is
 * a paired device, and a phone can un-pair it at any time — somebody taps "Log out" in
 * Linked Devices, or the phone is offline long enough for WhatsApp to drop the session.
 * From the app's side that looks like every message failing with an ordinary error. So a
 * whole evening's absences would burn their retry budgets against a gateway that was never
 * going to accept them, and then be marked failed forever.
 *
 * Asking first turns that into waiting. A message that was never attempted is HELD, not
 * failed, costs no attempts, and goes out when the link comes back — whether that is in
 * ten minutes or on Monday.
 */
class WhatsAppGateway
{
    private const STATE_KEY = 'whatsapp:gateway:state';

    /**
     * Short on purpose. Long enough that a burst of thirty queued jobs asks once rather
     * than thirty times; short enough that reconnecting is noticed within a slot or two,
     * which at an 8-second pacing gap is no delay at all in practice.
     */
    private const CACHE_SECONDS = 20;

    /** Linked and able to send. */
    public const OPEN = 'open';

    /** Reachable, but not linked to a phone — someone must scan a QR code. */
    public const NEEDS_SCAN = 'qr';

    public const LOGGED_OUT = 'logged_out';

    /** The gateway process itself is not answering. */
    public const UNREACHABLE = 'unreachable';

    /** Not using a gateway at all (log / null / the old paid vendor). */
    public const NOT_APPLICABLE = 'n/a';

    public static function state(bool $fresh = false): string
    {
        if (config('whatsapp.driver') !== 'evolution') {
            // Nothing to be disconnected FROM. The log driver always "succeeds" and the
            // paid vendor has no session of its own, so holding messages for those would
            // stall the queue over a condition that cannot occur.
            return self::NOT_APPLICABLE;
        }

        if ($fresh) {
            Cache::forget(self::STATE_KEY);
        }

        return Cache::remember(self::STATE_KEY, self::CACHE_SECONDS, function () {
            $config = config('whatsapp.evolution');

            if (empty($config['api_key'])) {
                return self::UNREACHABLE;
            }

            try {
                $response = Http::withHeaders(['apikey' => $config['api_key']])
                    // Deliberately much shorter than the send timeout: this is a
                    // pre-flight question, and a slow answer to it must not eat the
                    // budget of the send it precedes.
                    ->timeout(5)
                    ->acceptJson()
                    ->get(rtrim($config['base_url'], '/').'/health');
            } catch (\Throwable $e) {
                Log::warning('WhatsApp gateway health check failed', ['error' => $e->getMessage()]);

                return self::UNREACHABLE;
            }

            if ($response->failed()) {
                return self::UNREACHABLE;
            }

            $state = (string) $response->json('state', self::UNREACHABLE);

            // Anything the gateway reports that this app does not recognise is treated as
            // not-ready rather than optimistically as ready. Being wrong in that direction
            // delays a message; being wrong in the other loses it.
            return in_array($state, [self::OPEN, self::NEEDS_SCAN, self::LOGGED_OUT], true)
                ? $state
                : self::UNREACHABLE;
        });
    }

    /** True when a message may be attempted. */
    public static function canSend(bool $fresh = false): bool
    {
        $state = self::state($fresh);

        return $state === self::OPEN || $state === self::NOT_APPLICABLE;
    }

    /** The hold reason to record, or null when sending is fine. */
    public static function holdReason(): ?string
    {
        return match (self::state()) {
            self::OPEN, self::NOT_APPLICABLE => null,
            self::NEEDS_SCAN, self::LOGGED_OUT => 'gateway_disconnected',
            default => 'gateway_unreachable',
        };
    }

    /** Drop the cached answer — after a connect or disconnect, so the UI is not stale. */
    public static function forget(): void
    {
        Cache::forget(self::STATE_KEY);
    }
}
