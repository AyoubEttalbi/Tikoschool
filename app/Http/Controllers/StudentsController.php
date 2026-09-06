<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Classes;
use App\Models\Invoice;
use App\Models\Level;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Result;
use App\Models\School;
use App\Models\Student;
use App\Models\InvoicePaymentLog;
use App\Models\StudentMovement;
use App\Models\Teacher;
use App\Services\ProfileImageService;
use App\Support\PdfBudget;
use App\Support\SchoolScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class StudentsController extends Controller
{
    public function __construct(private ProfileImageService $profileImages) {}

    /**
     * Download a student's information as a PDF.
     */
    public function downloadPdf($id)
    {
        $student = Student::with(['level', 'class', 'school', 'memberships.offer'])
            ->findOrFail($id);

        // The PDF prints guardianNumber (students_pdf.blade.php). SchoolScope alone lets a
        // teacher through for students in their classes, so the role check must come first —
        // same rule as show() below.
        if (auth()->user() && auth()->user()->role === 'teacher') {
            abort(403, 'Accès refusé. Les enseignants ne peuvent pas consulter les fiches élèves.');
        }

        // This method had no check of any kind: an assistant scoped to one school could
        // walk /students/1..N/download-pdf and export every student in the product,
        // including guardian phone numbers and medical fields.
        SchoolScope::authorizeStudent($student);

        PdfBudget::apply();
        $pdf = Pdf::loadView('students_pdf', [
            'student' => $student,
        ]);
        $fileName = 'student_'.$student->id.'_'.now()->format('Ymd_His').'.pdf';

        return $pdf->download($fileName);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        // Initialize the query with eager loading for relationships
        $query = Student::with(['class', 'school', 'level', 'memberships.offer', 'memberships.invoices']);

        // Get the current user and their role
        $user = $request->user();
        $userRole = $user ? $user->role : null;

        // If user is a teacher, only show students they teach
        if ($userRole === 'teacher') {
            $teacher = \App\Models\Teacher::where('email', $user->email)->first();
            if ($teacher) {
                // NOTE: a "debug" block used to sit here that ran the SAME unindexable
                // JSON_CONTAINS scan over the whole memberships table and ->get() every
                // matching row, purely to feed a Log::info â€” then threw the result away and
                // ran the real filter below. It executed on every student-list page load.
                $query->whereHas('memberships', function ($membershipQuery) use ($teacher) {
                    // Try both string and integer versions of teacher ID
                    $membershipQuery->where(function ($q) use ($teacher) {
                        $q->whereRaw("JSON_CONTAINS(teachers, JSON_OBJECT('teacherId', ?))", [$teacher->id])
                            ->orWhereRaw("JSON_CONTAINS(teachers, JSON_OBJECT('teacherId', ?))", [(string) $teacher->id]);
                    });
                });
            }
        }

        // Get the selected school from session and apply filter
        $selectedSchoolId = session('school_id');
        if ($selectedSchoolId) {
            // Show students for the selected school OR students with no school assigned
            $query->where(function ($q) use ($selectedSchoolId) {
                $q->where('schoolId', $selectedSchoolId)
                    ->orWhereNull('schoolId');
            });
        }

        // Apply search filter if search term is provided
        if ($request->has('search') && ! empty($request->search)) {
            $this->applySearchFilter($query, $request->search, $userRole);
        }

        // Apply additional filters (e.g., school, class, level)
        $this->applyFilters($query, $request->only(['school', 'class', 'level']));

        // Membership status filter.
        //
        // This used to ->get() every matching student (no LIMIT) with class, school,
        // level, every membership, every offer and every invoice eager-loaded, filter in
        // PHP, then hand the collection to a LengthAwarePaginator that only SLICED it.
        // The paginator made it look paginated; the database was doing a full read on
        // every listing view, every search keystroke and every filter change. It stays in
        // SQL here for the same reason.
        //
        // What changed: it used to read `memberships.payment_status`, and the column the
        // admin is looking at while they pick a filter does not. The "Statut" badge comes
        // from calculateMembershipPaymentStatus(), which ignores payment_status entirely
        // and derives the answer from invoice money â€” so the filter and the column
        // disagreed by construction. Two concrete symptoms:
        //
        //   * `payment_status` is an enum of pending|paid|expired. It has never held
        //     'rest', so the old "Partiel" branch could only ever match on its fallback,
        //     which was "the student has no memberships at all" â€” the one case that is
        //     definitively not a partial payment. Those same students also came back
        //     under "Non payé", so one row appeared under two mutually exclusive filters.
        //   * A membership marked 'paid' whose invoice was later edited downward still
        //     read as paid to the filter while the badge showed money outstanding.
        //
        // "Payé" and "Tous" looked right only because InvoiceController happens to set
        // payment_status = 'paid' on the same condition, and because "Tous" filters
        // nothing. The predicates below are the SQL translation of the badge, so every
        // row returned now carries the badge that was asked for. A student with no
        // memberships shows "Aucune" and belongs to none of the three.
        $membershipStatus = $request->input('membership_status');

        if ($membershipStatus && $membershipStatus !== 'all') {
            // Scalar subqueries over the invoices of the membership row being tested.
            // whereHas() supplies the `memberships.student_id = students.id` correlation
            // and the soft-delete guard on memberships; these add the same for invoices.
            $paidSum = '(select coalesce(sum(inv.amountPaid), 0) from invoices inv'
                .' where inv.membership_id = memberships.id and inv.deleted_at is null)';
            $dueSum = '(select coalesce(sum(inv.totalAmount), 0) from invoices inv'
                .' where inv.membership_id = memberships.id and inv.deleted_at is null)';

            // Mirrors calculateMembershipPaymentStatus(): no invoices, or nothing paid
            // against them, counts as unpaid â€” coalesce() makes both the same test.
            $unpaid = fn ($q) => $q->whereRaw("$paidSum = 0");
            $partial = fn ($q) => $q->whereRaw("$paidSum > 0 and $paidSum < $dueSum");

            match ($membershipStatus) {
                // Badge priority is unpaid > partial > paid, so the two narrower filters
                // exclude what the wider one already claims. That keeps the three sets
                // disjoint: no student can be returned by more than one of them.
                'unpaid' => $query->whereHas('memberships', $unpaid),
                'rest' => $query->whereHas('memberships', $partial)
                    ->whereDoesntHave('memberships', $unpaid),
                'paid' => $query->has('memberships')
                    ->whereDoesntHave('memberships', $unpaid)
                    ->whereDoesntHave('memberships', $partial),
                default => null,
            };
        }

        $students = $query->orderBy('created_at', 'desc')
            ->paginate(10)
            ->withQueryString();

        // Transform for frontend — teacher must not receive guardianNumber/guardianName
        $students = $students->through(function ($student) use ($userRole) {
            return $this->transformStudentData($student, $userRole);
        });

        // Get all classes for filters, but filter them by selected school if applicable
        $classesQuery = Classes::query();
        if ($selectedSchoolId) {
            $classesQuery->where('school_id', $selectedSchoolId);
        }
        $classes = $classesQuery->get();

        return Inertia::render('Menu/StudentListPage', [
            'students' => $students,
            'Alllevels' => Level::all(),
            'Allclasses' => $classes,
            'Allschools' => School::all(),
            'search' => $request->search,
            'filters' => $request->only(['school', 'class', 'level', 'membership_status']),
            // NOTE: an 'Allmemberships' prop used to be shared here â€” EVERY membership row
            // in the database, serialized into the page on every load. StudentListPage
            // destructured it and never used it. It was also a scoping bypass: the query
            // above restricts a teacher to their own students, then this handed them the
            // whole memberships table anyway.
            'selectedSchool' => $selectedSchoolId ? [
                'id' => $selectedSchoolId,
                'name' => session('school_name'),
            ] : null,
        ]);
    }

    /**
     * Apply search filter to the query.
     *
     * Teachers must not be able to search by guardianNumber/guardianName — the column
     * is hidden from them, so an enumeration probe via ?search=0612 must also fail.
     */
    protected function applySearchFilter($query, $searchTerm, ?string $role = null)
    {
        $query->where(function ($q) use ($searchTerm, $role) {
            $q->where('firstName', 'LIKE', "%{$searchTerm}%")
                ->orWhere('lastName', 'LIKE', "%{$searchTerm}%")
                ->orWhere('massarCode', 'LIKE', "%{$searchTerm}%")
                ->orWhere('phoneNumber', 'LIKE', "%{$searchTerm}%")
                ->orWhere('email', 'LIKE', "%{$searchTerm}%")
                ->orWhere('address', 'LIKE', "%{$searchTerm}%")
              // Search by full name (firstName + lastName combined)
                ->orWhereRaw("CONCAT(firstName, ' ', lastName) LIKE ?", ["%{$searchTerm}%"])
              // Search by full name in reverse order (lastName + firstName)
                ->orWhereRaw("CONCAT(lastName, ' ', firstName) LIKE ?", ["%{$searchTerm}%"]);

            // Parent phone/name search is admin/assistant only.
            if ($role !== 'teacher') {
                $q->orWhere('guardianNumber', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('guardianName', 'LIKE', "%{$searchTerm}%");
            }

            // Search by class, school, and level names
            $this->applyRelationshipSearch($q, $searchTerm);
        });
    }

    /**
     * Apply search filter to relationships (class, school, level).
     */
    protected function applyRelationshipSearch($query, $searchTerm)
    {
        $query->orWhereHas('class', function ($classQuery) use ($searchTerm) {
            $classQuery->where('name', 'LIKE', "%{$searchTerm}%");
        })
            ->orWhereHas('school', function ($schoolQuery) use ($searchTerm) {
                $schoolQuery->where('name', 'LIKE', "%{$searchTerm}%");
            })
            ->orWhereHas('level', function ($levelQuery) use ($searchTerm) {
                $levelQuery->where('name', 'LIKE', "%{$searchTerm}%");
            });
    }

    /**
     * Apply additional filters (school, class, level).
     */
    protected function applyFilters($query, $filters)
    {
        if (! empty($filters['school'])) {
            $query->where('schoolId', $filters['school']);
        }

        if (! empty($filters['class'])) {
            $query->where('classId', $filters['class']);
        }

        if (! empty($filters['level'])) {
            $query->where('levelId', $filters['level']);
        }
    }

    /**
     * Calculate payment status counts for memberships
     */
    protected function calculateMembershipPaymentStatus($memberships)
    {
        $counts = [
            'paid' => 0,
            'partial' => 0,
            'unpaid' => 0,
            'total' => 0,
        ];

        foreach ($memberships as $membership) {
            // Skip soft-deleted memberships
            if ($membership->deleted_at) {
                continue;
            }

            $counts['total']++;

            // Get all invoices for this membership
            $invoices = $membership->invoices ?? collect();

            if ($invoices->isEmpty()) {
                $counts['unpaid']++;

                continue;
            }

            // Calculate total amounts
            $totalAmount = $invoices->sum('totalAmount');
            $amountPaid = $invoices->sum('amountPaid');

            if ($amountPaid == 0) {
                $counts['unpaid']++;
            } elseif ($amountPaid < $totalAmount) {
                $counts['partial']++;
            } else {
                $counts['paid']++;
            }
        }

        return $counts;
    }

    /**
     * Transform student data for the frontend.
     *
     * Parent phone/name are hidden from teachers — the column is not rendered for them
     * and the payload must not contain it either (otherwise it is one `props` peek away).
     */
    protected function transformStudentData($student, ?string $role = null)
    {
        // Get offer names from active memberships (excluding assurance-only invoices)
        $offerNames = $student->memberships
            ->filter(function ($membership) {
                return $membership->offer && $membership->offer->offer_name;
            })
            ->map(function ($membership) {
                return $membership->offer->offer_name;
            })
            ->unique()
            ->values()
            ->implode(', ');

        // Calculate payment status for memberships
        $paymentStatusCounts = $this->calculateMembershipPaymentStatus($student->memberships);

        $data = [
            'id' => $student->id,
            'name' => $student->firstName.' '.$student->lastName,
            'studentId' => $student->massarCode,
            'phone' => $student->phoneNumber,
            'address' => $student->address,
            'offerNames' => $offerNames ?: '', // Add offer names, empty string if none
            'paymentStatus' => $paymentStatusCounts, // Add payment status breakdown
            'classId' => $student->classId,
            'schoolId' => $student->schoolId,
            'firstName' => $student->firstName,
            'lastName' => $student->lastName,
            'dateOfBirth' => $student->dateOfBirth,
            'billingDate' => $student->billingDate,
            'CIN' => $student->CIN,
            'email' => $student->email,
            'massarCode' => $student->massarCode,
            'levelId' => $student->levelId,
            'status' => $student->status,
            'assurance' => $student->assurance,
            'assuranceAmount' => $student->assuranceAmount,
            'profile_image' => $student->profile_image ?? null,
            'phoneNumber' => $student->phoneNumber,
            'hasDisease' => $student->hasDisease,
            'diseaseName' => $student->diseaseName,
            'medication' => $student->medication,
        ];

        // Parent contact is admin/assistant only. Teachers keep their own students' `phone`
        // (student's own number) but must not receive the guardian's.
        if ($role !== 'teacher') {
            $data['guardianNumber'] = $student->guardianNumber;
            $data['guardianName'] = $student->guardianName;
        }

        return $data;
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Students/Create');
    }

    public function store(Request $request)
    {
        // Hoisted before try: the catch below reads this, and any exception thrown
        // by validate() would otherwise hit an undefined variable.
        $newImagePath = null;
        try {
            // Validate the incoming request data
            $validatedData = $request->validate([
                'firstName' => 'required|string|max:100',
                'lastName' => 'required|string|max:100',
                'dateOfBirth' => 'required|date',
                'billingDate' => 'required|date',
                'address' => 'nullable|string',
                'guardianNumber' => 'nullable|string|max:255',
                'guardianName' => 'required|string|max:255',
                'CIN' => 'nullable|string|max:50|unique:students,CIN',
                'phoneNumber' => 'nullable|string|max:20',
                'email' => 'nullable|string|email|max:255|unique:students,email',
                'massarCode' => 'nullable|string|max:50|unique:students,massarCode',
                'levelId' => 'required|exists:levels,id',
                'classId' => 'required|exists:classes,id',
                'schoolId' => 'required|exists:schools,id',
                'status' => 'required|in:active,inactive',
                'hasDisease' => 'sometimes',
                'diseaseName' => 'nullable|required_if:hasDisease,1,true',
                'medication' => 'nullable',
                'assurance' => 'required',
                'assuranceAmount' => 'required_if:assurance,1|nullable|numeric|min:0',
                'profile_image' => 'nullable|mimes:jpg,jpeg,png,webp,avif|max:5120', // Added for image upload
            ]);

            // `exists:schools,id` proves the school is real, not that the caller may write
            // to it. Without this an assistant could create students inside another
            // school by posting its id.
            SchoolScope::authorizeSchool((int) $validatedData['schoolId']);

            // Process hasDisease field - use simple integer conversion
            if (isset($validatedData['hasDisease'])) {
                // Convert to integer 1 or 0 explicitly, avoiding boolean conversion that might cause issues
                $hasDiseaseValue = (is_string($validatedData['hasDisease']) && strtolower($validatedData['hasDisease']) === 'true') ||
                                   $validatedData['hasDisease'] === 1 ||
                                   $validatedData['hasDisease'] === '1' ? 1 : 0;

                // Update the validated data with the integer value
                $validatedData['hasDisease'] = $hasDiseaseValue;
            } else {
                $validatedData['hasDisease'] = 0;
            }

            // Process assurance field in the same way
            if (isset($validatedData['assurance'])) {
                $assuranceValue = (is_string($validatedData['assurance']) && strtolower($validatedData['assurance']) === 'true') ||
                                  $validatedData['assurance'] === 1 ||
                                  $validatedData['assurance'] === '1' ? 1 : 0;

                $validatedData['assurance'] = $assuranceValue;
            } else {
                $validatedData['assurance'] = 0;
            }

            if ($request->hasFile('profile_image')) {
                // May throw ValidationException — rethrown below so the form renders the field error.
                $newImagePath = $this->profileImages->store($request->file('profile_image'), 'students');
                $validatedData['profile_image'] = $newImagePath;
            }

            // If hasDisease is false (0), set diseaseName and medication to NULL
            if ($validatedData['hasDisease'] === 0) {
                $validatedData['diseaseName'] = null;
                $validatedData['medication'] = null;
            }

            // Create and save the new student
            $student = Student::create($validatedData);

            // Update class student count if assigned to a class
            if (! empty($validatedData['classId'])) {
                $class = Classes::find($validatedData['classId']);
                if ($class) {
                    $class->updateStudentCount();

                    // Log the updated class count
                }
            }

            // Log the activity
            $this->logActivity('created', $student);

            // Insert assurance invoice if needed
            if ($validatedData['assurance'] == 1 && $request->filled('assuranceAmount') && floatval($request->input('assuranceAmount')) > 0) {
                $assuranceInvoice = Invoice::create([
                    'type' => 'assurance',
                    'assurance_amount' => $request->input('assuranceAmount'),
                    'membership_id' => null,
                    'offer_id' => null,
                    'student_id' => $student->id,
                    'amountPaid' => $request->input('assuranceAmount'),
                    'totalAmount' => $request->input('assuranceAmount'),
                    'rest' => 0,
                    'billDate' => $validatedData['billingDate'],
                    'creationDate' => now(),
                    'created_by' => auth()->id(),
                    'months' => 1,
                ]);
                InvoicePaymentLog::recordDelta($assuranceInvoice, 0, auth()->id());
            }

            return redirect()->route('students.show', $student->id)->with('success', 'Student created successfully.');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            // The WebP was already written to disk before Student::create(); if the row
            // never landed, discard the file instead of leaking an orphan.
            if ($newImagePath !== null) {
                $this->profileImages->discard($newImagePath);
            }

            return redirect()->back()->with('error', 'Failed to create student. Please try again.');
        }
    }

    /**
     * Display the specified resource.
     */
    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        // Block teachers from accessing student profiles
        if (auth()->user() && auth()->user()->role === 'teacher') {
            abort(403, 'Accès refusé. Les enseignants ne peuvent pas consulter les fiches élèves.');
        }
        // Fetch the student from the database
        $student = Student::find($id);

        // If the student doesn't exist, return a 404 error
        if (! $student) {
            abort(404);
        }

        // The teacher block above was the ONLY guard in this controller. It says nothing
        // about which school the caller belongs to, so an assistant scoped to one school
        // could read any student in the product â€” hasDisease, diseaseName, medication,
        // guardianNumber, CIN, plus full invoice, attendance and result history below.
        SchoolScope::authorizeStudent($student);

        // Fetch all levels, and teachers with their subjects
        $levels = Level::all();
        // Fetch offers based on student's level
        $offers = Offer::where('levelId', $student->levelId)->get();
        $teachers = Teacher::with('subjects')->get(); // Eager load subjects for each teacher

        // Fetch memberships for the student (including soft-deleted ones)
        $nowLabel = \App\Support\AcademicYear::label(now());
        $memberships = Membership::withTrashed()
            ->where('student_id', $student->id)
            ->with(['offer', 'invoices'])
            ->get()
            ->map(function ($membership) use ($nowLabel) {
                // Process invoices for this membership
                $membershipInvoices = $membership->invoices->map(function ($invoice) {
                    // Always send selectedMonths as array if present
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

                    return [
                        'id' => $invoice->id,
                        'membership_id' => $invoice->membership_id,
                        'months' => $invoice->months,
                        'billDate' => $invoice->billDate,
                        'creationDate' => $invoice->creationDate,
                        'totalAmount' => (float) $invoice->totalAmount,
                        'amountPaid' => (float) $invoice->amountPaid,
                        'rest' => (float) $invoice->rest,
                        'endDate' => $invoice->endDate,
                        'includePartialMonth' => $invoice->includePartialMonth,
                        'partialMonthAmount' => (float) $invoice->partialMonthAmount,
                        'last_payment' => $invoice->updated_at,
                        'created_at' => $invoice->created_at,
                        'selectedMonths' => $selectedMonths,
                        'type' => $invoice->type,
                        'assurance_amount' => (float) $invoice->assurance_amount,
                    ];
                });

                return [
                    'id' => $membership->id,
                    'offer_name' => optional($membership->offer)->offer_name,
                    'offer_id' => optional($membership->offer)->id,
                    'price' => optional($membership->offer)->price,
                    // The membership's OWN offer data. The student page level-filters
                    // the offer list (right for CREATE), so a membership on an
                    // old-level offer is never in it — the UPDATE form preloads
                    // from these fields instead. Already eager-loaded above: no
                    // new queries. (Offer carries no SoftDeletes trait, so the
                    // relation resolves any existing row; nulls mean the row is
                    // gone, which the FK cascade makes near-impossible.)
                    'subjects' => optional($membership->offer)->subjects,
                    'percentage' => optional($membership->offer)->percentage,
                    // Visual signal only, never a rule: past-school-year record.
                    'is_historical' => \App\Support\AcademicYear::isHistorical(
                        $membership->end_date ?? $membership->created_at,
                        $nowLabel
                    ),
                    'teachers' => $membership->teachers,
                    'created_at' => $membership->created_at,
                    'payment_status' => $membership->payment_status,
                    'is_active' => $membership->is_active,
                    'start_date' => $membership->start_date,
                    'end_date' => $membership->end_date,
                    'deleted_at' => $membership->deleted_at, // Include deletion status
                    'invoices' => $membershipInvoices, // Include invoices for this membership
                ];
            });

        // Fetch invoices for all student
        $invoices = Invoice::where('student_id', $student->id)
            ->with(['offer'])
            ->get()
            ->map(function ($invoice) {
                // Always send selectedMonths as array if present
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

                return [
                    'id' => $invoice->id,
                    'membership_id' => $invoice->membership_id,
                    'months' => $invoice->months,
                    'billDate' => $invoice->billDate,
                    'creationDate' => $invoice->creationDate,
                    'totalAmount' => (float) $invoice->totalAmount,
                    'amountPaid' => (float) $invoice->amountPaid,
                    'rest' => (float) $invoice->rest,
                    'endDate' => $invoice->endDate,
                    'includePartialMonth' => $invoice->includePartialMonth,
                    'partialMonthAmount' => (float) $invoice->partialMonthAmount,
                    'last_payment' => $invoice->updated_at,
                    'created_at' => $invoice->created_at,
                    'selectedMonths' => $selectedMonths,
                    'type' => $invoice->type,
                    'assurance_amount' => (float) $invoice->assurance_amount,
                ];
            });

        // Fetch latest assurance invoice for the student
        $assuranceInvoice = Invoice::where('student_id', $student->id)
            ->where('type', 'assurance')
            ->orderByDesc('created_at')
            ->first();

        // Fetch attendance records for the student
        $attendanceRows = Attendance::with(['class', 'recordedBy'])
            ->where('student_id', $student->id)
            ->latest()
            ->get();

        // Whether the parent was already told, per absence â€” one query for the whole list.
        // The "Envoyer WhatsApp" button on each row reads this instead of making somebody
        // press it to find out, which is how duplicate notices reached parents.
        $notifications = \App\Models\OutboundMessage::summaryForAttendances(
            $attendanceRows->pluck('id')->all()
        );

        $attendances = $attendanceRows->map(function ($attendance) use ($notifications) {
            return [
                'id' => $attendance->id,
                'date' => $attendance->date,
                'status' => $attendance->status,
                // Part of what makes two notices on one day legitimate (Maths and French
                // are separate absences), so the row has to carry it.
                'subject' => $attendance->subject,
                'class' => $attendance->class ? $attendance->class->name : null,
                'recordedBy' => $attendance->recordedBy ? $attendance->recordedBy->name : null,
                'created_at' => $attendance->created_at,
                'reason' => $attendance->reason,
                'notification' => $notifications[$attendance->id] ?? null,
            ];
        });

        // Fetch results/grades for the student
        $results = Result::with(['subject', 'class.level'])
            ->where('student_id', $student->id)
            ->get()
            ->map(function ($result) {
                return [
                    'id' => $result->id,
                    'subject' => $result->subject ? $result->subject->name : 'Unknown Subject',
                    'level' => $result->class && $result->class->level ? $result->class->level->name : 'Unknown Level',
                    'teacher' => $result->class ? ($result->class->teacher_name ?? 'Unknown Teacher') : 'Unknown Teacher',
                    'grade1' => $result->grade1,
                    'grade2' => $result->grade2,
                    'grade3' => $result->grade3,
                    'final_grade' => $result->final_grade,
                    'notes' => $result->notes,
                    'exam_date' => $result->exam_date,
                ];
            });

        // Fetch promotion status for the student
        $promotion = $student->getCurrentPromotion();
        $promotionData = $promotion ? [
            'id' => $promotion->id,
            'student_id' => $promotion->student_id,
            'is_promoted' => $promotion->is_promoted,
            'notes' => $promotion->notes,
            'school_year' => $promotion->school_year,
            'created_at' => $promotion->created_at,
            'updated_at' => $promotion->updated_at,
        ] : null;

        // Full academic history: all year snapshots (level X with date X) for clean history timeline
        $promotionsHistory = \App\Models\StudentPromotion::with(['level', 'class'])
            ->where('student_id', $student->id)
            ->orderBy('school_year', 'desc')
            ->get()
            ->map(function ($p) {
                return [
                    'id' => $p->id,
                    'school_year' => $p->school_year,
                    'year_label' => $p->year_label,
                    'level_id' => $p->level_id,
                    'level_name' => $p->level ? $p->level->name : null,
                    'class_id' => $p->class_id,
                    'class_name' => $p->class ? $p->class->name : null,
                    'is_promoted' => $p->is_promoted,
                    'notes' => $p->notes,
                    'created_at' => $p->created_at,
                    'updated_at' => $p->updated_at,
                ];
            });

        // Format the student data with new disease fields
        $studentData = [
            'id' => $student->id,
            'name' => $student->firstName.' '.$student->lastName,
            'studentId' => $student->massarCode,
            'phone' => $student->phoneNumber,
            'phoneNumber' => $student->phoneNumber,
            'address' => $student->address,
            'classId' => $student->classId,
            'schoolId' => $student->schoolId,
            'firstName' => $student->firstName,
            'lastName' => $student->lastName,
            'dateOfBirth' => $student->dateOfBirth,
            'billingDate' => $student->billingDate,
            'CIN' => $student->CIN,
            'email' => $student->email,
            'massarCode' => $student->massarCode,
            'levelId' => $student->levelId,
            'status' => $student->status,
            'assurance' => $student->assurance,
            'assuranceAmount' => $student->assuranceAmount,
            'guardianNumber' => $student->guardianNumber,
            'guardianName' => $student->guardianName,
            'profile_image' => $student->profile_image ?? null,
            'hasDisease' => $student->hasDisease,
            'diseaseName' => $student->diseaseName,
            'medication' => $student->medication,
            'created_at' => $student->created_at,
            'memberships' => $memberships,
            'invoices' => $invoices,
            'attendances' => $attendances,
            'results' => $results,
            'promotion' => $promotionData,
            'assurance_paid' => $assuranceInvoice ? true : false,
            'assurance_invoice' => $assuranceInvoice ? [
                'amount' => $assuranceInvoice->assurance_amount,
                'date' => $assuranceInvoice->created_at,
                'invoice_id' => $assuranceInvoice->id,
            ] : null,
        ];

        // Fetch schools and classes
        $schools = School::all();
        $classes = Classes::all();

        // Get student movement history
        $movements = StudentMovement::where('student_id', $student->id)
            ->with(['recordedBy'])
            ->orderBy('movement_date', 'desc')
            ->get()
            ->map(function ($movement) {
                return [
                    'id' => $movement->id,
                    'movement_type' => $movement->movement_type,
                    'movement_date' => $movement->movement_date,
                    'month_year' => $movement->month_year,
                    'reason' => $movement->reason,
                    'previous_status' => $movement->previous_status,
                    'new_status' => $movement->new_status,
                    'billing_date' => $movement->billing_date,
                    'assurance_amount' => $movement->assurance_amount,
                    'has_assurance' => $movement->has_assurance,
                    'recorded_by' => $movement->recordedBy ? $movement->recordedBy->name : null,
                    'notes' => $movement->notes,
                    'created_at' => $movement->created_at,
                ];
            });

        return Inertia::render('Menu/SingleStudentPage', [
            'student' => $studentData,
            'movements' => $movements,
            'promotionsHistory' => $promotionsHistory,
            'Alllevels' => $levels,
            'Allclasses' => $classes,
            'Allschools' => $schools,
            'Alloffers' => $offers,
            'Allteachers' => $teachers->map(function ($teacher) {
                return [
                    'id' => $teacher->id,
                    'first_name' => $teacher->first_name,
                    'last_name' => $teacher->last_name,
                    'subjects' => $teacher->subjects->pluck('name'),
                ];
            }),
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Student $student)
    {
        SchoolScope::authorizeStudent($student);

        return Inertia::render('Students/Edit', [
            'student' => $student,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Student $student)
    {
        // BEFORE the try, not inside it: this method's `catch (\Exception $e)` would
        // otherwise turn the denial into a redirect. (SchoolScope throws an \Error
        // subclass so it survives even a generic catch â€” see AccessDeniedException â€”
        // but placing the check outside the try keeps that from being load-bearing.)
        SchoolScope::authorizeStudent($student);

        try {
            $oldData = $student->toArray();
            $oldClassId = $student->classId;

            // Log the raw request data for debugging
            $validatedData = $request->validate([
                'firstName' => 'required|string|max:100',
                'lastName' => 'required|string|max:100',
                'dateOfBirth' => 'required|date',
                'billingDate' => 'required|date',
                'address' => 'nullable|string',
                'guardianNumber' => 'nullable|string|max:255',
                'guardianName' => 'required|string|max:255',
                'CIN' => 'nullable|string|max:50|unique:students,CIN,'.$student->id,
                'phoneNumber' => 'nullable|string|max:20',
                'email' => 'nullable|string|email|max:255|unique:students,email,'.$student->id,
                'massarCode' => 'nullable|string|max:50|unique:students,massarCode,'.$student->id,
                'levelId' => 'nullable',
                'classId' => 'nullable',
                'schoolId' => 'nullable',
                'status' => 'required|in:active,inactive',
                'assurance' => 'required',
                'assuranceAmount' => 'required_if:assurance,1|nullable|numeric|min:0',
                'hasDisease' => 'sometimes',
                'diseaseName' => 'nullable|string|max:255',
                'medication' => 'nullable|string',
                'profile_image' => 'nullable|mimes:jpg,jpeg,png,webp,avif|max:5120',
            ]);

            // Process hasDisease field - ensure it's always an integer 0 or 1
            $hasDiseaseValue = 0; // Default to 0
            if (isset($validatedData['hasDisease'])) {
                // Convert various truthy values to 1
                if (in_array($validatedData['hasDisease'], [1, '1', true, 'true', 'yes', 'Yes', 'YES'], true)) {
                    $hasDiseaseValue = 1;
                }
            }
            $validatedData['hasDisease'] = $hasDiseaseValue;

            // Process assurance field
            $assuranceValue = 0; // Default to 0
            if (isset($validatedData['assurance'])) {
                // Convert various truthy values to 1
                if (in_array($validatedData['assurance'], [1, '1', true, 'true', 'yes', 'Yes', 'YES'], true)) {
                    $assuranceValue = 1;
                }
            }
            $validatedData['assurance'] = $assuranceValue;

            // Handle disease fields based on hasDisease value
            if ($hasDiseaseValue === 0) {
                // When hasDisease is false, always set diseaseName and medication to NULL
                $validatedData['diseaseName'] = null;
                $validatedData['medication'] = null;
            } else {
                // When hasDisease is true, validate that diseaseName is provided
                if (empty($validatedData['diseaseName'])) {
                    return redirect()->back()->withErrors(['diseaseName' => 'The disease name field is required when has disease is true.'])->withInput();
                }

                // Convert empty strings to null
                if ($validatedData['diseaseName'] === '') {
                    $validatedData['diseaseName'] = null;
                }
                if (isset($validatedData['medication']) && $validatedData['medication'] === '') {
                    $validatedData['medication'] = null;
                }
            }

            // Convert other empty strings to null for nullable fields
            foreach (['CIN', 'phoneNumber', 'email', 'massarCode'] as $field) {
                if (isset($validatedData[$field]) && $validatedData[$field] === '') {
                    $validatedData[$field] = null;
                }
            }

            $newImagePath = null;
            $oldRawImage = $student->getRawOriginal('profile_image');
            if ($request->hasFile('profile_image')) {
                $newImagePath = $this->profileImages->store($request->file('profile_image'), 'students');

                // Optimistic concurrency: swap the reference only if it still holds the value
                // this request was rendered with. Two simultaneous replaces cannot both win;
                // the loser discards its freshly stored file instead of leaving an orphan leak.
                $swapped = Student::whereKey($student->getKey())
                    ->when($oldRawImage === null,
                        fn ($q) => $q->whereNull('profile_image'),
                        fn ($q) => $q->where('profile_image', $oldRawImage))
                    ->update(['profile_image' => $newImagePath]);

                if ($swapped === 0) {
                    $this->profileImages->discard($newImagePath);

                    return redirect()->back()
                        ->withErrors(['profile_image' => "L'image a été modifiée entre-temps. Rechargez la page et réessayez."])
                        ->withInput();
                }

                // Reference already atomically updated; keep it out of the bulk update below.
                unset($validatedData['profile_image']);
            }

            // Update the student using Eloquent to trigger events
            $student->update($validatedData);

            // Check if the class has changed
            $newClassId = $student->classId;
            if ($oldClassId != $newClassId) {
                // Update old class count if there was an old class
                if ($oldClassId) {
                    $oldClass = Classes::find($oldClassId);
                    if ($oldClass) {
                        $oldClass->updateStudentCount();
                    }
                }

                // Update new class count if there is a new class
                if ($newClassId) {
                    $newClass = Classes::find($newClassId);
                    if ($newClass) {
                        $newClass->updateStudentCount();
                    }
                }
            }

            // Log the activity with old and new data
            $this->logActivity('updated', $student, $oldData, $student->toArray());

            // Insert or update assurance invoice if needed (on update)
            if ($validatedData['assurance'] == 1) {
                // Find the latest assurance invoice for this student
                $assuranceInvoice = Invoice::where('student_id', $student->id)
                    ->where('type', 'assurance')
                    ->orderByDesc('created_at')
                    ->first();
                $raw = $validatedData['assuranceAmount'] ?? $request->input('assuranceAmount', 0);
                $amount = $raw === '' ? 0 : floatval($raw);
                if ($assuranceInvoice) {
                    // Update the existing invoice - capture previous for delta
                    $previousAmountPaid = $assuranceInvoice->amountPaid;
                    $assuranceInvoice->update([
                        'assurance_amount' => $amount,
                        'amountPaid' => $amount,
                        'totalAmount' => $amount,
                        'rest' => 0,
                        'billDate' => $validatedData['billingDate'],
                        'creationDate' => now(),
                        'created_by' => auth()->id(),
                        'months' => 1,
                    ]);
                    InvoicePaymentLog::recordDelta($assuranceInvoice, $previousAmountPaid, auth()->id());
                } else {
                    // Create a new invoice
                    $newAssuranceInvoice = Invoice::create([
                        'type' => 'assurance',
                        'assurance_amount' => $amount,
                        'membership_id' => null,
                        'offer_id' => null,
                        'student_id' => $student->id,
                        'amountPaid' => $amount,
                        'totalAmount' => $amount,
                        'rest' => 0,
                        'billDate' => $validatedData['billingDate'],
                        'creationDate' => now(),
                        'created_by' => auth()->id(),
                        'months' => 1,
                    ]);
                    InvoicePaymentLog::recordDelta($newAssuranceInvoice, 0, auth()->id());
                }
            }

            if ($newImagePath !== null && $oldRawImage !== null) {
                $this->profileImages->delete($oldRawImage);
            }

            return redirect()->route('students.show', $student->id)->with('success', 'Student updated successfully.');
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            // If this fires after the optimistic swap, roll the reference back to the
            // previous image and discard the fresh one â€” otherwise the old image orphans
            // and the new one strands behind an error message.
            if (($newImagePath ?? null) !== null) {
                Student::whereKey($student->getKey())->update(['profile_image' => $oldRawImage]);
                $this->profileImages->discard($newImagePath);
            }

            return redirect()->back()->with('error', 'Failed to update student: '.$e->getMessage());
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Student $student)
    {
        SchoolScope::authorizeStudent($student);

        try {
            // Save class ID before deleting student
            $classId = $student->classId;

            // Image file intentionally kept: this is a soft delete â€” the row keeps its
            // reference so a restore gets the image back. Permanent purge deletes the file.

            // Delete the student
            $student->delete();

            // Update class student count if student was assigned to a class
            if ($classId) {
                $class = Classes::find($classId);
                if ($class) {
                    $class->updateStudentCount();

                    // Log the updated class count
                }
            }

            return redirect()->route('students.index')->with('success', 'Student deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->back()->with('error', 'Failed to delete student. Please try again.');
        }
    }

    protected function logActivity($action, $model, $oldData = null, $newData = null)
    {
        $description = ucfirst($action).' '.class_basename($model).' ('.$model->id.')';
        $tableName = $model->getTable();

        // Define the properties to log
        $properties = [
            'TargetName' => $model->firstName.' '.$model->lastName, // Name of the target entity
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

        // For creations, show only the 4 most important columns
        if ($action === 'created') {
            $properties['new_data'] = [
                'firstName' => $model->firstName,
                'lastName' => $model->lastName,
                'email' => $model->email,
                'phoneNumber' => $model->phoneNumber,
            ];
        }

        // For deletions, show the key fields of the deleted entity
        if ($action === 'deleted') {
            $properties['deleted_data'] = [
                'firstName' => $oldData['firstName'],
                'lastName' => $oldData['lastName'],
                'email' => $oldData['email'],
                'phoneNumber' => $oldData['phoneNumber'],
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
