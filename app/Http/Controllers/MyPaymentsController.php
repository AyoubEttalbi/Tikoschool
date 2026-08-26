<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;
use App\Models\Transaction;
use App\Support\TeacherEarnings;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * « Mes paiements » — one payroll surface for BOTH staff roles.
 *
 * Assistants read their salary payouts (transactions keyed by user_id).
 * Teachers additionally get their commission wallet: the cached balance plus
 * the teacher_wallet_entries ledger behind it — the append-only truth, not a
 * derived view.
 *
 * Identity resolves through the email join for teachers; no teacher_id is ever
 * accepted from the request, so this surface can only ever show the caller's
 * own money. Admins are refused at the route (RequireRole) with a 403 — they
 * manage payroll from /transactions.
 */
class MyPaymentsController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $isTeacher = $user->role === 'teacher';
        $teacher = $isTeacher
            ? Teacher::where('email', $user->email)->first()
            : null;

        if ($isTeacher && ! $teacher) {
            // Orphaned login guard, same contract as RoleRedirect.
            return redirect()->route('profiles.select')
                ->with('error', "Aucun profil enseignant n'est relié à ce compte.");
        }

        $transactions = $this->payouts($user);

        $wallet = [
            'roleView' => $isTeacher ? 'teacher' : 'assistant',
            'balance' => $teacher ? (float) $teacher->wallet : null,
        ];

        $ledger = collect();
        $ledgerPage = null;
        $gainsPage = null;
        if ($teacher) {
            // Paginated ledger: the history grows forever, so 15 per page with
            // real LengthAwarePaginator metadata (the page renders prev/next).
            // Named page param: gains and ledger paginate independently.
            $ledgerPaginator = TeacherWalletEntry::query()
                ->where('teacher_id', $teacher->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(15, ['*'], 'ledger_page');

            $ledgerPage = [
                'data' => $ledgerPaginator->getCollection()
                    ->map(fn ($entry) => [
                        'id' => $entry->id,
                        'reason' => $entry->reason,
                        'label' => $this->reasonLabel($entry->reason),
                        'amount' => (float) $entry->amount,
                        'balance_after' => (float) $entry->balance_after,
                        'month' => $entry->month,
                        'note' => $entry->note,
                        'date' => optional($entry->created_at)->format('Y-m-d'),
                    ])
                    ->all(),
                'current_page' => $ledgerPaginator->currentPage(),
                'last_page' => $ledgerPaginator->lastPage(),
                'total' => $ledgerPaginator->total(),
            ];

            // The "if administration paid today" line under the balance.
            $pending = TeacherMembershipPayment::query()
                ->forTeacher($teacher->id)
                ->where('is_active', true)
                ->get()
                ->sum(fn ($row) => round(
                    (float) $row->monthly_teacher_amount * count($row->months_rest_not_paid_yet ?? []),
                    2
                ));
            $wallet['estimatedPending'] = round((float) $pending, 2);

            // « Mes gains » — the SAME per-month share rows as the profile's
            // gains table (TeacherEarnings is the single source), with the same
            // filter logic, summary stats and pagination. Deliberately a
            // separate section from payouts: gains = what the invoices earned;
            // payouts = what administration actually handed over.
            $allRows = TeacherEarnings::monthlyRows($teacher)
                ->sortByDesc('created_at')
                ->values();

            // Dropdown universes from ALL rows — a filter must not shrink its
            // own options (same rule as TeacherController::show).
            $gainsOptions = [
                'classes' => $allRows->pluck('student_class')->filter()->unique()->sort()->values()->all(),
                'offers' => $allRows->pluck('offer_name')->filter()->unique()->sort()->values()->all(),
                'schools' => $allRows->pluck('student_school')->filter()->unique()->sort()->values()->all(),
                'months' => $allRows->pluck('billDate')->filter()
                    ->map(fn ($d) => substr($d, 0, 7))
                    ->unique()->sortDesc()->values()->all(),
            ];

            $gainsFilters = [
                'search' => (string) request()->query('search', ''),
                'class_filter' => (string) request()->query('class_filter', 'all'),
                'offer_filter' => (string) request()->query('offer_filter', 'all'),
                'school_filter' => (string) request()->query('school_filter', 'all'),
                'date_filter' => (string) request()->query('date_filter', 'all'),
                'payment_status_filter' => (string) request()->query('payment_status_filter', 'all'),
            ];

            $rows = $this->filterGainsRows($allRows, $gainsFilters);

            // Summary cards — computed on the FILTERED set, like the profile page,
            // so the numbers always answer "what am I looking at right now".
            // NOTE: str_starts_with, NOT Collection::where(...,'like',...) —
            // 'like' is not a collection operator and silently matched nothing,
            // which pinned « Ce mois-ci » to zero forever.
            $currentMonthPrefix = now()->format('Y-m');
            $bestOffer = $rows->filter(fn ($r) => filled($r['offer_name']))
                ->groupBy('offer_name')
                ->map(fn ($group, $name) => ['name' => $name, 'amount' => round((float) $group->sum('teacher_amount'), 2)])
                ->sortByDesc('amount')
                ->first();
            $monthlyTotals = $rows->groupBy(fn ($r) => substr((string) $r['billDate'], 0, 7))
                ->map(fn ($group, $month) => [
                    'month' => $month,
                    'label' => date('m-Y', strtotime($month.'-01')),
                    'total' => round((float) $group->sum('teacher_amount'), 2),
                ])
                ->sortDesc()
                ->values()
                ->all();

            $gainsStats = [
                'total_gains' => round((float) $rows->sum('teacher_amount'), 2),
                'current_month_gains' => round(
                    (float) $rows->filter(fn ($r) => str_starts_with((string) $r['billDate'], $currentMonthPrefix))->sum('teacher_amount'),
                    2
                ),
                'unique_students' => $rows->pluck('student_id')->unique()->count(),
                'pending_months' => $rows->filter(fn ($r) => ! ($r['is_month_paid'] ?? false))->count(),
                'best_offer' => $bestOffer ?? null,
            ];

            $gainsPaginator = new LengthAwarePaginator(
                $rows->forPage(request()->input('gains_page', 1), 10)->values(),
                $rows->count(),
                10,
                request()->input('gains_page', 1),
                ['path' => route('staff.my-payments'), 'query' => array_filter($gainsFilters)]
            );
            $gainsPage = [
                'data' => $gainsPaginator->items(),
                'current_page' => $gainsPaginator->currentPage(),
                'last_page' => $gainsPaginator->lastPage(),
                'total' => $gainsPaginator->total(),
            ];
        }

        return Inertia::render('Menu/MyPaymentsPage', [
            'transactions' => $transactions,
            'userId' => $user?->id,
            'wallet' => $wallet,
            'ledger' => $ledgerPage ?? [],
            'gains' => $gainsPage,
            'gainsFilters' => $gainsFilters ?? [],
            'gainsOptions' => $gainsOptions ?? [],
            'gainsStats' => $gainsStats ?? [],
            'gainsMonths' => $monthlyTotals ?? [],
        ]);
    }

    /**
     * The gains-table filter logic, mirroring TeacherController::show row for
     * row: search on student name, class/offer/school exact match, month
     * prefix match on the bill date, and the Payé/En attente toggle.
     */
    private function filterGainsRows($rows, array $filters)
    {
        return $rows->filter(function ($row) use ($filters) {
            if ($filters['search'] !== '' && stripos((string) ($row['student_name'] ?? ''), $filters['search']) === false) {
                return false;
            }

            if ($filters['class_filter'] !== 'all' && ($row['student_class'] ?? '') !== $filters['class_filter']) {
                return false;
            }

            if ($filters['offer_filter'] !== 'all' && ($row['offer_name'] ?? '') !== $filters['offer_filter']) {
                return false;
            }

            if ($filters['school_filter'] !== 'all' && ($row['student_school'] ?? '') !== $filters['school_filter']) {
                return false;
            }

            if ($filters['date_filter'] !== 'all'
                && strpos((string) ($row['billDate'] ?? ''), $filters['date_filter']) !== 0) {
                return false;
            }

            if ($filters['payment_status_filter'] === 'paid' && ! ($row['is_month_paid'] ?? false)) {
                return false;
            }
            if ($filters['payment_status_filter'] === 'pending' && ($row['is_month_paid'] ?? false)) {
                return false;
            }

            return true;
        })->values();
    }

    /** Payout rows, with the recurring-paid-this-month badge rule preserved. */
    private function payouts($user)
    {
        $transactions = Transaction::where('user_id', $user->id)
            ->orderByDesc('payment_date')
            ->get();

        $currentMonth = now()->format('Y-m');
        $startDate = \Carbon\Carbon::parse($currentMonth.'-01')->startOfMonth();
        $endDate = \Carbon\Carbon::parse($currentMonth.'-01')->endOfMonth();

        $transactions->each(function ($transaction) use ($startDate, $endDate) {
            if ($transaction->is_recurring) {
                $transaction->paid_this_month = Transaction::where('is_recurring', 0)
                    ->where('description', 'like', '%(Recurring payment from #'.$transaction->id.')%')
                    ->whereBetween('payment_date', [$startDate, $endDate])
                    ->exists();
            }
        });

        return $transactions;
    }

    private function reasonLabel(string $reason): string
    {
        return match ($reason) {
            TeacherWalletEntry::REASON_IMMEDIATE => 'Commission facture',
            TeacherWalletEntry::REASON_RECONCILE => 'Régularisation',
            TeacherWalletEntry::REASON_MONTHLY => 'Commission mensuelle',
            TeacherWalletEntry::REASON_REVERSAL => 'Annulation',
            TeacherWalletEntry::REASON_PAYOUT => 'Paiement reçu',
            TeacherWalletEntry::REASON_ADJUSTMENT => 'Ajustement',
            default => $reason,
        };
    }
}
