<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Support\SchoolScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class MembershipController extends Controller
{
    /**
     * Object-level scope for a membership, via its student.
     *
     * This controller had no SchoolScope call, no role check and no abort(403) anywhere.
     * A membership carries the `teachers` JSON array that drives every payout, so an
     * unscoped id let one school's staff re-point another school's membership at
     * themselves and then trigger a wallet credit from that student's real payment.
     */
    private function authorizeMembership(Membership $membership): void
    {
        $student = $membership->student;

        if (! $student) {
            SchoolScope::authorizeRole(['admin']);

            return;
        }

        SchoolScope::authorizeStudent($student);
    }

    /**
     * Scope-check a submitted membership payload: the student being enrolled, and every
     * teacher named on it.
     *
     * The teacher half is the important one. Nothing previously stopped a caller listing
     * an arbitrary teacher id — including one at another school — as the highest-earning
     * subject teacher on a membership, which is what turns a missing route guard into a
     * way to move money into your own wallet.
     */
    private function authorizeMembershipPayload(array $validated): void
    {
        SchoolScope::authorizeStudent(Student::findOrFail($validated['student_id']));

        $schoolIds = SchoolScope::schoolIdsFor();

        if ($schoolIds === null) {
            return; // admin
        }

        $teacherIds = collect($validated['teachers'] ?? [])
            ->pluck('teacherId')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique();

        if ($teacherIds->isEmpty()) {
            return;
        }

        $inScope = Teacher::whereIn('teachers.id', $teacherIds)
            ->whereHas('schools', fn ($s) => $s->whereIn('schools.id', $schoolIds))
            ->pluck('teachers.id')
            ->map(fn ($id) => (int) $id);

        if ($teacherIds->diff($inScope)->isNotEmpty()) {
            throw new \App\Exceptions\AccessDeniedException(
                "Un ou plusieurs enseignants sélectionnés n'appartiennent pas à votre établissement."
            );
        }
    }

    /**
     * Dropdown data for the create/edit membership forms.
     *
     * This replaces `Student::all()` + `Teacher::all()`, which were unscoped, unpaginated
     * and unprojected — every membership form shipped every student in the product to the
     * browser, including guardian phone numbers and the medical fields (hasDisease,
     * diseaseName, medication), plus every teacher. Cost grew with total students across
     * all schools, on a page that only ever needs a name and an id.
     *
     * Now: scoped to the caller's schools, and only the columns the picker renders.
     */
    private function pickerData(): array
    {
        $schoolIds = SchoolScope::schoolIdsFor();

        $students = Student::query()
            ->select(['id', 'firstName', 'lastName', 'schoolId', 'classId', 'levelId'])
            ->when($schoolIds !== null, fn ($q) => $q->whereIn('schoolId', $schoolIds))
            ->orderBy('firstName')
            ->get();

        $teachers = Teacher::query()
            ->select(['id', 'first_name', 'last_name', 'email', 'status'])
            ->when($schoolIds !== null, fn ($q) => $q->whereHas(
                'schools',
                fn ($s) => $s->whereIn('schools.id', $schoolIds)
            ))
            ->orderBy('first_name')
            ->get();

        return [
            'students' => $students,
            'offers' => Offer::with('subjects')->get(),
            'teachers' => $teachers,
        ];
    }

    /**
     * Display a listing of memberships.
     */
    public function index()
    {
        try {
            $memberships = Membership::withTrashed()->with(['student', 'offer', 'invoices'])->paginate(10);

            return Inertia::render('Menu/SingleStudentPage', [
                'memberships' => $memberships,
            ]);
        } catch (\Exception $e) {
            Log::error('Error listing memberships:', ['error' => $e->getMessage()]);

            return redirect()->back()->withErrors(['error' => 'An error occurred while loading memberships.']);
        }
    }

    /**
     * Show the form for creating a new membership.
     */
    public function create()
    {
        try {
            return Inertia::render('Memberships/Create', $this->pickerData());
        } catch (\Exception $e) {
            Log::error('Error preparing membership creation:', ['error' => $e->getMessage()]);

            return redirect()->back()->withErrors(['error' => 'An error occurred while preparing the membership form.']);
        }
    }

    /**
     * Store a newly created membership in the database.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            // `teachers.*.teacherId` was `required|string` with no `exists:` rule and no
            // ownership check, and processTeacherPayment() then did Teacher::find() on it.
            // That was half of a wallet-fraud chain: name yourself on someone else's
            // membership, then trigger an invoice update to credit your own wallet from
            // their student's real payment. `exists:` makes the id real; the scope check
            // below makes it yours.
            $validated = $request->validate([
                'student_id' => 'required|integer|exists:students,id',
                'offer_id' => 'required|integer|exists:offers,id',
                'teachers' => 'required|array',
                'teachers.*.subject' => 'required|string',
                'teachers.*.teacherId' => 'required|integer|exists:teachers,id',
                'teachers.*.amount' => 'required|numeric|min:0',
            ]);

            $this->authorizeMembershipPayload($validated);

            // Create the membership record with payment_status set to 'pending'
            $membership = Membership::create([
                'student_id' => $validated['student_id'],
                'offer_id' => $validated['offer_id'],
                'teachers' => $validated['teachers'],
                'payment_status' => 'pending', // Add default payment status
                'is_active' => false, // Membership is inactive until paid
            ]);

            // Log the activity
            $this->logActivity('created', $membership, null, $membership->toArray());

            DB::commit();

            return redirect()->back()->with('success', 'Membership created successfully!');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating membership:', ['error' => $e->getMessage()]);

            return redirect()->back()->withErrors(['error' => 'An error occurred while processing your request.']);
        }
    }

    /**
     * Display the specified membership.
     */
    public function show($id)
    {
        $membership = Membership::withTrashed()->with(['student', 'offer', 'invoices'])->findOrFail($id);
        $this->authorizeMembership($membership);

        try {
            return Inertia::render('Memberships/Show', [
                'membership' => $membership,
            ]);
        } catch (\Exception $e) {
            Log::error('Error showing membership:', ['membership_id' => $id, 'error' => $e->getMessage()]);

            return redirect()->back()->withErrors(['error' => 'An error occurred while loading the membership.']);
        }
    }

    /**
     * Show the form for editing the specified membership.
     */
    public function edit($id)
    {
        $membership = Membership::withTrashed()->with('student')->findOrFail($id);
        $this->authorizeMembership($membership);

        try {
            return Inertia::render('Memberships/Edit', array_merge(
                ['membership' => $membership],
                $this->pickerData()
            ));
        } catch (\Exception $e) {
            Log::error('Error preparing membership edit:', ['membership_id' => $id, 'error' => $e->getMessage()]);

            return redirect()->back()->withErrors(['error' => 'An error occurred while loading the membership for editing.']);
        }
    }

    /**
     * Update the specified membership in the database.
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        try {
            // Log the incoming request data
            Log::info('Update Membership Request:', $request->all());

            // See the note on the same rules in store() — this is the endpoint the
            // wallet-fraud chain actually used.
            $validated = $request->validate([
                'student_id' => 'required|integer|exists:students,id',
                'offer_id' => 'required|integer|exists:offers,id',
                'teachers' => 'required|array',
                'teachers.*.subject' => 'required|string',
                'teachers.*.teacherId' => 'required|integer|exists:teachers,id',
                'teachers.*.amount' => 'required|numeric|min:0',
            ]);

            // Find the membership (including deleted ones)
            $membership = Membership::withTrashed()->with('student')->findOrFail($id);

            // Both ends: the membership you are editing, and the payload you are
            // editing it into. Checking only one lets you walk a record out of scope.
            $this->authorizeMembership($membership);
            $this->authorizeMembershipPayload($validated);

            // Capture old data before update
            $oldData = $membership->toArray();

            // Reverse the teacher wallet credits before re-pointing the membership.
            //
            // This block used to instantiate $paymentService on one line and never call it
            // (grep confirmed that line was its only occurrence in the file), then flip
            // is_active to false. The comment said "Reverse old teacher payments" and the
            // deactivation *looked* like a reversal, so the code read as correct — but no
            // money moved. Reassigning a paid membership's teachers left the old teacher
            // holding money they were no longer owed, while the record that would let
            // payouts:audit notice was marked inactive.
            //
            // reverseInvoicePayments() debits the wallet through TeacherWalletService and
            // deactivates the record itself, so this both moves the money and does what
            // the old code did. It throws on failure (see reverseTeacherPayment), which
            // rolls back the surrounding transaction rather than committing a half-update.
            // Merged across every invoice on the membership, so reassigning the teachers of a
            // membership carrying several invoices produces one dialog listing all of them
            // rather than one per invoice — or, as before, none at all.
            $reversal = [
                'reversed' => false, 'total_reversed' => 0.0, 'deadline_days' => \App\Services\TeacherMembershipPaymentService::REVERSAL_DEADLINE_DAYS,
                'days_since_payment' => null, 'within_deadline' => true, 'applied' => [], 'blocked' => [], 'messages' => [],
            ];

            if ($membership->payment_status === 'paid') {
                $paymentService = new \App\Services\TeacherMembershipPaymentService;

                foreach ($membership->invoices()->get() as $invoice) {
                    $outcome = $paymentService->reverseInvoicePayments($invoice);

                    $reversal['applied'] = array_merge($reversal['applied'], $outcome['applied']);
                    $reversal['blocked'] = array_merge($reversal['blocked'], $outcome['blocked']);
                    $reversal['messages'] = array_merge($reversal['messages'], $outcome['messages']);
                    $reversal['total_reversed'] += $outcome['total_reversed'];
                    $reversal['within_deadline'] = $reversal['within_deadline'] && $outcome['within_deadline'];
                }

                $reversal['total_reversed'] = round($reversal['total_reversed'], 2);
                $reversal['reversed'] = $reversal['total_reversed'] > 0;
                $reversal['messages'] = array_values(array_unique($reversal['messages']));

                // Anything left active belongs to an invoice that no longer exists;
                // reverseInvoicePayments() cannot reach it, and leaving it active would
                // double-count on the next payout run.
                \App\Models\TeacherMembershipPayment::where('membership_id', $membership->id)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
            }

            // Update the membership with new data
            $membership->update([
                'student_id' => $validated['student_id'],
                'offer_id' => $validated['offer_id'],
                'teachers' => $validated['teachers'],
            ]);

            // Log the activity
            $this->logActivity('updated', $membership, $oldData, $membership->toArray());

            DB::commit();

            $redirect = redirect()->back()->with('success', 'Adhésion mise à jour.');

            // Reassigning teachers moves money out of the previous teacher's wallet. Whoever
            // pressed save has to see that, and see what could not be taken back.
            $notice = \App\Support\PaymentNotice::fromReversal($reversal);

            return $notice
                ? $redirect->with('payment_notice', $notice->toArray())
                : $redirect;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating membership:', ['error' => $e->getMessage()]);

            return redirect()->back()->withErrors([
                'error' => "L'adhésion n'a pas pu être mise à jour. Aucune modification n'a été enregistrée, "
                    .'et les portefeuilles des enseignants sont inchangés.',
            ]);
        }
    }

    /**
     * Remove the specified membership from the database.
     */
    public function destroy($id)
    {
        DB::beginTransaction();

        $membership = Membership::withTrashed()->with('student')->findOrFail($id);
        $this->authorizeMembership($membership);

        try {
            // Log the activity before deletion
            $this->logActivity('deleted', $membership, $membership->toArray(), null);

            // Only deactivate teacher payment records if the membership was paid
            // (but don't reverse wallet payments - that only happens when invoices are deleted)
            if ($membership->payment_status === 'paid') {
                // Deactivate teacher payment records
                \App\Models\TeacherMembershipPayment::where('membership_id', $membership->id)
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
            }

            // Delete the membership
            $membership->delete();

            DB::commit();

            return redirect()->back()->with('success', 'Membership deleted successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting membership:', ['error' => $e->getMessage()]);

            return redirect()->back()->withErrors(['error' => 'An error occurred while deleting the membership.']);
        }
    }

    /**
     * Log activity for a model.
     */
    protected function logActivity($action, $model, $oldData = null, $newData = null)
    {
        $description = ucfirst($action).' '.class_basename($model).' ('.$model->id.')';
        $tableName = $model->getTable();

        // Define the properties to log
        $properties = [
            'TargetName' => $model->student->firstName.' '.$model->student->lastName, // Name of the target student
            'action' => $action, // Type of action (created, updated, deleted)
            'table' => $tableName, // Table where the action occurred
            'user' => Auth::user()->name, // User who performed the action
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
                'student_id' => $model->student_id,
                'offer_id' => $model->offer_id,
                'teachers' => $model->teachers,
            ];
        }

        // For deletions, show the key fields of the deleted entity
        if ($action === 'deleted') {
            $properties['deleted_data'] = [
                'student_id' => $oldData['student_id'],
                'offer_id' => $oldData['offer_id'],
                'teachers' => $oldData['teachers'],
            ];
        }

        // Log the activity
        activity()
            ->causedBy(Auth::user())
            ->performedOn($model)
            ->withProperties($properties)
            ->log($description);
    }
}
