<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Assistant;
use App\Models\Invoice;
use App\Models\InvoicePaymentLog;
use App\Models\Membership;
use App\Models\Task;
use App\Support\SchoolScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * The assistant's home (/dashboard).
 *
 * Assistants used to inherit the owner-level analytics dashboard and were bounced
 * to their own HR profile instead. This controller answers the question an
 * assistant actually has on login: "what needs me today?" — pending absence
 * notices, unpaid invoices, memberships about to lapse, cash taken today.
 *
 * Every number is scoped through SchoolScope::schoolIdsFor(), mirroring
 * CashierController: today's money is read from invoice_payment_logs (payment
 * events), never from invoices.amountPaid, which a later payment would rewrite.
 */
class AssistantDashboardController extends Controller
{
    public function index(Request $request)
    {
        // Before any try/catch: a denial must surface as 403, not as a redirect.
        SchoolScope::authorizeRole(['assistant']);

        $user = Auth::user();
        $schoolIds = SchoolScope::schoolIdsFor($user) ?? [];

        $today = Carbon::today();

        return Inertia::render('Menu/AssistantDashboard', [
            'identity' => $this->identity($user),
            'kpis' => [
                'unpaidInvoices' => $this->unpaidKpis($schoolIds),
                'expiringMemberships' => [
                    'count' => $this->expiringQuery($schoolIds, $today)->count(),
                ],
                'todayCash' => $this->cashKpis($schoolIds, $today),
            ],
            'queue' => [
                // The work queue: what needs attention NOW, in priority order.
                'pendingNotices' => $this->pendingNoticesList($user),
                'unpaidInvoices' => $this->unpaidList($schoolIds),
                'expiringMemberships' => $this->expiringList($schoolIds, $today),
                'openTasks' => $this->openTasks(),
            ],
            'announcements' => $this->announcements(),
        ]);
    }

    /**
     * Who the greeting is for. The full profile stays on assistants.show; this is
     * one line of context plus the school switcher flag.
     */
    private function identity($user): array
    {
        $assistant = Assistant::where('email', $user->email)->first();

        return [
            'name' => $assistant?->first_name ?: $user->name,
            'status' => $assistant?->status,
            // Multi-school assistants get the "Changer d'école" chip; single-school
            // ones have nothing to switch to.
            'canSwitchSchool' => $assistant ? $assistant->schools()->count() > 1 : false,
        ];
    }

    /** Unpaid = partially paid or untouched, same derived rule as AssistantController::show. */
    private function unpaidBaseQuery(array $schoolIds)
    {
        return Invoice::query()
            // withTrashed on the eager load too: without it a withdrawn student's
            // invoice is counted by the KPI but rendered as "Unknown" in the list.
            ->with([
                'student' => fn ($studentQuery) => $studentQuery->withTrashed()->with(['class', 'school']),
                'offer',
            ])
            ->where('type', 'invoice')
            ->whereHas('student', function ($studentQuery) use ($schoolIds) {
                // whereIn([]) matches nothing by itself — an assistant with no schools
                // sees zeros, never another school's rows.
                $studentQuery->withTrashed()->whereIn('schoolId', $schoolIds);
            })
            ->where(function ($query) {
                $query->whereRaw('COALESCE(rest, 0) > 0')
                    ->orWhereRaw('COALESCE(totalAmount, 0) > COALESCE(amountPaid, 0)');
            });
    }

    private function unpaidKpis(array $schoolIds): array
    {
        $aggregate = (clone $this->unpaidBaseQuery($schoolIds))
            ->selectRaw('COUNT(*) as n')
            ->selectRaw('COALESCE(SUM(GREATEST(COALESCE(rest, 0), COALESCE(totalAmount, 0) - COALESCE(amountPaid, 0))), 0) as rest')
            ->first();

        return [
            'count' => (int) ($aggregate->n ?? 0),
            // The rest column can lag behind totalAmount - amountPaid; the KPI shows
            // what is truly owed per invoice, like the profile page computes it.
            'totalRest' => round((float) ($aggregate->rest ?? 0), 2),
        ];
    }

    /** Top of the pile only — the full list lives on the invoices index page. */
    private function unpaidList(array $schoolIds, int $limit = 5): array
    {
        return $this->unpaidBaseQuery($schoolIds)
            ->orderByDesc('creationDate')
            ->orderByDesc('billDate')
            ->limit($limit)
            ->get()
            ->map(fn (Invoice $invoice) => $this->unpaidRow($invoice))
            ->all();
    }

    /** Row shape shared with SingleAssistantPage so InvoiceModal keeps working. */
    private function unpaidRow(Invoice $invoice): array
    {
        $student = $invoice->student;
        $total = is_numeric($invoice->totalAmount) ? (float) $invoice->totalAmount : 0.0;
        $paid = is_numeric($invoice->amountPaid) ? (float) $invoice->amountPaid : 0.0;

        return [
            'id' => $invoice->id,
            'student_id' => $student?->id,
            'student_name' => $student ? $student->firstName.' '.$student->lastName : 'Unknown',
            'student_class' => $student && $student->class ? $student->class->name : null,
            'billDate' => $invoice->billDate?->format('Y-m-d'),
            'totalAmount' => $total,
            'amountPaid' => $paid,
            'rest' => max(0.0, round($total - $paid, 2)),
            'offer_name' => $invoice->offer?->offer_name,
        ];
    }

