<?php

namespace App\Http\Controllers;

use App\Models\Classes;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\School;
use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Support\PdfBudget;
use App\Support\SchoolScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class InvoiceController extends Controller
{
    /**
     * Upper bound on a single bulk PDF run. PdfBudget::LIMIT covers roughly 2000 invoices
     * at the measured ~0.12 MB each; 500 keeps a wide margin and is far above any real
     * selection a user makes from the invoices table.
     */
    private const MAX_BULK_INVOICES = 500;

    /**
     * Object-level scope for a single invoice.
     *
     * This controller previously contained no SchoolScope call, no role check and no
     * abort(403) anywhere in the file — every method took an id from the route and
     * findOrFail()ed it. Invoices carry amounts, and destroy() reverses teacher wallet
     * credits, so an unscoped id was a money-destroying primitive, not just a data leak.
     *
     * An invoice belongs to a student (student_id), falling back to its membership's
     * student for the rows written before student_id was populated. Scoping the student
     * scopes the invoice.
     */
    private function authorizeInvoice(Invoice $invoice): void
    {
        $student = $invoice->student ?: $invoice->membership?->student;

        if (! $student) {
            // An invoice with no reachable student cannot be scoped, so it is
            // admin-only rather than open to everyone.
            SchoolScope::authorizeRole(['admin']);

            return;
        }

        SchoolScope::authorizeStudent($student);
    }

    /**
     * Display a listing of invoices.
     *
     * This method existed unrouted for years and rendered Menu/SingleStudentPage when
     * called by hand — the dead "Voir toutes les factures impayées" link on the
     * assistant home pointed here via GET /invoices, hit Route::fallback, and bounced
     * the user back to where they started. It is now the real list page behind
     * RequireRole:admin,assistant.
     *
     * Scope: assistants see only their schools' invoices (SchoolScope); admins get
     * everything, optionally narrowed with ?school=. "Unpaid" is the derived rule used
     * everywhere else: rest > 0 OR totalAmount > amountPaid.
     */
    public function index(Request $request)
    {
        SchoolScope::authorizeRole(['admin', 'assistant']);

        $user = Auth::user();
        $schoolIds = SchoolScope::schoolIdsFor($user);

        // Route-level middleware already gates the roles; this is the object-level half.
        // Placed before any query so a denial can never be swallowed into empty data.
        if ($schoolIds === []) {
            // An assistant attached to no school sees an empty page, not every invoice.
            $schoolIds = null;
            $query = Invoice::query()->whereRaw('1 = 0');
        } else {
            $query = Invoice::query()
                ->with([
                    // withTrashed: a withdrawn student's partially paid invoice is
                    // still receivable money — it stays listed and searchable.
                    'student' => fn ($studentQuery) => $studentQuery->withTrashed()->with(['class', 'school']),
                    'offer',
                ])
                ->where('type', 'invoice');

            if ($schoolIds !== null) {
                $query->whereHas('student', fn ($studentQuery) => $studentQuery
                    ->withTrashed()
                    ->whereIn('schoolId', $schoolIds));
            }
        }

        // Object-level scope. Assistants are pinned to their schools; ?school= may
        // only NARROW that (a multi-school assistant clicking a per-school link on
        // their home page gets that school, never another). Admins get everything
        // and may narrow freely.
        if ($request->filled('school')) {
            $requestedSchool = (int) $request->input('school');
            $narrowedTo = $schoolIds === null
                ? [$requestedSchool]
                : array_values(array_intersect([$requestedSchool], array_map('intval', $schoolIds)));

            // An out-of-scope school narrows to nothing — it must not widen the scope.
            if ($narrowedTo !== []) {
                $query->whereHas('student', fn ($studentQuery) => $studentQuery
                    ->where('schoolId', $narrowedTo[0]));
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        // 'toutes' is the frontend alias for 'all': useFilterNavigation strips 'all'
        // as an unset value, so the alias is what actually arrives in the URL.
        $statusInput = $request->input('status');
        $status = in_array($statusInput, ['unpaid', 'paid', 'all', 'toutes'], true)
            ? $statusInput
            : 'unpaid';

        if ($status === 'unpaid') {
            $query->where(function ($q) {
                $q->whereRaw('COALESCE(rest, 0) > 0')
                    ->orWhereRaw('COALESCE(totalAmount, 0) > COALESCE(amountPaid, 0)');
            });
        } elseif ($status === 'paid') {
            $query->whereRaw('COALESCE(totalAmount, 0) > 0')
                ->whereRaw('COALESCE(amountPaid, 0) >= COALESCE(totalAmount, 0)');
        }

        $search = trim((string) $request->input('search', ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            // The first/last-name OR must be grouped in its own closure, or it
            // escapes whereHas's student correlation: "firstName LIKE ? OR
            // lastName LIKE ? AND invoices.student_id = students.id" matches
            // ANY invoice via any same-first-name student anywhere.
            $query->whereHas('student', fn ($studentQuery) => $studentQuery
                ->withTrashed()
                ->where(function ($name) use ($like) {
                    $name->where('firstName', 'like', $like)
                        ->orWhere('lastName', 'like', $like);
                }));
        }

        $invoices = $query
            ->orderByDesc('creationDate')
            ->orderByDesc('billDate')
            ->paginate(15)
            ->withQueryString();

        $rows = collect($invoices->items())->map(function (Invoice $invoice) {
            $student = $invoice->student;
            $total = is_numeric($invoice->totalAmount) ? (float) $invoice->totalAmount : 0.0;
            $paid = is_numeric($invoice->amountPaid) ? (float) $invoice->amountPaid : 0.0;

            return [
                'id' => $invoice->id,
                'student_id' => $student?->id,
                'student_name' => $student ? $student->firstName.' '.$student->lastName : 'Unknown',
                'student_class' => $student && $student->class ? $student->class->name : null,
                'student_school' => $student && $student->school ? $student->school->name : null,
                'billDate' => $invoice->billDate?->format('Y-m-d'),
                'creationDate' => $invoice->creationDate?->format('Y-m-d'),
                'totalAmount' => $total,
                'amountPaid' => $paid,
                'rest' => max(0.0, round($total - $paid, 2)),
                'offer_name' => $invoice->offer?->offer_name,
                'offer_id' => $invoice->offer_id,
            ];
        });

        return Inertia::render('Menu/InvoicesIndexPage', [
            'invoices' => $rows,
            'links' => $invoices->linkCollection(),
            'filters' => [
                'status' => $status,
                'search' => $search,
                'school' => $request->input('school'),
            ],
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
            $membership = Membership::withTrashed()->with(['student', 'offer'])->findOrFail($membership_id);
        }

        $studentMemberships = Membership::withTrashed()->with(['student', 'offer'])
            ->where('payment_status', 'pending')
            ->get()
            ->map(function ($membership) {
                return [
                    'id' => $membership->id,
                    'offer_name' => $membership->student->name.' - '.$membership->offer->name,
                    'price' => $membership->offer->price,
                    'offer_id' => $membership->offer_id,
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
            // Normalize membership_id and student_id before validation
            $incomingMembershipId = $request->input('membership_id', $request->input('membershipId'));
            if ($incomingMembershipId !== null && $incomingMembershipId !== '') {
                if (is_string($incomingMembershipId) && is_numeric($incomingMembershipId)) {
                    $incomingMembershipId = (int) $incomingMembershipId;
                }
                $request->merge(['membership_id' => $incomingMembershipId]);
            }

            // If student_id is missing but membership_id is provided, infer student_id from membership
            if (! $request->filled('student_id') && $request->filled('membership_id')) {
                $membershipForStudent = Membership::withTrashed()->find($request->input('membership_id'));
                if ($membershipForStudent) {
                    $request->merge(['student_id' => $membershipForStudent->student_id]);
                }
            }

            // Validate the incoming request

            $validated = $request->validate([
                // `exists:` matters — these ids drive teacher payouts. Without it an invoice
                // could be pointed at an arbitrary membership/student id.
                'membership_id' => 'required|integer|exists:memberships,id',
                'student_id' => 'required|integer|exists:students,id',
                'months' => [
                    'required',
                    'integer',
                    'min:0',
                    'max:24',
                    function ($attribute, $value, $fail) use ($request) {
                        if ($value === 0 && ! $request->input('includePartialMonth')) {
                            $fail('Le champ mois doit être supérieur à 0 si le mois partiel n\'est pas sélectionné.');
                        }
                    },
                ],
                'selected_months' => 'nullable', // Accept array or stringified JSON
                'billDate' => 'required|date',
                'creationDate' => 'nullable|date',
                // Money fields are floored at 0. `rest` may legitimately be 0 but never negative.
                'totalAmount' => 'required|numeric|min:0|max:9999999.99',
                'amountPaid' => 'required|numeric|min:0|max:9999999.99',
                'rest' => 'required|numeric|min:0|max:9999999.99',
                'offer' => 'nullable|string',
                'offer_id' => 'nullable|integer|exists:offers,id',
                'endDate' => 'nullable|date',
                'includePartialMonth' => 'nullable|boolean',
                // partialMonthAmount feeds straight into the teacher commission calculation
                // and then into an increment('wallet'). Unbounded, it was a direct way to
                // credit an arbitrary amount to a teacher's wallet.
                'partialMonthAmount' => 'nullable|numeric|min:0|lte:amountPaid',
                'last_payment_date' => 'nullable|date',
            ], [
                'membership_id.required' => 'Adhésion manquante: veuillez sélectionner une adhésion valide.',
                'membership_id.exists' => 'Adhésion introuvable.',
                'student_id.required' => 'Étudiant manquant: veuillez sélectionner un étudiant.',
                'student_id.exists' => 'Étudiant introuvable.',
                'months.required' => 'Le nombre de mois est obligatoire.',
                'billDate.required' => 'La date de facturation est obligatoire.',
                'totalAmount.required' => 'Le montant total est obligatoire.',
                'amountPaid.required' => 'Le montant payé est obligatoire.',
                'rest.required' => 'Le reste à payer est obligatoire.',
                'partialMonthAmount.lte' => 'Le montant du mois partiel ne peut pas dépasser le montant payé.',
            ]);

            // `exists:` proves the student is real, not that this staff member may bill
            // them. Creating an invoice credits teacher wallets, so an unscoped
            // student_id lets one school's assistant move money against another's student.
            SchoolScope::authorizeStudent(
                \App\Models\Student::findOrFail($validated['student_id'])
            );

            // Always accept both selectedMonths and selected_months from frontend
            $selectedMonths = $request->input('selectedMonths');
            if (is_null($selectedMonths)) {
                $selectedMonths = $request->input('selected_months');
            }
            if (is_string($selectedMonths)) {
                $decoded = json_decode($selectedMonths, true);
                if (is_array($decoded)) {
                    $selectedMonths = $decoded;
                } else {
                    $selectedMonths = [];
                }
            }
            if (! is_array($selectedMonths)) {
                $selectedMonths = [];
            }

            $validated['selected_months'] = json_encode($selectedMonths);
            // Set the creator
            $validated['created_by'] = auth()->email ?? auth()->id(); // Fallback to ID if email is not available

            // Fetch the membership (including deleted ones)
            $membership = Membership::withTrashed()->findOrFail($validated['membership_id']);

            // Pré-vérifications bloquantes pour éviter des factures invalides
            if (! $membership->offer) {
                throw new \Exception('Offre introuvable pour cette adhésion. Veuillez vérifier l\'offre.');
            }
            if (! is_array($membership->teachers) || count($membership->teachers) === 0) {
                throw new \Exception('Aucun enseignant n\'est associé à cette adhésion. Veuillez ajouter au moins un enseignant.');
            }
            if (! is_array($membership->offer->percentage)) {
                throw new \Exception('L\'offre sélectionnée n\'a pas de pourcentages valides.');
            }
            // Always set offer_id from membership
            $validated['offer_id'] = $membership->offer_id;

            // RECOMPUTE the money server-side. The pro-rata formula previously lived only in
            // the browser and whatever the client posted was stored verbatim — so a crafted
            // request could set any price, and (because teacher commission is a percentage of
            // the invoice) mint arbitrary teacher wallet credit.
            // A client total BELOW the computed price is still honoured as a discount.
            $pricing = new \App\Services\InvoicePricingService;
            $priced = $pricing->reconcile($membership, $validated + [
                'selected_months' => $selectedMonths,
                'billDate' => $validated['billDate'] ?? null,
            ]);

            $validated['totalAmount'] = $priced['totalAmount'];
            $validated['amountPaid'] = $priced['amountPaid'];
            $validated['rest'] = $priced['rest'];
            $validated['partialMonthAmount'] = $priced['partialMonthAmount'];

            if ($priced['discountApplied'] > 0) {
                Log::info('Invoice created with a discount', [
                    'membership_id' => $membership->id,
                    'discount' => $priced['discountApplied'],
                    'charged' => $priced['totalAmount'],
                    'by' => auth()->id(),
                ]);
            }

            // The payment clock is stamped server-side, never trusted from the client.
            // last_payment_date drives the reversal deadline (and through it the
            // settled membership delete): a backdated value would convert a hard
            // block into a delete that lets teachers keep fresh money. The update
            // path already forces now(); store was the outlier.
            $validated['last_payment_date'] = round((float) $validated['amountPaid'], 2) > 0
                ? now()->toDateTimeString()
                : null;

            // Create the invoice
            $invoice = Invoice::create($validated);
            Log::info('Invoice created successfully', ['invoice_id' => $invoice->id]);

            // The payment EVENT, at the moment it happened. The cashier sums events; the
            // invoice's cumulative amountPaid can never answer "what came in today".
            \App\Models\InvoicePaymentLog::recordDelta($invoice, 0, auth()->id());

            // Log the activity
            $this->logActivity('created', $invoice, null, $invoice->toArray());

            // Process teacher membership payments using the new service with validation
            $paymentService = new \App\Services\TeacherMembershipPaymentService;
            $paymentResult = $paymentService->processInvoicePayment($invoice, $validated);

            // Validate that payment records were created successfully
            if (! $paymentResult || ! $paymentResult['success'] || (($paymentResult['created_records'] ?? 0) + ($paymentResult['updated_records'] ?? 0)) === 0) {
                Log::error('No payment records created', ['invoice_id' => $invoice->id, 'result' => $paymentResult]);

                // Carry EVERY reason, not just the first — see PaymentProcessingException.
                throw new \App\Exceptions\PaymentProcessingException(
                    $this->convertToUserFriendlyErrors($paymentResult['errors'] ?? [])
                );
            }

            Log::info('Payment records created successfully', [
                'invoice_id' => $invoice->id,
                'created_records' => $paymentResult['created_records'] ?? 0,
                'updated_records' => $paymentResult['updated_records'] ?? 0,
            ]);

            // NEW: Reconcile teacher payouts for initial invoice creation
            if ($validated['amountPaid'] > 0) {
                $reconcileResult = $paymentService->reconcilePaidMonthsForInvoice($invoice);
                if (! $reconcileResult['success']) {
                    Log::warning('Initial reconciliation reported issues', [
                        'invoice_id' => $invoice->id,
                        'errors' => $reconcileResult['errors'],
                    ]);
                } else {
                    Log::info('Initial reconciliation completed', [
                        'invoice_id' => $invoice->id,
                        'adjusted_records' => $reconcileResult['adjusted_records'],
                        'total_delta' => $reconcileResult['total_delta'],
                    ]);
                }
            }

            // Always update start_date. Update end_date based on actual paid period
            $updateData = [
                'start_date' => $validated['billDate'],
                'payment_status' => ($validated['amountPaid'] >= $validated['totalAmount']) ? 'paid' : 'pending',
                'is_active' => ($validated['amountPaid'] >= $validated['totalAmount']),
            ];

            // Update end_date: use invoice end_date if it's more recent than current membership end_date
            // This ensures the membership reflects the actual paid period
            if (empty($membership->end_date) || (isset($validated['endDate']) && $validated['endDate'] > $membership->end_date)) {
                $updateData['end_date'] = $validated['endDate'];
            }
            $membership->update($updateData);

            DB::commit();

            return redirect()->back()->with('success', 'Facture créée avec succès.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating invoice:', ['error' => $e->getMessage()]);

            // NEW: Return proper error response
            if (request()->expectsJson() || request()->header('Accept') === 'application/json') {
                // Only the ids needed to correlate the failure. This used to echo
                // $request->except(['_token']) — the whole submitted body — straight back
                // to the caller, which turns any failed request into a reflection
                // primitive and leaks whatever the client sent.
                return $this->createErrorResponse([$e->getMessage()], [
                    'membership_id' => $request->input('membership_id'),
                    'student_id' => $request->input('student_id'),
                ]);
            }

            return $this->withPaymentNotice(
                redirect()->back()->withErrors([
                    'error' => $this->convertSingleError($e->getMessage()) ?: 'Création de facture annulée: '.$e->getMessage(),
                ])->withInput(),
                $e,
            );
        }
    }

    /**
     * Attach the full list of reasons to a redirect, so the UI can open a dialog listing
     * every problem instead of a one-line banner carrying the first one.
     */
    private function withPaymentNotice(\Illuminate\Http\RedirectResponse $redirect, \Throwable $e): \Illuminate\Http\RedirectResponse
    {
        $errors = $e instanceof \App\Exceptions\PaymentProcessingException
            ? $e->errors()
            : [$this->convertSingleError($e->getMessage())];

        $notice = \App\Support\PaymentNotice::fromProcessingErrors(array_filter($errors));

        return $notice ? $redirect->with('payment_notice', $notice->toArray()) : $redirect;
    }

    /**
     * Display the specified invoice.
     */
    public function show($id)
    {
        $invoice = Invoice::with([
            'membership' => function ($membershipQuery) {
                $membershipQuery->withTrashed()->with(['student', 'student.class', 'student.school', 'offer']);
            },
            'student',
            'student.class',
            'student.school',
            'offer',
        ])->findOrFail($id);

        $this->authorizeInvoice($invoice);

        $invoiceData = $invoice->toArray();
        // Always send selectedMonths as array if present
        if (isset($invoiceData['selected_months'])) {
            $selectedMonths = $invoiceData['selected_months'];
            if (is_string($selectedMonths)) {
                $decoded = json_decode($selectedMonths, true);
                if (is_array($decoded)) {
                    $invoiceData['selectedMonths'] = $decoded;
                } else {
                    $invoiceData['selectedMonths'] = [];
                }
            } elseif (is_array($selectedMonths)) {
                $invoiceData['selectedMonths'] = $selectedMonths;
            } else {
                $invoiceData['selectedMonths'] = [];
            }
        } else {
            $invoiceData['selectedMonths'] = [];
        }

        return Inertia::render('Invoices/InvoiceViewer', [
            'invoice' => $invoiceData,
        ]);
    }

    /**
     * API: Get a single invoice as JSON (for modal details)
     */
    public function apiShow($id)
    {
        $invoice = Invoice::with([
            'membership' => function ($membershipQuery) {
                $membershipQuery->withTrashed()->with(['student', 'student.class', 'student.school', 'offer']);
            },
            'student',
            'student.class',
            'student.school',
            'offer',
        ])->findOrFail($id);

        $this->authorizeInvoice($invoice);

        // Prefer membership.student, fallback to invoice.student
        $student = $invoice->membership && $invoice->membership->student ? $invoice->membership->student : $invoice->student;
        $student_name = $student ? trim(($student->firstName ?? '').' '.($student->lastName ?? '')) : null;
        $student_class = $student && $student->class ? $student->class->name : null;
        $student_school = $student && $student->school ? $student->school->name : null;
        $student_id = $student ? $student->id : null;

        // Prefer membership.offer, fallback to invoice.offer
        $offer = $invoice->membership && $invoice->membership->offer ? $invoice->membership->offer : $invoice->offer;
        $offer_name = $offer ? $offer->offer_name : null;

        // Payments: if amountPaid > 0, show a single payment (for now)
        $payments = [];
        if ($invoice->amountPaid > 0) {
            $payments[] = [
                'date' => $invoice->last_payment_date ? $invoice->last_payment_date->format('Y-m-d') : ($invoice->creationDate ? $invoice->creationDate->format('Y-m-d') : null),
                'amount' => (float) $invoice->amountPaid,
                'method' => 'Cash',
            ];
        }

        // Teachers: from membership.teachers (array of {teacherId, name, amount})
        $teachers = [];
        if ($invoice->membership && is_array($invoice->membership->teachers)) {
            foreach ($invoice->membership->teachers as $teacher) {
                $teachers[] = [
                    'teacherId' => $teacher['teacherId'] ?? null,
                    'name' => $teacher['name'] ?? null,
                    'amount' => $teacher['amount'] ?? null,
                ];
            }
        }

        // selectedMonths
        $selectedMonths = [];
        if (isset($invoice->selected_months)) {
            if (is_string($invoice->selected_months)) {
                $decoded = json_decode($invoice->selected_months, true);
                if (is_array($decoded)) {
                    $selectedMonths = $decoded;
                }
            } elseif (is_array($invoice->selected_months)) {
                $selectedMonths = $invoice->selected_months;
            }
        }

        // Ensure creationDate is returned as 'Y-m-d' (no timezone) to make client parsing deterministic
        $creationDateFormatted = null;
        if ($invoice->creationDate) {
            try {
                $creationDateFormatted = $invoice->creationDate->format('Y-m-d');
            } catch (\Exception $e) {
                $creationDateFormatted = (string) $invoice->creationDate;
            }
        }

        $data = [
            'id' => $invoice->id,
            'membership_id' => $invoice->membership_id,
            'months' => $invoice->months,
            'billDate' => $invoice->billDate,
            'creationDate' => $creationDateFormatted,
            'totalAmount' => is_numeric($invoice->totalAmount) ? floatval($invoice->totalAmount) : 0,
            'amountPaid' => is_numeric($invoice->amountPaid) ? floatval($invoice->amountPaid) : 0,
            'rest' => is_numeric($invoice->rest) ? floatval($invoice->rest) : 0,
            'student_id' => $student_id,
            'student_name' => $student_name,
            'student_class' => $student_class,
            'student_school' => $student_school,
            'offer_id' => $invoice->offer_id,
            'offer_name' => $offer_name,
            'endDate' => $invoice->endDate,
            'includePartialMonth' => $invoice->includePartialMonth,
            'partialMonthAmount' => $invoice->partialMonthAmount,
            'last_payment' => $invoice->last_payment_date,
            'created_at' => $invoice->created_at,
            'selectedMonths' => $selectedMonths,
            'payments' => $payments,
            'teachers' => $teachers,
        ];

        return response()->json(['invoice' => $data]);
    }

    /**
     * Show the form for editing the specified invoice.
     */
    public function edit($id)
    {
        $invoice = Invoice::findOrFail($id);
        $this->authorizeInvoice($invoice);

        $studentMemberships = Membership::withTrashed()->with(['student', 'offer'])
            ->get()
            ->map(function ($membership) {
                return [
                    'id' => $membership->id,
                    'offer_name' => $membership->student->name.' - '.$membership->offer->name,
                    'price' => $membership->offer->price,
                    'offer_id' => $membership->offer_id,
                ];
            });

        // Always send selected_months as an array if present
        $invoiceData = $invoice->toArray();
        if (isset($invoiceData['selected_months'])) {
            $selectedMonths = $invoiceData['selected_months'];
            if (is_string($selectedMonths)) {
                $decoded = json_decode($selectedMonths, true);
                if (is_array($decoded)) {
                    $invoiceData['selectedMonths'] = $decoded;
                } else {
                    $invoiceData['selectedMonths'] = [];
                }
            } elseif (is_array($selectedMonths)) {
                $invoiceData['selectedMonths'] = $selectedMonths;
            } else {
                $invoiceData['selectedMonths'] = [];
            }
        } else {
            $invoiceData['selectedMonths'] = [];
        }

        return Inertia::render('Menu/SingleStudentPage', [
            'invoice' => $invoiceData,
            'StudentMemberships' => $studentMemberships,
        ]);
    }

    /**
     * Update the specified invoice in the database.
     */
    public function update(Request $request, $id)
    {
        // Outside the transaction and outside the try: an update re-triggers
        // processInvoicePayment, which credits teacher wallets. This is the second half
        // of the wallet-fraud chain described in the audit (§5.1) — the first half being
        // MembershipController::update accepting an arbitrary teachers array.
        $this->authorizeInvoice(Invoice::findOrFail($id));

        DB::beginTransaction();

        try {
            // Normalize membership_id and student_id before validation
            $incomingMembershipId = $request->input('membership_id', $request->input('membershipId'));
            if ($incomingMembershipId !== null && $incomingMembershipId !== '') {
                if (is_string($incomingMembershipId) && is_numeric($incomingMembershipId)) {
                    $incomingMembershipId = (int) $incomingMembershipId;
                }
                $request->merge(['membership_id' => $incomingMembershipId]);
            }

            // If student_id is missing but membership_id is provided, infer student_id from membership
            if (! $request->filled('student_id') && $request->filled('membership_id')) {
                $membershipForStudent = Membership::withTrashed()->find($request->input('membership_id'));
                if ($membershipForStudent) {
                    $request->merge(['student_id' => $membershipForStudent->student_id]);
                }
            }

            // Validate the incoming request
            // Bounds mirror store() — see the comments there. Without them a PUT could
            // credit an arbitrary amount to a teacher's wallet via partialMonthAmount,
            // and (unlike amountPaid) that path is never reconciled afterwards.
            $validated = $request->validate([
                'membership_id' => 'nullable|integer|exists:memberships,id',
                'student_id' => 'required|integer|exists:students,id',
                'months' => [
                    'required',
                    'integer',
                    'min:0',
                    'max:24',
                    function ($attribute, $value, $fail) use ($request) {
                        if ($value === 0 && ! $request->input('includePartialMonth')) {
                            $fail('Le champ mois doit être supérieur à 0 si le mois partiel n\'est pas sélectionné.');
                        }
                    },
                ],
                'selected_months' => 'nullable', // Accept array or stringified JSON
                'billDate' => 'required|date',
                'creationDate' => 'nullable|date',
                'totalAmount' => 'required|numeric|min:0|max:9999999.99',
                'amountPaid' => 'required|numeric|min:0|max:9999999.99',
                'rest' => 'required|numeric|min:0|max:9999999.99',
                'offer' => 'nullable|string',
                'offer_id' => 'nullable|integer|exists:offers,id',
                'endDate' => 'nullable|date',
                'includePartialMonth' => 'nullable|boolean',
                'partialMonthAmount' => 'nullable|numeric|min:0|lte:amountPaid',
                'last_payment_date' => 'nullable|date',
            ], [
                'student_id.required' => 'Étudiant manquant: veuillez sélectionner un étudiant.',
                'student_id.exists' => 'Étudiant introuvable.',
                'membership_id.exists' => 'Adhésion introuvable.',
                'partialMonthAmount.lte' => 'Le montant du mois partiel ne peut pas dépasser le montant payé.',
                'months.required' => 'Le nombre de mois est obligatoire.',
                'billDate.required' => 'La date de facturation est obligatoire.',
                'totalAmount.required' => 'Le montant total est obligatoire.',
                'amountPaid.required' => 'Le montant payé est obligatoire.',
                'rest.required' => 'Le reste à payer est obligatoire.',
            ]);

            $invoice = Invoice::findOrFail($id);
            $previousAmountPaid = $invoice->amountPaid;

            // Capture old data before update
            $oldData = $invoice->toArray(); // Keep this for activity log if needed

            // Update last_payment_date only if amountPaid has changed
            if (round((float) ($validated['amountPaid']), 2) != round((float) ($previousAmountPaid), 2)) {
                $validated['last_payment_date'] = now()->toDateTimeString();
            } else {
                // Keep the existing last_payment_date if amountPaid hasn't changed
                $validated['last_payment_date'] = $invoice->last_payment_date;
            }

            // Always accept both selectedMonths and selected_months from frontend
            $selectedMonths = $request->input('selectedMonths');
            if (is_null($selectedMonths)) {
                $selectedMonths = $request->input('selected_months');
            }
            if (is_string($selectedMonths)) {
                $decoded = json_decode($selectedMonths, true);
                if (is_array($decoded)) {
                    $selectedMonths = $decoded;
                } else {
                    $selectedMonths = [];
                }
            }
            if (! is_array($selectedMonths)) {
                $selectedMonths = [];
            }
            $validated['selected_months'] = json_encode($selectedMonths);

            // Ensure membership_id exists: default to the invoice's current membership when not provided
            if (empty($validated['membership_id'])) {
                $validated['membership_id'] = $invoice->membership_id;
            }

            // Fetch the membership (including deleted ones)
            $membership = Membership::withTrashed()->findOrFail($validated['membership_id']);

            // Pré-vérifications bloquantes pour éviter des factures invalides
            if (! $membership->offer) {
                throw new \Exception('Offre introuvable pour cette adhésion. Veuillez vérifier l\'offre.');
            }
            if (! is_array($membership->teachers) || count($membership->teachers) === 0) {
                throw new \Exception('Aucun enseignant n\'est associé à cette adhésion. Veuillez ajouter au moins un enseignant.');
            }
            if (! is_array($membership->offer->percentage)) {
                throw new \Exception('L\'offre sélectionnée n\'a pas de pourcentages valides.');
            }
            // Always set offer_id from membership
            $validated['offer_id'] = $membership->offer_id;

            // RECOMPUTE server-side — same reasoning as store(). This closes the update path,
            // which was the more dangerous of the two: partialMonthAmount flows into the
            // teacher commission, and when amountPaid is unchanged the reconcile step below
            // never runs, so an inflated wallet credit was never corrected.
            $pricing = new \App\Services\InvoicePricingService;
            $priced = $pricing->reconcile($membership, $validated + [
                'selected_months' => $selectedMonths,
                'billDate' => $validated['billDate'] ?? null,
            ]);

            $validated['totalAmount'] = $priced['totalAmount'];
            $validated['amountPaid'] = $priced['amountPaid'];
            $validated['rest'] = $priced['rest'];
            $validated['partialMonthAmount'] = $priced['partialMonthAmount'];

            // Re-evaluate against the RECONCILED amount, not the client's figure.
            $validated['last_payment_date'] =
                round((float) $validated['amountPaid'], 2) != round((float) $previousAmountPaid, 2)
                    ? now()->toDateTimeString()
                    : $invoice->last_payment_date;

            if ($priced['discountApplied'] > 0) {
                Log::info('Invoice updated with a discount', [
                    'invoice_id' => $invoice->id,
                    'discount' => $priced['discountApplied'],
                    'charged' => $priced['totalAmount'],
                    'by' => auth()->id(),
                ]);
            }

            // Update the invoice
            $invoice->update($validated);

            // THE SECOND HALF OF THE PAYMENT. amountPaid is cumulative, so the rest
            // paid days later arrives as an edit like this one — the event log gets the
            // DELTA, which is the money that actually changed hands today. Negative
            // deltas (a correction that reduces amountPaid) are logged too: cash that
            // leaves must leave the day it leaves, or the register stops adding up.
            \App\Models\InvoicePaymentLog::recordDelta($invoice, $previousAmountPaid, auth()->id());

            // Log the activity
            $this->logActivity('updated', $invoice, $oldData, $invoice->toArray());

            // --- TEACHER MEMBERSHIP PAYMENT LOGIC ---
            // The payment service now handles updates incrementally without full reversal
            $paymentService = new \App\Services\TeacherMembershipPaymentService;
            $paymentResult = $paymentService->processInvoicePayment($invoice, $validated);

            // Log warning if payment processing has issues but don't fail the update
            if (! $paymentResult || ! $paymentResult['success'] || (($paymentResult['created_records'] ?? 0) + ($paymentResult['updated_records'] ?? 0)) === 0) {
                Log::error('Failed to process teacher payment records during invoice update', [
                    'invoice_id' => $invoice->id,
                    'errors' => $paymentResult['errors'] ?? ['Unknown error'],
                ]);

                // Was a fixed English string, so whatever the service actually objected to
                // never reached the person editing the invoice.
                throw new \App\Exceptions\PaymentProcessingException(
                    $this->convertToUserFriendlyErrors($paymentResult['errors'] ?? [])
                );
            }

            // NEW: Reconcile deltas whenever amountPaid changes (not only when fully paid)
            if (round((float) ($validated['amountPaid']), 2) != round((float) ($previousAmountPaid), 2)) {
                $reconcileResultAny = $paymentService->reconcilePaidMonthsForInvoice($invoice);
                if (! $reconcileResultAny['success']) {
                    Log::warning('Reconciliation (any change) reported issues', [
                        'invoice_id' => $invoice->id,
                        'errors' => $reconcileResultAny['errors'],
                    ]);
                } else {
                    Log::info('Reconciled teacher payouts after amount change', [
                        'invoice_id' => $invoice->id,
                        'adjusted_records' => $reconcileResultAny['adjusted_records'],
                        'total_delta' => $reconcileResultAny['total_delta'],
                    ]);
                }
            }

            // If invoice is fully paid, reactivate any inactive payment records
            if ($invoice->amountPaid >= $invoice->totalAmount) {
                $reactivationResult = $paymentService->reactivatePaymentRecords($invoice);
                if ($reactivationResult['success'] && $reactivationResult['reactivated_records'] > 0) {
                    Log::info('Reactivated payment records for fully paid invoice', [
                        'invoice_id' => $invoice->id,
                        'reactivated_count' => $reactivationResult['reactivated_records'],
                    ]);
                }

                // Keep reconciliation for fully paid case as well (already handled above if amount changed)
            }
            // --- END TEACHER MEMBERSHIP PAYMENT LOGIC ---

            // Always update start_date. Update end_date based on actual paid period
            $updateData = [
                'start_date' => $validated['billDate'],
                'payment_status' => (round((float) ($validated['amountPaid']), 2) >= round((float) ($validated['totalAmount']), 2)) ? 'paid' : 'pending',
                'is_active' => (round((float) ($validated['amountPaid']), 2) >= round((float) ($validated['totalAmount']), 2)),
            ];

            // Update end_date: use invoice end_date if it's more recent than current membership end_date
            // This ensures the membership reflects the actual paid period
            if (empty($membership->end_date) || (isset($validated['endDate']) && Carbon::parse($validated['endDate']) > Carbon::parse($membership->end_date))) {
                $updateData['end_date'] = $validated['endDate'];
            }
            $membership->update($updateData);

            DB::commit();

            return redirect()->back()->with('success', 'Facture mise à jour avec succès.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating invoice:', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            // NEW: Return proper error response
            if (request()->expectsJson() || request()->header('Accept') === 'application/json') {
                // See the note on the same pattern in store(): never echo the raw body.
                return $this->createErrorResponse([$e->getMessage()], [
                    'invoice_id' => $id,
                    'membership_id' => $request->input('membership_id'),
                    'student_id' => $request->input('student_id'),
                ]);
            }

            return $this->withPaymentNotice(
                redirect()->back()->withErrors([
                    'error' => $this->convertSingleError($e->getMessage()) ?: 'Mise à jour de facture annulée: '.$e->getMessage(),
                ])->withInput(),
                $e,
            );
        }
    }

    /**
     * Price preview for the invoice form.
     *
     * Lets the UI show the authoritative figure instead of relying on its own copy of the
     * formula. The client keeps a local calculation for instant feedback, but this is what
     * store()/update() will actually charge — if the two ever disagree, this one wins.
     */
    public function priceQuote(Request $request)
    {
        $validated = $request->validate([
            'membership_id' => 'required|integer|exists:memberships,id',
            'selected_months' => 'nullable',
            'includePartialMonth' => 'nullable|boolean',
            'billDate' => 'nullable|date',
        ]);

        $membership = Membership::withTrashed()->findOrFail($validated['membership_id']);

        if (! $membership->offer) {
            return response()->json(['message' => 'Offre introuvable pour cette adhésion.'], 422);
        }

        $pricing = new \App\Services\InvoicePricingService;

        return response()->json($pricing->price(
            $membership,
            $pricing->normaliseMonths($validated['selected_months'] ?? []),
            (bool) ($validated['includePartialMonth'] ?? false),
            $validated['billDate'] ?? null
        ));
    }

    /**
     * Remove the specified invoice from the database.
     *
     * Two shapes, depending on the claw-back deadline:
     *
     *  - INSIDE the window, deleting is fully reversible in the sense that matters — the
     *    money comes back out of the teachers' wallets — so it happens straight away.
     *
     *  - PAST the window, it is not: the invoice disappears and the teachers keep what they
     *    were paid, whatever anyone does next. So the first request does NOT delete. It
     *    returns a dialog saying what will happen and offering the choice; only a request
     *    carrying `confirm_keep_wallet` goes through with it.
     */
    public function destroy(Request $request, $id)
    {
        // Before the transaction: deleting an invoice debits teacher wallets.
        $invoice = Invoice::findOrFail($id);
        $this->authorizeInvoice($invoice);

        $paymentService = new \App\Services\TeacherMembershipPaymentService;
        $preview = $paymentService->previewInvoiceReversal($invoice);

        $needsConfirmation = ! $preview['within_deadline'] && $preview['blocked'] !== [];

        if ($needsConfirmation && ! $request->boolean('confirm_keep_wallet')) {
            // Nothing has been written. The invoice is untouched and still listed.
            return redirect()->back()->with(
                'payment_notice',
                \App\Support\PaymentNotice::confirmDeletion($preview, $invoice->id)->toArray()
            );
        }

        try {
            // Captured from inside the transaction so the notice describes what was actually
            // committed. Built into a dialog below — deleting an invoice can leave money in
            // a teacher's wallet that the person clicking delete now has to recover by hand,
            // and that cannot be a log line.
            $reversal = [];

            // ALL of this must be atomic. It writes memberships, teachers.wallet,
            // teacher_membership_payments and invoices. Previously it ran with no
            // transaction, so a failure partway through left teacher wallets already
            // debited while the invoice survived — and deleting it again debited them twice.
            DB::transaction(function () use ($id, &$reversal) {
                $invoice = Invoice::findOrFail($id);

                // Log the activity before deletion
                $this->logActivity('deleted', $invoice, $invoice->toArray(), null);

                $membership = $invoice->membership;
                if ($membership) {
                    // Find the latest active invoice for this membership (excluding the one being deleted)
                    $latestActiveInvoice = Invoice::where('membership_id', $membership->id)
                        ->where('id', '!=', $invoice->id)
                        ->orderBy('endDate', 'desc')
                        ->first();

                    if ($latestActiveInvoice) {
                        // Update membership based on the latest active invoice
                        $membership->end_date = $latestActiveInvoice->endDate;
                        $membership->payment_status = 'paid';
                        $membership->is_active = true;
                    } else {
                        // No other active invoices, set to expired
                        $membership->payment_status = 'expired';
                        $membership->is_active = false;
                    }
                    $membership->save();
                }

                // Reverse teacher payments REGARDLESS of membership state. This used to sit
                // inside `if ($membership)`, so an invoice whose membership had been deleted
                // was removed without ever reversing the teacher's wallet credit.
                $paymentService = new \App\Services\TeacherMembershipPaymentService;
                $reversal = $paymentService->reverseInvoicePayments($invoice);

                $invoice->delete();
            });

            $redirect = redirect()->back()->with('success', 'Facture supprimée.');

            $notice = \App\Support\PaymentNotice::fromReversal($reversal);

            return $notice
                ? $redirect->with('payment_notice', $notice->toArray())
                : $redirect;
        } catch (\Throwable $e) {
            Log::error('Error deleting invoice:', ['invoice_id' => $id, 'error' => $e->getMessage()]);

            return redirect()->back()->withErrors([
                'error' => 'La facture n\'a pas pu être supprimée. Aucune modification n\'a été enregistrée, '
                    .'et les portefeuilles des enseignants sont inchangés.',
            ]);
        }
    }

    /**
     * NEW METHOD: Validate and fix invoice payment states
     * Use this to diagnose and fix payment issues like invoice #837
     */
    public function validateInvoice($id)
    {
        // reconcilePaidMonthsForInvoice() below writes payout records — this is not a
        // read-only endpoint despite the name.
        $this->authorizeInvoice(Invoice::findOrFail($id));

        try {
            $invoice = Invoice::findOrFail($id);
            $paymentService = new \App\Services\TeacherMembershipPaymentService;

            // Run comprehensive validation
            $validationResult = $paymentService->validateInvoicePaymentState($invoice);

            // Run reconciliation to fix any issues
            $reconcileResult = $paymentService->reconcilePaidMonthsForInvoice($invoice);

            $data = [
                'invoice' => $invoice,
                'validation' => $validationResult,
                'reconciliation' => $reconcileResult,
                'summary' => [
                    'invoice_total' => $invoice->totalAmount,
                    'amount_paid' => $invoice->amountPaid,
                    'payment_percentage' => round(($invoice->amountPaid / $invoice->totalAmount) * 100, 2),
                    'bill_date' => $invoice->billDate,
                    'selected_months' => $invoice->selected_months,
                ],
            ];

            Log::info('Invoice validation completed', [
                'invoice_id' => $invoice->id,
                'validation_valid' => $validationResult['valid'],
                'validation_errors' => $validationResult['errors'],
                'validation_warnings' => $validationResult['warnings'],
                'reconciliation_success' => $reconcileResult['success'],
                'reconciliation_adjusted_records' => $reconcileResult['adjusted_records'],
            ]);

            return response()->json($data);

        } catch (\Exception $e) {
            Log::error('Error validating invoice', [
                'invoice_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to validate invoice: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate and download the invoice as a PDF.
     */
    public function generateInvoicePdf($id)
    {
        // `student.level` feeds the Niveau row on the redesigned invoice.
        $invoice = Invoice::with(['membership' => function ($membershipQuery) {
            $membershipQuery->withTrashed()->with('offer');
        }, 'student.level'])
            ->findOrFail($id);

        $this->authorizeInvoice($invoice);

        // Extract membership, student, and offer details
        $membership = $invoice->membership;
        $student = $invoice->student;
        $offerName = $membership?->offer?->offer_name ?? 'No offer available';

        // Load the view for the invoice
        PdfBudget::apply();
        $pdf = Pdf::loadView('invoices.invoice_pdf', [
            'invoice' => $invoice,
            'membership' => $membership,
            'student' => $student,
            'offerName' => $offerName,
        ]);

        // Return the PDF as a downloadable file
        return $pdf->download('invoice_'.$invoice->id.'.pdf');
    }

    /**
     * Download the invoice as a PDF.
     */
    public function download($id)
    {
        // Fetch the invoice by ID
        $invoice = Invoice::with(['student.class', 'offer'])->findOrFail($id);

        $this->authorizeInvoicesForDownload(collect([$invoice]));

        $className = $invoice->student->class->name;

        // Add the class name to the invoice object
        $invoice->className = $className;

        // Generate the PDF
        PdfBudget::apply();
        $pdf = Pdf::loadView('invoices.teacher_invoicePdf', compact('invoice'));

        // Download the PDF
        return $pdf->download("TeacherInvoice-{$invoice->id}.pdf");
    }

    /**
     * Who may render these invoice documents?
     *
     * Admins: everything. Assistants: the same SchoolScope student rule as on
     * screen. Teachers: the earnings table's definition of "mine" is a COMMISSION
     * row linking the invoice to them (teacher_membership_payments) — not
     * classes_teacher pivot ownership. The two disagree whenever a teacher holds
     * a commission on a student whose class they do not pivot-teach, which is
     * exactly why every Télécharger click on their own earnings table used to 403.
     *
     * Shared by download() AND both bulk endpoints: guarding one door while the
     * neighbouring doors take raw id arrays would make the guard decorative.
     */
    private function authorizeInvoicesForDownload($invoices): void
    {
        $user = Auth::user();

        if ($user && $user->role === 'admin') {
            return;
        }

        if ($user && $user->role === 'teacher') {
            $myTeacherId = Teacher::where('email', $user->email)->value('id');
            $ids = $invoices->pluck('id')->all();

            $heldCount = $myTeacherId === null ? 0 : TeacherMembershipPayment::query()
                ->where('teacher_id', $myTeacherId)
                ->whereIn('invoice_id', $ids)
                ->distinct()
                ->count('invoice_id');

            if ($heldCount < count($ids)) {
                throw new \App\Exceptions\AccessDeniedException(
                    'Vous ne pouvez télécharger que les factures de vos propres gains.'
                );
            }

            return;
        }

        $invoices->each(function ($invoice) {
            $this->authorizeInvoice($invoice);
        });
    }

    /**
     * Bulk download invoices as a PDF.
     */
    public function bulkDownload(Request $request)
    {
        // Get the selected invoice IDs from the request
        $invoiceIds = $request->input('invoiceIds', []);

        if (empty($invoiceIds)) {
            return redirect()->back()->with('error', 'Aucune facture selectionnee.');
        }

        // Nothing bounded how many ids a caller could post. At ~0.12 MB per invoice on top
        // of the fixed font cost, a large enough selection walks into the memory ceiling —
        // and PHP memory exhaustion is fatal, not catchable, so it has to be refused here.
        if (count($invoiceIds) > self::MAX_BULK_INVOICES) {
            return redirect()->back()->with('error',
                'Trop de factures selectionnees ('.count($invoiceIds).'). Maximum '
                .self::MAX_BULK_INVOICES.' par telechargement.');
        }

        $invoices = Invoice::with(['student', 'offer'])
            ->whereIn('id', $invoiceIds)
            ->get();

        if ($invoices->isEmpty()) {
            return redirect()->back()->with('error', 'No invoices found');
        }

        // Same door as single download — without this, any staff member could
        // post arbitrary ids and walk away with other schools' invoices as PDF.
        $this->authorizeInvoicesForDownload($invoices);

        // Fetch class names for each invoice
        $invoices->each(function ($invoice) {
            $invoice->className = Classes::find($invoice->student->classId)->name;
        });

        // Generate the PDF with proper headers
        PdfBudget::apply();
        $pdf = Pdf::loadView('invoices.teacher-bulk-invoices', compact('invoices'));

        // Make sure proper headers are set for download
        return $pdf->download('teacher-bulk-invoices-'.date('Y-m-d').'.pdf', [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="teacher-bulk-invoices-'.date('Y-m-d').'.pdf"',
        ]);
    }

    /**
     * Bulk download teacher income report as a PDF.
     */
    public function teacherBulkDownload(Request $request)
    {
        // Get the selected invoice IDs and summary data from the request (works for both GET and POST)
        $invoiceIds = $request->input('invoiceIds', $request->query('invoiceIds', []));

        // Handle JSON string if invoiceIds is passed as JSON
        if (is_string($invoiceIds)) {
            $invoiceIds = json_decode($invoiceIds, true) ?? [];
        }
        $totalIncome = $request->input('totalIncome', $request->query('totalIncome', 0));
        $totalInvoices = $request->input('totalInvoices', $request->query('totalInvoices', 0));
        $teacherName = $request->input('teacherName', $request->query('teacherName', 'Teacher'));
        $dateRange = $request->input('dateRange', $request->query('dateRange', 'All time'));

        if (empty($invoiceIds)) {
            return redirect()->back()->with('error', 'Aucune facture selectionnee.');
        }

        if (count($invoiceIds) > self::MAX_BULK_INVOICES) {
            return redirect()->back()->with('error',
                'Trop de factures selectionnees ('.count($invoiceIds).'). Maximum '
                .self::MAX_BULK_INVOICES.' par telechargement.');
        }

        // Get the selected invoices with their details
        $invoices = Invoice::with(['student', 'student.class', 'student.school', 'offer'])
            ->whereIn('id', $invoiceIds)
            ->get();

        if ($invoices->isEmpty()) {
            return redirect()->back()->with('error', 'No invoices found');
        }

        // Same door as single download — this endpoint even accepts GET with id
        // arrays, so without the check it is a one-URL exfiltration of arbitrary
        // invoices across every school.
        $this->authorizeInvoicesForDownload($invoices);

        // Prepare data for the PDF
        $summaryData = [
            'totalIncome' => $totalIncome,
            'totalInvoices' => $totalInvoices,
            'teacherName' => $teacherName,
            'dateRange' => $dateRange,
            'generatedDate' => now()->format('Y-m-d H:i:s'),
        ];

        // Generate the PDF
        PdfBudget::apply();
        $pdf = Pdf::loadView('invoices.teacher-income-report', compact('invoices', 'summaryData'));

        // output() RENDERS the document — it is not a cached getter. Calling it twice, as
        // the Content-Length line below used to, built the entire PDF a second time: double
        // the CPU and double the peak memory, on the endpoint least able to afford either.
        $body = $pdf->output();

        return response($body, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="teacher-income-report-'.date('Y-m-d').'.pdf"',
            'Content-Length' => strlen($body),
        ]);
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

    /**
     * Convert technical validation errors to user-friendly messages
     */
    private function convertToUserFriendlyErrors(array $technicalErrors): array
    {
        $userFriendlyMessages = [];

        foreach ($technicalErrors as $error) {
            $message = $this->convertSingleError($error);
            if ($message) {
                $userFriendlyMessages[] = $message;
            }
        }

        // If no specific conversion found, return generic message
        if (empty($userFriendlyMessages)) {
            $userFriendlyMessages[] = 'Une erreur s\'est produite lors de la création de la facture. Veuillez réessayer.';
        }

        return $userFriendlyMessages;
    }

    /**
     * Convert a single technical error to user-friendly message
     */
    private function convertSingleError(string $error): ?string
    {
        // Map technical errors to user-friendly messages
        $errorMappings = [
            // Offer percentage errors
            'percentages don\'t sum to 100%' => 'La configuration des pourcentages enseignants n\'est pas complète. Veuillez vérifier les pourcentages dans l\'offre.',
            'percentages exceed 100%' => 'Les pourcentages enseignants dépassent 100%. Veuillez ajuster les pourcentages dans l\'offre.',
            'has invalid percentage configuration' => 'La configuration des pourcentages est invalide. Contactez l\'administrateur.',
            'has no teachers assigned' => 'Aucun enseignant n\'est assigné à cette adhésion. Veuillez sélectionner des enseignants.',
            'has no associated offer' => 'Aucune offre associée à cette adhésion. Veuillez sélectionner une offre valide.',

            // Membership errors
            'No membership or teachers found' => 'Impossible de créer la facture: aucune information d\'adhésion trouvée.',
            'has no teachers assigned' => 'Cette adhésion n\'a pas d\'enseignants assignés. Veuillez ajouter des enseignants.',

            // Data validation errors
            'Math validation failed' => 'Les montants ne sont pas cohérents. Vérifiez le montant total, payé et le reste.',
            'no selected months' => 'Aucun mois sélectionné pour cette facture. Veuillez sélectionner au moins un mois.',

            // Generic patterns
            'Offer' => 'Problème avec la configuration de l\'offre. Vérifiez les paramètres de l\'offre.',
            'Membership' => 'Problème avec la configuration de l\'adhésion. Vérifiez les paramètres de l\'adhésion.',
            'Teacher' => 'Problème avec la configuration des enseignants. Vérifiez les enseignants assignés.',
            'validation failed' => 'Les données saisies ne sont pas valides. Vérifiez tous les champs obligatoires.',
            'permission denied' => 'Vous n\'avez pas les permissions pour effectuer cette action.',
            'not found' => 'La ressource demandée est introuvable.',
        ];

        // Check for exact matches first
        foreach ($errorMappings as $technicalPattern => $userMessage) {
            if (strpos($error, $technicalPattern) !== false) {
                return $userMessage;
            }
        }

        // Nothing matched. The table above translates ENGLISH strings that the payment
        // service used to emit; it now emits French messages that already name the offer,
        // the teacher and the fix. Truncating those to 100 characters — which is what this
        // fallback used to do unconditionally — threw away the only part worth reading.
        //
        // So: pass a message through untouched unless it looks like raw machinery, and only
        // then fall back to something a user can act on.
        $looksTechnical = preg_match('/SQLSTATE|Exception|::|\.php|Stack trace|\\\\[A-Z]\w+\\\\/', $error) === 1;

        if (! $looksTechnical && mb_strlen($error) <= 400) {
            return $error;
        }

        Log::warning('Unmapped technical error surfaced during invoice processing', ['error' => $error]);

        return 'Une erreur technique est survenue lors du traitement de la facture. '
            .'Réessayez, puis contactez l\'administrateur si le problème persiste.';
    }

    /**
     * Create detailed error response for frontend
     */
    private function createErrorResponse(array $errors, array $additionalData = [])
    {
        $userFriendlyErrors = $this->convertToUserFriendlyErrors($errors);

        return response()->json([
            'success' => false,
            'message' => 'Erreur lors de la création de la facture',
            'errors' => $userFriendlyErrors,
            'technical_errors' => $errors, // Keep technical errors for debugging
            'data' => $additionalData,
            'suggestions' => $this->getErrorSuggestions($errors),
        ], 422);
    }

    /**
     * Get helpful suggestions based on errors
     */
    private function getErrorSuggestions(array $errors): array
    {
        $suggestions = [];

        foreach ($errors as $error) {
            if (strpos($error, 'percentages') !== false) {
                $suggestions[] = 'Allez dans Gestion des Offres pour vérifier les pourcentages enseignants';
            } elseif (strpos($error, 'teachers') !== false) {
                $suggestions[] = 'Ajoutez des enseignants à cette adhésion dans la configuration';
            } elseif (strpos($error, 'offer') !== false) {
                $suggestions[] = 'Vérifiez que l\'offre est correctement configurée';
            } elseif (strpos($error, 'validation failed') !== false) {
                $suggestions[] = 'Vérifiez que tous les champs obligatoires sont remplis';
            }
        }

        return array_unique($suggestions);
    }
}
