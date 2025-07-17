<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\Assistant;
use App\Models\Invoice;
use App\Models\School;
use App\Models\Announcement;
use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use App\Models\Membership;
use App\Models\Transaction;

class StatsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
{
    $schoolId = $request->get('school_id');
    $month = $request->get('month');
    $membershipStats = $this->getMembershipStats($month, $schoolId);

    // Teacher counts
    $teacherCountsQuery = DB::table('school_teacher')
        ->select('school_id', DB::raw('COUNT(DISTINCT teacher_id) as count'));
    if ($schoolId) {
        $teacherCountsQuery->where('school_id', $schoolId);
    }
    $teacherCounts = $teacherCountsQuery->groupBy('school_id')->get()->keyBy('school_id');
    $totalTeacherCount = $schoolId
        ? DB::table('school_teacher')->where('school_id', $schoolId)->select(DB::raw('COUNT(DISTINCT teacher_id) as count'))->first()->count
        : DB::table('school_teacher')->select(DB::raw('COUNT(DISTINCT teacher_id) as count'))->first()->count;

    // Student counts
    $studentCountsQuery = Student::select('schoolId', DB::raw('COUNT(*) as count'));
    if ($schoolId) {
        $studentCountsQuery->where('schoolId', $schoolId);
    }
    $studentCounts = $studentCountsQuery->groupBy('schoolId')->get()->keyBy('schoolId');
    $totalStudentCount = $schoolId
        ? Student::where('schoolId', $schoolId)->count()
        : Student::count();

    // Assistant counts
    $assistantCountsQuery = DB::table('assistant_school')
        ->select('school_id', DB::raw('COUNT(DISTINCT assistant_id) as count'));
    if ($schoolId) {
        $assistantCountsQuery->where('school_id', $schoolId);
    }
    $assistantCounts = $assistantCountsQuery->groupBy('school_id')->get()->keyBy('school_id');
    $totalAssistantCount = $schoolId
        ? DB::table('assistant_school')->where('school_id', $schoolId)->select(DB::raw('COUNT(DISTINCT assistant_id) as count'))->first()->count
        : DB::table('assistant_school')->select(DB::raw('COUNT(DISTINCT assistant_id) as count'))->first()->count;

    // Schools list
    $schoolsList = School::select('id', 'name')->get();

    // Monthly incomes and expenses
    $now = Carbon::now();
    $start = $now->copy()->subMonths(11)->startOfMonth();
    $end = $now->copy()->endOfMonth();

    $incomeQuery = DB::table('invoices')
        ->join('students', 'invoices.student_id', '=', 'students.id')
        ->select(
            DB::raw('YEAR(billDate) as year'),
            DB::raw('MONTH(billDate) as month'),
            'students.schoolId as school_id',
            DB::raw('SUM(amountPaid) as income')
        )
        ->whereNull('invoices.deleted_at')
        ->whereColumn('invoices.amountPaid', '>=', 'invoices.totalAmount')
        ->whereBetween('billDate', [$start, $end]);
    if ($schoolId) {
        $incomeQuery->where('students.schoolId', $schoolId);
    }
    $incomeQuery = $incomeQuery
        ->groupBy('year', 'month', 'students.schoolId')
        ->orderBy('year')
        ->orderBy('month')
        ->get();

