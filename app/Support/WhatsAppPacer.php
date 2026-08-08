<?php

namespace App\Support;

use App\Models\OutboundMessage;
use Illuminate\Support\Facades\Cache;

/**
 * The thing that stops the school's WhatsApp number getting banned.
 *
 * With the paid vendor there was at least a service between this app and WhatsApp. A
 * self-hosted gateway has nothing: it drives a real logged-in account, and a burst of
 * near-identical automated messages is exactly the pattern that gets a number blocked.
 * Whatever pacing exists has to exist here.
 *
 * Three controls, all cheap and all independent:
 *
 *   1. A minimum gap between any two sends, process-wide.
 *   2. Random jitter on top, because a message every exactly N seconds is a machine
 *      signature — the regularity is itself the tell.
 *   3. A rolling daily cap, so a bug that dispatches one job per student per absence
 *      cannot empty a whole roster into WhatsApp before anyone notices.
 *
 * The three do NOT share a home, because they do not share a tolerance for being wrong.
 * The gap, the jitter and the lock live in the cache: losing them to a `cache:clear`
 * costs one slightly-tight interval, which does not get a number banned. The daily cap is
 * counted from the outbound_messages table, because a safety net that evaporates on a
 * cache flush, a deploy or a Redis restart is not a safety net.
 */
class WhatsAppPacer
{
    private const LOCK_KEY = 'whatsapp:pace:lock';

    private const LAST_SENT_KEY = 'whatsapp:pace:last_sent';

    /**
     * How long the decision lock is held.
     *
     * Ten seconds is generous for "read two values and write one" and is deliberately far
     * SHORTER than the gateway's HTTP timeout — because the lock must never be held across
     * the network call. See SendOutboundMessage::handle(): the slot is reserved under the
     * lock, the lock is released, and only then is the message sent.
     */
    public const LOCK_SECONDS = 10;

    /**
     * Seconds the caller must wait before it is allowed to send. 0 means send now.
     *
     * The caller is expected to re-queue itself for this many seconds rather than
     * sleep() — a sleeping worker is a worker not doing anything else, and the previous
     * implementation slept *while holding the lock*, so a long enough wait let the lock
     * expire and a second worker send at the same moment anyway.
     */
    public static function secondsUntilAllowed(): int
    {
        $gap = (int) config('whatsapp.min_seconds_between', 8);
        $lastSent = (int) Cache::get(self::LAST_SENT_KEY, 0);

        if ($lastSent === 0) {
            return 0;
        }

        $elapsed = time() - $lastSent;

        // A clock that jumped backwards would otherwise wedge this forever.
        if ($elapsed < 0) {
            return 0;
        }

        return max(0, $gap - $elapsed);
    }

    /** Extra, random spacing so the send pattern is not perfectly periodic. */
    public static function jitter(): int
    {
        $max = (int) config('whatsapp.jitter_seconds', 4);

        return $max > 0 ? random_int(0, $max) : 0;
    }

    /**
     * How many have gone out today.
     *
     * Counted from outbound_messages, not from the cache. The min-gap and the jitter can
     * afford to be approximate — losing them costs one slightly-tight gap. The daily cap
     * cannot: its entire purpose is "a bug cannot empty the roster into WhatsApp", and a
     * safety net that disappears on `cache:clear`, a deploy or a Redis restart is not a
     * safety net. Served by the (status, sent_at) index; at ~80 sends a day it is free.
     */
    public static function sentToday(): int
    {
        return OutboundMessage::sentToday()->count();
    }

    /** Whether today's allowance is spent. A cap of 0 disables the control entirely. */
    public static function dailyCapReached(): bool
    {
        $cap = (int) config('whatsapp.daily_cap', 250);

        return $cap > 0 && self::sentToday() >= $cap;
    }

    /**
     * Claim the next send slot. Call this while holding the lock and BEFORE sending.
     *
     * Named for reserving rather than recording because that is what it does: it moves the
     * clock forward so no other worker may send for another gap, and it does so before the
     * network call rather than after. Doing it afterwards was the bug — two workers could
     * both be mid-send with neither having recorded anything yet.
     *
     * A failed send therefore still consumes its slot. That is correct: the pacer governs
     * how often the gateway is CONTACTED, and a failure contacted it just as much as a
     * success did. The daily cap, which counts only delivered messages, is a separate
     * control and is counted from the table.
     */
    public static function reserveSlot(): void
    {
        // Only the last-sent clock lives in the cache now; the daily count is a query.
        Cache::put(self::LAST_SENT_KEY, time(), now()->addHour());
    }

    /** Serialise the check-and-record so two workers cannot both decide they may send. */
    public static function lock()
    {
        return Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
    }

    /** Only for tests and for an operator clearing a stuck throttle. */
    public static function reset(): void
    {
        Cache::forget(self::LAST_SENT_KEY);
    }
}
