<?php

namespace App\Console\Commands;

use App\Models\OutboundMessage;
use App\Support\WhatsAppGateway;
use App\Support\WhatsAppPacer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Does this system still reach parents?
 *
 * Every other failure mode here is loud: a bad number fails, a dead gateway holds, a stale
 * notice expires. All of them end up on the notifications screen with a reason attached.
 *
 * One is not loud, and it is the one that actually happened. If nothing is consuming the
 * `whatsapp` queue, messages are created correctly, marked `pending`, and never picked up.
 * Nothing fails, so `failed_jobs` stays empty. Nothing is attempted, so `attempts` stays 0.
 * Every screen in the app reports success. The only symptom is a parent who was never told,
 * discovered weeks later. That is the exact shape of the bug this command watches for, and
 * it is why supervisord.conf carries a ten-line comment above one flag.
 *
 * Read-only. It changes nothing and repairs nothing — `notifications:retry-failed` does the
 * repairing. This one only answers "is it moving?" and exits non-zero when it is not, so
 * the scheduler's onFailure hook and any external monitor both see it.
 */
class CheckNotificationHealth extends Command
{
    protected $signature = 'notifications:health
                            {--stale-minutes=20 : How long a claimable job may sit before the worker is presumed dead}
                            {--held-hours=6 : How long a held backlog may sit before the outage is worth an alert}';

    protected $description = 'Fail loudly when parent notifications have stopped moving';

    public function handle(): int
    {
        $staleMinutes = max(1, (int) $this->option('stale-minutes'));
        $heldHours = max(1, (int) $this->option('held-hours'));

        $problems = [];
        $facts = [];

        /*
         * 1. The dead worker.
         *
         * `available_at` is when a job became claimable. A running worker takes it within
         * seconds, so anything still unreserved twenty minutes later means nobody is
         * listening. This is immune to backlog size, which the pending-row check below is
         * not: the pacer RELEASES a job with a fresh delay rather than leaving it sitting,
         * so even a two-hundred-message backlog keeps its oldest claimable job seconds old.
         */
        $stalled = $this->stalledQueueMinutes();
        $facts[] = ['Oldest unclaimed job', $stalled === null ? '—' : $stalled.' min'];

        if ($stalled !== null && $stalled >= $staleMinutes) {
            $problems[] = 'Nothing is consuming the `whatsapp` queue: the oldest job has been '
                ."claimable for {$stalled} minutes. Messages are queued and will never be sent. "
                .'Check the queue-worker process (supervisorctl status queue-worker).';
        }

        /*
         * 2. Pending rows with nothing behind them.
         *
         * notifications:retry-failed rescues these after two hours. Finding them here means
         * that sweep is not running either — so the scheduler itself is down, and every
         * recovery path this system has is off.
         */
        $stranded = OutboundMessage::where('status', OutboundMessage::STATUS_PENDING)
            ->where('scheduled_at', '<', now()->subHours(3))
            ->count();
        $facts[] = ['Pending, 3 h past due', $stranded];

        if ($stranded > 0) {
            $problems[] = "{$stranded} notification(s) have been pending for over three hours. "
                .'The recovery sweep should have rescued them — check that the scheduler is running.';
        }

        /*
         * 3. A held backlog nobody has noticed.
         *
         * Held is a correct, safe state: the gateway is down and the messages are waiting
         * rather than failing. It stops being safe when it lasts, because absences age out
         * of usefulness (whatsapp.max_age_days) and are then abandoned unsent.
         */
        $oldestHeld = OutboundMessage::where('status', OutboundMessage::STATUS_HELD)->min('held_since');
        $heldCount = OutboundMessage::where('status', OutboundMessage::STATUS_HELD)->count();
        $heldAge = $oldestHeld ? now()->diffInHours($oldestHeld, absolute: true) : 0;
        $facts[] = ['Held', $heldCount.($heldCount ? " (oldest {$heldAge} h)" : '')];

        if ($heldCount > 0 && $heldAge >= $heldHours) {
            $problems[] = "{$heldCount} notification(s) have been held for {$heldAge} hours — "
                .'the WhatsApp gateway has been unusable that long. They expire after '
                .config('whatsapp.max_age_days').' days and are then lost.';
        }

        /*
         * 4. Jobs that gave up entirely. Distinct from a `failed` message, which is a
         *    delivery that was attempted and refused; a failed JOB is our own code throwing.
         */
        $failedJobs = $this->failedJobCount();
        $facts[] = ['Failed jobs (24 h)', $failedJobs];

        if ($failedJobs > 0) {
            $problems[] = "{$failedJobs} notification job(s) crashed in the last 24 hours. "
                .'Inspect with: php artisan queue:failed';
        }

        // 5. The cap is a safety valve, not a target. Reaching it means either a real surge
        //    or a bug dispatching duplicates — and either way the rest of the day is muted.
        $sentToday = WhatsAppPacer::sentToday();
        $cap = (int) config('whatsapp.daily_cap');
        $facts[] = ['Sent today', $sentToday.' / '.$cap];

        if ($cap > 0 && $sentToday >= $cap) {
            $problems[] = "The daily cap of {$cap} has been reached. Nothing further will be "
                .'sent today; remaining notices wait for tomorrow.';
        }

        $facts[] = ['Gateway', WhatsAppGateway::state(fresh: true)];

        $this->table(['Check', 'Value'], $facts);

        if ($problems === []) {
            $this->info('Notifications are moving.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error('• '.$problem);
        }

        // Cron discards stdout and the exit code both, so the log is the only channel that
        // survives an unattended run. bootstrap/app.php adds ->onFailure() on top of it.
        Log::error('Notification health check failed', [
            'problems' => $problems,
            'stalled_minutes' => $stalled,
            'stranded_pending' => $stranded,
            'held' => $heldCount,
            'failed_jobs' => $failedJobs,
        ]);

        return self::FAILURE;
    }

    /**
     * Minutes the oldest claimable, unclaimed `whatsapp` job has been waiting.
     *
     * Null when the answer is not knowable: no jobs waiting, or a queue driver with no
     * `jobs` table. Null is deliberately NOT treated as a problem — a Redis deployment
     * must not alarm every thirty minutes about a table it does not have.
     */
    private function stalledQueueMinutes(): ?int
    {
        // now()->timestamp, not time(). `jobs.available_at` is a raw unix integer, which
        // makes time() the obvious reach — but the two diverge the moment anything freezes
        // the clock, and a check that silently reads a different "now" than the rest of the
        // app is a check that passes for the wrong reason.
        $now = now()->timestamp;

        try {
            $oldest = DB::table('jobs')
                ->where('queue', 'whatsapp')
                // A reserved job is in a worker's hands right now; only unreserved rows
                // say anything about whether a worker exists.
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $now)
                ->min('available_at');
        } catch (\Throwable) {
            return null;
        }

        if (! $oldest) {
            return null;
        }

        return (int) floor(($now - (int) $oldest) / 60);
    }

    private function failedJobCount(): int
    {
        try {
            return DB::table('failed_jobs')
                ->where('payload', 'like', '%SendOutboundMessage%')
                ->where('failed_at', '>=', now()->subDay())
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
