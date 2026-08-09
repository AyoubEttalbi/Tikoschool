<?php

namespace App\Http\Controllers;

use App\Models\OutboundMessage;
use App\Services\OutboundMessageService;
use App\Support\SchoolScope;
use App\Support\WhatsAppGateway;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

/**
 * "Was this parent told?"
 *
 * That is the only question a school actually asks about notifications, and until this
 * screen existed it had no answer — the outcome lived in a queue that had already drained
 * and a log file nobody opens. A notification table without a way to look at it is a
 * write-only table.
 */
class OutboundMessageController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'date' => 'nullable|date',
            'status' => 'nullable|in:all,pending,held,sent,failed,skipped,expired',
        ]);

        $date = ! empty($filters['date']) ? $filters['date'] : now()->toDateString();
        $status = $filters['status'] ?? 'all';

        $query = OutboundMessage::query()
            ->with(['student:id,firstName,lastName,classId,guardianNumber'])
            ->whereDate('created_at', $date);

        /*
         * TWO different things, and only the first is a security boundary.
         *
         * schoolIdsFor() is what the caller is ALLOWED to see. session('school_id') is
         * which of those they have currently picked in the UI — a preference, chosen by
         * the caller, that narrows the view but authorises nothing. TeacherController
         * carries the same note; without the hard scope, one cleared session key would
         * hand an assistant every school's children and their absences.
         */
        $allowedSchoolIds = SchoolScope::schoolIdsFor();
        if ($allowedSchoolIds !== null) {
            $query->whereIn('school_id', $allowedSchoolIds);
        }

        $schoolId = session('school_id');
        if ($schoolId) {
            $query->where('school_id', $schoolId);
        }

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $messages = $query->latest('id')->paginate(30)->withQueryString();

        $messages->through(fn (OutboundMessage $m) => [
            'id' => $m->id,
            'student_id' => $m->student_id,
            'studentName' => $m->student
                ? trim($m->student->firstName.' '.$m->student->lastName)
                : 'Élève supprimé',
            'type' => $m->type,
            'channel' => $m->channel,
            'status' => $m->status,
            // A readable sentence, never the provider's raw response — that leaks gateway
            // internals onto a staff screen and helps nobody.
            'reason' => $m->reason(),
            'attempts' => $m->attempts,
            'scheduledAt' => $m->scheduled_at?->toIso8601String(),
            'heldSince' => $m->held_since?->toIso8601String(),
            'sentAt' => $m->sent_at?->toIso8601String(),
            'createdAt' => $m->created_at?->toIso8601String(),
            // Matches OutboundMessageService::retry()'s allow-list. Offering it on a
            // PENDING row invited a second job for a message already queued.
            // Matches OutboundMessageService::retry()'s allow-list. NOT offered on a
            // pending row (a job is already coming) and NOT on a held one — those are
            // released automatically the moment the gateway returns, and a manual nudge
            // would only re-hold them.
            'canRetry' => in_array($m->status, [
                OutboundMessage::STATUS_FAILED,
                OutboundMessage::STATUS_SKIPPED,
                OutboundMessage::STATUS_EXPIRED,
            ], true),
            // NOTE: `recipient` and `message` are deliberately absent. They are $hidden on
            // the model and are not added back here — the guardian's phone number and the
            // child's absence do not need to travel to the browser for staff to see that
            // a notice went out.
        ]);

        // Counts for the day, before the status filter, so the tabs do not depend on
        // which tab is open.
        $base = OutboundMessage::whereDate('created_at', $date);
        if ($schoolId) {
            $base->where('school_id', $schoolId);
        }

        $counts = $base->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        // Held rows are counted across ALL days, not just the one being viewed: a backlog
        // from a four-day outage is the thing an administrator most needs to see, and it
        // would be invisible on a screen that only ever shows today.
        $heldTotal = OutboundMessage::where('status', OutboundMessage::STATUS_HELD)->count();

        return Inertia::render('Menu/NotificationsPage', [
            'messages' => $messages,
            'filters' => ['date' => $date, 'status' => $status],
            'gateway' => $this->gatewayState(),
            'queue' => $this->queueState(),
            'service' => [
                'minSeconds' => (int) config('whatsapp.min_seconds_between'),
                'jitter' => (int) config('whatsapp.jitter_seconds'),
                'dailyCap' => (int) config('whatsapp.daily_cap'),
                'sentToday' => \App\Support\WhatsAppPacer::sentToday(),
                'from' => (string) config('school.notify_from'),
                'until' => (string) config('school.notify_until'),
                'maxAgeDays' => (int) config('whatsapp.max_age_days'),
                'maxNumberFailures' => (int) config('whatsapp.max_number_failures'),
            ],
            'counts' => [
                'sent' => (int) ($counts['sent'] ?? 0),
                'pending' => (int) ($counts['pending'] ?? 0),
                'held' => $heldTotal,
                'failed' => (int) ($counts['failed'] ?? 0),
                'skipped' => (int) ($counts['skipped'] ?? 0),
                'expired' => (int) ($counts['expired'] ?? 0),
            ],
        ]);
    }

    /**
     * Put a failed or skipped notice back in the queue.
     *
     * The scope check is BEFORE any try/catch on purpose. CLAUDE.md documents the trap:
     * abort(403) throws HttpException, which extends \Exception, so a generic catch turns
     * a denial into a harmless redirect — a guard that silently does nothing.
     */
    public function retry(OutboundMessage $message, OutboundMessageService $service)
    {
        if ($message->student) {
            SchoolScope::authorizeStudent($message->student);
        } else {
            SchoolScope::authorizeRole(['admin']);
        }

        if ($service->retry($message)) {
            return back()->with('success', 'Notification remise en file d\'attente.');
        }

        return back()->with('error', $message->reason() ?: 'Cette notification ne peut pas être renvoyée.');
    }

    /**
     * Unlink the phone.
     *
     * The one destructive control on this screen, so it is admin-only (the whole page is)
     * and it says what it costs: until somebody stands in front of this page with the
     * school phone and scans a new code, nothing is delivered. Messages are HELD in the
     * meantime rather than failed, so nothing is lost — but nothing arrives either.
     */
    public function disconnect()
    {
        $config = config('whatsapp.evolution');

        if (config('whatsapp.driver') !== 'evolution') {
            return back()->with('error', 'Aucune passerelle à déconnecter (WHATSAPP_DRIVER='.config('whatsapp.driver').').');
        }

        try {
            $response = Http::withHeaders(['apikey' => (string) $config['api_key']])
                ->timeout(10)
                ->post(rtrim($config['base_url'], '/').'/logout');
        } catch (\Throwable $e) {
            return back()->with('error', 'Passerelle injoignable — déconnexion impossible.');
        }

        // The cached state is now a lie, and this page is about to re-read it.
        WhatsAppGateway::forget();

        /*
         * A 404 is not a refusal, and saying "refused" sent somebody looking for a
         * permission problem that did not exist. The gateway answered — it simply has no
         * /logout route, which means the process running is older than the code on disk.
         * Restarting it is the fix, and the message has to say so, because nothing else
         * about the symptom points there.
         */
        if ($response->status() === 404) {
            return back()->with('error', 'Cette passerelle ne gère pas la déconnexion à distance. Le service doit être redémarré pour prendre en compte sa dernière version.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return back()->with('error', 'La passerelle a rejeté la clé d\'accès (HTTP '.$response->status().'). Vérifiez EVOLUTION_API_KEY.');
        }

        if ($response->failed()) {
            return back()->with('error', 'La passerelle a refusé la déconnexion (HTTP '.$response->status().').');
        }

        return back()->with('success', 'WhatsApp déconnecté. Scannez un nouveau code pour reprendre les envois.');
    }

    /**
     * Ask the gateway for a fresh QR code.
     *
     * The missing half of the pair. `disconnect` had a button and this had nothing, so once
     * WhatsApp revoked the pairing — which happens on its own if a code goes unscanned long
     * enough — the screen showed "Déconnectée" and offered no way back. The gateway does not
     * retry a logged-out session by design (the stored credentials are dead and reusing them
     * fails identically forever), so somebody had to redeploy the container to get a code.
     */
    public function connect()
    {
        $config = config('whatsapp.evolution');

        if (config('whatsapp.driver') !== 'evolution') {
            return back()->with('error', 'Aucune passerelle à connecter (WHATSAPP_DRIVER='.config('whatsapp.driver').').');
        }

        try {
            $response = Http::withHeaders(['apikey' => (string) $config['api_key']])
                // Longer than the status probe: this tears a socket down and opens a new
                // one, and answering slowly is not the same as being broken.
                ->timeout(20)
                ->post(rtrim($config['base_url'], '/').'/connect');
        } catch (\Throwable $e) {
            return back()->with('error', 'Passerelle injoignable — le service WhatsApp est-il démarré ?');
        }

        // The cached state is now a lie, and this page is about to re-read it.
        WhatsAppGateway::forget();

        if ($response->status() === 404) {
            return back()->with('error', 'Cette passerelle ne gère pas la reconnexion à distance. Le service doit être redémarré pour prendre en compte sa dernière version.');
        }

        if ($response->status() === 401 || $response->status() === 403) {
            return back()->with('error', 'La passerelle a rejeté la clé d\'accès (HTTP '.$response->status().'). Vérifiez EVOLUTION_API_KEY.');
        }

        if ($response->failed()) {
            return back()->with('error', 'La passerelle n\'a pas pu ouvrir de session (HTTP '.$response->status().').');
        }

        if ($response->json('state') === 'open') {
            return back()->with('success', 'WhatsApp est déjà connecté.');
        }

        // Baileys needs a moment to negotiate before it emits a code, so this promises the
        // code rather than claiming one is already on screen.
        return back()->with('success', 'Connexion demandée. Le code QR apparaît dans quelques secondes.');
    }

    /**
     * Whether the gateway is actually linked to the school's phone.
     *
     * This is the single most useful fact on the screen and the app had no way to know it.
     * A logged-out gateway fails every send with a perfectly ordinary error, so the first
     * sign is a parent complaining a week later. It is fetched server-side, never from the
     * browser: the API key stays on the server, and the QR is a CREDENTIAL — whoever scans
     * it links their own device to the school's WhatsApp and can read every conversation
     * on it.
     */
    private function gatewayState(): array
    {
        $config = config('whatsapp.evolution');
        $driver = config('whatsapp.driver');

        if ($driver !== 'evolution') {
            return [
                'driver' => $driver,
                'state' => 'disabled',
                'qr' => null,
                'note' => $driver === 'log'
                    ? 'Les messages sont écrits dans le journal, pas envoyés (WHATSAPP_DRIVER=log).'
                    : "Passerelle WhatsApp désactivée (WHATSAPP_DRIVER={$driver}).",
            ];
        }

        try {
            $response = Http::withHeaders(['apikey' => (string) $config['api_key']])
                // Short: this runs inside a page render, and a hanging gateway must not
                // hang the screen that exists to tell you the gateway is hanging.
                ->timeout(4)
                ->acceptJson()
                ->get(rtrim($config['base_url'], '/').'/qr.json');
        } catch (\Throwable $e) {
            return [
                'driver' => $driver,
                'state' => 'unreachable',
                'qr' => null,
                'note' => 'Passerelle injoignable. Est-elle démarrée ?',
            ];
        }

        if ($response->failed()) {
            return [
                'driver' => $driver,
                'state' => 'unreachable',
                'qr' => null,
                'note' => 'Passerelle joignable mais elle refuse la clé (HTTP '.$response->status().').',
            ];
        }

        return [
            'driver' => $driver,
            'state' => (string) $response->json('state', 'unknown'),
            'qr' => $response->json('qr'),
            'instance' => $response->json('instance'),
            'note' => null,
        ];
    }

    /**
     * The queue behind the notifications, in the two numbers that matter.
     *
     * `waiting` above zero with nothing being delivered means no worker is consuming the
     * `whatsapp` queue — which is the silent failure mode here, because everything else
     * looks healthy: rows are created, statuses say pending, and nobody is ever told.
     * `failedJobs` is the other half: jobs that gave up entirely.
     */
    private function queueState(): array
    {
        $waiting = 0;
        $failed = 0;
        $stalledMinutes = 0;

        // Only meaningful on the database queue driver; wrapped because a Redis or SQS
        // deployment has no such tables and this panel must not take the page down.
        try {
            $waiting = DB::table('jobs')->where('queue', 'whatsapp')->count();
            $failed = DB::table('failed_jobs')
                ->where('payload', 'like', '%SendOutboundMessage%')
                ->where('failed_at', '>=', now()->subDays(7))
                ->count();

            /*
             * How long the oldest CLAIMABLE job has gone unclaimed — the one number that
             * distinguishes "busy" from "dead".
             *
             * `waiting` alone cannot: a healthy paced backlog and a queue with no worker
             * both show a positive count. The difference is that the pacer releases each
             * job with a fresh delay, so under a working worker the oldest claimable job
             * is always seconds old. Minutes here means nobody is listening, and every
             * one of those parents will simply never be told.
             */
            $now = now()->timestamp;

            $oldest = DB::table('jobs')
                ->where('queue', 'whatsapp')
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $now)
                ->min('available_at');

            if ($oldest) {
                $stalledMinutes = (int) floor(($now - (int) $oldest) / 60);
            }
        } catch (\Throwable) {
            // leave the counters at zero rather than break the screen
        }

        return [
            'waiting' => $waiting,
            'failedJobs' => $failed,
            'stalledMinutes' => $stalledMinutes,
            'connection' => (string) config('queue.default'),
        ];
    }
}
