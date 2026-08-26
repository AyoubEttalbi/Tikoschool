<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Membership;
use App\Models\Task;
use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;
use App\Support\SchoolScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * The teacher's home (/dashboard).
 *
 * Teachers used to be hard-bounced to their HR profile page — /dashboard was a
 * boomerang. This controller answers the questions a teacher actually has on
 * login: "what is my money doing?" and "what do I teach?".
 *
 * Money numbers are resolved through the EMAIL identity join (never a
 * teacher_id parameter) and read exclusively from append-only surfaces: the
 * wallet column (cached projection) plus teacher_wallet_entries for movement
 * history — the same ledger discipline as TeacherWalletService.
 *
 * The pending-commissions definition of "mine" is teacher_membership_payments,
 * NOT the classes_teacher pivot: a teacher can hold a commission on a student
 * whose class they do not pivot-teach. Mixing the two definitions is what made
 * earnings-table actions 403 on their own rows.
 */
class TeacherDashboardController extends Controller
{
    /** French copy for ledger reasons — the frontend stays dumb. Keys are the model's constants. */
    private const REASON_LABELS = [
        TeacherWalletEntry::REASON_IMMEDIATE => 'Commission facture',
        TeacherWalletEntry::REASON_RECONCILE => 'Régularisation',
        TeacherWalletEntry::REASON_MONTHLY => 'Commission mensuelle',
        TeacherWalletEntry::REASON_REVERSAL => 'Annulation',
        TeacherWalletEntry::REASON_PAYOUT => 'Paiement reçu',
        TeacherWalletEntry::REASON_ADJUSTMENT => 'Ajustement',
    ];

    public function index(Request $request)
    {
        // Before any try/catch: a denial must surface as 403, not a redirect.
        SchoolScope::authorizeRole(['teacher']);

        $user = Auth::user();
        $teacher = Teacher::where('email', $user->email)->first();

        // RoleRedirect guards this path, but never render money for a row we
        // cannot resolve — defense in depth over the orphaned-login edge.
        if (! $teacher) {
            return redirect()->route('profiles.select')
                ->with('error', "Aucun profil enseignant n'est relié à ce compte.");
        }

        $commissions = $this->activeCommissions($teacher);
        $pending = $commissions->filter(fn ($row) => count($row['months']) > 0)->values();

        return Inertia::render('Menu/TeacherDashboard', [
            'identity' => $this->identity($user, $teacher),
            'kpis' => [
                'wallet' => $this->walletKpis($teacher),
                // One load feeds both the KPI aggregate and the queue list.
                'pendingCommissions' => [
                    'count' => $pending->count(),
                    'total' => round($pending->sum('estimated'), 2),
                ],
                'myStudents' => $this->myStudentsCount($teacher),
                'myClasses' => $this->myClassesCount($user),
            ],
            'queue' => [
                'recentLedger' => $this->recentLedger($teacher),
                // Biggest money first: which commission matters most.
                'pendingCommissionsList' => $pending
                    ->sortByDesc('estimated')
                    ->take(5)
                    ->map(fn ($row) => [
                        'student_name' => $row['student_name'],
                        'months_count' => count($row['months']),
                        'estimated' => $row['estimated'],
                    ])
                    ->values()
                    ->all(),
                'openTasks' => $this->openTasks(),
            ],
            'announcements' => $this->announcements(),
        ]);
    }

    private function identity($user, Teacher $teacher): array
    {
        return [
            'name' => $teacher->first_name ?: $user->name,
            'status' => $teacher->status,
            // Multi-school teachers get the "Changer d'école" chip.
            'canSwitchSchool' => $teacher->schools()->count() > 1,
            // The cockpit's identity chip links here; CanViewTeacherProfile
            // already guarantees self-access.
            'profileUrl' => route('teachers.show', $teacher->id),
        ];
    }

    /**
     * Balance plus the movement context that makes it meaningful: what this
     * month added versus last month. (The 12-week sparkline was removed from
     * the card by product decision — the ledger history on « Mes paiements »
     * carries the detail.)
     */
    private function walletKpis(Teacher $teacher): array
    {
        $now = now();

        $netBetween = function ($start, $end) use ($teacher) {
            return round((float) TeacherWalletEntry::where('teacher_id', $teacher->id)
                ->whereBetween('created_at', [$start, $end])
                ->sum('amount'), 2);
        };

        return [
            'balance' => (float) $teacher->wallet,
            'monthNet' => $netBetween($now->copy()->startOfMonth(), $now->copy()->endOfMonth()),
            'prevMonthNet' => $netBetween(
                $now->copy()->subMonth()->startOfMonth(),
                $now->copy()->subMonth()->endOfMonth()
            ),
        ];
    }

    /**
     * Active commission rows enriched with their unpaid months and per-row
     * estimate. One source for both the KPI aggregate and the list.
     */
    private function activeCommissions(Teacher $teacher)
    {
        return TeacherMembershipPayment::query()
            ->forTeacher($teacher->id)
            ->where('is_active', true)
            ->with('student:id,firstName,lastName')
            ->get()
            ->map(function ($row) {
                $months = $row->months_rest_not_paid_yet ?? [];

                return [
                    'months' => $months,
                    'estimated' => round((float) $row->monthly_teacher_amount * count($months), 2),
                    'student_name' => $row->student
                        ? $row->student->firstName.' '.$row->student->lastName
                        : null,
                ];
            });
    }

    /**
     * Students tied to this teacher through MEMBERSHIPS — including unpaid
     * ones. Product decision: the profile's « Nombre d'élèves » is the
     * canonical definition (a teacher is responsible for students their
     * memberships name, whether or not a class pivot exists), so the cockpit
     * shows the same number, not the class-roster count.
     */
    private function myStudentsCount(Teacher $teacher): int
    {
        return Membership::withTrashed()
            ->whereJsonContains('teachers', [['teacherId' => (string) $teacher->id]])
            ->distinct('student_id')
            ->count('student_id');
    }

    private function myClassesCount($user): int
    {
        return count(SchoolScope::classIdsFor($user) ?? []);
    }

    /**
     * Latest ledger movements — credits AND debits, labeled. The full filterable
     * history lives on « Mes paiements »; this is the pulse.
     */
    private function recentLedger(Teacher $teacher, int $limit = 5): array
    {
        return TeacherWalletEntry::query()
            ->where('teacher_id', $teacher->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn ($entry) => [
                'id' => $entry->id,
                'label' => self::REASON_LABELS[$entry->reason] ?? $entry->reason,
                'reason' => $entry->reason,
                'amount' => (float) $entry->amount,
                'balance_after' => (float) $entry->balance_after,
                'date' => optional($entry->created_at)->format('Y-m-d'),
            ])
            ->all();
    }

    /** Same "what is mine" rule as the board: cards assigned to me. */
    private function openTasks(int $limit = 4): array
    {
        $user = Auth::user();
        $schoolId = session('school_id');
        if (! $schoolId || ! $user) {
            return [];
        }

        return Task::query()
            ->with('assignee:id,name')
            ->whereIn('status', ['todo', 'in_progress'])
            ->where('school_id', $schoolId)
            ->where('assigned_to', $user->id)
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
            ])
            ->all();
    }

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
            ->whereIn('visibility', ['all', 'teacher'])
            ->orderByDesc('date_announcement')
            ->limit($limit)
            ->get(['id', 'title', 'content', 'date_announcement'])
            ->all();
    }
}
