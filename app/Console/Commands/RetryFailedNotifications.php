<?php

namespace App\Console\Commands;

use App\Models\OutboundMessage;
use App\Services\OutboundMessageService;
use App\Support\WhatsAppGateway;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The recovery sweep. Everything that did not reach a parent gets looked at again here.
 *
 * The first version of this looked back two days, and that was wrong in a way worth
 * spelling out, because it is the difference between a system and a script.
 *
 * "Two days" conflates two completely different things:
 *
 *   A message that was ATTEMPTED and failed has told us something — usually that the
 *   number is bad. Retrying it next week helps nobody, and each attempt costs a pacing
 *   slot a working number could have used.
 *
 *   A message that was NEVER ATTEMPTED, because the gateway was logged out or nobody was
 *   running a worker, has told us nothing at all. If that lasted from Friday to Tuesday,
 *   a two-day window silently discards Friday's and Saturday's absences — the exact
 *   outage this system is supposed to survive.
 *
 * So the two are handled separately. `held` rows are released regardless of how long the
 * outage lasted; `failed` rows get a short, bounded second chance. The only thing that
 * abandons a message is AGE — past whatever `whatsapp.max_age_days` says, telling a parent
 * about it stops being useful and it is recorded as `expired` rather than deleted.
 *
 * Runs every 30 minutes rather than once a morning. A gateway that reconnects at 10:15
 * should not wait until tomorrow, and holding a whole day's absences to release them in
 * one burst is exactly the pattern that gets a WhatsApp number banned.
 */
class RetryFailedNotifications extends Command
{
    protected $signature = 'notifications:retry-failed
                            {--failed-days=2 : How far back to retry rows that were actually attempted}
                            {--limit=200 : Most rows to release in one run}
                            {--dry-run : Report what would happen and change nothing}';

    protected $description = 'Release held notifications and retry recent failures';

    public function handle(OutboundMessageService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $maxAgeDays = max(1, (int) config('whatsapp.max_age_days', 5));
        $cutoff = now()->subDays($maxAgeDays);

        // 1. Abandon what is too old to be worth sending — held or failed alike. Done
        //    FIRST so the passes below never release something that should have expired.
        $expired = OutboundMessage::recoverable()
            ->where('created_at', '<', $cutoff)
            ->limit($limit)
            ->get();

        // 2. Anything the system never attempted. Released whatever the outage's length —
        //    this is the pass that makes a four-day gateway outage survivable.
        $held = OutboundMessage::where('status', OutboundMessage::STATUS_HELD)
            ->where('created_at', '>=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        // 3. Attempted and failed. Deliberately a SHORT window: a failure is evidence,
        //    and re-driving week-old evidence just burns slots.
        $failed = OutboundMessage::where('status', OutboundMessage::STATUS_FAILED)
            ->where('created_at', '>=', now()->subDays(max(1, (int) $this->option('failed-days'))))
            ->where('created_at', '>=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        // 4. Rows left `pending` with nothing driving them: the worker was killed between
        //    reserving a slot and recording the outcome, or the container restarted. They
        //    sit there forever otherwise, and nothing ever looks at them again.
        $stranded = OutboundMessage::where('status', OutboundMessage::STATUS_PENDING)
            ->where('created_at', '>=', $cutoff)
            ->where('scheduled_at', '<', now()->subHours(2))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $gateway = WhatsAppGateway::state(fresh: true);

        $this->table(['', 'Rows'], [
            ['Gateway', $gateway],
            ['Expired (older than '.$maxAgeDays.'d)', $expired->count()],
            ['Held — never attempted', $held->count()],
            ['Failed — recent', $failed->count()],
            ['Stranded in pending', $stranded->count()],
        ]);

        if ($dryRun) {
            return self::SUCCESS;
        }

        foreach ($expired as $message) {
            $message->update([
                'status' => OutboundMessage::STATUS_EXPIRED,
                'last_error' => $message->last_error ?: 'non envoyé dans les délais',
            ]);
        }

        // Releasing into a gateway that is still down would immediately re-hold every row
        // and churn the queue for nothing. The held rows simply stay held; that is what
        // the state is for, and the next run picks them up.
        if (! WhatsAppGateway::canSend()) {
            $this->warn('Gateway is not ready ('.$gateway.') — held messages stay held.');
            $this->info($expired->count().' expired.');

            return self::SUCCESS;
        }

        $released = 0;

        foreach ($held as $message) {
            // Straight back to pending: a held row was never attempted, so it keeps its
            // attempt count and does not need the failure path.
            $message->update([
                'status' => OutboundMessage::STATUS_PENDING,
                'hold_reason' => null,
                'held_since' => null,
            ]);

            $service->dispatchFor($message);
            $released++;
        }

        $retried = 0;
        $refused = 0;

        foreach ($failed as $message) {
            $service->retry($message) ? $retried++ : $refused++;
        }

        foreach ($stranded as $message) {
            $message->update([
                'status' => OutboundMessage::STATUS_FAILED,
                'failed_at' => now(),
                'last_error' => $message->last_error ?: 'resté en attente sans résultat',
            ]);

            $service->retry($message) ? $retried++ : $refused++;
        }

        $this->info(sprintf(
            '%d released, %d retried, %d expired, %d left alone.',
            $released, $retried, $expired->count(), $refused
        ));

        if ($released + $retried + $expired->count() > 0) {
            // Unattended, so the log is the only place anyone will see this. A run that
            // releases eighty messages means the gateway was down all evening, and that
            // is worth knowing without reading a table.
            Log::info('Notification recovery sweep', [
                'released' => $released,
                'retried' => $retried,
                'expired' => $expired->count(),
                'refused' => $refused,
                'gateway' => $gateway,
            ]);
        }

        return self::SUCCESS;
    }
}
