<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use App\Models\InvoicePaymentLog;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CashierController extends Controller
{
    /*
     * The daily register reads PAYMENT EVENTS (invoice_payment_logs), never the
     * invoices' cumulative amountPaid.
     *
     * A pupil paying 200 on the 1st and the remaining 100 on the 11th used to make the
     * 1st's sheet say 300 — amountPaid is state, and state has no idea when money
     * arrived. Events do: one row per movement, with the delta and the moment. The
     * register sums events for the day and can no longer be rewritten by a later
     * payment.
     */
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
            $assistant = Assistant::where('email', $user->email)->first();
            if ($assistant) {
                $assistantSchoolIds = $assistant->schools()->pluck('schools.id')->toArray();
            }
        }

        $query = InvoicePaymentLog::with([
            'student',
            'creator',
            'invoice',
            'invoice.membership' => function ($membershipQuery) {
                $membershipQuery->withTrashed()->with('offer');
            },
        ])
            ->notVoided()
            ->whereDate('paid_at', $date);

        // If assistant, only their schools' pupils — a boundary, not a preference.
        if ($isAssistant && count($assistantSchoolIds) > 0) {
            $query->whereHas('student', function ($studentQuery) use ($assistantSchoolIds) {
                $studentQuery->whereIn('schoolId', $assistantSchoolIds);
            });
        }

        // The filters the page already offered, translated to the event's own columns
        // where it has them and through the invoice where it does not.
        if ($request->filled('student_id')) {
            $query->where('student_id', $request->student_id);
        }

        if ($request->filled('creator_id')) {
            $query->where('created_by', $request->creator_id);
        }

        if ($request->filled('membership_id')) {
            $query->whereHas('invoice', fn ($invoiceQuery) => $invoiceQuery
                ->where('membership_id', $request->membership_id));
        }

        if ($request->filled('school_id')) {
            $query->whereHas('student', function ($studentQuery) use ($request) {
                $studentQuery->where('schoolId', $request->school_id);
            });
        }

        if ($request->filled('offer_id')) {
            $query->whereHas('invoice.membership', function ($membershipQuery) use ($request) {
                $membershipQuery->withTrashed()->where('offer_id', $request->offer_id);
            });
        }

        // All filtered events for the statistics, then the page of them for the table.
        $allFilteredEvents = $query->get();

        $totalPaid = $allFilteredEvents->sum('amount');
        $totalPayments = $allFilteredEvents->count();
        $averagePayment = $totalPayments > 0 ? $totalPaid / $totalPayments : 0;

        $hourlyData = $allFilteredEvents->groupBy(fn ($event) => $event->paid_at->format('H'))
            ->map(fn ($events, $hour) => [
                'hour' => (int) $hour,
                'total' => $events->sum('amount'),
            ]);
        $peakHour = $hourlyData->sortByDesc('total')->first() ?? ['hour' => 0, 'total' => 0];

        // Yesterday, through the same events — the trend line must move for the same
        // reason the day total must not.
        $previousDate = Carbon::parse($date)->subDay()->toDateString();
        $previousDayQuery = InvoicePaymentLog::notVoided()->whereDate('paid_at', $previousDate);

        if ($isAssistant && count($assistantSchoolIds) > 0) {
            $previousDayQuery->whereHas('student', function ($studentQuery) use ($assistantSchoolIds) {
                $studentQuery->whereIn('schoolId', $assistantSchoolIds);
            });
        }
        if ($request->filled('student_id')) {
            $previousDayQuery->where('student_id', $request->student_id);
        }
        if ($request->filled('creator_id')) {
            $previousDayQuery->where('created_by', $request->creator_id);
        }
        if ($request->filled('membership_id')) {
            $previousDayQuery->whereHas('invoice', fn ($invoiceQuery) => $invoiceQuery
                ->where('membership_id', $request->membership_id));
        }
        if ($request->filled('school_id')) {
            $previousDayQuery->whereHas('student', function ($studentQuery) use ($request) {
                $studentQuery->where('schoolId', $request->school_id);
            });
        }
        if ($request->filled('offer_id')) {
            $previousDayQuery->whereHas('invoice.membership', function ($membershipQuery) use ($request) {
                $membershipQuery->withTrashed()->where('offer_id', $request->offer_id);
            });
        }
        $previousDayTotal = $previousDayQuery->sum('amount');

        // Now paginate the results for display
        $events = $query->orderBy('paid_at', 'desc')->paginate(20);

        $chartData = $hourlyData->sortBy('hour')->values()->map(function ($item) {
            return [
                'hour' => $item['hour'],
                'total' => (float) $item['total'],
                'label' => sprintf('%02d:00', $item['hour']),
            ];
        });

        // For filters: get all memberships with their offers (including deleted ones)
        $membershipsQuery = Membership::withTrashed()->with('offer')->whereHas('offer');
        $studentsQuery = Student::select('id', 'firstName', 'lastName');
        $schoolsQuery = School::select('id', 'name');
        if ($isAssistant && count($assistantSchoolIds) > 0) {
            $studentsQuery->whereIn('schoolId', $assistantSchoolIds);
            $schoolsQuery->whereIn('id', $assistantSchoolIds);
        }
        $memberships = $membershipsQuery->get()->map(function ($membership) {
            return [
                'id' => $membership->id,
                'name' => $membership->offer ? $membership->offer->offer_name : 'No Offer',
                'deleted_at' => $membership->deleted_at,
                'is_deleted' => ! is_null($membership->deleted_at),
            ];
        })->sortBy('name')->values();
        $students = $studentsQuery->orderBy('firstName')->get()->map(function ($student) {
            return [
                'id' => $student->id,
                'name' => $student->firstName.' '.$student->lastName,
            ];
        });
        $creators = User::whereIn('id', InvoicePaymentLog::whereNotNull('created_by')->pluck('created_by')->unique())
            ->select('id', 'name')
            ->orderBy('name')
            ->get()
            ->map(function ($creator) {
                return [
                    'id' => $creator->id,
                    'name' => $creator->name,
                ];
            });
        $schools = $schoolsQuery->orderBy('name')->get()->map(function ($school) {
            return [
                'id' => $school->id,
                'name' => $school->name,
            ];
        });
        // Fetch all offers for the offer filter
        $offers = Offer::select('id', 'offer_name')->orderBy('offer_name')->get()->map(function ($offer) {
            return [
                'id' => $offer->id,
                'name' => $offer->offer_name,
            ];
        });

        /*
         * Same shape the page always rendered, so the frontend did not have to change:
         * one row per EVENT now — amountPaid is the money that moved on that row's
         * moment, not the invoice's balance. `invoice_id` carries what the details and
         * receipt actions need, because the row's own id is the event's.
         */
        $formattedEvents = $events->getCollection()->map(function ($event) {
            $invoice = $event->invoice;

            return [
                'id' => $event->id,
                'invoice_id' => $invoice?->id,
                'amountPaid' => (float) $event->amount,
                'created_at' => $event->paid_at?->format('Y-m-d H:i:s'),
                'student' => $event->student ? [
                    'id' => $event->student->id,
                    'name' => $event->student->firstName.' '.$event->student->lastName,
                ] : null,
                'creator' => $event->creator ? [
                    'id' => $event->creator->id,
                    'name' => $event->creator->name,
                ] : null,
                'membership' => $invoice?->membership ? [
                    'id' => $invoice->membership->id,
                    'name' => $invoice->membership->offer ? $invoice->membership->offer->offer_name : 'No Offer',
                    'deleted_at' => $invoice->membership->deleted_at,
                    'is_deleted' => ! is_null($invoice->membership->deleted_at),
                ] : null,
                'type' => $invoice?->type,
                'assurance_amount' => $invoice?->assurance_amount,
            ];
        });

        return Inertia::render('Menu/CashierPage', [
            'invoices' => $formattedEvents,
            'pagination' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'links' => $events->linkCollection(),
            ],
            'chartData' => $chartData,
            'totalPaid' => (float) $totalPaid,
            'previousDayTotal' => (float) $previousDayTotal,
            'cashierStats' => [
                'totalInvoices' => $totalPayments,
                'totalPaid' => (float) $totalPaid,
                'averagePayment' => (float) $averagePayment,
                'peakHour' => $peakHour,
            ],
            'date' => $date,
            'filters' => [
                'memberships' => $memberships,
                'students' => $students,
                'creators' => $creators,
                'schools' => $schools,
                'offers' => $offers,
            ],
            'currentFilters' => [
                'membership_id' => $request->input('membership_id'),
                'student_id' => $request->input('student_id'),
                'creator_id' => $request->input('creator_id'),
                'school_id' => $request->input('school_id'),
                'offer_id' => $request->input('offer_id'),
                'date' => $date,
            ],
            'role' => $user ? $user->role : null,
        ]);
    }
}
