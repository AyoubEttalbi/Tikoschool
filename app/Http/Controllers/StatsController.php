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
    // Fetch counts of teachers grouped by school (distinct by teacher_id)
    $teacherCounts = DB::table('school_teacher')
        ->select('school_id', DB::raw('COUNT(DISTINCT teacher_id) as count'))
        ->groupBy('school_id')
        ->get()
        ->keyBy('school_id');

    // Fetch total count of distinct teachers across all schools
    $totalTeacherCount = DB::table('school_teacher')
        ->select(DB::raw('COUNT(DISTINCT teacher_id) as count'))
        ->first()
        ->count;

    // Fetch counts of students grouped by school
    $studentCounts = Student::select('schoolId', DB::raw('COUNT(*) as count'))
        ->groupBy('schoolId')
        ->get()
        ->keyBy('schoolId');

    // Fetch total count of students across all schools
    $totalStudentCount = Student::count();

    // Fetch counts of assistants grouped by school (distinct by assistant_id)
    $assistantCounts = DB::table('assistant_school')
        ->select('school_id', DB::raw('COUNT(DISTINCT assistant_id) as count'))
        ->groupBy('school_id')
        ->get()
        ->keyBy('school_id');

    // Fetch total count of distinct assistants across all schools
    $totalAssistantCount = DB::table('assistant_school')
        ->select(DB::raw('COUNT(DISTINCT assistant_id) as count'))
        ->first()
        ->count;

    // Fetch all schools
    $schools = School::all();

    // Fetch monthly incomes
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

    return Inertia::render('Dashboard', [
        'teacherCounts' => $teacherCounts,
        'totalTeacherCount' => $totalTeacherCount,
        'studentCounts' => $studentCounts,
        'totalStudentCount' => $totalStudentCount,
        'assistantCounts' => $assistantCounts,
        'totalAssistantCount' => $totalAssistantCount,
        'schools' => $schools,
        'monthlyIncomes' => $monthlyIncomes,
    ]);
}
}