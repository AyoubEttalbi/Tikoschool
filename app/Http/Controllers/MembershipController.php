<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\TeacherMembershipPaymentService;
use App\Support\OfferPercentages;
use App\Support\SchoolScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
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
        // See the note on the same rules in store() — this is the endpoint the
        // wallet-fraud chain actually used.
        $baseRules = [
            'student_id' => 'required|integer|exists:students,id',
            'offer_id' => 'required|integer|exists:offers,id',
            'teachers' => 'required|array',
            'teachers.*.subject' => 'required|string',
            'teachers.*.teacherId' => 'required|integer|exists:teachers,id',
            'teachers.*.amount' => 'required|numeric|min:0',
        ];

        // The confirm resubmission carries the money payload nested under `payload`
        // (the preview request saved nothing), so validate the EFFECTIVE source.
        // Unknown keys are ignored, exactly like $request->validate() does.
        $confirmed = $request->boolean('confirm_teachers_change');
        $validated = Validator::make(
            $confirmed ? $request->input('payload', []) : $request->all(),
            $baseRules
        )->validate();

        // Find the membership (including deleted ones)
        $membership = Membership::withTrashed()->with('student')->findOrFail($id);

        // Both ends: the membership you are editing, and the payload you are
        // editing it into. Checking only one lets you walk a record out of scope.
        $this->authorizeMembership($membership);
        $this->authorizeMembershipPayload($validated);

        // Hard block, before any transaction: every teacher's subject must exist in
        // the NEW offer. The form rebuilds its rows from the selected offer and
        // back-fills old teacher ids by index, so changing the offer silently
        // carries stale subjects along — prod invoice 7027 billed a French teacher
        // on a MATH+PC+SVT offer. Block naming the subjects; never auto-strip,
        // because silent removal is the same bug class. Shown as a dialog because
        // this form never renders the validation error bag.
        $offer = Offer::findOrFail($validated['offer_id']);
        $unknownSubjects = \App\Services\TeacherMembershipPaymentService::unknownOfferSubjects($offer, $validated['teachers']);

        if ($unknownSubjects !== []) {
            return redirect()->back()->with('payment_notice', \App\Support\PaymentNotice::error(
                'Enseignant sans matière dans l\'offre',
                ['Les matières suivantes n\'existent pas dans l\'offre « '.$offer->offer_name.' » : '.implode(', ', $unknownSubjects).'. Corrigez les enseignants avant d\'enregistrer.']
            )->toArray())->withInput();
        }

        DB::beginTransaction();

        try {
            // Log the incoming request data
            Log::info('Update Membership Request:', $request->all());

            // Capture old data before update
            $oldData = $membership->toArray();

            $paymentService = new \App\Services\TeacherMembershipPaymentService;
            $teachersChanged = self::membershipTeachersChanged($membership, $validated);
            $isPaid = $membership->payment_status === 'paid';

            // Reverse the teacher wallet credits before re-pointing the membership.
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
                'days_since_payment' => null, 'within_deadline' => true, 'applied' => [], 'blocked' => [], 'skipped' => [], 'messages' => [],
            ];
            $blockedInvoiceIds = [];

            if ($teachersChanged && $isPaid) {
                // Preview first: this request writes NOTHING. When it would move
                // money, the dialog resubmits the same payload with the confirm
                // flag to execute. Step 0 of the guard: edits that change no
                // teacher, offer or student skip the money path entirely — even a
                // typo fix used to reverse and re-credit every invoice.
                if (! $confirmed) {
                    $preview = $paymentService->previewMembershipTeacherChange($membership, $validated['teachers'], $offer);

                    DB::rollBack();

                    if (self::previewMovesMoney($preview)) {
                        return redirect()->back()->with('payment_notice', \App\Support\PaymentNotice::confirmMembershipChange(
                            $preview,
                            $membership->id,
                            [
                                'student_id' => $validated['student_id'],
                                'offer_id' => $validated['offer_id'],
                                'teachers' => array_values($validated['teachers']),
                            ]
                        )->toArray())->withInput();
                    }

                    // Nothing would move (no invoices, or nothing paid yet): fall
                    // through to the plain save below, no dialog.
                    DB::beginTransaction();
                } else {
                    foreach ($membership->invoices()->get() as $invoice) {
                        $outcome = $paymentService->reverseInvoicePayments($invoice);

                        $reversal['applied'] = array_merge($reversal['applied'], $outcome['applied']);
                        $reversal['blocked'] = array_merge($reversal['blocked'], $outcome['blocked']);
                        // Carried for telemetry (and the skipped-aware dialog): skipped
                        // rows are already healed to zero, so reprocessing below is
                        // safe and they stay out of $blockedInvoiceIds on purpose.
                        $reversal['skipped'] = array_merge($reversal['skipped'], $outcome['skipped'] ?? []);
                        $reversal['messages'] = array_merge($reversal['messages'], $outcome['messages']);
                        $reversal['total_reversed'] += $outcome['total_reversed'];
                        $reversal['within_deadline'] = $reversal['within_deadline'] && $outcome['within_deadline'];

                        // A fully blocked reversal moved no money (past the deadline,
                        // empty wallet): there is nothing to heal, so this invoice is
                        // excluded from the reprocessing below and keeps its current
                        // state. A PARTIALLY applied reversal (wallet_insufficient with
                        // applied > 0) still reprocesses: what was taken is restored,
                        // the unrecovered remainder stays flagged in the notice.
                        if (($outcome['applied'] ?? []) === [] && $outcome['blocked'] !== []) {
                            $blockedInvoiceIds[] = $invoice->id;
                        }
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
            }

            // Update the membership with new data
            $membership->update([
                'student_id' => $validated['student_id'],
                'offer_id' => $validated['offer_id'],
                'teachers' => $validated['teachers'],
            ]);

            // Heal what the reversal above took: reprocess every cleanly reversed
            // invoice against the NEW teacher list. Kept teachers are reactivated and
            // re-credited through the normal delta, added teachers get fresh records,
            // removed teachers stay dead. Without this, kept teachers stay short
            // until someone re-saves each invoice — or forever.
            // Confirmed path only: the preview request reversed nothing, so there is
            // nothing to heal. Inside the surrounding transaction: a failure rolls
            // the whole edit back rather than committing pointed teachers with
            // taken money.
            if ($teachersChanged && $isPaid && $confirmed) {
                $reprocess = $paymentService->reprocessMembershipInvoices($membership, $blockedInvoiceIds);

                if (! ($reprocess['success'] ?? false)) {
                    throw new \RuntimeException(
                        'Le recalcul des paiements enseignants a échoué après la mise à jour. Aucune modification n\'a été enregistrée.'
                    );
                }

                Log::info('Membership teacher change confirmed and executed', [
                    'membership_id' => $membership->id,
                    'confirmed_by' => auth()->id(),
                    'total_reversed' => $reversal['total_reversed'],
                    'reprocessed' => $reprocess['reprocessed'] ?? 0,
                ]);
            }

            // Log the activity
            $this->logActivity('updated', $membership, $oldData, $membership->toArray());

            DB::commit();

            // A confirmed swap reversed AND re-credited wallets; the plain text says
            // so, or the post-save "repris" dialog reads as money taken and kept.
            $feedback = ($teachersChanged && $isPaid && $confirmed)
                ? 'Adhésion mise à jour. Les portefeuilles des enseignants ont été recalculés.'
                : 'Adhésion mise à jour.';

            $redirect = redirect()->back()->with('success', $feedback);

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
        $membership = Membership::withTrashed()->with('student')->findOrFail($id);
        $this->authorizeMembership($membership);

        // Deleting twice must not tombstone twice: the paid invoices are
        // deliberately kept, so a second DELETE would re-run the settled path
        // with zero active rows and write a duplicate trace.
        if ($membership->trashed()) {
            return redirect()->back()->withErrors(['error' => 'Adhésion déjà supprimée.']);
        }

        // Two tiers. FRESH money (a paid invoice inside the reversal window) keeps
        // the hard block below: deactivating the payout records WITHOUT reversing
        // the wallets and then deleting the invoice afterwards finds no ACTIVE
        // record left to reverse, so the claw-back silently never happens. Void
        // each invoice through its own preview + confirm first; deleting the
        // emptied membership stays one click. SETTLED money (every paid invoice
        // past the window) deletes freely instead — the teachers earned it long
        // ago, so destroy() freezes their rows, writes no ledger row, and keeps
        // the paid invoices as history.
        $block = $this->membershipDeleteBlock($membership, false);

        if ($block !== null) {
            return redirect()->back()->with('payment_notice', $block);
        }

        DB::beginTransaction();

        try {
            // Re-check under row locks inside the transaction: a payment landing
            // between the pre-check above and the commit would otherwise be
            // credited to a record this delete is about to deactivate — the same
            // leak through concurrency instead of ordering.
            $block = $this->membershipDeleteBlock($membership, true);

            if ($block !== null) {
                DB::rollBack();

                return redirect()->back()->with('payment_notice', $block);
            }

            // Settled path: every paid invoice is past the reversal window, so the
            // teachers keep what they earned long ago. Freeze their rows WITHOUT
            // moving any wallet, tombstone the kept amounts, keep the paid
            // invoices as financial history.
            $settled = $this->settledDeleteContext($membership);

            if ($settled !== null) {
                $settled['rows'] = (new TeacherMembershipPaymentService)->settleMembershipPayouts($membership);
                $settled['kept_total'] = round(array_sum(array_column($settled['rows'], 'kept')), 2);

                $this->logActivity('deleted', $membership, $membership->toArray(), null, ['settled_delete' => [
                    'membership_id' => $membership->id,
                    'deadline_days' => TeacherMembershipPaymentService::REVERSAL_DEADLINE_DAYS,
                    'invoices' => $settled['invoices'],
                    'rows_kept' => array_map(fn ($row) => [
                        'record_id' => $row['record_id'],
                        'teacher_id' => $row['teacher_id'],
                        'invoice_id' => $row['invoice_id'],
                        'kept' => $row['kept'],
                    ], $settled['rows']),
                ]]);

                // Delete the membership
                $membership->delete();

                DB::commit();

                return redirect()->back()
                    ->with('success', 'Adhésion supprimée.')
                    ->with('payment_notice', \App\Support\PaymentNotice::fromSettledMembershipDelete($settled)->toArray());
            }

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

            return redirect()->back()->with('success', 'Adhésion supprimée.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting membership:', ['error' => $e->getMessage()]);

            return redirect()->back()->withErrors(['error' => 'An error occurred while deleting the membership.']);
        }
    }

    /**
     * Paid-money summary for a delete the block just let through, or null when the
     * membership holds nothing at all.
     *
     * Called inside the destroy transaction right after the locked re-check, so
     * everything listed here is settled by construction — the block would have
     * stopped on anything fresh.
     *
     * @return array{deadline_days: int, invoices: array<int, array{id: int, amount_paid: float, days_since_payment: int|null}>}|null
     */
    private function settledDeleteContext(Membership $membership): ?array
    {
        $invoices = $membership->invoices()->lockForUpdate()->get()
            ->filter(fn ($invoice) => round((float) ($invoice->amountPaid ?? 0), 2) > 0)
            ->map(fn ($invoice) => [
                'id' => $invoice->id,
                'amount_paid' => round((float) $invoice->amountPaid, 2),
                'days_since_payment' => TeacherMembershipPaymentService::reversalDeadlineState($invoice)[0],
            ])
            ->values()
            ->all();

        $hasPaidRows = \App\Models\TeacherMembershipPayment::where('membership_id', $membership->id)
            ->where('is_active', true)
            ->lockForUpdate()
            ->get()
            ->contains(fn ($record) => round((float) ($record->total_paid_to_teacher ?? 0), 2) > 0);

        if ($invoices === [] && ! $hasPaidRows) {
            return null;
        }

        return [
            'deadline_days' => TeacherMembershipPaymentService::REVERSAL_DEADLINE_DAYS,
            'invoices' => $invoices,
        ];
    }

    /**
     * Block-or-null for deleting a membership that still holds teacher money.
     *
     * Two signals, because invoice totals and payout rows provably diverge on
     * prod (amounts edited down after payment, NULL totals, trashed invoices
     * with live paid records): live invoices showing paid money, OR active
     * payout records still holding paid money. Either one blocks.
     *
     * With $lock the reads take row locks for the in-transaction re-check.
     *
     * @return array<string, mixed>|null ready-to-flash payment_notice payload
     */
    private function membershipDeleteBlock(Membership $membership, bool $lock): ?array
    {
        if ($lock) {
            // Serialize against concurrent billing: lock the parent (invoice
            // store/update both touch it) plus every invoice and active payout
            // row, then judge paid-ness in PHP. Locks scoped to the paid-only
            // predicate would miss a 0→paid flip or a fresh insert landing
            // inside the re-check→deactivate window.
            Membership::whereKey($membership->id)->lockForUpdate()->first();

            $liveInvoices = $membership->invoices()->lockForUpdate()->get();
            $paidRows = \App\Models\TeacherMembershipPayment::where('membership_id', $membership->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->get()
                ->filter(fn ($record) => round((float) ($record->total_paid_to_teacher ?? 0), 2) > 0)
                ->values();
        } else {
            $liveInvoices = $membership->invoices()->get();
            $paidRows = \App\Models\TeacherMembershipPayment::where('membership_id', $membership->id)
                ->where('is_active', true)
                ->get()
                ->filter(fn ($record) => round((float) ($record->total_paid_to_teacher ?? 0), 2) > 0)
                ->values();
        }

        $livePaidInvoices = $liveInvoices
            ->filter(fn ($invoice) => round((float) ($invoice->amountPaid ?? 0), 2) > 0)
            ->values();

        // Settled path: every paid invoice is past the reversal window AND every
        // paid row sits on one of those settled invoices. No money can still move,
        // so the delete may proceed — destroy() freezes the rows without touching
        // the wallets. Anything else (a fresh invoice, a stranded row on a trashed
        // invoice, a row on an edited-down zero invoice) keeps the hard block.
        $settledInvoiceIds = $livePaidInvoices
            ->filter(fn ($invoice) => TeacherMembershipPaymentService::isInvoiceSettled($invoice))
            ->map(fn ($invoice) => (int) $invoice->id)
            ->all();

        $unsettled = $paidRows->reject(fn ($record) => in_array((int) $record->invoice_id, $settledInvoiceIds, true));

        if (count($settledInvoiceIds) === $livePaidInvoices->count() && $unsettled->isEmpty()) {
            return null;
        }

        $details = $livePaidInvoices->map(fn ($invoice) => [
            'label' => 'Facture n°'.$invoice->id,
            'value' => number_format((float) $invoice->amountPaid, 2, ',', ' ').' DH payés',
            'note' => 'À annuler d’abord',
        ])->all();

        $messages = $livePaidInvoices->isNotEmpty()
            ? ['Cette adhésion porte '.$livePaidInvoices->count().' facture(s) payée(s). Annulez chaque facture d’abord (le remboursement des enseignants sera proposé à ce moment-là), puis supprimez l’adhésion.']
            : ['Des versements déjà effectués aux enseignants restent liés à cette adhésion (factures annulées ou montants ajustés). Régularisez-les manuellement avant de supprimer l’adhésion.'];

        return \App\Support\PaymentNotice::error(
            'Adhésion non supprimée : de l’argent enseignant est en jeu',
            $messages,
            $details
        )->toArray();
    }

    /**
     * Whether a membership save changes who teaches what.
     *
     * Compares teacher SETS (id + subject) plus the offer and student links. Row
     * amounts are excluded on purpose: the form resubmits float dust
     * (49.999998999999995) and the commission math never reads those amounts.
     * Anything this returns false for skips the money path entirely.
     */
    private static function membershipTeachersChanged(Membership $membership, array $validated): bool
    {
        $normalise = fn ($list) => collect(is_array($list) ? $list : [])
            ->map(fn ($t) => (string) (is_array($t) ? ($t['teacherId'] ?? '') : '')
                .'|'.OfferPercentages::normalise((string) (is_array($t) ? ($t['subject'] ?? '') : '')))
            ->sort()
            ->values()
            ->all();

        return $normalise($membership->teachers ?? []) !== $normalise($validated['teachers'] ?? [])
            || (int) $membership->offer_id !== (int) ($validated['offer_id'] ?? 0)
            || (int) $membership->student_id !== (int) ($validated['student_id'] ?? 0);
    }

    /**
     * Whether a teacher-change preview would move any money.
     *
     * A change with no invoices, or nothing paid yet, executes directly — a
     * confirm dialog nobody needed is a dialog people learn to click through.
     */
    private static function previewMovesMoney(array $preview): bool
    {
        if ((($preview['reversal'] ?? [])['total_reversed'] ?? 0) > 0) {
            return true;
        }

        if ((($preview['reversal'] ?? [])['blocked'] ?? []) !== []) {
            return true;
        }

        return array_sum(array_column($preview['added'] ?? [], 'est_amount')) > 0;
    }

    /**
     * Log activity for a model.
     */
    protected function logActivity($action, $model, $oldData = null, $newData = null, array $extraDeletedData = [])
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

        // Tombstones (e.g. settled_delete) live top-level so they stay queryable
        // without digging through the entity snapshot.
        $properties += $extraDeletedData;

        // Log the activity
        activity()
            ->causedBy(Auth::user())
            ->performedOn($model)
            ->withProperties($properties)
            ->log($description);
    }
}
