<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Inertia\Inertia;

class CashierController extends Controller
{
    // Fetch daily statistics for the cashier page
    public function daily(Request $request)
    {
        // Use today's date if no date is provided
$date = $request->input('date');
if (empty($date)) {
    $date = Carbon::today()->toDateString();
}
    

        $user = auth()->user();
        $isAssistant = $user && $user->role === 'assistant';
        $assistantSchoolIds = [];
        if ($isAssistant) {
            $assistant = \App\Models\Assistant::where('email', $user->email)->first();
            if ($assistant) {
                $assistantSchoolIds = $assistant->schools()->pluck('schools.id')->toArray();
            }
        }

        $query = Invoice::with(['student', 'creator', 'membership.offer'])
            ->whereDate('created_at', $date)
            ->where('amountPaid', '>', 0);

        // If assistant, filter invoices by related schools
        if ($isAssistant && count($assistantSchoolIds) > 0) {
            $query->whereHas('student', function ($studentQuery) use ($assistantSchoolIds) {
                $studentQuery->whereIn('schoolId', $assistantSchoolIds);
            });
        }

        // Optional: filter by membership, student, creator, or school
        if ($request->filled('membership_id')) {
            $query->where('membership_id', $request->membership_id);
        }

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->student_id);
        }

        if ($request->filled('creator_id')) {
            $query->where('created_by', $request->creator_id);
        }

        // School filter: filter invoices by the schoolId of the related student
        if ($request->filled('school_id')) {
            $query->whereHas('student', function ($studentQuery) use ($request) {
                $studentQuery->where('schoolId', $request->school_id);
            });
        }

        $invoices = $query->orderBy('created_at', 'desc')->paginate(50);

        // For chart: group by hour and sum amountPaid
        $chartDataQuery = Invoice::whereDate('created_at', $date)
            ->where('amountPaid', '>', 0);
        if ($isAssistant && count($assistantSchoolIds) > 0) {
            $chartDataQuery->whereHas('student', function ($studentQuery) use ($assistantSchoolIds) {
                $studentQuery->whereIn('schoolId', $assistantSchoolIds);
            });
        }
        $chartData = $chartDataQuery
            ->selectRaw('HOUR(created_at) as hour, SUM(amountPaid) as total')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(function ($item) {
                return [
                    'hour' => $item->hour,
                    'total' => (float) $item->total,
                    'label' => sprintf('%02d:00', $item->hour)
                ];
            });

        // Total for the day
        $totalPaid = $invoices->sum('amountPaid');

        // For filters: get all memberships with their offers
        $membershipsQuery = \App\Models\Membership::with('offer')->whereHas('offer');
        $studentsQuery = \App\Models\Student::select('id', 'firstName', 'lastName');
        $schoolsQuery = \App\Models\School::select('id', 'name');
        if ($isAssistant && count($assistantSchoolIds) > 0) {
            $studentsQuery->whereIn('schoolId', $assistantSchoolIds);
            $schoolsQuery->whereIn('id', $assistantSchoolIds);
        }
        $memberships = $membershipsQuery->get()->map(function ($membership) {
            return [
                'id' => $membership->id,
                'name' => $membership->offer ? $membership->offer->offer_name : 'No Offer'
            ];
        })->sortBy('name')->values();
        $students = $studentsQuery->orderBy('firstName')->get()->map(function ($student) {
            return [
                'id' => $student->id,
                'name' => $student->firstName . ' ' . $student->lastName
            ];
        });
        $creators = \App\Models\User::whereIn('id', Invoice::whereNotNull('created_by')->pluck('created_by')->unique())
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(function ($creator) {
                return [
                    'id' => $creator->id,
                    'name' => $creator->name
                ];
            });
        $schools = $schoolsQuery->orderBy('name')->get()->map(function ($school) {
            return [
                'id' => $school->id,
                'name' => $school->name
            ];
        });

        // Format invoices for frontend (for paginated results, use ->getCollection())
        $formattedInvoices = $invoices->getCollection()->map(function ($invoice) {
            return [
                'id' => $invoice->id,
                'amountPaid' => (float) $invoice->amountPaid,
                'created_at' => $invoice->created_at->format('Y-m-d H:i:s'),
                'student' => $invoice->student ? [
                    'id' => $invoice->student->id,
                    'name' => $invoice->student->firstName . ' ' . $invoice->student->lastName
                ] : null,
                'creator' => $invoice->creator ? [
                    'id' => $invoice->creator->id,
                    'name' => $invoice->creator->name
                ] : null,
                'membership' => $invoice->membership ? [
                    'id' => $invoice->membership->id,
                    'name' => $invoice->membership->offer ? $invoice->membership->offer->offer_name : 'No Offer'
                ] : null,
            ];
        });

        return Inertia::render('Menu/CashierPage', [
            'invoices' => $formattedInvoices,
            'pagination' => [
                'current_page' => $invoices->currentPage(),
                'last_page' => $invoices->lastPage(),
                'per_page' => $invoices->perPage(),
                'total' => $invoices->total(),
                'links' => $invoices->linkCollection(),
            ],
            'chartData' => $chartData,
            'totalPaid' => (float) $totalPaid,
            'date' => $date,
            'filters' => [
                'memberships' => $memberships,
                'students' => $students,
                'creators' => $creators,
                'schools' => $schools,
            ],
            'currentFilters' => [
                'membership_id' => $request->input('membership_id'),
                'student_id' => $request->input('student_id'),
                'creator_id' => $request->input('creator_id'),
                'school_id' => $request->input('school_id'),
                'date' => $date, // Always return the date being used
            ],
            'role' => $user ? $user->role : null,
        ]);
    }
}