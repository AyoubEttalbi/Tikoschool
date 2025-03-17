<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Teacher;
use App\Models\Classes;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;
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
            'student_id' => 'required|integer',
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
            'last_payment_date' => 'nullable|date',
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
            'membership_id' => 'integer',
            'student_id' => 'required|integer',
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
            'last_payment_date' => 'nullable|date',
        ]);

        $invoice = Invoice::findOrFail($id);
        $previousAmountPaid = $invoice->amountPaid;

        // Check if amountPaid has changed
        if ($validated['amountPaid'] != $previousAmountPaid) {
            // Update last_payment_date only if amountPaid has changed
            $validated['last_payment_date'] = now()->toDateTimeString();
        } else {
            // Keep the existing last_payment_date if amountPaid hasn't changed
            $validated['last_payment_date'] = $invoice->last_payment_date;
        }

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

    // Generate and download the invoice as a PDF
    public function generateInvoicePdf($id)
    {
       
        $invoice = \App\Models\Invoice::with(['membership.offer', 'student'])
            ->findOrFail($id);

        // Extract membership, student, and offer details
        $membership = $invoice->membership;
        $student = $invoice->student;
        $offerName = $membership?->offer?->offer_name ?? 'No offer available';

        // Load the view for the invoice
        $pdf = Pdf::loadView('invoices.invoice_pdf', [
            'invoice' => $invoice,
            'membership' => $membership,
            'student' => $student,
            'offerName' => $offerName,
        ]);

        // Return the PDF as a downloadable file
        return $pdf->download('invoice_' . $invoice->id . '.pdf');
    }
        public function download($id)
    {
        // Fetch the invoice by ID
        $invoice = Invoice::with(['student.class', 'offer'])->findOrFail($id);
        $className = $invoice->student->class->name;

        // Add the class name to the invoice object
        $invoice->className = $className;
        // Generate the PDF
        $pdf = Pdf::loadView('invoices.teacher_invoicePdf', compact('invoice'));

        // Download the PDF
        return $pdf->download("TeacherInvoice-{$invoice->id}.pdf");
    }

    public function bulkDownload(Request $request)
{
    // Get the selected invoice IDs from the request
    $invoiceIds = $request->input('invoiceIds', []);
    
    if (empty($invoiceIds)) {
        return redirect()->back()->with('error', 'No invoices selected for download');
    }
    
    // Log for debugging
    \Log::info('Selected Invoice IDs:', $invoiceIds);

    $invoices = Invoice::with(['student', 'offer'])
        ->whereIn('id', $invoiceIds)
        ->get();

    if ($invoices->isEmpty()) {
        return redirect()->back()->with('error', 'No invoices found');
    }
    
    // Fetch class names for each invoice
    $invoices->each(function ($invoice) {
        $invoice->className = Classes::find($invoice->student->classId)->name;
    });

    // Generate the PDF with proper headers
    $pdf = Pdf::loadView('invoices.teacher-bulk-invoices', compact('invoices'));
    
    // Make sure proper headers are set for download
    return $pdf->download("teacher-bulk-invoices-" . date('Y-m-d') . ".pdf", [
        'Content-Type' => 'application/pdf',
        'Content-Disposition' => 'attachment; filename="teacher-bulk-invoices-' . date('Y-m-d') . '.pdf"'
    ]);
}
}