    $expenseRaw = DB::table('transactions')
        ->whereBetween('payment_date', [$start, $end])
        ->get();
    $expenseRows = [];
    foreach ($expenseRaw as $tx) {
        if ($tx->type === 'payment') {
            $user = \App\Models\User::find($tx->user_id);
            if ($user && $user->role === 'teacher' && $user->teacher) {
                $schools = $user->teacher->schools()->pluck('schools.id');
                foreach ($schools as $sid) {
                    $expenseRows[] = [
                        'year' => (int)date('Y', strtotime($tx->payment_date)),
                        'month' => (int)date('m', strtotime($tx->payment_date)),
                        'school_id' => $sid,
                        'expense' => (float)$tx->amount
                    ];
                }
            }
        } elseif ($tx->type === 'salary') {
            $user = \App\Models\User::find($tx->user_id);
            if ($user && $user->role === 'assistant' && $user->assistant) {
                $schools = $user->assistant->schools()->pluck('schools.id');
                foreach ($schools as $sid) {
                    $expenseRows[] = [
                        'year' => (int)date('Y', strtotime($tx->payment_date)),
                        'month' => (int)date('m', strtotime($tx->payment_date)),
                        'school_id' => $sid,
                        'expense' => (float)$tx->amount
                    ];
                }
            }
        } elseif ($tx->type === 'expense') {
            $expenseRows[] = [
                'year' => (int)date('Y', strtotime($tx->payment_date)),
                'month' => (int)date('m', strtotime($tx->payment_date)),
                'school_id' => null,
                'expense' => (float)$tx->amount
            ];
        }
    }
    if ($schoolId) {
        $expenseRows = array_filter($expenseRows, function($row) use ($schoolId) {
            return $row['school_id'] == $schoolId;
        });
    }
    $expenseGrouped = [];
    foreach ($expenseRows as $row) {
        $key = $row['year'] . '-' . str_pad($row['month'], 2, '0', STR_PAD_LEFT) . '-' . ($row['school_id'] ?? 'none');
        if (!isset($expenseGrouped[$key])) {
            $expenseGrouped[$key] = [
                'year' => $row['year'],
                'month' => $row['month'],
                'school_id' => $row['school_id'],
                'expense' => 0
            ];
        }
        $expenseGrouped[$key]['expense'] += $row['expense'];
    }
    $merged = [];
    foreach ($incomeQuery as $item) {
        $key = $item->year . '-' . str_pad($item->month, 2, '0', STR_PAD_LEFT) . '-' . $item->school_id;
        $merged[$key] = [
            'name' => date('F Y', mktime(0,0,0,$item->month,1,$item->year)),
            'income' => (float)$item->income,
            'expense' => 0,
            'school_id' => $item->school_id,
            'year' => $item->year,
            'month' => $item->month
        ];
    }
    foreach ($expenseGrouped as $key => $item) {
        if (!isset($merged[$key])) {
            $merged[$key] = [
                'name' => date('F Y', mktime(0,0,0,$item['month'],1,$item['year'])),
                'income' => 0,
                'expense' => (float)$item['expense'],
                'school_id' => $item['school_id'],
                'year' => $item['year'],
                'month' => $item['month']
            ];
        } else {
            $merged[$key]['expense'] = (float)$item['expense'];
        }
    }
    $result = array_reverse(array_values($merged));

    // Most selling offers
    $mostSellingOffersQuery = DB::table('invoices')
        ->join('offers', 'invoices.offer_id', '=', 'offers.id')
        ->join('students', 'invoices.student_id', '=', 'students.id')
        ->select(
            'offers.offer_name as name',
            DB::raw('COUNT(DISTINCT invoices.student_id) as student_count'),
            DB::raw('SUM(invoices.`totalAmount`) as total_price'),
            'students.schoolId as school_id'
        )
        ->whereNull('invoices.deleted_at');
    if ($schoolId) {
        $mostSellingOffersQuery->where('students.schoolId', $schoolId);
    }
    $mostSellingOffers = $mostSellingOffersQuery
        ->groupBy('offers.id', 'offers.offer_name', 'students.schoolId')
        ->orderByDesc('student_count')
        ->limit(5)
        ->get();

    // Announcements
    $status = $request->query('status', 'all');
    $query = Announcement::query();
    $now = Carbon::now();
    if ($status === 'active') {
        $query->where(function($q) use ($now) {
            $q->where(function($q) use ($now) {
                $q->whereNull('date_start')
                  ->orWhere('date_start', '<=', $now);
            })->where(function($q) use ($now) {
                $q->whereNull('date_end')
                  ->orWhere('date_end', '>=', $now);
            });
        });
    } elseif ($status === 'upcoming') {
        $query->where('date_start', '>', $now);
    } elseif ($status === 'expired') {
        $query->where('date_end', '<', $now);
    }
    $userRole = Auth::user() ? Auth::user()->role : null;
    if ($userRole === 'admin') {
        // Admin sees all announcements
    } else {
        $query->where(function($q) use ($userRole) {
            $q->where('visibility', 'all')
              ->orWhere('visibility', $userRole);
        });
    }
    $query->orderBy('date_announcement', 'desc');
    $announcements = $query->get();

    return Inertia::render('Dashboard', [
        'teacherCounts' => $teacherCounts,
        'totalTeacherCount' => $totalTeacherCount,
        'studentCounts' => $studentCounts,
        'totalStudentCount' => $totalStudentCount,
        'assistantCounts' => $assistantCounts,
        'totalAssistantCount' => $totalAssistantCount,
        'schools' => $schoolsList,
        'monthlyIncomes' => $result,
        'mostSellingOffers' => $mostSellingOffers,
        'announcements' => $announcements,
        'filters' => [
            'status' => $status,
        ],
        'userRole' => $userRole,
        'membershipStats' => $membershipStats,
        'selectedSchoolId' => $schoolId,
    ]);
}

