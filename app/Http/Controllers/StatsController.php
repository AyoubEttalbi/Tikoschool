<?php

namespace App\Http\Controllers;

use App\Models\Student;
use App\Models\Teacher;
use App\Models\Assistant;
use App\Models\Invoice;
use App\Models\School;
use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StatsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
{
    // Fetch counts of teachers, students, and assistants (existing logic)
    $teacherCounts = DB::table('school_teacher')
        ->select('school_id', DB::raw('COUNT(DISTINCT teacher_id) as count'))
        ->groupBy('school_id')
        ->get()
        ->keyBy('school_id');

    $totalTeacherCount = DB::table('school_teacher')
        ->select(DB::raw('COUNT(DISTINCT teacher_id) as count'))
        ->first()
        ->count;

    $studentCounts = Student::select('schoolId', DB::raw('COUNT(*) as count'))
        ->groupBy('schoolId')
        ->get()
        ->keyBy('schoolId');

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
            DB::raw('SUM(invoices.totalAmount) as income'),
            DB::raw('SUM(invoices.amountPaid) as expense'),
            DB::raw('MONTH(invoices.created_at) as month'),
            'students.schoolId'
        )
        ->whereNotNull('students.schoolId')
        ->groupBy('month', 'students.schoolId')
        ->get()
        ->map(function ($item) {
            return [
                'name' => date('M', mktime(0, 0, 0, $item->month, 10)),
                'income' => $item->income,
                'expense' => $item->expense,
                'school_id' => $item->schoolId,
            ];
        });

    // Fetch most selling offers, students count, and total price
    $mostSellingOffers = DB::table('invoices')
        ->join('offers', 'invoices.offer_id', '=', 'offers.id')
        ->select(
            'offers.offer_name as name',
            DB::raw('COUNT(DISTINCT invoices.student_id) as student_count'),
            DB::raw('SUM(invoices.totalAmount) as total_price')
        )
        ->groupBy('offers.id', 'offers.offer_name')
        ->orderByDesc('student_count')
        ->limit(5) // Limit to top 5 most selling offers
        ->get();

    return Inertia::render('Dashboard', [
        'teacherCounts' => $teacherCounts,
        'totalTeacherCount' => $totalTeacherCount,
        'studentCounts' => $studentCounts,
        'totalStudentCount' => $totalStudentCount,
        'assistantCounts' => $assistantCounts,
        'totalAssistantCount' => $totalAssistantCount,
        'schools' => $schools,
        'monthlyIncomes' => $monthlyIncomes,
        'mostSellingOffers' => $mostSellingOffers, // Pass the most selling offers data
    ]);
}
}