<?php

namespace App\Http\Controllers;

use App\Exceptions\AccessDeniedException;
use App\Models\Announcement;
use App\Models\Assistant;
use App\Models\Attendance;
use App\Models\Classes;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ProfileImageService;
use App\Support\ProfileImageUrl;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class AssistantController extends Controller
{
    public function __construct(private ProfileImageService $profileImages) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $selectedSchoolId = session('school_id');

        $query = Assistant::query()->with('schools'); // Eager load schools

        // Filter by selected school if one is in session
        if ($selectedSchoolId) {
            $query->whereHas('schools', function ($schoolQuery) use ($selectedSchoolId) {
                $schoolQuery->where('schools.id', $selectedSchoolId);
            });
        }

        // Apply search filter if search term is provided
        if ($request->has('search') && ! empty($request->search)) {
            $searchTerm = $request->search;

            $query->where(function ($q) use ($searchTerm) {
                // Search by assistant fields
                $q->where('first_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('last_name', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('phone_number', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('email', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('address', 'LIKE', "%{$searchTerm}%")
                  // Search by full name (first_name + last_name combined)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$searchTerm}%"])
                  // Search by full name in reverse order (last_name + first_name)
                    ->orWhereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", ["%{$searchTerm}%"]);

                // Search by school name
                $q->orWhereHas('schools', function ($schoolQuery) use ($searchTerm) {
                    $schoolQuery->where('name', 'LIKE', "%{$searchTerm}%");
                });
            });
        }

        // Apply additional filters (school, status)
        if (! empty($request->school)) {
            $query->whereHas('schools', function ($schoolQuery) use ($request) {
                $schoolQuery->where('schools.id', $request->school);
            });
        }

        if (! empty($request->status)) {
            $query->where('status', $request->status);
        }

        // Fetch paginated and filtered assistants
        $assistants = $query->paginate(10)->withQueryString()->through(function ($assistant) {
            return [
                'id' => $assistant->id,
                'name' => $assistant->first_name.' '.$assistant->last_name,
                'phone_number' => $assistant->phone_number,
                'first_name' => $assistant->first_name,
                'last_name' => $assistant->last_name,
                'email' => $assistant->email,
                'address' => $assistant->address,
                'status' => $assistant->status,
                'salary' => $assistant->salary,
                'profile_image' => $assistant->profile_image ? $assistant->profile_image : null,
                'schools_assistant' => $assistant->schools,
            ];
        });

        // Fetch schools for filter dropdowns
        $schoolsForFilter = School::all();

        return Inertia::render('Menu/AssistantsListPage', [
            'assistants' => $assistants,
            'schools' => $schoolsForFilter,
            'search' => $request->search,
            'filters' => $request->only(['school', 'status']), // Pass current filters
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $subjects = Subject::all();
        $classes = Classes::all();
        $schools = School::all();

        return Inertia::render('AssistantsListPage/Create', [
            'subjects' => $subjects,
            'classes' => $classes,
            'schools' => $schools,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $newImagePath = null;
        try {
            $validatedData = $request->validate([
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                // Live rows only: a soft-deleted assistant with this email is re-hired below.
                'email' => [
                    'required', 'string', 'email', 'max:255',
                    function (string $attribute, mixed $value, \Closure $fail) {
                        if (Assistant::where('email', $value)->exists()) {
                            $fail('Cette adresse e-mail est déjà utilisée par un autre assistant.');
                        }
                        // Cross-table invariant kept by ValidateEmailUnique: a live
                        // teacher owning this email blocks the create/re-hire.
                        if (Teacher::where('email', $value)->exists()) {
                            $fail('Cette adresse e-mail est déjà utilisée par un enseignant.');
                        }
                    },
                ],
                'phone_number' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:255',
                'profile_image' => 'nullable|mimes:jpg,jpeg,png,webp,avif|max:5120', // Increased to 5MB
                'salary' => 'required|numeric|min:0',
                'status' => 'required|in:active,inactive',
                'schools' => 'required|array',
                'schools.*' => 'exists:schools,id',
            ]);

            $newImagePath = null;
            if ($request->hasFile('profile_image')) {
                // May throw ValidationException â€” caught below by the dedicated handler.
                $newImagePath = $this->profileImages->store($request->file('profile_image'), 'assistants');
                $validatedData['profile_image'] = $newImagePath;
            }

            // Re-hire: revive the soft-deleted row (same id, history intact) instead
            // of colliding with its unique email index.
            $rehired = Assistant::onlyTrashed()->where('email', $validatedData['email'])->first();

            if ($rehired) {
                unset($validatedData['email']);
                $rehired->fill($validatedData)->restore();
                $assistant = $rehired;
            } else {
                // Create the assistant record
                $assistant = Assistant::create($validatedData);
            }

            // Sync schools with the assistant
            $assistant->schools()->sync($request->schools);

            return redirect()->back()->with('success', 'Assistant created successfully.');
        } catch (ValidationException $e) {
            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput();
        } catch (\Exception $e) {
            // The WebP was already written to disk before Assistant::create(); if the row
            // never landed, discard the file instead of leaking an orphan.
            if (($newImagePath ?? null) !== null) {
                $this->profileImages->discard($newImagePath);
            }

            return redirect()->back()
                ->with('error', 'An error occurred while creating the assistant: '.$e->getMessage())
                ->withInput();
        }
    }

    /**
     * Admin, or the assistant looking at their own record. Nobody else.
     *
     * GET assistants/{assistant} and its /student-payments sibling were the only two
     * assistant routes with no guard at all â€” PUT and DELETE both chained AdminMiddleware,
     * read did not. show() returns salary, phone_number, address, unpaid invoices with
     * student names and amounts, recent payments and the activity log.
     *
     * CanViewTeacherProfile already reasons that individual profiles "carry wallet
     * balances, invoices and payout history" and hides them; the same reasoning simply
     * was never applied to assistants.
     *
     * Identity joins on email, matching User::assistant() and SchoolScope.
     */
    private function authorizeAssistantAccess(Assistant $assistant): void
    {
        $user = Auth::user();

        if ($user && $user->role === 'admin') {
            return;
        }

        if ($user && $user->role === 'assistant' && $user->email === $assistant->email) {
            return;
        }

        throw new AccessDeniedException("Vous n'avez pas accÃ¨s Ã  ce profil.");
    }

    /**
     * Display the specified resource.
     */
    public function show(Assistant $assistant)
    {
        $this->authorizeAssistantAccess($assistant);

        try {
            // Load the assistant with schools relationship
            $assistant->load(['schools']);

            // Get the assistant's user (for user_id in transactions)
            $assistantUser = User::where('email', $assistant->email)->first();

            $transactions = collect();
            if ($assistantUser) {
                // Fetch transactions by user_id (not assistant ID)
                $transactions = Transaction::where('user_id', $assistantUser->id)->get();

                // Log transaction fetching results
                Log::info('Assistant transactions fetched', [
                    'user_id' => $assistantUser->id,
                    'transactions_count' => $transactions->count(),
                    'transaction_user_ids' => $transactions->pluck('user_id')->unique()->toArray(),
                ]);
                // Mark recurring transactions as paid_this_month if a corresponding one-time payment exists
                $currentMonth = now()->format('Y-m');
                $startDate = Carbon::parse($currentMonth.'-01')->startOfMonth();
                $endDate = Carbon::parse($currentMonth.'-01')->endOfMonth();
                foreach ($transactions as $transaction) {
                    if ($transaction->is_recurring) {
                        $isPaidThisMonth = Transaction::where('is_recurring', 0)
                            ->where('description', 'like', '%(Recurring payment from #'.$transaction->id.')%')
                            ->whereBetween('payment_date', [$startDate, $endDate])
                            ->exists();
                        $transaction->paid_this_month = $isPaidThisMonth;
                    }
                }
            }

            // Get selected school from session or default to first school
            $selectedSchoolId = session('school_id');
            if (! $selectedSchoolId && $assistant->schools->isNotEmpty()) {
                $selectedSchoolId = $assistant->schools->first()->id;
            }

            // Define schoolIds based on selected school or all assistant's schools
            $schoolIds = $selectedSchoolId
                ? [$selectedSchoolId]
                : $assistant->schools->pluck('id')->toArray();

            // Log assistant and school info
            Log::info('Assistant dashboard data fetch started', [
                'assistant_id' => $assistant->id,
                'selected_school_id' => $selectedSchoolId,
                'assistant_schools' => $assistant->schools->pluck('id')->toArray(),
                'school_ids' => $schoolIds,
            ]);

            // Get current date
            $today = Carbon::now();

            // Get user and initialize basic data
            $user = Auth::user();
            $schools = School::all();

            $selectedAssistant = User::where('email', $assistant->email)->first();
            $classes = Classes::when($selectedSchoolId, function ($query) use ($selectedSchoolId) {
                return $query->where('school_id', $selectedSchoolId);
            })->get();
            $subjects = Subject::all();

            // Initialize logs with a default paginator structure
            $logs = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10);

            // Get announcements if user exists
            $announcements = [];
            if ($selectedAssistant) {
                // Fetch the assistant's activity logs
                $logs = Activity::where('causer_type', User::class)
                    ->where('causer_id', $selectedAssistant->id)
                    ->latest()
                    ->paginate(10);

                // Fetch announcements for the employee
                if ($user) {
                    $now = Carbon::now();
                    $announcements = Announcement::where(function ($q) use ($now) {
                        $q->where(function ($subq) use ($now) {
                            $subq->whereNull('date_start')
                                ->orWhere('date_start', '<=', $now);
                        })->where(function ($subq) use ($now) {
                            $subq->whereNull('date_end')
                                ->orWhere('date_end', '>=', $now);
                        });
                    })
                        ->where(function ($q) use ($user) {
                            $q->where('visibility', 'all')
                                ->orWhere('visibility', $user->role);
                        })
                        ->orderBy('date_announcement', 'desc')
                        ->limit(5)
                        ->get();
                }
            }

            // Get statistics
            $statistics = [
                // Was whereIn(DB::raw('"schoolId"'), ...) â€” a double-quoted token is a STRING
                // LITERAL in MySQL, so this compared the constant 'schoolId' against the id
                // list and always returned 0.
                'students_count' => Student::whereIn('schoolId', $schoolIds)->count(),
                'classes_count' => Classes::whereIn('school_id', $schoolIds)->count(),
            ];

            // Get selected school info
            $selectedSchool = $selectedSchoolId ? [
                'id' => $selectedSchoolId,
                'name' => session('school_name'),
            ] : null;

            // NOTE: a large "diagnostic logging" block used to sit here. On every assistant
            // dashboard load it ran Student::whereNull('deleted_at')->get() (EVERY student in
            // the system), fetched a hardcoded student id 1, and wrote all of it plus every
            // matching invoice â€” names, amounts, balances â€” into storage/logs. Removed.
            // It also contained the DB::raw('"schoolId"') predicate, which never matched.

            // FEATURE 1: Recent absences
            try {
                Log::info('Fetching recent absences', ['school_ids' => $schoolIds]);

                $recentAbsences = Attendance::with(['student', 'class'])
                    ->where(function ($query) use ($schoolIds) {
                        $query->whereHas('class', function ($classQuery) use ($schoolIds) {
                            $classQuery->whereIn('school_id', $schoolIds);
                        });

                        // Also get absences from students belonging to the assistant's schools
                        $query->orWhereHas('student', function ($studentQuery) use ($schoolIds) {
                            $studentQuery->whereIn('schoolId', $schoolIds);
                        });
                    })
                    ->where('date', '>=', $today->copy()->subDays(7)) // Show only from last 7 days
                    ->orderBy('date', 'desc')
                    ->limit(10)
                    ->get();

                // Log the executed query and results
                Log::info('Recent absences query log', [
                    'count' => $recentAbsences->count(),
                    'first_record' => $recentAbsences->first() ? $recentAbsences->first()->toArray() : null,
                ]);
                DB::disableQueryLog();

                $totalAbsences = Attendance::where(function ($query) use ($schoolIds) {
                    $query->whereHas('class', function ($classQuery) use ($schoolIds) {
                        $classQuery->whereIn('school_id', $schoolIds);
                    });
                    $query->orWhereHas('student', function ($studentQuery) use ($schoolIds) {
                        $studentQuery->whereIn('schoolId', $schoolIds);
                    });
                })
                    ->where('date', '>=', $today->copy()->subDays(7))
                    ->count();

                Log::info('Total absences count', ['count' => $totalAbsences]);

                $mappedAbsences = $recentAbsences->map(function ($attendance) {
                    return [
                        'id' => $attendance->id,
                        'student_id' => $attendance->student ? $attendance->student->id : null,
                        'student_name' => $attendance->student ? $attendance->student->firstName.' '.$attendance->student->lastName : 'Unknown',
                        'class_name' => $attendance->class ? $attendance->class->name : 'Unknown',
                        'date' => $attendance->date,
                        'status' => $attendance->status,
                        'reason' => $attendance->reason,
                    ];
                });

                $recentAbsences = $mappedAbsences;
            } catch (\Exception $e) {
                Log::error('Error fetching recent absences: '.$e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                    'school_ids' => $schoolIds,
                ]);
                $recentAbsences = [];
                $totalAbsences = 0;
            }

            // FEATURE 2: Unpaid invoices
            try {
                Log::info('Fetching unpaid invoices', [
                    'school_ids' => $schoolIds,
                    'today' => $today->format('Y-m-d'),
                    'seven_days_ago' => $today->copy()->subDays(7)->format('Y-m-d'),
                ]);

                $unpaidInvoicesQuery = Invoice::with(['student', 'student.class', 'student.school', 'offer'])
                    ->where(function ($query) use ($schoolIds) {
                        $query->whereHas('student', function ($studentQuery) use ($schoolIds) {
                            $studentQuery->whereIn('schoolId', $schoolIds);
                        });
                    })
                    ->whereNull('deleted_at')
                    ->where('type', 'invoice')
                    ->where(function ($q) {
                        $q->whereRaw('COALESCE(rest, 0) > 0')
                            ->orWhereRaw('COALESCE(totalAmount, 0) > COALESCE(amountPaid, 0)');
                    })
                    ->orderBy('creationDate', 'desc')
                    ->orderBy('billDate', 'desc');

                $unpaidInvoices = $unpaidInvoicesQuery->paginate(10);

                // Log the executed query and results
                Log::info('Unpaid invoices query log', [
                    'count' => $unpaidInvoices->count(),
                    'first_record' => $unpaidInvoices->firstItem() ? $unpaidInvoices->items()[0] : null,
                ]);
                DB::disableQueryLog();

                $totalUnpaidInvoices = $unpaidInvoices->total();

                Log::info('Total unpaid invoices count', ['count' => $totalUnpaidInvoices]);

                $unpaidInvoicesData = collect($unpaidInvoices->items())->map(function ($invoice) {
                    $student = $invoice->student;
                    $offerName = $invoice->offer ? $invoice->offer->offer_name : 'N/A';
                    $total = is_numeric($invoice->totalAmount) ? floatval($invoice->totalAmount) : 0.0;
                    $paid = is_numeric($invoice->amountPaid) ? floatval($invoice->amountPaid) : 0.0;
                    $computedRest = max(0.0, $total - $paid);

                    return [
                        'id' => $invoice->id,
                        'student_id' => $student ? $student->id : null,
                        'student_name' => $student ? $student->firstName.' '.$student->lastName : 'Unknown',
                        'student_class' => $student && $student->class ? $student->class->name : 'N/A',
                        'student_school' => $student && $student->school ? $student->school->name : 'N/A',
                        'billDate' => $invoice->billDate ? $invoice->billDate->format('Y-m-d') : null,
                        'creationDate' => $invoice->creationDate ? $invoice->creationDate->format('Y-m-d') : null,
                        'endDate' => $invoice->endDate ? $invoice->endDate->format('Y-m-d') : null,
                        'totalAmount' => $total,
                        'amountPaid' => $paid,
                        'rest' => $computedRest,
                        'months' => $invoice->months ?? 1,
                        'offer_name' => $offerName,
                        'offer_id' => $invoice->offer_id,
                        'payments' => ($invoice->amountPaid > 0) ? [[
                            'date' => $invoice->last_payment_date ? $invoice->last_payment_date->format('Y-m-d') : ($invoice->creationDate ? $invoice->creationDate->format('Y-m-d') : null),
                            'amount' => is_numeric($invoice->amountPaid) ? floatval($invoice->amountPaid) : 0,
                            'method' => 'Cash',
                        ]] : [],
                    ];
                });
                $unpaidInvoicesLinks = $unpaidInvoices->linkCollection();
            } catch (\Exception $e) {
                Log::error('Error fetching unpaid invoices: '.$e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                    'school_ids' => $schoolIds,
                ]);
                $unpaidInvoices = [];
                $totalUnpaidInvoices = 0;
            }

            // FEATURE 3: Expiring memberships
            try {
                Log::info('Fetching expiring memberships', ['school_ids' => $schoolIds]);

                $expiringMembershipsQuery = Membership::with(['student'])
                    ->whereNull('deleted_at')
                    ->whereNotNull('end_date')
                    ->where(function ($query) use ($schoolIds) {
                        $query->whereHas('student', function ($studentQuery) use ($schoolIds) {
                            $studentQuery->whereIn('schoolId', $schoolIds);
                        });
                    })
                    // Include already expired and those expiring within next 30 days
                    ->where('end_date', '<=', $today->copy()->addDays(30))
                    // Logical ordering: expired first, then <= 3 days, then others by end_date
                    ->orderByRaw(
                        'CASE 
                             WHEN end_date < ? THEN 0 
                             WHEN end_date <= ? THEN 1 
                             ELSE 2 
                          END',
                        [
                            $today->toDateString(),
                            $today->copy()->addDays(3)->toDateString(),
                        ]
                    )
                    ->orderBy('end_date', 'asc');

                $expiringMemberships = $expiringMembershipsQuery->paginate(10);

                // Log the executed query and results
                Log::info('Expiring memberships query log', [
                    'count' => $expiringMemberships->count(),
                    'first_record' => $expiringMemberships->firstItem() ? $expiringMemberships->items()[0] : null,
                ]);
                DB::disableQueryLog();

                $totalExpiringMemberships = $expiringMemberships->total();

                Log::info('Total expiring memberships count', ['count' => $totalExpiringMemberships]);

                $expiringMembershipsData = collect($expiringMemberships->items())->map(function ($membership) use ($today) {
                    $endDate = Carbon::parse($membership->end_date);
                    $daysLeft = $today->diffInDays($endDate, false);
                    $urgency = 'upcoming';
                    if ($daysLeft < 0) {
                        $urgency = 'expired';
                    } elseif ($daysLeft <= 3) {
                        $urgency = 'due_soon';
                    }

                    return [
                        'id' => $membership->id,
                        'student_id' => $membership->student ? $membership->student->id : null,
                        'student_name' => $membership->student ? $membership->student->firstName.' '.$membership->student->lastName : 'Unknown',
                        'start_date' => $membership->start_date,
                        'end_date' => $membership->end_date,
                        'days_left' => max(0, round($daysLeft)),
                        'urgency' => $urgency,
                    ];
                });
                $expiringMembershipsLinks = $expiringMemberships->linkCollection();
            } catch (\Exception $e) {
                Log::error('Error fetching expiring memberships: '.$e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                    'school_ids' => $schoolIds,
                ]);
                $expiringMemberships = [];
                $totalExpiringMemberships = 0;
            }

            // FEATURE 4: Recent payments
            $recentPaymentsData = [];
            $recentPaymentsLinks = [];
            $totalRecentPayments = 0;

            try {
                Log::info('Fetching recent payments', [
                    'school_ids' => $schoolIds,
                    'today' => $today->format('Y-m-d'),
                    'thirty_days_ago' => $today->copy()->subDays(30)->format('Y-m-d'),
                ]);

                $recentPayments = Invoice::with(['student', 'student.class', 'student.school', 'offer'])
                    ->where(function ($query) use ($schoolIds) {
                        $query->whereHas('student', function ($studentQuery) use ($schoolIds) {
                            $studentQuery->whereIn('schoolId', $schoolIds);
                        });
                    })
                    ->where('amountPaid', '>', 0)
                    ->orderBy('creationDate', 'desc')
                    ->paginate(10);

                // Log the executed query and results
                Log::info('Recent payments query log', [
                    'count' => $recentPayments->count(),
                    'first_record' => $recentPayments->first() ? $recentPayments->first()->toArray() : null,
                    'all_records' => $recentPayments->toArray(),
                ]);
                DB::disableQueryLog();

                $totalRecentPayments = $recentPayments->total();

                Log::info('Total recent payments count', ['count' => $totalRecentPayments]);

                $recentPaymentsData = $recentPayments->map(function ($invoice) {
                    $student = $invoice->student;
                    $offerName = $invoice->offer ? $invoice->offer->offer_name : 'N/A';

                    return [
                        'id' => $invoice->id,
                        'student_id' => $student ? $student->id : null,
                        'student_name' => $student ? $student->firstName.' '.$student->lastName : 'Unknown',
                        'payment_date' => $invoice->last_payment_date ? $invoice->last_payment_date->format('Y-m-d') : ($invoice->creationDate ? $invoice->creationDate->format('Y-m-d') : null),
                        'amount' => is_numeric($invoice->amountPaid) ? floatval($invoice->amountPaid) : 0,
                        'payment_method' => 'Cash',
                        'offer_name' => $offerName,
                    ];
                });
            } catch (\Exception $e) {
                Log::error('Error fetching recent payments: '.$e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                    'school_ids' => $schoolIds,
                ]);
                $recentPaymentsData = [];
                $recentPaymentsLinks = [];
                $totalRecentPayments = 0;
            }

            // Log final data being sent to view
            Log::info('Assistant dashboard data being sent to view', [
                'recent_absences_count' => count($recentAbsences),
                'unpaid_invoices_count' => count($unpaidInvoicesData),
                'expiring_memberships_count' => count($expiringMembershipsData),
                'recent_payments_count' => count($recentPaymentsData),
            ]);

            return Inertia::render('Menu/SingleAssistantPage', [
                // makeHidden: an assistant viewing this page is looking at their own HR
                // file; the salary column must not ride along in the page props. Admin
                // edit forms read it from the list endpoints, which stay untouched.
                'assistant' => $assistantUser
                    ? array_merge($assistant->makeHidden('salary')->toArray(), ['user_id' => $assistantUser->id])
                    : array_merge($assistant->makeHidden('salary')->toArray(), ['user_id' => null]),
                'transactions' => $transactions,
                'announcements' => $announcements,
                'classes' => $classes,
                'subjects' => $subjects,
                'schools' => $schools,
                'logs' => $logs,
                'recentAbsences' => $recentAbsences,
                'unpaidInvoices' => $unpaidInvoicesData,
                'unpaidInvoicesLinks' => $unpaidInvoicesLinks,
                'expiringMemberships' => $expiringMembershipsData,
                'expiringMembershipsLinks' => $expiringMembershipsLinks,
                'recentPayments' => $recentPaymentsData,
                'recentPaymentsLinks' => $recentPaymentsLinks,
                'totalAbsences' => $totalAbsences,
                'totalUnpaidInvoices' => $totalUnpaidInvoices,
                'totalExpiringMemberships' => $totalExpiringMemberships,
                'totalRecentPayments' => $totalRecentPayments,
                'selectedSchool' => $selectedSchool,
                'statistics' => $statistics,
            ]);
        } catch (\Exception $e) {
            Log::error('Error in assistant dashboard: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'assistant_id' => $assistant->id,
            ]);

            return redirect()->back()->with('error', 'An error occurred while loading the assistant dashboard.');
        }
    }

    /**
     * Show all student payments visible to a specific assistant
     */
    public function studentPayments(Assistant $assistant)
    {
        $this->authorizeAssistantAccess($assistant);

        try {
            // Load the assistant with schools relationship
            $assistant->load(['schools']);

            // Get selected school from session or default to first school
            $selectedSchoolId = session('school_id');
            if (! $selectedSchoolId && $assistant->schools->isNotEmpty()) {
                $selectedSchoolId = $assistant->schools->first()->id;
            }

            // Define schoolIds based on selected school or all assistant's schools
            $schoolIds = $selectedSchoolId
                ? [$selectedSchoolId]
                : $assistant->schools->pluck('id')->toArray();

            // Get paginated payments
            $payments = Invoice::with(['student', 'student.class', 'student.school', 'offer'])
                ->where(function ($query) use ($schoolIds) {
                    $query->whereHas('student', function ($studentQuery) use ($schoolIds) {
                        $studentQuery->whereIn('schoolId', $schoolIds);
                    });
                })
                ->where('amountPaid', '>', 0)
                ->orderBy('creationDate', 'desc')
                ->paginate(20);

            // Transform the data
            $paymentsData = $payments->map(function ($invoice) {
                $student = $invoice->student;
                $offerName = $invoice->offer ? $invoice->offer->offer_name : 'N/A';

                return [
                    'id' => $invoice->id,
                    'student_id' => $student ? $student->id : null,
                    'student_name' => $student ? $student->firstName.' '.$student->lastName : 'Unknown',
                    'payment_date' => $invoice->last_payment_date ? $invoice->last_payment_date->format('Y-m-d') : ($invoice->creationDate ? $invoice->creationDate->format('Y-m-d') : null),
                    'amount' => is_numeric($invoice->amountPaid) ? floatval($invoice->amountPaid) : 0,
                    'payment_method' => 'Cash',
                    'offer_name' => $offerName,
                ];
            });

            return Inertia::render('Menu/StudentPaymentsPage', [
                'assistant' => $assistant,
                'studentPayments' => $paymentsData,
                'paymentsLinks' => $payments->links(),
                'totalPayments' => $payments->total(),
                'selectedSchool' => $selectedSchoolId ? [
                    'id' => $selectedSchoolId,
                    'name' => session('school_name'),
                ] : null,
            ]);
        } catch (\Exception $e) {
            Log::error('Error in assistant payments: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'assistant_id' => $assistant->id,
            ]);

            return redirect()->back()->with('error', 'An error occurred while loading the payments.');
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Assistant $assistant)
    {
        $subjects = Subject::all();
        $classes = Classes::all();
        $schools = School::all();
        $assistantUser = User::where('email', $assistant->email)->first();

        return Inertia::render('Assistants/Edit', [
            'assistant' => $assistantUser ? array_merge($assistant->toArray(), ['user_id' => $assistantUser->id]) : $assistant,
            'subjects' => $subjects,
            'classes' => $classes,
            'schools' => $schools,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Assistant $assistant)
    {
        try {
            $currentSchoolId = session('school_id');
            $currentSchoolName = session('school_name');

            // Store the old email before updating
            $oldEmail = $assistant->email;

            // Validate input, but do not allow duplicate emails in users or assistants (except for this assistant/user)
            $validatedData = $request->validate([
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'email' => [
                    'required',
                    'string',
                    'email',
                    'max:255',
                    // Unique in assistants, except for this assistant
                    'unique:assistants,email,'.$assistant->id,
                ],
                'phone_number' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:255',
                'profile_image' => 'nullable|mimes:jpg,jpeg,png,webp,avif|max:5120',
                'salary' => 'required|numeric|min:0',
                'status' => 'required|in:active,inactive',
                'schools' => 'array',
                'schools.*' => 'exists:schools,id',
            ]);

            // Check for duplicate email in users table (except for the user with the old email)
            $userWithEmail = User::where('email', $validatedData['email'])
                ->where('email', '!=', $oldEmail)
                ->first();
            // withTrashed(): the DB unique index includes soft-deleted rows — renaming
            // onto a trashed email must fail here with a field error, not at the index.
            $assistantWithEmail = Assistant::withTrashed()
                ->where('email', $validatedData['email'])
                ->where('id', '!=', $assistant->id)
                ->first();
            if ($userWithEmail || $assistantWithEmail) {
                return redirect()->back()
                    ->withErrors(['email' => 'Cette adresse e-mail est dÃ©jÃ  utilisÃ©e par un autre utilisateur ou assistant.'])
                    ->withInput();
            }

            $newImagePath = null;
            $oldRawImage = $assistant->getRawOriginal('profile_image');
            if ($request->hasFile('profile_image')) {
                $newImagePath = $this->profileImages->store($request->file('profile_image'), 'assistants');

                // Optimistic concurrency: swap the reference only if it still holds the value
                // this request was rendered with. Two simultaneous replaces cannot both win;
                // the loser discards its freshly stored file instead of leaving an orphan leak.
                $swapped = Assistant::whereKey($assistant->getKey())
                    ->when($oldRawImage === null,
                        fn ($q) => $q->whereNull('profile_image'),
                        fn ($q) => $q->where('profile_image', $oldRawImage))
                    ->update(['profile_image' => $newImagePath]);

                if ($swapped === 0) {
                    $this->profileImages->discard($newImagePath);

                    return redirect()->back()
                        ->withErrors(['profile_image' => "L'image a Ã©tÃ© modifiÃ©e entre-temps. Rechargez la page et rÃ©essayez."])
                        ->withInput();
                }

                // Reference already atomically updated; keep it out of the bulk update below.
                unset($validatedData['profile_image']);
            }

            // Update the assistant record
            $assistant->update($validatedData);

            // Sync schools with the assistant
            $assistant->schools()->sync($request->schools ?? []);

            // Update the corresponding user (if exists)
            $user = User::where('email', $oldEmail)->first();
            if ($user) {
                $user->name = $validatedData['first_name'].' '.$validatedData['last_name'];
                $user->email = $validatedData['email'];
                $user->save();
            }

            if ($currentSchoolId) {
                session([
                    'school_id' => $currentSchoolId,
                    'school_name' => $currentSchoolName,
                ]);
            }

            if ($newImagePath !== null && $oldRawImage !== null) {
                $this->profileImages->delete($oldRawImage);
            }

            $isFormUpdate = $request->has('is_form_update');
            if ($isFormUpdate) {
                return redirect()->route('assistants.show', $assistant->id)->with('success', 'Assistant updated successfully.');
            }
            $isViewingAs = session()->has('admin_user_id');
            if ($isViewingAs) {
                return redirect()->route('dashboard')->with('success', 'Assistant updated successfully.');
            } else {
                return redirect()->route('assistants.show', $assistant->id)->with('success', 'Assistant updated successfully.');
            }
        } catch (ValidationException $e) {
            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput();
        } catch (\Exception $e) {
            // If this fires after the optimistic swap, roll the reference back to the
            // previous image and discard the fresh one â€” otherwise the old image orphans
            // and the new one strands behind an error message.
            if (($newImagePath ?? null) !== null) {
                Assistant::whereKey($assistant->getKey())->update(['profile_image' => $oldRawImage]);
                $this->profileImages->discard($newImagePath);
            }

            return redirect()->back()
                ->with('error', 'An error occurred while updating the assistant: '.$e->getMessage())
                ->withInput();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Assistant $assistant)
    {
        try {
            // Image file intentionally kept: this is a soft delete â€" the row keeps its
            // reference so a restore gets the image back. Permanent purge deletes the file.

            // Atomic: a soft-deleted staff row with a live login (or vice versa)
            // would leave self-service surfaces 404ing for that account.
            DB::transaction(function () use ($assistant) {
                $assistant->delete();

                // Deleting an assistant ends their login too: recreating with the same
                // email must not be blocked by a live users row. Role-guarded so an admin
                // sharing the address can never be caught here.
                User::where('email', $assistant->email)->where('role', 'assistant')->delete();
            });

            return redirect()->route('assistants.index')->with('success', 'Assistant deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'An error occurred while deleting the assistant: '.$e->getMessage());
        }
    }

    public function storeWithUser(Request $request)
    {
        DB::beginTransaction();
        // Hoisted BEFORE try: every catch below reads these, and a ValidationException
        // from the validate() calls fires long before the old in-try assignment ran —
        // that undefined-variable crash masked duplicate-email errors with a 500.
        $newImagePath = null;
        $pendingOldImageDelete = null;
        try {
            // Validate user data
            $userData = $request->validate([
                'user.name' => 'required|string|max:255',
                'user.email' => 'required|string|lowercase|email|max:255|unique:users,email',
                'user.password' => ['required', 'confirmed', Rules\Password::defaults()],
                'user.role' => 'required|in:admin,assistant,teacher',
            ]);

            // Validate assistant data. Email conflicts are checked against LIVE rows
            // only: an email held by a soft-deleted assistant re-hires that record
            // (same id, history preserved) instead of failing on its unique index.
            $assistantData = $request->validate([
                'assistant.first_name' => 'required|string|max:100',
                'assistant.last_name' => 'required|string|max:100',
                'assistant.phone_number' => 'required|string|max:20',
                'assistant.email' => [
                    'required', 'string', 'email', 'max:255',
                    function (string $attribute, mixed $value, \Closure $fail) {
                        if (Assistant::where('email', $value)->exists()) {
                            $fail('Cette adresse e-mail est déjà utilisée par un autre assistant.');
                        }
                        // Cross-table invariant kept by ValidateEmailUnique: a live
                        // teacher owning this email blocks the create/re-hire.
                        if (Teacher::where('email', $value)->exists()) {
                            $fail('Cette adresse e-mail est déjà utilisée par un enseignant.');
                        }
                    },
                ],
                'assistant.address' => 'required|string|max:255',
                'assistant.status' => 'required|in:active,inactive',
                'assistant.salary' => 'required|numeric|min:0',
                'assistant.profile_image' => 'nullable|mimes:jpg,jpeg,png,webp,avif|max:5120',
                'assistant.schools' => 'array',
                'assistant.schools.*' => 'exists:schools,id',
            ]);

            // Create user
            $user = User::create([
                'name' => $request->input('user.name'),
                'email' => $request->input('user.email'),
                'password' => Hash::make($request->input('user.password')),
                'role' => $request->input('user.role'),
            ]);

            // Handle assistant profile image
            $assistantFields = [
                'first_name' => $request->input('assistant.first_name'),
                'last_name' => $request->input('assistant.last_name'),
                'phone_number' => $request->input('assistant.phone_number'),
                'address' => $request->input('assistant.address'),
                'status' => $request->input('assistant.status'),
                'salary' => $request->input('assistant.salary'),
            ];

            // Re-hire: same email as a soft-deleted assistant revives THAT row —
            // same id keeps salary/wallet/payout history attached.
            $rehired = Assistant::onlyTrashed()
                ->where('email', $request->input('assistant.email'))
                ->first();

            if ($rehired) {
                // The identity join is by email: reviving an assistant whose staff
                // email differs from the new login would silently break that join.
                if ($request->input('user.email') !== $request->input('assistant.email')) {
                    throw ValidationException::withMessages([
                        'user.email' => 'L\'adresse e-mail du compte doit correspondre à celle de l\'assistant réintégré.',
                    ]);
                }

                if ($request->hasFile('assistant.profile_image')) {
                    $newImagePath = $this->profileImages->store($request->file('assistant.profile_image'), 'assistants', 'assistant.profile_image');

                    // Optimistic swap, mirroring update(): only succeed if the stored
                    // reference is what we read; loser discards its fresh file.
                    // withTrashed(): the row is still soft-deleted right now, and
                    // whereKey() alone would scope it out and always report 0 rows.
                    $oldRawImage = $rehired->getRawOriginal('profile_image');
                    $swapped = Assistant::withTrashed()
                        ->whereKey($rehired->getKey())
                        ->when($oldRawImage === null,
                            fn ($q) => $q->whereNull('profile_image'),
                            fn ($q) => $q->where('profile_image', $oldRawImage))
                        ->update(['profile_image' => $newImagePath]);

                    if ($swapped === 0) {
                        $this->profileImages->discard($newImagePath);
                        $newImagePath = null;

                        throw ValidationException::withMessages([
                            'assistant.profile_image' => "L'image a été modifiée entre-temps. Rechargez la page et réessayez.",
                        ]);
                    }

                    // Old file leaves disk only after the transaction commits (spec Phase 9).
                    $pendingOldImageDelete = ProfileImageUrl::isLogicalPath($oldRawImage) ? $oldRawImage : null;
                }

                $rehired->fill($assistantFields)->restore();
                $assistant = $rehired;
            } else {
                if ($request->hasFile('assistant.profile_image')) {
                    // May throw ValidationException â€" forwarded to the form by the dedicated catch below.
                    $newImagePath = $this->profileImages->store($request->file('assistant.profile_image'), 'assistants', 'assistant.profile_image');
                    $assistantFields['profile_image'] = $newImagePath;
                }

                $assistantFields['email'] = $request->input('assistant.email');
                $assistant = Assistant::create($assistantFields);
            }

            // Sync schools
            $assistant->schools()->sync($request->input('assistant.schools', []));
            DB::commit();

            if ($pendingOldImageDelete !== null) {
                // Post-commit cleanup: a disk hiccup here must NOT hit the generic
                // catch below, which would discard the NEW committed file and fake
                // a failure flash over a successful save.
                try {
                    $this->profileImages->delete($pendingOldImageDelete);
                } catch (\Throwable $cleanupError) {
                    Log::warning('Old profile-image cleanup failed after commit', [
                        'path' => $pendingOldImageDelete,
                        'error' => $cleanupError->getMessage(),
                    ]);
                }
            }

            // Always redirect to assistants.index for Inertia
            return redirect()->route('assistants.index')->with('success', 'User and Assistant created successfully.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            DB::rollBack();
            if (($newImagePath ?? null) !== null) {
                $this->profileImages->discard($newImagePath);
            }
            // Rethrow: Laravel's handler renders the correct response for the caller
            // — a 303 + session errors for Inertia, JSON for true APIs. A manual
            // response()->json() here breaks Inertia ("plain JSON response").
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            if (($newImagePath ?? null) !== null) {
                $this->profileImages->discard($newImagePath);
            }
            if ($request->expectsJson() || $request->isXmlHttpRequest()) {
                return response()->json(['error' => 'Failed to create user and assistant.'], 500);
            } else {
                return redirect()->back()->with('error', 'Failed to create user and assistant.');
            }
        }
    }
}
