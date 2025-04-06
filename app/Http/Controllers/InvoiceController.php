<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Teacher;
use App\Models\Classes;
use App\Models\School;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Barryvdh\DomPDF\Facade\Pdf;
use Spatie\Activitylog\Models\Activity;

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

            // Log the activity
            $this->logActivity('created', $invoice, null, $invoice->toArray());

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
        try {
            // Load all relevant relationships with explicit column names
            $invoice = Invoice::with([
                'membership',
                'membership.student',
                'membership.student.class',
                'membership.student.school',
                'student',
                'student.class',
                'student.school',
                'offer'
            ])->findOrFail($id);
            
            // Log detailed information about the loaded invoice
            Log::info('Invoice data', [
                'invoice_id' => $id,
                'invoice_data' => $invoice->toArray(),
                'has_membership' => $invoice->membership ? true : false,
                'has_student_direct' => $invoice->student ? true : false
            ]);
            
            // Get student either directly or through membership
            $student = $invoice->student ?? ($invoice->membership ? $invoice->membership->student : null);
            
            // If student exists, make sure we fetch class and school data
            if ($student) {
                Log::info('Student data', [
                    'student_id' => $student->id,
                    'classId' => $student->classId,
                    'schoolId' => $student->schoolId,
                    'has_class_relation' => $student->class ? true : false,
                    'has_school_relation' => $student->school ? true : false
                ]);
                
                if ($student->classId && !$student->class) {
                    $class = Classes::find($student->classId);
                    $className = $class ? $class->name : 'N/A';
                } else {
                    $className = $student->class ? $student->class->name : 'N/A';
                }
                
                if ($student->schoolId && !$student->school) {
                    $school = School::find($student->schoolId);
                    $schoolName = $school ? $school->name : 'N/A';
                } else {
                    $schoolName = $student->school ? $student->school->name : 'N/A';
                }
            } else {
                $className = 'N/A';
                $schoolName = 'N/A';
            }
            
            // Ensure numeric values are properly formatted
            $totalAmount = is_numeric($invoice->totalAmount) ? floatval($invoice->totalAmount) : 0;
            $amountPaid = is_numeric($invoice->amountPaid) ? floatval($invoice->amountPaid) : 0;
            $rest = is_numeric($invoice->rest) ? floatval($invoice->rest) : ($totalAmount - $amountPaid);
            
            // Get offer name from the relationship
            $offerName = 'N/A';
            if ($invoice->offer && isset($invoice->offer->offer_name)) {
                $offerName = $invoice->offer->offer_name;
            } elseif ($invoice->membership && $invoice->membership->offer) {
                $offerName = $invoice->membership->offer->offer_name;
            }
            
            $formattedInvoice = [
                'id' => $invoice->id,
                'student_id' => $student ? $student->id : null,
                'student_name' => $student ? $student->firstName . ' ' . $student->lastName : 'Unknown',
                'student_class' => $className,
                'student_school' => $schoolName,
                'billDate' => $invoice->billDate ? $invoice->billDate->format('Y-m-d') : null,
                'creationDate' => $invoice->creationDate ? $invoice->creationDate->format('Y-m-d') : null,
                'endDate' => $invoice->endDate ? $invoice->endDate->format('Y-m-d') : null,
                'totalAmount' => $totalAmount,
                'amountPaid' => $amountPaid,
                'rest' => $rest,
                'months' => $invoice->months ?? 1,
                'includePartialMonth' => $invoice->includePartialMonth ?? false,
                'partialMonthAmount' => is_numeric($invoice->partialMonthAmount) ? floatval($invoice->partialMonthAmount) : 0,
                'offer_name' => $offerName,
                'offer_id' => $invoice->offer_id,
                'membership_id' => $invoice->membership_id,
            ];
            
            // Get teachers associated with this invoice's membership
            if ($invoice->membership && isset($invoice->membership->teachers) && is_array($invoice->membership->teachers)) {
                $teachers = [];
                
                foreach ($invoice->membership->teachers as $teacherData) {
                    $teacherId = $teacherData['teacherId'] ?? null;
                    $teacherAmount = isset($teacherData['amount']) && is_numeric($teacherData['amount']) ? floatval($teacherData['amount']) : 0;
                    
                    if ($teacherId) {
                        $teacher = Teacher::find($teacherId);
                        $teacherName = $teacher ? $teacher->first_name . ' ' . $teacher->last_name : 'Unknown Teacher';
                        
                        $teachers[] = [
                            'teacherId' => $teacherId,
                            'name' => $teacherName,
                            'amount' => $teacherAmount
                        ];
                    }
                }
                
                $formattedInvoice['teachers'] = $teachers;
            }

            // Add payment data
            if ($invoice->amountPaid > 0) {
                $formattedInvoice['payments'] = [
                    [
                        'date' => $invoice->last_payment_date ? $invoice->last_payment_date->format('Y-m-d') : ($invoice->creationDate ? $invoice->creationDate->format('Y-m-d') : now()->format('Y-m-d')),
                        'amount' => $amountPaid,
                        'method' => 'Cash'
                    ]
                ];
            } else {
                $formattedInvoice['payments'] = [];
            }

            // Log the final formatted data before rendering
            Log::info('Final formatted invoice data being sent to view', [
                'invoice_id' => $id,
                'formatted_data' => $formattedInvoice
            ]);

            return Inertia::render('Invoices/InvoiceViewer', [
                'invoice' => $formattedInvoice
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching invoice details: ' . $e->getMessage(), [
                'invoice_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->route('dashboard')->with('error', 'Could not find invoice details: ' . $e->getMessage());
        }
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

            // Capture old data before update
            $oldData = $invoice->toArray();

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

            // Log the activity
            $this->logActivity('updated', $invoice, $oldData, $invoice->toArray());

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

            // Log the activity before deletion
            $this->logActivity('deleted', $invoice, $invoice->toArray(), null);

            $invoice->delete();
            return redirect()->back()->with('success', 'Invoice deleted successfully.');
        } catch (\Exception $e) {
            Log::error('Error deleting invoice:', ['error' => $e->getMessage()]);
            return redirect()->back()->withErrors(['error' => 'An error occurred while deleting the invoice.']);
        }
    }

    /**
     * Generate and download the invoice as a PDF.
     */
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

    /**
     * Download the invoice as a PDF.
     */
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

    /**
     * Bulk download invoices as a PDF.
     */
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

    /**
     * Log activity for a model.
     */
    protected function logActivity($action, $model, $oldData = null, $newData = null)
    {
        $description = ucfirst($action) . ' ' . class_basename($model) . ' (' . $model->id . ')';
        $tableName = $model->getTable();

        // Define the properties to log
        $properties = [
            'TargetName' => $model->student->name, // Name of the target student
            'action' => $action, // Type of action (created, updated, deleted)
            'table' => $tableName, // Table where the action occurred
            'user' => auth()->user()->name, // User who performed the action
        ];

        // For updates, show only the changed fields
        if ($action === 'updated' && $oldData && $newData) {
            $changedFields = [];
            foreach ($newData as $key => $value) {
                if ($oldData[$key] !== $value) {
                    $changedFields[$key] = [
                        'old' => $oldData[$key],
                        'new' => $value,
                    ];
                }
            }
            $properties['changed_fields'] = $changedFields;
        }

        // For creations, show only the key fields
        if ($action === 'created') {
            $properties['new_data'] = [
                'membership_id' => $model->membership_id,
                'student_id' => $model->student_id,
                'totalAmount' => $model->totalAmount,
                'amountPaid' => $model->amountPaid,
            ];
        }

        // For deletions, show the key fields of the deleted entity
        if ($action === 'deleted') {
            $properties['deleted_data'] = [
                'membership_id' => $oldData['membership_id'],
                'student_id' => $oldData['student_id'],
                'totalAmount' => $oldData['totalAmount'],
                'amountPaid' => $oldData['amountPaid'],
            ];
        }

        // Log the activity
        activity()
            ->causedBy(auth()->user())
            ->performedOn($model)
            ->withProperties($properties)
            ->log($description);
    }
}