private function getMembershipStats($month = null, $schoolId = null)
{
    try {
        $date = $month ? Carbon::parse($month) : Carbon::now();
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        \Log::info('Querying memberships for:', ['month' => $date->format('Y-m')]);

        // Get base query for memberships within the date range and not deleted
        $query = Membership::where(function($q) use ($startOfMonth, $endOfMonth) {
            $q->where(function($inner) use ($startOfMonth, $endOfMonth) {
                $inner->where('start_date', '<=', $endOfMonth)
                    ->where(function($deepest) use ($startOfMonth) {
                        $deepest->whereNull('end_date')
                            ->orWhere('end_date', '>=', $startOfMonth);
                    });
            });
        })->whereNull('deleted_at');

        // Count paid memberships (active AND paid status)
        $paidQuery = (clone $query)
            ->where('is_active', true)
            ->where('payment_status', 'paid');
            
        $paidCount = $paidQuery->count();
        
        \Log::info('Paid query:', [
            'sql' => $paidQuery->toSql(),
            'bindings' => $paidQuery->getBindings()
        ]);

 
        $unpaidQuery = (clone $query)
            ->where(function($q) use ($endOfMonth) {
                $q->where('is_active', false)
                  ->orWhereIn('payment_status', ['pending', 'expired'])
                  ->orWhere('end_date', '<=', $endOfMonth);
            });
            
        $unpaidCount = $unpaidQuery->count();

        \Log::info('Unpaid query:', [
            'sql' => $unpaidQuery->toSql(),
            'bindings' => $unpaidQuery->getBindings()
        ]);

        // Detailed logging of membership statuses
        \Log::info('Membership counts:', [
            'paid' => $paidCount,
            'unpaid' => $unpaidCount,
            'month' => $date->format('Y-m'),
            'start_date' => $startOfMonth->format('Y-m-d'),
            'end_date' => $endOfMonth->format('Y-m-d')
        ]);

        return [
            'paidCount' => $paidCount,
            'unpaidCount' => $unpaidCount,
            'month' => $date->format('Y-m')
        ];
    } catch (\Exception $e) {
        \Log::error('Error in getMembershipStats:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        return [
            'error' => 'Error fetching stats: ' . $e->getMessage(),
            'paidCount' => 0,
            'unpaidCount' => 0,
            'month' => $date->format('Y-m')
        ];
    }
}

public function getStatsOfPaidUnpaid(Request $request, $month = null)
{
    try {
        $date = $month ? Carbon::parse($month) : Carbon::now();
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        \Log::info('Querying memberships for:', ['month' => $date->format('Y-m')]);

        // Get base query for memberships active in the specified month
        $query = Membership::where(function($q) use ($startOfMonth, $endOfMonth) {
            $q->where(function($inner) use ($startOfMonth, $endOfMonth) {
                // Memberships that start before or during this month
                $inner->where('start_date', '<=', $endOfMonth)
                    // AND end after or during this month (or have no end date)
                    ->where(function($deepest) use ($startOfMonth) {
                        $deepest->whereNull('end_date')
                            ->orWhere('end_date', '>=', $startOfMonth);
                    });
            });
        })->whereNull('deleted_at');

        // Count paid memberships
        $paidCount = (clone $query)
            ->where('payment_status', 'paid')
            ->count();

        // Count unpaid memberships
        $unpaidCount = (clone $query)
            ->whereIn('payment_status', ['unpaid', 'pending'])
            ->count();

        \Log::info('Membership counts:', [
            'paid' => $paidCount,
            'unpaid' => $unpaidCount,
            'month' => $date->format('Y-m'),
            'start_date' => $startOfMonth->format('Y-m-d'),
            'end_date' => $endOfMonth->format('Y-m-d')
        ]);

        return Inertia::render('Invoices/CountChart', [
            'paidCount' => $paidCount,
            'unpaidCount' => $unpaidCount,
            'month' => $date->format('Y-m'),
        ]);

    } catch (\Exception $e) {
        \Log::error('Error in getStatsOfPaidUnpaid:', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);

        return Inertia::render('Invoices/CountChart', [
            'error' => 'Error fetching stats: ' . $e->getMessage()
        ]);
    }
}

}