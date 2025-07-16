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
     
        // Debug: Log the date being used
        \Log::info('CashierController - Date being used: ' . $date);
        \Log::info('CashierController - Request date: ' . $request->input('date'));
        \Log::info('CashierController - Today date: ' . Carbon::today()->toDateString());
        
        // Debug: Check what we're querying
        \Log::info('CashierController - Total invoices count: ' . Invoice::count());
        \Log::info('CashierController - Paid invoices count: ' . Invoice::where('amountPaid', '>', 0)->count());
        \Log::info('CashierController - Today invoices count: ' . Invoice::whereDate('created_at', $date)->count());
        \Log::info('CashierController - Today paid invoices count: ' . Invoice::whereDate('created_at', $date)->where('amountPaid', '>', 0)->count());

        $query = Invoice::with(['student', 'creator', 'membership.offer'])
            ->whereDate('created_at', $date)
            ->where('amountPaid', '>', 0);

        // Optional: filter by membership, student, or creator
        if ($request->filled('membership_id')) {
            $query->where('membership_id', $request->membership_id);
        }

        if ($request->filled('student_id')) {
            $query->where('student_id', $request->student_id);
        }

        if ($request->filled('creator_id')) {
            $query->where('created_by', $request->creator_id);
        }

        $invoices = $query->orderBy('created_at', 'desc')->get();

        // For chart: group by hour and sum amountPaid
        $chartData = Invoice::whereDate('created_at', $date)
            ->where('amountPaid', '>', 0)
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
        $memberships = \App\Models\Membership::with('offer')
            ->whereHas('offer') // Only get memberships that have an offer
            ->get()
            ->map(function ($membership) {
                return [
                    'id' => $membership->id,
                    'name' => $membership->offer ? $membership->offer->name : 'No Offer'
                ];
            })
            ->sortBy('name')
            ->values();

        $students = \App\Models\Student::select('id', 'firstName', 'lastName')
            ->orderBy('firstName')
            ->get()
            ->map(function ($student) {
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

        // Format invoices for frontend
        $formattedInvoices = $invoices->map(function ($invoice) {
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
                    // Fixed: Use consistent field names - either 'name' or 'first_name'/'last_name'
                    'name' => $invoice->creator->name ?? ($invoice->creator->first_name . ' ' . $invoice->creator->last_name)
                ] : null,
                'membership' => $invoice->membership ? [
                    'id' => $invoice->membership->id,
                    'name' => $invoice->membership->offer ? $invoice->membership->offer->name : 'No Offer'
                ] : null,
            ];
        });

        return Inertia::render('Menu/CashierPage', [
            'invoices' => $formattedInvoices,
            'chartData' => $chartData,
            'totalPaid' => (float) $totalPaid,
            'date' => $date,
            'filters' => [
                'memberships' => $memberships,
                'students' => $students,
                'creators' => $creators,
            ],
            'currentFilters' => [
                'membership_id' => $request->input('membership_id'),
                'student_id' => $request->input('student_id'),
                'creator_id' => $request->input('creator_id'),
                'date' => $date, // Always return the date being used
            ]
        ]);
    }
}