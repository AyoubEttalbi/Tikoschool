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

class StatsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
{
    // Get the selected month for membership stats
    $month = $request->get('month');
    
    // Get membership stats
    $membershipStats = $this->getMembershipStats($month);

    // Fetch counts of teachers using the pivot table
    $teacherCounts = DB::table('school_teacher')
        ->select('school_id', DB::raw('COUNT(DISTINCT teacher_id) as count'))
        ->groupBy('school_id')
        ->get()
        ->keyBy('school_id');

    $totalTeacherCount = DB::table('school_teacher')
        ->select(DB::raw('COUNT(DISTINCT teacher_id) as count'))
        ->first()
        ->count;

    $studentCounts = Student::select(DB::raw('`schoolId`'), DB::raw('COUNT(*) as count'))
        ->groupBy(DB::raw('`schoolId`'))
        ->get()
        ->keyBy('schoolid');

    $totalStudentCount = Student::count();

    $assistantCounts = DB::table('assistant_school')
        ->select('school_id', DB::raw('COUNT(DISTINCT assistant_id) as count'))
        ->groupBy('school_id')
        ->get()
        ->keyBy('school_id');

    $totalAssistantCount = DB::table('assistant_school')
        ->select(DB::raw('COUNT(DISTINCT assistant_id) as count'))
        ->first()
        ->count;

    // Fetch all schools
    $schools = School::all();

    // Fetch monthly incomes (existing logic)
    $monthlyIncomes = Invoice::join('students', 'invoices.student_id', '=', 'students.id')
        ->select(
            DB::raw('SUM(invoices.`totalAmount`) as income'),
            DB::raw('SUM(invoices.`amountPaid`) as expense'),
            DB::raw('MONTH(invoices.created_at) as month'),
            DB::raw('students.`schoolId`')
        )
        ->whereNotNull('students.schoolId')
        ->whereNull('invoices.deleted_at')
        ->groupBy('month', 'students.schoolId')
        ->get()
        ->map(function ($item) {
            return [
                'name' => date('M', mktime(0, 0, 0, $item->month, 10)),
                'income' => $item->income,
                'expense' => $item->expense,
                'school_id' => $item->schoolId, // Changed from lowercase schoolid to match the correct column name
            ];
        });

    // Fetch most selling offers, students count, and total price
    $mostSellingOffers = DB::table('invoices')
        ->join('offers', 'invoices.offer_id', '=', 'offers.id')
        ->select(
            'offers.offer_name as name',
            DB::raw('COUNT(DISTINCT invoices.student_id) as student_count'),
            DB::raw('SUM(invoices.`totalAmount`) as total_price')
        )
        ->whereNull('invoices.deleted_at')
        ->groupBy('offers.id', 'offers.offer_name')
        ->orderByDesc('student_count')
        ->limit(5) // Limit to top 5 most selling offers
        ->get();

    // Fetch announcements
    
     $status = $request->query('status', 'all'); // 'all', 'active', 'upcoming', 'expired'
        
     // Base query
     $query = Announcement::query();
     
     // Apply date filtering based on status parameter
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
     
     // Get user role for role-based visibility
     $userRole = Auth::user() ? Auth::user()->role : null;
     
     // Apply role-based visibility filter based on user role
     if ($userRole === 'admin') {
         // Admin sees all announcements (no visibility filter needed)
     } else {
         // Teachers and assistants only see announcements with visibility 'all' or matching their role
         $query->where(function($q) use ($userRole) {
             $q->where('visibility', 'all')
               ->orWhere('visibility', $userRole);
         });
     }
     
     // Order by announcement date (most recent first)
     $query->orderBy('date_announcement', 'desc');
     
     // Execute query
     $announcements = $query->get();

    return Inertia::render('Dashboard', [
        'teacherCounts' => $teacherCounts,
        'totalTeacherCount' => $totalTeacherCount,
        'studentCounts' => $studentCounts,
        'totalStudentCount' => $totalStudentCount,
        'assistantCounts' => $assistantCounts,
        'totalAssistantCount' => $totalAssistantCount,
        'schools' => $schools,
        'monthlyIncomes' => $monthlyIncomes,
        'mostSellingOffers' => $mostSellingOffers,
        'announcements' => $announcements,
        'filters' => [
            'status' => $status,
        ],
        'userRole' => $userRole,
        // Add membership stats
        'membershipStats' => $membershipStats
    ]);
}

private function getMembershipStats($month = null)
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

        // Get all memberships for the month for debugging
        $allMemberships = (clone $query)
            ->select('id', 'payment_status', 'is_active', 'start_date', 'end_date')
            ->get();
        
        \Log::info('All memberships for the month:', $allMemberships->toArray());

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