<?php

namespace App\Jobs;

use App\Models\OutboundMessage;
use App\Notifications\Channels\WhatsAppChannel;
use App\Notifications\Contracts\MessageChannel;
use App\Support\WhatsAppGateway;
use App\Support\WhatsAppPacer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Delivers one recorded message and writes the outcome back to its row.
 *
 * Takes an id, not a phone number and a body. The previous job carried both as
 * constructor arguments, which meant the guardian's number and the child's name were
 * serialised into the `jobs` table in plain text and stayed there in `failed_jobs`
 * indefinitely. The row is the record; the job is only the trigger.
 */
class SendOutboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /*
     * Two limits, deliberately split — see the retryUntil note below. Only a throw
     * increments maxExceptions, and a paced release() does not throw, so waits are free
     * and real failures are not.
     */
    public $backoff = [60, 300, 900];   // 1m, 5m, 15m

    public $timeout = 60;

    public $maxExceptions = 4;

    public function __construct(public int $messageId) {}

    /**
     * Worker.php:520 returns before it ever looks at maxTries when retryUntil() is set,
     * so a `$tries` beside this would be dead config that reads as a limit and enforces
     * nothing. Six hours covers the pacing waits of a large class plus the backoff ladder,
     * and is short enough that a notice never arrives the next day out of nowhere.
     */
    public function retryUntil(): \DateTime
    {
        return now()->addHours(6);
    }

    public function handle(): void
    {
        $message = OutboundMessage::find($this->messageId);

        // Deleted, or already delivered by a duplicate job. Both are fine and neither is
        // an error: this is the check that makes the job safe to run twice.
        if (! $message || $message->status !== OutboundMessage::STATUS_PENDING) {
            return;
        }

        /*
         * THE LOCK COVERS THE DECISION, NOT THE SEND.
         *
         * The first version of this held the lock through $channel->send(). The HTTP call
         * has a 20-second timeout and the lock a 10-second TTL, so a merely SLOW gateway —
         * not even a failing one — let the lock expire mid-send. A second worker then
         * acquired it, saw the same "clear to go" state because the first had not recorded
         * anything yet, and sent concurrently: precisely the burst the pacer exists to
         * prevent, produced by the pacer itself.
         *
         * So the slot is RESERVED while the lock is held, and the network call happens
         * after the lock is gone. Reserving before sending also means a send that fails
         * still consumes its slot, which is correct — pacing is about how often the
         * gateway is contacted, not how often it succeeds.
         */
        /*
         * ASK BEFORE SPENDING ANYTHING.
         *
         * A WhatsApp link is a paired device and a phone can un-pair it at any moment.
         * From here that looks like every send failing with an ordinary error — so an
         * evening's absences would each burn their retry budget against a gateway that
         * was never going to accept them, and end up permanently failed. Held instead:
         * no attempt spent, no failure recorded, and the recovery sweep releases the whole
         * backlog when the link returns, whether that is in ten minutes or on Monday.
         */
        if (! WhatsAppGateway::canSend()) {
            $this->hold($message, WhatsAppGateway::holdReason() ?? 'gateway_unreachable');

            return;
        }

        /*
         * A NUMBER THAT NEVER WORKS COSTS EVERY OTHER PARENT TIME.
         *
         * Guardian numbers are years of free-text entry. Some parse perfectly and reach
         * nobody, and each attempt against one consumes a pacing slot a working number
         * could have used. After a few consecutive failures the student is put on a list
         * for somebody to fix rather than retried into the void every day; the counter
         * resets the moment the number is edited or a message gets through.
         */
        if ($message->student && $this->breakerOpen($message->student)) {
            $message->update([
                'status' => OutboundMessage::STATUS_SKIPPED,
                'skip_reason' => OutboundMessage::SKIP_UNREACHABLE_NUMBER,
            ]);

            return;
        }

        $lock = WhatsAppPacer::lock();

        if (! $lock->get()) {
            $this->release(WhatsAppPacer::LOCK_SECONDS);

            return;
        }

        try {
            if (WhatsAppPacer::dailyCapReached()) {
                // Terminal. The cap means something is either legitimately busy or looping;
                // six hours of retries would keep hitting the same wall, and the row records
                // why so an operator can re-drive it deliberately.
                $this->markFailed($message, 'plafond quotidien atteint');
                $this->fail(new \RuntimeException('daily cap reached'));

                return;
            }

            $wait = WhatsAppPacer::secondsUntilAllowed();

            if ($wait > 0) {
                // Re-queue, never sleep: a sleeping worker does no other work, and an
                // earlier version slept while HOLDING this lock, which let a second worker
                // through once the wait outlasted the lock TTL.
                $this->release($wait + WhatsAppPacer::jitter());

                return;
            }

            WhatsAppPacer::reserveSlot();
        } finally {
            $lock->release();
        }

        $channel = $this->channelFor($message->channel);
        $message->increment('attempts');

        $result = $channel->send($message->recipient, $message->message);

        if ($result->success) {
            $message->update([
                'status' => OutboundMessage::STATUS_SENT,
                'provider' => $channel->name(),
                'provider_message_id' => $result->messageId,
                'sent_at' => now(),
                'last_error' => null,
            ]);

            // The number works. Whatever went wrong before is history — a guardian who
            // was unreachable last week and answers today must not stay on the list.
            if ($message->student && $message->student->guardianNumberFailures > 0) {
                $message->student->forceFill(['guardianNumberFailures' => 0])->saveQuietly();
            }

            Log::info('Outbound message sent', ['id' => $message->id]);

            return;
        }

        if ($result->permanent) {
            // A wrong number does not become right by being tried again, and repeatedly
            // pushing a rejected recipient at WhatsApp is itself a way to get flagged.
            $this->countAgainstNumber($message);
            $this->markFailed($message, $result->error);
            $this->fail(new \RuntimeException('permanent failure: '.$result->error));

            return;
        }

        $message->update([
            'provider' => $channel->name(),
            'last_error' => mb_substr((string) $result->error, 0, 500),
        ]);

        throw new \RuntimeException($result->error ?? 'send failed');
    }

    /**
     * The channel seam. `whatsapp` is the only one today; an SMS fallback is a case here
     * plus a class, and nothing upstream of this job changes.
     */
    private function channelFor(string $channel): MessageChannel
    {
        return match ($channel) {
            OutboundMessage::CHANNEL_WHATSAPP => app(WhatsAppChannel::class),
            default => throw new \InvalidArgumentException("Unknown channel [{$channel}]"),
        };
    }

    /**
     * Park a message that was never attempted.
     *
     * Not `failed`: nothing was tried, no attempt was spent, and the row must not look
     * like a broken number to the person reading the screen. `held_since` records when
     * the wait STARTED, which is the number an administrator needs to judge whether a
     * backlog is still worth sending — created_at cannot answer that once a message has
     * been held, released and held again.
     */
    private function hold(OutboundMessage $message, string $reason): void
    {
        $message->update([
            'status' => OutboundMessage::STATUS_HELD,
            'hold_reason' => $reason,
            'held_since' => $message->held_since ?? now(),
        ]);
    }

    /** Consecutive failures before a guardian's number is treated as dead. */
    private function breakerOpen(\App\Models\Student $student): bool
    {
        $limit = (int) config('whatsapp.max_number_failures', 4);

        return $limit > 0 && $student->guardianNumberFailures >= $limit;
    }

    private function countAgainstNumber(OutboundMessage $message): void
    {
        // saveQuietly: this is bookkeeping about a phone number, and it must not fire
        // the student observers that exist for real edits.
        if ($message->student) {
            $message->student->forceFill([
                'guardianNumberFailures' => $message->student->guardianNumberFailures + 1,
            ])->saveQuietly();
        }
    }

    private function markFailed(OutboundMessage $message, ?string $error): void
    {
        $message->update([
            'status' => OutboundMessage::STATUS_FAILED,
            'failed_at' => now(),
            'last_error' => mb_substr((string) $error, 0, 500),
        ]);
    }

    /**
     * Runs when the attempts or the six hours are exhausted. Marks the row so the failure
     * is visible on a screen rather than only in a log file — and note the guardian's
     * number is NOT logged: the id is enough to find the parent through the app.
     */
    public function failed(\Throwable $exception): void
    {
        $message = OutboundMessage::find($this->messageId);

        if ($message && $message->status === OutboundMessage::STATUS_PENDING) {
            $this->markFailed($message, $exception->getMessage());
        }

        Log::critical('Outbound message permanently failed', [
            'id' => $this->messageId,
            'error' => $exception->getMessage(),
        ]);
    }
}