    /** Expired first, then due within 3 days — the order the work is urgent in. */
    private function expiringQuery(array $schoolIds, Carbon $today)
    {
        return Membership::query()
            ->with('student')
            ->whereNotNull('end_date')
            ->whereHas('student', function ($studentQuery) use ($schoolIds) {
                $studentQuery->whereIn('schoolId', $schoolIds);
            })
            // Bounded window: without the lower bound every membership expired since
            // the beginning of time would pile into the KPI forever. Thirty days back
            // keeps genuinely lapsed renewals visible while old history stays out.
            ->whereBetween('end_date', [
                $today->copy()->subDays(30)->toDateString(),
                $today->copy()->addDays(7)->toDateString(),
            ])
            ->orderByRaw(
                'CASE WHEN end_date < ? THEN 0 WHEN end_date <= ? THEN 1 ELSE 2 END',
                [$today->toDateString(), $today->copy()->addDays(3)->toDateString()]
            )
            ->orderBy('end_date');
    }

    private function expiringList(array $schoolIds, Carbon $today, int $limit = 5): array
    {
        return $this->expiringQuery($schoolIds, $today)
            ->limit($limit)
            ->get()
            ->map(function (Membership $membership) use ($today) {
                $endDate = Carbon::parse($membership->end_date);
                $daysLeft = $today->diffInDays($endDate, false);

                return [
                    'id' => $membership->id,
                    'student_id' => $membership->student?->id,
                    'student_name' => $membership->student
                        ? $membership->student->firstName.' '.$membership->student->lastName
                        : 'Unknown',
                    'end_date' => $endDate->format('Y-m-d'),
                    'days_left' => max(0, round($daysLeft)),
                    'urgency' => $daysLeft < 0 ? 'expired' : ($daysLeft <= 3 ? 'due_soon' : 'upcoming'),
                ];
            })
            ->all();
    }

    /**
     * Today's cash from payment events — the CashierController pipeline, scoped.
     * Summing invoices.amountPaid here would let tomorrow's payments rewrite today.
     */
    private function cashKpis(array $schoolIds, Carbon $today): array
    {
        $eventsFor = fn ($date) => InvoicePaymentLog::query()
            ->notVoided()
            ->whereDate('paid_at', $date)
            ->whereHas('student', function ($studentQuery) use ($schoolIds) {
                $studentQuery->whereIn('schoolId', $schoolIds);
            });

        $todayEvents = $eventsFor($today);

        return [
            'total' => round((float) $todayEvents->sum('amount'), 2),
            'payments' => (clone $todayEvents)->count(),
            'yesterdayTotal' => round((float) $eventsFor($today->copy()->subDay())->sum('amount'), 2),
        ];
    }

    /** Active-window announcements visible to assistants — same rule as the shared feeds. */
    private function announcements(int $limit = 5): array
    {
        $now = now();

        return Announcement::query()
            ->where(function ($query) use ($now) {
                $query->whereNull('date_start')->orWhere('date_start', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('date_end')->orWhere('date_end', '>=', $now);
            })
            ->whereIn('visibility', ['all', 'assistant'])
            ->orderByDesc('date_announcement')
            ->limit($limit)
            ->get(['id', 'title', 'content', 'date_announcement'])
            ->all();
    }

    /**
     * Absence notices waiting for approval — the top of the work queue. The shared
     * pendingNoticesCount prop carries the number; this is what to actually review.
     * Released/declined rows are gone from here, so the section empties as it is worked.
     */
    private function pendingNoticesList($user, int $limit = 5): array
    {
        $schoolIds = SchoolScope::schoolIdsFor($user) ?? [];
        if ($schoolIds === []) {
            return [];
        }

        return \App\Models\OutboundMessage::query()
            ->awaitingApproval()
            ->whereHas('attendance')
            ->with('attendance.student:id,firstName,lastName')
            ->whereIn('school_id', $schoolIds)
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->map(function ($message) {
                $student = $message->attendance?->student;

                return [
                    'id' => $message->id,
                    'student_name' => $student
                        ? $student->firstName.' '.$student->lastName
                        : 'Élève inconnu',
                    'created_at' => optional($message->created_at)->format('Y-m-d'),
                ];
            })
            ->all();
    }

    /**
     * Open tasks for the viewer — the cockpit links straight into them. An
     * assistant's cockpit lists THEIR cards only (the board's rule: "what is
     * mine"), not the whole school's queue.
     */
    private function openTasks(int $limit = 4): array
    {
        $user = Auth::user();
        $schoolId = session('school_id');
        if (! $schoolId || ! $user) {
            return [];
        }

        return Task::query()
            ->whereIn('status', ['todo', 'in_progress'])
            ->where('school_id', $schoolId)
            ->when($user->role !== 'admin', fn ($q) => $q->where('assigned_to', $user->id))
            ->orderByRaw("CASE priority WHEN 'high' THEN 0 ELSE 1 END")
            ->orderBy('due_date')
            ->limit($limit)
            ->get()
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'priority' => $task->priority,
                'due_date' => $task->due_date?->format('Y-m-d'),
                'assigned_to_name' => $task->assignee?->name,
            ])
            ->all();
    }
}
