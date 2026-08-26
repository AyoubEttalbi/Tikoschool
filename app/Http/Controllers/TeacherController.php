<?php

namespace App\Http\Controllers;

use App\Events\CheckEmailUnique;
use App\Models\Assistant;
use App\Models\Classes;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\School;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherWalletEntry;
use App\Models\User;
use App\Services\ProfileImageService;
use App\Support\ProfileImageUrl;
use App\Support\SchoolScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class TeacherController extends Controller
{
    public function __construct(private ProfileImageService $profileImages) {}

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $selectedSchoolId = session('school_id');

        // Initialize the query with eager loading for relationships
        $query = Teacher::with(['subjects', 'classes', 'schools']);

        // Hard scope first. `session('school_id')` below is a UI preference the caller picks
        // on /select-profile â€” it narrows the view, it does not authorize it. Assistants and
        // teachers see only the schools they are actually assigned to; admins are unrestricted.
        $allowedSchoolIds = SchoolScope::schoolIdsFor();
        if ($allowedSchoolIds !== null) {
            $query->whereHas('schools', function ($schoolQuery) use ($allowedSchoolIds) {
                $schoolQuery->whereIn('schools.id', $allowedSchoolIds);
            });
        }

        // Filter by selected school if one is in session
        if ($selectedSchoolId) {
            $query->whereHas('schools', function ($schoolQuery) use ($selectedSchoolId) {
                $schoolQuery->where('schools.id', $selectedSchoolId);
            });
        }

        // Apply search filter if search term is provided
        if ($request->has('search') && ! empty($request->search)) {
            $this->applySearchFilter($query, $request->search);
        }

        // Apply additional filters (subject, class, school, status)
        // Note: The individual school filter might become redundant if session school is always applied,
        // but keep it for explicit filtering capabilities.
        $this->applyFilters($query, $request->only(['subject', 'class', 'school', 'status']));

        // Fetch paginated and filtered teachers, newest first
        $teachers = $query->orderBy('created_at', 'desc')->paginate(10)->withQueryString()->through(function ($teacher) {
            return $this->transformTeacherData($teacher);
        });

        // Filter dropdowns, scoped the same way as the rows. Offering an assistant the names
        // of schools they cannot see is both a leak and a dead option in the UI.
        $schoolsForFilter = $allowedSchoolIds === null
            ? School::all()
            : School::whereIn('id', $allowedSchoolIds)->get();
        $subjects = Subject::all();
        $classes = $allowedSchoolIds === null
            ? Classes::all()
            : Classes::whereIn('school_id', $allowedSchoolIds)->get();

        return Inertia::render('Menu/TeacherListPage', [
            'teachers' => $teachers,
            'schools' => $schoolsForFilter, // Pass schools for the filter dropdown
            'subjects' => $subjects,
            'classes' => $classes,
            'search' => $request->search,
            'filters' => $request->only(['subject', 'class', 'school', 'status']), // Pass current filters
            // activeSchool is already shared via HandleInertiaRequests
        ]);
    }

    /**
     * Apply search filter to the query.
     */
    protected function applySearchFilter($query, $searchTerm)
    {
        $query->where(function ($q) use ($searchTerm) {
            // Search by teacher fields
            $q->where('first_name', 'LIKE', "%{$searchTerm}%")
                ->orWhere('last_name', 'LIKE', "%{$searchTerm}%")
                ->orWhere('phone_number', 'LIKE', "%{$searchTerm}%")
                ->orWhere('email', 'LIKE', "%{$searchTerm}%")
                ->orWhere('address', 'LIKE', "%{$searchTerm}%")
              // Search by full name (first_name + last_name combined)
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", ["%{$searchTerm}%"])
              // Search by full name in reverse order (last_name + first_name)
                ->orWhereRaw("CONCAT(last_name, ' ', first_name) LIKE ?", ["%{$searchTerm}%"]);

            // Search by related models through pivot tables
            $this->applyRelationshipSearch($q, $searchTerm);
        });
    }

    /**
     * Apply search filter to relationships (subjects, classes, schools).
     */
    protected function applyRelationshipSearch($query, $searchTerm)
    {
        $query->orWhereHas('subjects', function ($subjectQuery) use ($searchTerm) {
            $subjectQuery->where('name', 'LIKE', "%{$searchTerm}%");
        })
            ->orWhereHas('classes', function ($classQuery) use ($searchTerm) {
                $classQuery->where('name', 'LIKE', "%{$searchTerm}%");
            })
            ->orWhereHas('schools', function ($schoolQuery) use ($searchTerm) {
                $schoolQuery->where('name', 'LIKE', "%{$searchTerm}%");
            });
    }

    /**
     * Apply additional filters (subject, class, school, status).
     */
    protected function applyFilters($query, $filters)
    {
        if (! empty($filters['subject'])) {
            $query->whereHas('subjects', function ($subjectQuery) use ($filters) {
                $subjectQuery->where('subjects.id', $filters['subject']);
            });
        }

        if (! empty($filters['class'])) {
            $query->whereHas('classes', function ($classQuery) use ($filters) {
                $classQuery->where('classes.id', $filters['class']);
            });
        }

        if (! empty($filters['school'])) {
            $query->whereHas('schools', function ($schoolQuery) use ($filters) {
                $schoolQuery->where('schools.id', $filters['school']);
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
    }

    /**
     * Transform teacher data for the frontend.
     */
    protected function transformTeacherData($teacher)
    {
        // Wallet balances are payroll data: admins manage them, so the
        // directory payload carries them only for admin callers. A teacher or
        // assistant hitting /teachers directly must not receive every
        // colleague's balance just because the menu hides the link. Own-wallet
        // reads go through /my-payments and /dashboard, which resolve identity
        // server-side.
        $includeWallet = Auth::user()?->role === 'admin';

        $data = [
            'id' => $teacher->id,
            'name' => $teacher->first_name.' '.$teacher->last_name,
            'phone_number' => $teacher->phone_number,
            'first_name' => $teacher->first_name,
            'last_name' => $teacher->last_name,
            'phone' => $teacher->phone_number,
            'email' => $teacher->email,
            'address' => $teacher->address,
            'status' => $teacher->status,
            'profile_image' => $teacher->profile_image ?? null,
            'subjects' => $teacher->subjects,
            'classes' => $teacher->classes,
            'schools' => $teacher->schools,
        ];

        if ($includeWallet) {
            $data['wallet'] = $teacher->wallet;
        }

        return $data;
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $subjects = Subject::all();
        $classes = Classes::all();

        return Inertia::render('Teachers/Create', [
            'subjects' => $subjects,
            'classes' => $classes,
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
                'address' => 'nullable|string|max:255',
                'phone_number' => 'nullable|string|max:20',
                // Live rows only: a soft-deleted teacher with this email is re-hired below.
                'email' => [
                    'required', 'string', 'email', 'max:255',
                    function (string $attribute, mixed $value, \Closure $fail) {
                        if (Teacher::where('email', $value)->exists()) {
                            $fail('Cette adresse e-mail est déjà utilisée par un autre enseignant.');
                        }
                        // Cross-table invariant kept by ValidateEmailUnique: a live
                        // assistant owning this email blocks the create/re-hire.
                        if (Assistant::where('email', $value)->exists()) {
                            $fail('Cette adresse e-mail est déjà utilisée par un assistant.');
                        }
                    },
                ],
                'status' => 'required|in:active,inactive',
                'profile_image' => 'nullable|mimes:jpg,jpeg,png,webp,avif|max:5120',
                'schools' => 'array',
                'schools.*' => 'exists:schools,id',
                'subjects' => 'array',
                'subjects.*' => 'exists:subjects,id',
                'classes' => 'array',
                'classes.*' => 'exists:classes,id',
            ]);

            // A new teacher always starts at zero. The wallet is ledger-derived
            // (see TeacherWalletService); an opening balance typed into a create form would
            // be money with no corresponding entry, which is exactly the drift the ledger
            // exists to prevent.
            //
            // Zero now comes from the column default rather than from here: `wallet` is no
            // longer in Teacher::$fillable, so passing it to create() would be silently
            // dropped. Unsetting says that out loud instead of leaving a line that looks
            // like it does something.
            unset($validatedData['wallet']);

            $newImagePath = null;
            $pendingOldImageDelete = null;
            if ($request->hasFile('profile_image')) {
                // May throw ValidationException — rethrown below so the form renders the field error.
                $newImagePath = $this->profileImages->store($request->file('profile_image'), 'teachers');
                $validatedData['profile_image'] = $newImagePath;
            }

            event(new CheckEmailUnique($request->email));

            // Re-hire: revive the soft-deleted row (same id, wallet/payout history
            // intact) instead of colliding with its unique email index.
            $rehired = Teacher::onlyTrashed()->where('email', $validatedData['email'])->first();

            if ($rehired) {
                // A re-hire WITH a photo replaces the old reference: queue the old
                // file for deletion only once the row is safely restored and synced
                // — otherwise every re-hire-with-photo leaks an orphan that the
                // nightly integrity check can report but never clean.
                $oldRawImage = $rehired->getRawOriginal('profile_image');
                unset($validatedData['email']);
                $rehired->fill($validatedData)->restore();
                if ($newImagePath !== null && $oldRawImage !== null
                    && ProfileImageUrl::isLogicalPath($oldRawImage)) {
                    $pendingOldImageDelete = $oldRawImage;
                }
                $teacher = $rehired;
            } else {
                // Create the teacher record
                $teacher = Teacher::create($validatedData);
            }

            // Sync relationships
            $teacher->subjects()->sync($request->subjects ?? []);
            $teacher->classes()->sync($request->classes ?? []);
            $teacher->schools()->sync($request->schools ?? []);

            if ($pendingOldImageDelete !== null) {
                $this->profileImages->delete($pendingOldImageDelete);
            }

            return redirect()->route('teachers.index')->with('success', 'Teacher created successfully.');
        } catch (ValidationException $e) {
            // CheckEmailUnique fires AFTER the WebP is stored; a duplicate email must
            // not leave that fresh file orphaned on disk.
            if (($newImagePath ?? null) !== null) {
                $this->profileImages->discard($newImagePath);
            }

            throw $e;
        } catch (\Exception $e) {
            // The WebP was already written to disk before Teacher::create(); if the row
            // never landed, discard the file instead of leaking an orphan.
            if (($newImagePath ?? null) !== null) {
                // A re-hire that crashed mid-flight already overwrote profile_image
                // with the new path — point the row back at its still-existing old
                // file so neither image orphans behind a failed request.
                if (($pendingOldImageDelete ?? null) !== null) {
                    Teacher::withTrashed()->whereKey($rehired->getKey())
                        ->update(['profile_image' => $pendingOldImageDelete]);
                }
                $this->profileImages->discard($newImagePath);
            }

            Log::error('Error creating teacher: '.$e->getMessage());

            return redirect()->back()->with('error', 'Failed to create teacher. Please try again.');
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Teacher $teacher)
    {
        // NOTE: a debug Log::info here serialized Invoice::all() on every profile view,
        // writing every student's financial data to storage/logs. Do not reintroduce.
        try {
            // Eager load teacher relationships
            $teacher->load(['subjects', 'classes', 'schools']);

            // Get filter parameters from request
            $filters = [
                'search' => $request->get('search', ''),
                'class_filter' => $request->get('class_filter', 'all'),
                'offer_filter' => $request->get('offer_filter', 'all'),
                'school_filter' => $request->get('school_filter', 'all'),
                // Default to the current month, matching what the invoice table shows in its
                // month picker on first paint. It used to default to '' (= every month), so
                // the page rendered every invoice, the table then noticed its own default
                // disagreed and issued a SECOND request for the same page with
                // ?date_filter=YYYY-MM. Two round trips and a visible flash of the wrong rows.
                'date_filter' => $request->get('date_filter', now()->format('Y-m')),
                'membership_status_filter' => $request->get('membership_status_filter', 'all'),
                'payment_status_filter' => $request->get('payment_status_filter', 'all'),
                'page' => $request->get('page', 1),
            ];

            // Announcements used to be computed here and shipped to the profile
            // page, which never rendered them (dead right-rail). The cockpit now
            // owns announcements; this page pays only for what it shows.

            // Validate teacher email before proceeding
            if (empty($teacher->email) || ! filter_var($teacher->email, FILTER_VALIDATE_EMAIL)) {
                Log::error('Teacher has invalid or missing email', [
                    'teacher_id' => $teacher->id,
                    'teacher_email' => $teacher->email,
                ]);

                // Return error response instead of crashing
                return response()->json([
                    'error' => 'Teacher has invalid or missing email address',
                    'teacher_id' => $teacher->id,
                ], 400);
            }

            $teacherUser = User::where('email', $teacher->email)->first();

            // If teacher doesn't have a user account, create one (fallback mechanism)
            if (! $teacherUser && $teacher->email) {
                try {
                    DB::beginTransaction();

                    // Check if email is already taken by another user
                    $existingUser = User::where('email', $teacher->email)->first();
                    if ($existingUser) {
                        Log::warning('Email already exists in users table but not linked to teacher', [
                            'teacher_id' => $teacher->id,
                            'teacher_email' => $teacher->email,
                            'existing_user_id' => $existingUser->id,
                        ]);
                        $teacherUser = $existingUser;
                    } else {
                        // Check if email is valid and not empty
                        if (empty($teacher->email) || ! filter_var($teacher->email, FILTER_VALIDATE_EMAIL)) {
                            throw new \Exception('Invalid email address: '.$teacher->email);
                        }

                        $teacherUser = User::create([
                            'name' => $teacher->first_name.' '.$teacher->last_name,
                            'email' => $teacher->email,
                            'password' => bcrypt('temp_password_'.time()), // Temporary password
                            'role' => 'teacher',
                        ]);

                        Log::info('Created missing user account for teacher', [
                            'teacher_id' => $teacher->id,
                            'teacher_email' => $teacher->email,
                            'new_user_id' => $teacherUser->id,
                        ]);
                    }

                    DB::commit();
                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error('Failed to create user account for teacher', [
                        'teacher_id' => $teacher->id,
                        'teacher_email' => $teacher->email,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    // Continue without user account - the system will handle this gracefully
                }
            }

            // Final safety check - if we still don't have a teacherUser, create a minimal one
            if (! $teacherUser) {
                Log::warning('Teacher has no user account and creation failed, using fallback data', [
                    'teacher_id' => $teacher->id,
                    'teacher_email' => $teacher->email,
                ]);

                // Create a minimal user object for the frontend
                $teacherUser = (object) [
                    'id' => 'temp_'.$teacher->id,
                    'email' => $teacher->email,
                    'name' => $teacher->first_name.' '.$teacher->last_name,
                    'role' => 'teacher',
                ];
            }

            // Log teacher data for debugging
            Log::info('Teacher data fetched', [
                'teacher_id' => $teacher->id,
                'teacher_email' => $teacher->email,
                'teacher_exists' => $teacher ? true : false,
                'user_found' => $teacherUser ? true : false,
                'user_id' => $teacherUser ? $teacherUser->id : null,
            ]);

            if (! $teacher) {
                abort(404);
            }

            // Calculate total students for this teacher (including deleted memberships)
            $totalStudents = Membership::withTrashed()
                ->whereJsonContains('teachers', [['teacherId' => (string) $teacher->id]])
                ->distinct('student_id')
                ->count('student_id');

            // The per-month earnings rows are built by the shared pipeline — the
            // SAME math feeds « Mes gains » on the payroll page. Filters, stats
            // and pagination stay local below.
            $invoices = \App\Support\TeacherEarnings::monthlyRows($teacher);

            // Apply filters to invoices
            $invoices = $invoices->filter(function ($invoice) use ($filters) {
                // Search filter
                if (! empty($filters['search'])) {
                    $studentName = $invoice['student_name'] ?? '';
                    if (stripos($studentName, $filters['search']) === false) {
                        return false;
                    }
                }

                // Class filter
                if ($filters['class_filter'] !== 'all') {
                    if (($invoice['student_class'] ?? '') !== $filters['class_filter']) {
                        return false;
                    }
                }

                // Offer filter
                if ($filters['offer_filter'] !== 'all') {
                    if (($invoice['offer_name'] ?? '') !== $filters['offer_filter']) {
                        return false;
                    }
                }

                // School filter
                if ($filters['school_filter'] !== 'all') {
                    if (($invoice['student_school'] ?? '') !== $filters['school_filter']) {
                        return false;
                    }
                }

                // Date filter
                if (! empty($filters['date_filter'])) {
                    $invoiceMonths = $invoice['selected_months'] ?? [];
                    if (empty($invoiceMonths)) {
                        // Fallback: if no selected_months, use the billDate month
                        $invoiceMonths = [$invoice['billDate'] ? date('Y-m', strtotime($invoice['billDate'])) : null];
                    }

                    // Check if any of the invoice months match the filter
                    $hasMatchingMonth = false;
                    foreach ($invoiceMonths as $month) {
                        if ($month && strpos($month, $filters['date_filter']) === 0) {
                            $hasMatchingMonth = true;
                            break;
                        }
                    }

                    if (! $hasMatchingMonth) {
                        // Debug: Log filtered out invoices (only for first few to avoid spam)
                        if (($invoice['invoice_id'] ?? 0) <= 10) {
                            Log::info('Invoice filtered out by date', [
                                'invoice_id' => $invoice['id'] ?? 'unknown',
                                'student_id' => $invoice['student_id'] ?? 'unknown',
                                'selected_months' => $invoice['selected_months'] ?? 'empty',
                                'processed_months' => $invoiceMonths,
                                'date_filter' => $filters['date_filter'],
                                'billDate' => $invoice['billDate'] ?? 'unknown',
                            ]);
                        }

                        return false;
                    }
                }

                // Membership status filter
                if ($filters['membership_status_filter'] !== 'all') {
                    $isDeleted = $invoice['membership_deleted'] ?? false;
                    if ($filters['membership_status_filter'] === 'active' && $isDeleted) {
                        return false;
                    }
                    if ($filters['membership_status_filter'] === 'deleted' && ! $isDeleted) {
                        return false;
                    }
                }

                // Payment status filter
                if ($filters['payment_status_filter'] !== 'all') {
                    $isPaid = $invoice['is_month_paid'] ?? false;
                    if ($filters['payment_status_filter'] === 'paid' && ! $isPaid) {
                        return false;
                    }
                    if ($filters['payment_status_filter'] === 'pending' && $isPaid) {
                        return false;
                    }
                }

                return true;
            });

            // Sort invoices by creation date (newest first) - this ensures the most recently created invoices appear first
            $invoices = $invoices->sortByDesc('created_at')->values();

            // Calculate stats from ALL filtered invoices (before pagination)
            $stats = [
                'total_invoices' => $invoices->count(),
                'total_amount' => $invoices->sum('teacher_amount'),
                'unique_students' => $invoices->pluck('student_id')->unique()->count(),
                'best_offer' => $this->calculateBestOffer($invoices),
                'current_month_amount' => $this->calculateCurrentMonthAmount($invoices),
                // Distinct memberships carrying this teacher that were since
                // withdrawn — the rows flag it, so count them for real (this
                // used to be a hardcoded 0 shown as-is in the UI).
                'deleted_memberships' => $invoices->where('membership_deleted', true)
                    ->pluck('membership_id')->filter()->unique()->count(),
                'pending_months' => $this->calculatePendingMonths($invoices),
                'active_memberships' => $invoices->pluck('membership_id')->unique()->count(),
            ];

            // Debug: Log the stats for troubleshooting
            Log::info('Teacher stats calculated', [
                'teacher_id' => $teacher->id,
                'date_filter' => $filters['date_filter'] ?? 'none',
                'total_invoices' => $stats['total_invoices'],
                'unique_students' => $stats['unique_students'],
                'invoices_count_before_filter' => $invoices->count(),
                'sample_student_ids' => $invoices->pluck('student_id')->unique()->take(5)->toArray(),
                'sample_invoice_ids' => $invoices->pluck('id')->take(5)->toArray(),
                'all_student_ids_count' => $invoices->pluck('student_id')->count(),
                'unique_student_ids_count' => $invoices->pluck('student_id')->unique()->count(),
                'duplicate_students' => $invoices->pluck('student_id')->count() - $invoices->pluck('student_id')->unique()->count(),
            ]);

            // Paginate the invoices
            $perPage = 10; // Number of invoices per page
            $currentPage = (int) $filters['page']; // Use filter page parameter and ensure it's an integer
            $paginatedInvoices = new \Illuminate\Pagination\LengthAwarePaginator(
                $invoices->forPage($currentPage, $perPage),
                $invoices->count(),
                $perPage,
                $currentPage,
                ['path' => request()->url(), 'query' => request()->query()]
            );

            // Fetch other necessary data
            $schools = School::all();
            $classes = Classes::all();
            $subjects = Subject::all();

            // Get unique filter options from all invoices (not just filtered ones).
            // Independent membership load: the dropdown universe must not shrink
            // when a filter is active.
            $memberships = Membership::withTrashed()
                ->whereJsonContains('teachers', [['teacherId' => (string) $teacher->id]])
                ->with(['invoices' => function ($query) {
                    $query->whereNull('deleted_at');
                }, 'student', 'student.class', 'offer'])
                ->get();

            $allInvoices = $memberships->flatMap(function ($membership) use ($teacher) {
                if (! $membership->student) {
                    return [];
                }

                return $membership->invoices->flatMap(function ($invoice) use ($membership, $teacher) {
                    $teacherData = collect($membership->teachers)->first(function ($item) use ($teacher) {
                        return isset($item['teacherId']) && $item['teacherId'] == (string) $teacher->id;
                    });

                    if (! $teacherData) {
                        return [];
                    }

                    // Use subject from teacher data or fallback to teacher's first subject
                    $subject = $teacherData['subject'] ?? ($teacher->subjects->first()->name ?? 'Unknown');

                    $selectedMonths = $invoice->selected_months ?? [];
                    if (is_string($selectedMonths)) {
                        $selectedMonths = json_decode($selectedMonths, true) ?? [];
                    }
                    if (empty($selectedMonths)) {
                        $selectedMonths = [$invoice->billDate ? $invoice->billDate->format('Y-m') : null];
                    }

                    $schoolName = 'Unknown';
                    if ($membership->student->school) {
                        $schoolName = $membership->student->school->name;
                    } else {
                        $school = School::find($membership->student->schoolId);
                        if ($school) {
                            $schoolName = $school->name;
                        }
                    }

                    $className = $membership->student->class ? $membership->student->class->name : 'Unknown';
                    $offerName = $invoice->offer ? $invoice->offer->offer_name : null;

                    return collect($selectedMonths)->map(function ($month) use ($className, $schoolName, $offerName) {
                        return [
                            'student_class' => $className,
                            'student_school' => $schoolName,
                            'offer_name' => $offerName,
                        ];
                    });
                });
            });

            // Extract unique values for filter dropdowns
            $filterOptions = [
                'classes' => $allInvoices->pluck('student_class')->unique()->filter()->values()->toArray(),
                'offers' => $allInvoices->pluck('offer_name')->unique()->filter()->values()->toArray(),
                'schools' => $allInvoices->pluck('student_school')->unique()->filter()->values()->toArray(),
            ];

            // Get recurring transactions for this teacher
            $recurringTransactions = collect();
            if ($teacherUser) {
                $recurringTransactions = \App\Models\Transaction::where('is_recurring', 1)
                    ->where('user_id', $teacherUser->id)
                    ->get();
            }

            // Log the relationship for debugging
            Log::info('Teacher-User relationship', [
                'teacher_id' => $teacher->id,
                'teacher_email' => $teacher->email,
                'user_found' => $teacherUser ? true : false,
                'user_id' => $teacherUser ? $teacherUser->id : null,
                'user_email' => $teacherUser ? $teacherUser->email : null,
            ]);

            // Check if any recurring transactions have been paid this month
            $currentMonth = now()->format('Y-m');
            $startDate = \Carbon\Carbon::parse($currentMonth.'-01')->startOfMonth();
            $endDate = \Carbon\Carbon::parse($currentMonth.'-01')->endOfMonth();

            foreach ($recurringTransactions as $transaction) {
                // Check if a corresponding one-time transaction exists for this month
                $isPaidThisMonth = \App\Models\Transaction::where('is_recurring', 0)
                    ->where('description', 'like', '%(Recurring payment from #'.$transaction->id.')%')
                    ->whereBetween('payment_date', [$startDate, $endDate])
                    ->exists();

                $transaction->paid_this_month = $isPaidThisMonth;
            }

            // Get all transactions (recurring and one-time) for this teacher
            $transactions = collect();
            if ($teacherUser) {
                $transactions = \App\Models\Transaction::where('user_id', $teacherUser->id)->get();
            }

            // Log transaction fetching results
            Log::info('Teacher transactions fetched', [
                'user_id' => $teacherUser ? $teacherUser->id : null,
                'transactions_count' => $transactions->count(),
                'transaction_user_ids' => $transactions->pluck('user_id')->unique()->toArray(),
            ]);

            // Mark recurring transactions as paid_this_month if a corresponding one-time payment exists
            $currentMonth = now()->format('Y-m');
            $startDate = \Carbon\Carbon::parse($currentMonth.'-01')->startOfMonth();
            $endDate = \Carbon\Carbon::parse($currentMonth.'-01')->endOfMonth();

            foreach ($transactions as $transaction) {
                if ($transaction->is_recurring) {
                    $isPaidThisMonth = \App\Models\Transaction::where('is_recurring', 0)
                        ->where('description', 'like', '%(Recurring payment from #'.$transaction->id.')%')
                        ->whereBetween('payment_date', [$startDate, $endDate])
                        ->exists();
                    $transaction->paid_this_month = $isPaidThisMonth;
                }
            }

            // Get the currently selected school from session
            $selectedSchool = null;
            $selectedSchoolId = session('school_id');
            $selectedSchoolName = session('school_name');

            if ($selectedSchoolId && $selectedSchoolName) {
                $selectedSchool = [
                    'id' => $selectedSchoolId,
                    'name' => $selectedSchoolName,
                ];
            }

            return Inertia::render('Menu/SingleTeacherPage', [
                'teacher' => $teacherUser ? array_merge($teacher->toArray(), ['user_id' => $teacherUser->id, 'totalStudents' => $totalStudents]) : array_merge($teacher->toArray(), ['totalStudents' => $totalStudents]),
                'invoices' => $paginatedInvoices,
                'invoiceStats' => $stats, // Add calculated stats
                'schools' => $schools,
                'subjects' => $subjects,
                'classes' => $classes,
                'filters' => [
                    'invoice_filters' => $filters, // Add invoice filters
                ],
                'filterOptions' => $filterOptions, // Add filter options for dropdowns
                'userRole' => Auth::user()?->role,
                'selectedSchool' => $selectedSchool, // Add the selected school
                'recurringTransactions' => $recurringTransactions, // Add the recurring transactions
                'transactions' => $transactions, // Add all transactions
            ]);
        } catch (\Exception $e) {
            Log::error('Error in TeacherController@show: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->back()->with('error', "Impossible de charger la fiche de l'enseignant. Veuillez réessayer.");
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Teacher $teacher)
    {
        $subjects = Subject::all();
        $classes = Classes::all(); // âœ… Changed from 'groups' to 'classes'
        $schools = School::all();
        $teacherUser = User::where('email', $teacher->email)->first();

        return Inertia::render('Teachers/Edit', [
            'teacher' => $teacherUser ? array_merge($teacher->toArray(), ['user_id' => $teacherUser->id]) : $teacher,
            'subjects' => $subjects,
            'classes' => $classes, // âœ… Changed from 'groups' to 'classes'
            'schools' => $schools,
        ]);
    }

    public function update(Request $request, Teacher $teacher)
    {
        try {
            $currentSchoolId = session('school_id');
            $currentSchoolName = session('school_name');

            // Store the old email before updating
            $oldEmail = $teacher->email;

            // Validate input, but do not allow duplicate emails in users or teachers (except for this teacher/user)
            $validatedData = $request->validate([
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'address' => 'nullable|string|max:255',
                'phone_number' => 'nullable|string|max:20',
                'email' => [
                    'required',
                    'string',
                    'email',
                    'max:255',
                    // Unique in teachers, except for this teacher
                    'unique:teachers,email,'.$teacher->id,
                ],
                'status' => 'required|in:active,inactive',
                'profile_image' => 'nullable|mimes:jpg,jpeg,png,webp,avif|max:5120',
                'subjects' => 'array',
                'subjects.*' => 'exists:subjects,id',
                'classes' => 'array',
                'classes.*' => 'exists:classes,id',
                'schools' => 'array',
                'schools.*' => 'exists:schools,id',
            ]);

            // `wallet` is deliberately NOT accepted here.
            //
            // It is a ledger-derived balance (see TeacherWalletService). The edit form
            // round-tripped whatever value it loaded, so if a student paid an invoice
            // between the form being opened and submitted, saving an unrelated field
            // (a phone number, a school assignment) silently reverted the teacher's
            // earnings â€” a classic lost update.
            //
            // To CHANGE a balance deliberately, use adjustWallet() below. It is a separate,
            // admin-only action that records the movement in the ledger with a reason, which
            // is exactly what saving a phone number must never be able to do by accident.
            unset($validatedData['wallet']);

            // Check for duplicate email in users table (except for the user with the old email)
            $userWithEmail = User::where('email', $validatedData['email'])
                ->where('email', '!=', $oldEmail)
                ->first();
            // withTrashed(): the DB unique index includes soft-deleted rows, so renaming
            // onto a trashed teacher's email must fail HERE with a field error, not at
            // the index with a generic QueryException.
            $teacherWithEmail = Teacher::withTrashed()
                ->where('email', $validatedData['email'])
                ->where('id', '!=', $teacher->id)
                ->first();
            if ($userWithEmail || $teacherWithEmail) {
                return redirect()->back()
                    ->withErrors(['email' => 'Cette adresse e-mail est déjà utilisée par un autre utilisateur ou enseignant.'])
                    ->withInput();
            }

            // Cross-table half, mirroring AssistantController::update: a LIVE
            // assistant owning this email blocks the rename, through the one
            // authority (ValidateEmailUnique). Gated on an ACTUAL change so
            // routine edits of photo/phone can never trip it.
            if (strcasecmp($validatedData['email'], $oldEmail) !== 0) {
                event(new CheckEmailUnique($validatedData['email'], $teacher->id));
            }

            $newImagePath = null;
            $oldRawImage = $teacher->getRawOriginal('profile_image');
            if ($request->hasFile('profile_image')) {
                $newImagePath = $this->profileImages->store($request->file('profile_image'), 'teachers');

                // Optimistic concurrency: swap the reference only if it still holds the value
                // this request was rendered with. Two simultaneous replaces cannot both win;
                // the loser discards its freshly stored file instead of leaving an orphan leak.
                $swapped = Teacher::whereKey($teacher->getKey())
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

            event(new CheckEmailUnique($request->email, $teacher->id));

            // Update teacher attributes
            $teacher->update($validatedData);

            // Sync relationships
            $teacher->subjects()->sync($request->subjects ?? []);
            $teacher->classes()->sync($request->classes ?? []);
            $teacher->schools()->sync($request->schools ?? []);

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
                return redirect()->route('teachers.show', $teacher->id)->with('success', 'Enseignant mis à jour avec succès.');
            }
            $isViewingAs = session()->has('admin_user_id');
            if ($isViewingAs) {
                return redirect()->route('dashboard')->with('success', 'Enseignant mis à jour avec succès.');
            } else {
                return redirect()->route('teachers.show', $teacher->id)->with('success', 'Enseignant mis à jour avec succès.');
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            // If this fires after the optimistic swap, roll the reference back to the
            // previous image and discard the fresh one â€” otherwise the old image orphans
            // and the new one strands behind an error message.
            if (($newImagePath ?? null) !== null) {
                Teacher::whereKey($teacher->getKey())->update(['profile_image' => $oldRawImage]);
                $this->profileImages->discard($newImagePath);
            }

            Log::error('Error updating teacher: '.$e->getMessage());

            return redirect()->back()->with('error', 'Failed to update teacher. Please try again.');
        }
    }

    /**
     * Set a teacher's wallet balance to a new figure, recording the difference.
     *
     * The replacement for editing `wallet` on the teacher form. That field was removed
     * because saving ANY unrelated field re-submitted a stale balance and wiped out earnings
     * credited in the meantime. The answer is not to allow the blind write again, but to
     * make changing a balance its own deliberate action:
     *
     *   - the delta is computed from the balance read UNDER THE ROW LOCK, not from whatever
     *     the browser had on screen, so a payment landing mid-edit is preserved rather than
     *     overwritten;
     *   - the movement is written to teacher_wallet_entries with a mandatory reason and the
     *     user who made it, so `wallet:check` still reconciles and `payouts:audit` can see
     *     where a hand-adjustment came from;
     *   - admin only, because it moves money.
     */
    public function adjustWallet(Request $request, Teacher $teacher)
    {
        SchoolScope::authorizeRole(['admin']);

        $validated = $request->validate([
            'new_balance' => 'required|numeric|min:0|max:9999999.99',
            'note' => 'required|string|min:3|max:255',
        ], [
            'new_balance.required' => 'Le nouveau solde est obligatoire.',
            'new_balance.min' => 'Le solde ne peut pas être négatif.',
            'note.required' => 'Indiquez la raison de cet ajustement.',
            'note.min' => 'La raison doit être un peu plus explicite.',
        ]);

        $target = round((float) $validated['new_balance'], 2);
        $applied = 0.0;
        $before = 0.0;

        DB::transaction(function () use ($teacher, $target, $validated, &$applied, &$before) {
            $locked = Teacher::whereKey($teacher->id)->lockForUpdate()->firstOrFail();
            $before = round((float) $locked->wallet, 2);
            $delta = round($target - $before, 2);

            if ($delta === 0.0) {
                return;
            }

            $wallet = new \App\Services\TeacherWalletService;
            $note = 'ajustement manuel : '.$validated['note'];

            // No invoice id and no month, so this is deliberately EXEMPT from the ledger's
            // idempotency key â€” two genuine adjustments of the same size on the same day are
            // both real and must both be recorded. @see CLAUDE.md on NULL semantics.
            $delta > 0
                ? $wallet->credit($locked, $delta, TeacherWalletEntry::REASON_ADJUSTMENT, null, null, null, $note)
                : $wallet->debit($locked, abs($delta), TeacherWalletEntry::REASON_ADJUSTMENT, null, null, null, $note);

            $applied = round((float) $locked->fresh()->wallet, 2) - $before;
        });

        $after = round((float) $teacher->fresh()->wallet, 2);

        Log::info('Teacher wallet adjusted by hand', [
            'teacher_id' => $teacher->id,
            'requested_balance' => $target,
            'balance_before' => $before,
            'balance_after' => $after,
            'note' => $validated['note'],
            'by' => auth()->id(),
        ]);

        if (round($applied, 2) === 0.0) {
            return redirect()->back()->with('success', 'Le solde était déjà à cette valeur — rien n\'a changé.');
        }

        // The applied delta is reported rather than the requested one: debit() clamps at
        // zero, and a balance that moved between opening the form and saving means the
        // change is not the subtraction the user did in their head.
        return redirect()->back()->with('payment_notice', \App\Support\PaymentNotice::success(
            'Portefeuille ajusté',
            [number_format($before, 2, ',', ' ').' DH â†’ '.number_format($after, 2, ',', ' ').' DH.'],
            [[
                'label' => trim($teacher->first_name.' '.$teacher->last_name),
                'value' => ($applied > 0 ? '+' : 'âˆ’').number_format(abs($applied), 2, ',', ' ').' DH',
                'note' => $validated['note'],
            ]],
        )->toArray());
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Teacher $teacher)
    {
        try {
            // Image file intentionally kept: this is a soft delete â€" the row keeps its
            // reference so a restore gets the image back. Permanent purge deletes the file.

            // Atomic: a soft-deleted staff row with a live login (or vice versa)
            // would leave self-service surfaces 404ing for that account.
            DB::transaction(function () use ($teacher) {
                // Detach relationships
                $teacher->subjects()->detach();
                $teacher->classes()->detach();
                $teacher->schools()->detach();

                // Delete teacher
                $teacher->delete();

                // Deleting a teacher ends their login too. The login is SOFT-deleted
                // (messages.sender_id/recipient_id and attendances.recorded_by are
                // RESTRICT foreign keys — a hard delete rolls back for anyone who
                // ever sent a message or recorded a sheet), and the email is rewritten
                // first because the unique index would otherwise block re-creating a
                // login with the same address, which is the whole point of this
                // cleanup. Role-guarded so an admin sharing the address is never
                // caught here.
                $login = User::where('email', $teacher->email)->where('role', 'teacher')->first();
                if ($login !== null) {
                    $login->email = 'deleted+'.$login->id.'@tikoschool.invalid';
                    $login->save();
                    $login->delete();
                }
            });

            return redirect()->route('teachers.index')->with('success', 'Teacher deleted successfully.');
        } catch (\Exception $e) {
            Log::error('Error deleting teacher: '.$e->getMessage());

            return redirect()->back()->with('error', 'Failed to delete teacher. Please try again.');
        }
    }

    /**
     * Store both a user and a teacher in a single transaction.
     */
    public function storeWithUser(Request $request)
    {
        DB::beginTransaction();
        // Hoisted BEFORE try: the catches below read these, and a ValidationException
        // from validate() fires before any in-try assignment — reading an undefined
        // variable inside a catch crashed as a 500 instead of showing field errors.
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

            // Validate teacher data. Email conflicts are checked against LIVE rows
            // only: an email held by a soft-deleted teacher re-hires that record
            // (same id, wallet/payout history preserved) instead of failing.
            $teacherData = $request->validate([
                'teacher.first_name' => 'required|string|max:100',
                'teacher.last_name' => 'required|string|max:100',
                'teacher.address' => 'nullable|string|max:255',
                'teacher.phone_number' => 'nullable|string|max:20',
                'teacher.email' => [
                    'required', 'string', 'email', 'max:255',
                    function (string $attribute, mixed $value, \Closure $fail) {
                        if (Teacher::where('email', $value)->exists()) {
                            $fail('Cette adresse e-mail est déjà utilisée par un autre enseignant.');
                        }
                        // Cross-table invariant kept by ValidateEmailUnique: a live
                        // assistant owning this email blocks the create/re-hire.
                        if (Assistant::where('email', $value)->exists()) {
                            $fail('Cette adresse e-mail est déjà utilisée par un assistant.');
                        }
                    },
                ],
                'teacher.status' => 'required|in:active,inactive',
                'teacher.profile_image' => 'nullable|mimes:jpg,jpeg,png,webp,avif|max:5120',
                'teacher.schools' => 'array',
                'teacher.schools.*' => 'exists:schools,id',
                'teacher.subjects' => 'array',
                'teacher.subjects.*' => 'exists:subjects,id',
                'teacher.classes' => 'array',
                'teacher.classes.*' => 'exists:classes,id',
            ]);

            // Create user
            $user = User::create([
                'name' => $request->input('user.name'),
                'email' => $request->input('user.email'),
                'password' => Hash::make($request->input('user.password')),
                'role' => $request->input('user.role'),
            ]);

            // Handle teacher profile image
            $teacherFields = [
                'first_name' => $request->input('teacher.first_name'),
                'last_name' => $request->input('teacher.last_name'),
                'address' => $request->input('teacher.address'),
                'phone_number' => $request->input('teacher.phone_number'),
                'status' => $request->input('teacher.status'),
                // `wallet` is intentionally absent: it is not fillable, and the column
                // defaults to 0. See the note in store().
            ];

            // Re-hire: same email as a soft-deleted teacher revives THAT row — same id
            // keeps salary/wallet/payout history attached.
            $rehired = Teacher::onlyTrashed()
                ->where('email', $request->input('teacher.email'))
                ->first();

            if ($rehired) {
                // The identity join is by email: reviving a teacher whose staff email
                // differs from the new login would silently break that join.
                if ($request->input('user.email') !== $request->input('teacher.email')) {
                    throw ValidationException::withMessages([
                        'user.email' => 'L\'adresse e-mail du compte doit correspondre à celle de l\'enseignant réintégré.',
                    ]);
                }

                if ($request->hasFile('teacher.profile_image')) {
                    $newImagePath = $this->profileImages->store($request->file('teacher.profile_image'), 'teachers', 'teacher.profile_image');

                    // Optimistic swap, mirroring update(): only succeed if the stored
                    // reference is what we read; loser discards its fresh file.
                    // withTrashed(): the row is still soft-deleted right now, and
                    // whereKey() alone would scope it out and always report 0 rows.
                    $oldRawImage = $rehired->getRawOriginal('profile_image');
                    $swapped = Teacher::withTrashed()
                        ->whereKey($rehired->getKey())
                        ->when($oldRawImage === null,
                            fn ($q) => $q->whereNull('profile_image'),
                            fn ($q) => $q->where('profile_image', $oldRawImage))
                        ->update(['profile_image' => $newImagePath]);

                    if ($swapped === 0) {
                        $this->profileImages->discard($newImagePath);
                        $newImagePath = null;

                        throw ValidationException::withMessages([
                            'teacher.profile_image' => "L'image a été modifiée entre-temps. Rechargez la page et réessayez.",
                        ]);
                    }

                    // Old file leaves disk only after the transaction commits (spec Phase 9).
                    $pendingOldImageDelete = ProfileImageUrl::isLogicalPath($oldRawImage) ? $oldRawImage : null;
                }

                $rehired->fill($teacherFields)->restore();
                $teacher = $rehired;
            } else {
                if ($request->hasFile('teacher.profile_image')) {
                    // May throw ValidationException — forwarded to the form by the dedicated catch below.
                    $newImagePath = $this->profileImages->store($request->file('teacher.profile_image'), 'teachers', 'teacher.profile_image');
                    $teacherFields['profile_image'] = $newImagePath;
                }

                $teacherFields['email'] = $request->input('teacher.email');
                $teacher = Teacher::create($teacherFields);
            }

            // Sync relationships
            $teacher->subjects()->sync($request->input('teacher.subjects', []));
            $teacher->classes()->sync($request->input('teacher.classes', []));
            $teacher->schools()->sync($request->input('teacher.schools', []));

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

            // Always redirect to teachers.index for Inertia
            return redirect()->route('teachers.index')->with('success', 'User and Teacher created successfully.');
        } catch (ValidationException $e) {
            DB::rollBack();
            if (($newImagePath ?? null) !== null) {
                $this->profileImages->discard($newImagePath);
            }
            // Rethrow: Laravel's handler renders the correct response for the caller
            // — a 303 + session errors for Inertia, JSON for true APIs.
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            if (($newImagePath ?? null) !== null) {
                $this->profileImages->discard($newImagePath);
            }
            if ($request->expectsJson() || $request->isXmlHttpRequest()) {
                return response()->json(['error' => 'Failed to create user and teacher.'], 500);
            } else {
                return redirect()->back()->with('error', 'Failed to create user and teacher.');
            }
        }
    }

    /**
     * Calculate the best offer from invoices
     */
    private function calculateBestOffer($invoices)
    {
        $offerTotals = $invoices->groupBy('offer_name')->map(function ($group) {
            return $group->sum('teacher_amount');
        });

        if ($offerTotals->isEmpty()) {
            return ['name' => 'N/A', 'amount' => 0];
        }

        $bestOffer = $offerTotals->sortDesc()->first();
        $bestOfferName = $offerTotals->sortDesc()->keys()->first();

        return [
            'name' => $bestOfferName ?: 'N/A',
            'amount' => number_format($bestOffer, 2),
        ];
    }

    /**
     * Calculate current month amount
     */
    private function calculateCurrentMonthAmount($invoices)
    {
        $currentMonth = now()->format('Y-m');

        return $invoices->filter(function ($invoice) use ($currentMonth) {
            return strpos($invoice['billDate'], $currentMonth) === 0;
        })->sum('teacher_amount');
    }

    /**
     * Calculate pending months
     *
     * REAL count, not the placeholder it was for years (a bare `return 0`
     * shipped under this comment while the UI displayed « Mois en attente »):
     * a row is pending when its month has not been handed over to the teacher
     * yet — exactly what the table's Payé/En attente badge reads.
     */
    private function calculatePendingMonths($invoices)
    {
        return $invoices->filter(fn ($invoice) => ! ($invoice['is_month_paid'] ?? false))->count();
    }
}
