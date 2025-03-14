<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InvoiceController extends Controller
{
    /**
     * Display a listing of invoices.
     */
    public function index()
    {
        $invoices = Invoice::with(['membership', 'membership.student', 'membership.offer'])->paginate(10);

        return Inertia::render('Menu/SingleStudentPage', [
            'invoices' => $invoices,
        ]);
    }

    /**
     * Show the form for creating a new invoice.
     */
    public function create(Request $request)
    {
        $membership_id = $request->input('membership_id');
        $membership = null;
        
        if ($membership_id) {
            $membership = Membership::with(['student', 'offer'])->findOrFail($membership_id);
        }
        
        $studentMemberships = Membership::with(['student', 'offer'])
            ->where('payment_status', 'pending')
            ->get()
            ->map(function ($membership) {
                return [
                    'id' => $membership->id,
                    'offer_name' => $membership->student->name . ' - ' . $membership->offer->name,
                    'price' => $membership->offer->price,
                    'offer_id' => $membership->offer_id
                ];
            });

        return Inertia::render('Menu/SingleStudentPage', [
            'StudentMemberships' => $studentMemberships,
            'selectedMembership' => $membership,
        ]);
    }

    /**
     * Store a newly created invoice in the database.
     */
    public function store(Request $request)
{
    DB::beginTransaction();

    try {
        // Validate the incoming request
        $validated = $request->validate([
            'membership_id' => 'required|integer',
            'months' => 'required|integer',
            'billDate' => 'required|date',
            'creationDate' => 'nullable|date',
            'totalAmount' => 'required|numeric',
            'amountPaid' => 'required|numeric',
            'rest' => 'required|numeric',
            'offer' => 'nullable|string',
            'offer_id' => 'nullable|integer',
            'endDate' => 'nullable|date',
            'includePartialMonth' => 'nullable|boolean',
            'partialMonthAmount' => 'nullable|numeric',
        ]);

        // Create the invoice
        $invoice = Invoice::create($validated);

        // Fetch the membership
        $membership = Membership::findOrFail($validated['membership_id']);

        // Calculate the percentage of the amount paid
        $paymentPercentage = ($validated['amountPaid'] / $validated['totalAmount']) * 100;

        // Update teachers' wallets based on the payment percentage
        foreach ($membership->teachers as $teacherData) {
            $teacher = Teacher::find($teacherData['teacherId']);
            if ($teacher) {
                // Calculate the teacher's amount for the paid percentage
                $teacherAmount = ($teacherData['amount'] * $validated['months']) * ($paymentPercentage / 100);

                // Update the teacher's wallet
                $teacher->increment('wallet', $teacherAmount);
            }
        }

        // Update membership status if fully paid
        if ($validated['amountPaid'] >= $validated['totalAmount']) {
            $membership->update([
                'payment_status' => 'paid',
                'is_active' => true,
                'start_date' => $validated['billDate'],
                'end_date' => $validated['endDate'],
            ]);
        }

        DB::commit();
        return redirect()->back()->with('success', 'Invoice created successfully!');
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Error creating invoice:', ['error' => $e->getMessage()]);
        return redirect()->back()->withErrors(['error' => 'An error occurred while processing your request.']);
    }
}

    /**
     * Display the specified invoice.
     */
    public function show($id)
    {
        $invoice = Invoice::with(['membership', 'membership.student', 'membership.offer'])->findOrFail($id);

        return Inertia::render('Menu/SingleStudentPage', [
            'invoice' => $invoice,
        ]);
    }

    /**
     * Show the form for editing the specified invoice.
     */
    public function edit($id)
    {
        $invoice = Invoice::findOrFail($id);
        $studentMemberships = Membership::with(['student', 'offer'])
            ->get()
            ->map(function ($membership) {
                return [
                    'id' => $membership->id,
                    'offer_name' => $membership->student->name . ' - ' . $membership->offer->name,
                    'price' => $membership->offer->price,
                    'offer_id' => $membership->offer_id
                ];
            });

        return Inertia::render('Menu/SingleStudentPage', [
            'invoice' => $invoice,
            'StudentMemberships' => $studentMemberships,
        ]);
    }

    /**
     * Update the specified invoice in the database.
     */
    public function update(Request $request, $id)
{
    DB::beginTransaction();

    try {
        // Validate the incoming request
        $validated = $request->validate([
            'membership_id' => 'required|integer',
            'months' => 'required|integer',
            'billDate' => 'required|date',
            'creationDate' => 'nullable|date',
            'totalAmount' => 'required|numeric',
            'amountPaid' => 'required|numeric',
            'rest' => 'required|numeric',
            'offer' => 'nullable|string',
            'offer_id' => 'nullable|integer',
            'endDate' => 'nullable|date',
            'includePartialMonth' => 'nullable|boolean',
            'partialMonthAmount' => 'nullable|numeric',
        ]);

        $invoice = Invoice::findOrFail($id);
        $previousAmountPaid = $invoice->amountPaid;

        // Update the invoice
        $invoice->update($validated);

        // Fetch the membership
        $membership = Membership::findOrFail($validated['membership_id']);

        // Calculate the percentage of the amount paid
        $paymentPercentage = ($validated['amountPaid'] / $validated['totalAmount']) * 100;

        // Update teachers' wallets based on the payment percentage
        foreach ($membership->teachers as $teacherData) {
            $teacher = Teacher::find($teacherData['teacherId']);
            if ($teacher) {
                // Calculate the teacher's amount for the paid percentage
                $teacherAmount = ($teacherData['amount'] * $validated['months']) * ($paymentPercentage / 100);

                // Update the teacher's wallet
                $teacher->increment('wallet', $teacherAmount);
            }
        }

        // Update membership status if fully paid
        if ($validated['amountPaid'] >= $validated['totalAmount']) {
            $membership->update([
                'payment_status' => 'paid',
                'is_active' => true,
                'start_date' => $validated['billDate'],
                'end_date' => $validated['endDate'],
            ]);
        }

        DB::commit();
        return redirect()->back()->with('success', 'Invoice updated successfully!');
    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Error updating invoice:', ['error' => $e->getMessage()]);
        return redirect()->back()->withErrors(['error' => 'An error occurred while updating the invoice.']);
    }
}

    /**
     * Remove the specified invoice from the database.
     */
    public function destroy($id)
    {
        try {
            $invoice = Invoice::findOrFail($id);
            $invoice->delete();
            return redirect()->back()->with('success', 'Invoice deleted successfully.');
        } catch (\Exception $e) {
            Log::error('Error deleting invoice:', ['error' => $e->getMessage()]);
            return redirect()->back()->withErrors(['error' => 'An error occurred while deleting the invoice.']);
        }
    }
}
