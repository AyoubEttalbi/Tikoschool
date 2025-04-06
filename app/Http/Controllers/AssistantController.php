<?php

namespace App\Http\Controllers;
use Spatie\Activitylog\Models\Activity;
use App\Models\User;
use App\Models\Assistant;
use App\Models\School;
use App\Models\Subject;
use App\Models\Classes;
use App\Models\Announcement;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
// use CloudinaryLabs\CloudinaryLaravel\Facades\Cloudinary;
use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class AssistantController extends Controller
{   
    // Helper function to get Cloudinary
    private function getCloudinary()
    {
        return new Cloudinary(
            Configuration::instance([
                'cloud' => [
                    'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
                    'api_key' => env('CLOUDINARY_API_KEY'),
                    'api_secret' => env('CLOUDINARY_API_SECRET'),
                ],
                'url' => [
                    'secure' => true
                ]
            ])
        );
    }
    /**
    * @param \Illuminate\Http\UploadedFile $file
    * @param string $folder
    * @param int $width
    * @param int $height
    * @return array
    */
   private function uploadToCloudinary($file, $folder = 'assistants', $width = 300, $height = 300)
   {
       $cloudinary = $this->getCloudinary();
       $uploadApi = $cloudinary->uploadApi();
       
       // Get file info
       $fileSize = $file->getSize(); // Size in bytes
       $fileExtension = $file->extension();
       
       // Set quality based on file size to optimize
       $quality = 'auto';
       
       // For large images, we can be more aggressive with compression
       if ($fileSize > 1000000) { // 1MB
           $quality = 'auto:low';
       }
       
       // Upload parameters
       $options = [
           'folder' => $folder,
           'transformation' => [
               // Resize to fit within dimensions while maintaining aspect ratio
               [
                   'width' => $width, 
                   'height' => $height, 
                   'crop' => 'fill',
                   'gravity' => 'auto', // Smart cropping
               ],
               // Quality and format optimization
               [
                   'quality' => $quality,
                   'fetch_format' => 'auto', // Auto select best format (webp when possible)
               ],
           ],
           // Add public_id for better organization (optional)
           'public_id' => 'assistant_' . time() . '_' . random_int(1000, 9999),
           // Optional: Add these for even more optimization
           'resource_type' => 'image',
           'flags' => 'lossy', // Apply lossy compression
           // Add metadata for better tracking
           'context' => 'source=laravel-app|user=assistant',
       ];
       
       // Upload to Cloudinary
       $result = $uploadApi->upload($file->getRealPath(), $options);
       
       // Return the results with all URLs and data
       return [
           'secure_url' => $result['secure_url'],
           'public_id' => $result['public_id'],
           'format' => $result['format'],
           'width' => $result['width'],
           'height' => $result['height'],
           'bytes' => $result['bytes'],
           'resource_type' => $result['resource_type'],
           'created_at' => $result['created_at'],
       ];
   }
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
        if ($request->has('search') && !empty($request->search)) {
            $searchTerm = $request->search;

            $query->where(function ($q) use ($searchTerm) {
                // Search by assistant fields
                $q->where('first_name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('last_name', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('phone_number', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('email', 'LIKE', "%{$searchTerm}%")
                  ->orWhere('address', 'LIKE', "%{$searchTerm}%");

                // Search by school name (if assistant has a relationship with schools)
                // This might be redundant if session filter is active, but good for general search
                $q->orWhereHas('schools', function ($schoolQuery) use ($searchTerm) {
                    $schoolQuery->where('name', 'LIKE', "%{$searchTerm}%");
                });
            });
        }

        // Fetch paginated and filtered assistants
        $assistants = $query->paginate(10)->withQueryString()->through(function ($assistant) {
            return [
                'id' => $assistant->id,
                'name' => $assistant->first_name . ' ' . $assistant->last_name,
                'phone_number' => $assistant->phone_number,
                'first_name' => $assistant->first_name,
                'last_name' => $assistant->last_name,
                'email' => $assistant->email,
                'address' => $assistant->address,
                'status' => $assistant->status,
                'salary' => $assistant->salary,
                'profile_image' => $assistant->profile_image ? $assistant->profile_image : null,
                'schools_assistant' => $assistant->schools, // Keep school data if needed on list
            ];
        });

        // Fetch schools for potential filter dropdowns (though list is already filtered by session)
        $schoolsForFilter = School::all();

        return Inertia::render('Menu/AssistantsListPage', [
            'assistants' => $assistants,
            'schools' => $schoolsForFilter, // Pass schools for filters if you add them
            'search' => $request->search,
            // activeSchool is already shared via HandleInertiaRequests
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
        try {
            $validatedData = $request->validate([
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'email' => 'required|string|email|max:255|unique:assistants,email',
                'phone_number' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:255',
                'profile_image' => 'nullable|image|mimes:jpg,jpeg,png|max:5120', // Increased to 5MB
                'salary' => 'required|numeric|min:0',
                'status' => 'required|in:active,inactive',
                'schools' => 'required|array',
                'schools.*' => 'exists:schools,id',
            ]);

            // Handle profile image upload to Cloudinary
            if ($request->hasFile('profile_image')) {
                $uploadedFile = $request->file('profile_image');
                
                // Use our optimized upload method
                $uploadResult = $this->uploadToCloudinary($uploadedFile);
                
                // Store only the secure URL in the database
                $validatedData['profile_image'] = $uploadResult['secure_url'];
                
                // Optionally, you can store more metadata in your database
                // $validatedData['profile_image_public_id'] = $uploadResult['public_id'];
                // $validatedData['profile_image_bytes'] = $uploadResult['bytes'];
            }

            // Create the assistant record
            $assistant = Assistant::create($validatedData);

            // Sync schools with the assistant
            $assistant->schools()->sync($request->schools);

            return redirect()->back()->with('success', 'Assistant created successfully.');
        } catch (ValidationException $e) {
            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput();
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'An error occurred while creating the assistant: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Display the specified resource.
     */
    
     public function show($id)
     {
         $assistant = Assistant::with(['schools'])->find($id);
         
         if (!$assistant) {
             abort(404);
         }

         $user = Auth::user();
         $selectedSchoolId = session('school_id');
         $isAdminBrowsingNormally = $user->role === 'admin' && !session()->has('admin_user_id');
         $isCurrentUserTheAssistant = $user->email === $assistant->email; // Or use user_id if available
         
         \Log::info('Assistant show method - Access check', [
            'assistant_id' => $id,
            'selected_school_id' => $selectedSchoolId,
            'user_role' => $user->role,
            'is_admin_browsing' => $isAdminBrowsingNormally,
            'is_current_user_assistant' => $isCurrentUserTheAssistant,
            'assistant_has_schools' => $assistant->schools->isNotEmpty(),
         ]);

         // If no school selected AND assistant HAS schools, potentially redirect.
         if (!$selectedSchoolId && $assistant->schools->isNotEmpty()) {
            // Redirect if NOT an admin browsing normally AND NOT the assistant viewing their own profile.
            if (!$isAdminBrowsingNormally && !$isCurrentUserTheAssistant) {
                \Log::info('Assistant show: Redirecting to profiles.select (no school selected)');
                return redirect()->route('profiles.select')->with('info', 'Please select a school context to view this assistant.');
            }
         }
         
         // Get all schools this assistant has access to
         $assistantSchools = $assistant->schools;

         // If a school IS selected, check if the assistant belongs to it.
         if ($selectedSchoolId) {
             $schoolBelongsToAssistant = $assistant->schools->contains('id', $selectedSchoolId);
             // Redirect if NOT admin browsing normally AND assistant doesn't belong to selected school.
             if (!$isAdminBrowsingNormally && !$schoolBelongsToAssistant) {
                 \Log::warning('Assistant show: Redirecting to profiles.select (assistant not in selected school)', [
                     'assistant_id' => $assistant->id,
                     'selected_school_id' => $selectedSchoolId
                 ]);
                 return redirect()->route('profiles.select')->with('error', 'This assistant is not associated with your selected school.');
             }
         }
         
         // Get all schools for the dropdown
         $schools = School::all();
         
         // Filter classes by the selected school
         $classes = Classes::when($selectedSchoolId, function ($query) use ($selectedSchoolId) {
             return $query->where('school_id', $selectedSchoolId);
         })->get();
         
         // Filter students by the selected school
         $students = \App\Models\Student::when($selectedSchoolId, function ($query) use ($selectedSchoolId) {
             return $query->where('schoolId', $selectedSchoolId);
         })->get();
         
         $subjects = Subject::all();
     
         // Find the user by email
         $user = User::where('email', $assistant->email)->first();
         
         // Initialize logs with a default paginator structure
         $logs = new \Illuminate\Pagination\LengthAwarePaginator([], 0, 10);

         // If selected school ID exists, use only that school ID for filtering
         // Otherwise fall back to all schools this assistant has access to
         $schoolIds = $selectedSchoolId 
             ? [$selectedSchoolId] 
             : $assistant->schools->pluck('id')->toArray();
     
         if (!$user) {
             // If no user is found, keep the default empty logs and initialize announcements
             $announcements = [];
         } else {
             // Fetch the assistant's activity logs based on the user's ID if user exists
             $logs = Activity::where('causer_type', User::class)
                 ->where('causer_id', $user->id)
                 ->latest()
                 ->paginate(10); // Use paginate here
     
             // Fetch announcements for the employee
             $now = Carbon::now();
             $announcements = Announcement::where(function($q) use ($now) {
                 // Active announcements: 
                 // - No start date, or start date is in the past
                 // - No end date, or end date is in the future
                 $q->where(function($subq) use ($now) {
                     $subq->whereNull('date_start')
                          ->orWhere('date_start', '<=', $now);
                 })->where(function($subq) use ($now) {
                     $subq->whereNull('date_end')
                          ->orWhere('date_end', '>=', $now);
                 });
             })
             // Visibility filter
             ->where(function($q) use ($user) {
                 $q->where('visibility', 'all')
                   ->orWhere('visibility', $user->role);
             })
             ->orderBy('date_announcement', 'desc')
             ->limit(5) // Limit to 5 most recent announcements
             ->get();
         }
         
         // Get current date
         $today = Carbon::now();
         
         // FEATURE 1: Recent absences from the selected school only (not all schools)
         try {
             \Log::debug('Fetching recent absences', ['school_ids' => $schoolIds]);
             
             // First, get the total count for 7 days for "See more" button determination
             $totalAbsences = \App\Models\Attendance::whereIn('status', ['absent', 'late'])
                 ->where(function($query) use ($schoolIds) {
                     // Get absences from classes directly related to schools
                     $query->whereHas('class', function($classQuery) use ($schoolIds) {
                         $classQuery->whereIn('school_id', $schoolIds);
                     });
                     
                     // Also get absences from students belonging to the assistant's schools
                     $query->orWhereHas('student', function($studentQuery) use ($schoolIds) {
                         $studentQuery->whereIn('schoolId', $schoolIds);
                     });
                 })
                 ->where('date', '>=', $today->copy()->subDays(7)) // Only count from last 7 days for "See more"
                 ->count();
             
             // Debug the SQL being executed
             \DB::enableQueryLog();
             
             // Then fetch the data with limit to 10 from last 7 days
             $recentAbsences = \App\Models\Attendance::with(['student', 'class'])
                 ->whereIn('status', ['absent', 'late'])
                 ->where(function($query) use ($schoolIds) {
                     // Get absences from classes directly related to schools
                     $query->whereHas('class', function($classQuery) use ($schoolIds) {
                         $classQuery->whereIn('school_id', $schoolIds);
                     });
                     
                     // Also get absences from students belonging to the assistant's schools
                     $query->orWhereHas('student', function($studentQuery) use ($schoolIds) {
                         $studentQuery->whereIn('schoolId', $schoolIds);
                     });
                 })
                 ->where('date', '>=', $today->copy()->subDays(7)) // Show only from last 7 days
                 ->orderBy('date', 'desc')
                 ->limit(10)
                 ->get();
             
             // Log the executed query
             \Log::debug('Query log for recent absences', ['queries' => \DB::getQueryLog()]);
             \DB::disableQueryLog();
             
             $mappedAbsences = $recentAbsences->map(function($attendance) {
                 return [
                     'id' => $attendance->id,
                     'student_id' => $attendance->student ? $attendance->student->id : null,
                     'student_name' => $attendance->student ? $attendance->student->firstName . ' ' . $attendance->student->lastName : 'Unknown',
                     'class_name' => $attendance->class ? $attendance->class->name : 'Unknown',
                     'date' => $attendance->date,
                     'status' => $attendance->status,
                     'reason' => $attendance->reason
                 ];
             });
             
             $recentAbsences = $mappedAbsences;
         } catch (\Exception $e) {
             \Log::error('Error fetching recent absences: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
             $recentAbsences = [];
             $totalAbsences = 0;
         }
         
         // FEATURE 2: Unpaid invoices for students in the selected school only
         try {
             \Log::info('Fetching unpaid invoices', ['school_ids' => $schoolIds]);
             
             // Get total count for invoices in the last 7 days
             $totalUnpaidInvoices = \App\Models\Invoice::where(function($query) use ($schoolIds) {
                 $query->whereHas('student', function($studentQuery) use ($schoolIds) {
                     $studentQuery->whereIn('schoolId', $schoolIds);
                 });
             })
             ->where('rest', '>', 0) // Invoices with remaining balance
             ->where('billDate', '>=', $today->copy()->subDays(7)) // Only from last 7 days for "See more"
             ->count();
             
             // Then fetch data with limit to 10 from the last 7 days
             $unpaidInvoices = \App\Models\Invoice::with(['student', 'student.class', 'student.school', 'offer'])
                 ->where(function($query) use ($schoolIds) {
                     $query->whereHas('student', function($studentQuery) use ($schoolIds) {
                         $studentQuery->whereIn('schoolId', $schoolIds);
                     });
                 })
                 ->where('rest', '>', 0) // Invoices with remaining balance
                 ->where('billDate', '>=', $today->copy()->subDays(7)) // Only from last 7 days
                 ->orderBy('billDate', 'asc')
                 ->limit(10)
                 ->get()
                 ->map(function($invoice) {
                     $student = $invoice->student; // Access the loaded student
                     $offerName = $invoice->offer ? $invoice->offer->offer_name : 'N/A'; // Access offer if loaded
                     
                     return [
                         'id' => $invoice->id,
                         'student_id' => $student ? $student->id : null,
                         'student_name' => $student ? $student->firstName . ' ' . $student->lastName : 'Unknown',
                         'student_class' => $student && $student->class ? $student->class->name : 'N/A',
                         'student_school' => $student && $student->school ? $student->school->name : 'N/A',
                         'billDate' => $invoice->billDate ? $invoice->billDate->format('Y-m-d') : null,
                         'creationDate' => $invoice->creationDate ? $invoice->creationDate->format('Y-m-d') : null,
                         'endDate' => $invoice->endDate ? $invoice->endDate->format('Y-m-d') : null,
                         'totalAmount' => is_numeric($invoice->totalAmount) ? floatval($invoice->totalAmount) : 0,
                         'amountPaid' => is_numeric($invoice->amountPaid) ? floatval($invoice->amountPaid) : 0,
                         'rest' => is_numeric($invoice->rest) ? floatval($invoice->rest) : 0,
                         'months' => $invoice->months ?? 1,
                         'offer_name' => $offerName,
                         'offer_id' => $invoice->offer_id,
                         // Include payments array for the modal
                         'payments' => ($invoice->amountPaid > 0) ? [[
                             'date' => $invoice->last_payment_date ? $invoice->last_payment_date->format('Y-m-d') : ($invoice->creationDate ? $invoice->creationDate->format('Y-m-d') : null),
                             'amount' => is_numeric($invoice->amountPaid) ? floatval($invoice->amountPaid) : 0,
                             'method' => 'Cash' // Assuming cash for now
                         ]] : [],
                     ];
                 });
         } catch (\Exception $e) {
             \Log::error('Error fetching unpaid invoices: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
             $unpaidInvoices = [];
             $totalUnpaidInvoices = 0;
         }
         
         // FEATURE 3: Expiring memberships
         try {
             \Log::info('Fetching expiring memberships', ['school_ids' => $schoolIds]);
             
             // Get total count of expiring memberships for the "See more" button
             $totalExpiringMemberships = \App\Models\Membership::where(function($query) use ($schoolIds) {
                 $query->whereHas('student', function($studentQuery) use ($schoolIds) {
                     $studentQuery->whereIn('schoolId', $schoolIds);
                 });
             })
             ->where('endDate', '>=', $today) // Not expired yet
             ->where('endDate', '<=', $today->copy()->addDays(30)) // Expires within the next 30 days
             ->count();
             
             // Fetch expiring memberships with limit to 10
             $expiringMemberships = \App\Models\Membership::with(['student'])
                 ->where(function($query) use ($schoolIds) {
                     $query->whereHas('student', function($studentQuery) use ($schoolIds) {
                         $studentQuery->whereIn('schoolId', $schoolIds);
                     });
                 })
                 ->where('endDate', '>=', $today) // Not expired yet
                 ->where('endDate', '<=', $today->copy()->addDays(30)) // Expires within the next 30 days
                 ->orderBy('endDate', 'asc') // Soonest expiry first
                 ->limit(10)
                 ->get()
                 ->map(function($membership) use ($today) {
                     $endDate = Carbon::parse($membership->endDate);
                     $daysLeft = $today->diffInDays($endDate, false);
                     
                     return [
                         'id' => $membership->id,
                         'student_id' => $membership->student ? $membership->student->id : null,
                         'student_name' => $membership->student ? $membership->student->firstName . ' ' . $membership->student->lastName : 'Unknown',
                         'start_date' => $membership->startDate,
                         'end_date' => $membership->endDate,
                         'days_left' => max(0, $daysLeft) // Ensure non-negative days
                     ];
                 });
         } catch (\Exception $e) {
             \Log::error('Error fetching expiring memberships: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
             $expiringMemberships = [];
             $totalExpiringMemberships = 0;
         }
         
         // FEATURE 4: Recent payments
         try {
             \Log::info('Fetching recent payments', ['school_ids' => $schoolIds]);
             
             // Get total count of recent payments for the "See more" button
             $totalRecentPayments = \App\Models\Invoice::where(function($query) use ($schoolIds) {
                 $query->whereHas('student', function($studentQuery) use ($schoolIds) {
                     $studentQuery->whereIn('schoolId', $schoolIds);
                 });
             })
             ->where('amountPaid', '>', 0) // Has some payment
             ->where('creationDate', '>=', $today->copy()->subDays(30)) // Created in last 30 days
             ->count();
             
             // Fetch recent payments with limit to 10
             $recentPayments = \App\Models\Invoice::with(['student', 'student.class', 'student.school', 'offer'])
                 ->where(function($query) use ($schoolIds) {
                     $query->whereHas('student', function($studentQuery) use ($schoolIds) {
                         $studentQuery->whereIn('schoolId', $schoolIds);
                     });
                 })
                 ->where('amountPaid', '>', 0) // Has some payment
                 ->where('creationDate', '>=', $today->copy()->subDays(30)) // Created in last 30 days
                 ->orderBy('creationDate', 'desc') // Most recent first
                 ->limit(10)
                 ->get()
                 ->map(function($invoice) {
                     $student = $invoice->student; // Access the loaded student
                     $offer = $invoice->offer; // Access the loaded offer
                     
                     return [
                         'id' => $invoice->id,
                         'invoice_id' => $invoice->id, // Keep for consistency if needed
                         'student_id' => $student ? $student->id : null,
                         'student_name' => $student ? $student->firstName . ' ' . $student->lastName : 'Unknown',
                         'student_class' => $student && $student->class ? $student->class->name : 'N/A',
                         'student_school' => $student && $student->school ? $student->school->name : 'N/A',
                         'billDate' => $invoice->billDate ? $invoice->billDate->format('Y-m-d') : null,
                         'creationDate' => $invoice->creationDate ? $invoice->creationDate->format('Y-m-d') : null,
                         'endDate' => $invoice->endDate ? $invoice->endDate->format('Y-m-d') : null,
                         'totalAmount' => is_numeric($invoice->totalAmount) ? floatval($invoice->totalAmount) : 0,
                         'amountPaid' => is_numeric($invoice->amountPaid) ? floatval($invoice->amountPaid) : 0,
                         'rest' => is_numeric($invoice->rest) ? floatval($invoice->rest) : 0,
                         'payment_date' => $invoice->last_payment_date ? $invoice->last_payment_date->format('Y-m-d') : ($invoice->creationDate ? $invoice->creationDate->format('Y-m-d') : null),
                         'amount' => is_numeric($invoice->amountPaid) ? floatval($invoice->amountPaid) : 0, // Use amountPaid for payment amount
                         'payment_method' => 'Invoice Payment', // Keep default method
                         'offer_name' => $offer ? $offer->offer_name : 'N/A',
                         'offer_id' => $invoice->offer_id,
                         // Include payments array for the modal
                         'payments' => ($invoice->amountPaid > 0) ? [[
                             'date' => $invoice->last_payment_date ? $invoice->last_payment_date->format('Y-m-d') : ($invoice->creationDate ? $invoice->creationDate->format('Y-m-d') : null),
                             'amount' => is_numeric($invoice->amountPaid) ? floatval($invoice->amountPaid) : 0,
                             'method' => 'Cash' // Assuming cash
                         ]] : [],
                     ];
                 });
         } catch (\Exception $e) {
             \Log::error('Error fetching recent payments: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
             $recentPayments = [];
             $totalRecentPayments = 0;
         }
         
         // Get additional statistics based on the selected school
         $studentsCount = \App\Models\Student::whereIn('schoolId', $schoolIds)->count();
         $classesCount = \App\Models\Classes::whereIn('school_id', $schoolIds)->count();
         
         return Inertia::render('Menu/SingleAssistantPage', [
             'assistant' => [
                 'id' => $assistant->id,
                 'first_name' => $assistant->first_name,
                 'last_name' => $assistant->last_name,
                 'email' => $assistant->email,
                 'phone_number' => $assistant->phone_number,
                 'profile_image' => $assistant->profile_image,
                 'address' => $assistant->address,
                 'status' => $assistant->status,
                 'salary' => $assistant->salary,
                 'schools' => $assistantSchools,
             ],
             'schools' => $schools,
             'classes' => $classes,
             'subjects' => $subjects,
             'students' => $students,
             'logs' => $logs,
             'announcements' => $announcements,
             'recentAbsences' => $recentAbsences,
             'totalAbsences' => $totalAbsences,
             'unpaidInvoices' => $unpaidInvoices,
             'totalUnpaidInvoices' => $totalUnpaidInvoices,
             'expiringMemberships' => $expiringMemberships,
             'totalExpiringMemberships' => $totalExpiringMemberships,
             'recentPayments' => $recentPayments,
             'totalRecentPayments' => $totalRecentPayments,
             'selectedSchool' => $selectedSchoolId ? [
                 'id' => $selectedSchoolId,
                 'name' => session('school_name')
             ] : null,
             'statistics' => [
                 'students_count' => $studentsCount,
                 'classes_count' => $classesCount,
             ],
         ]);
     }
    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Assistant $assistant)
    {
        $subjects = Subject::all();
        $classes = Classes::all();
        $schools = School::all();

        return Inertia::render('Assistants/Edit', [
            'assistant' => $assistant,
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
            // Store the current school_id and school_name from session
            $currentSchoolId = session('school_id');
            $currentSchoolName = session('school_name');
            
            $validatedData = $request->validate([
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'email' => 'required|string|email|max:255|unique:assistants,email,' . $assistant->id,
                'phone_number' => 'nullable|string|max:20',
                'address' => 'nullable|string|max:255',
                'profile_image' => 'nullable|image|mimes:jpg,jpeg,png|max:5120', // Increased to 5MB
                'salary' => 'required|numeric|min:0',
                'status' => 'required|in:active,inactive',
                'schools' => 'array',
                'schools.*' => 'exists:schools,id',
            ]);

            // Handle profile image upload to Cloudinary
            if ($request->hasFile('profile_image')) {
                $uploadedFile = $request->file('profile_image');
                
                // Use our optimized upload method
                $uploadResult = $this->uploadToCloudinary($uploadedFile);
                
                // Store only the secure URL in the database
                $validatedData['profile_image'] = $uploadResult['secure_url'];
                
                // If you want to delete the old image, you could do that here
                if ($assistant->profile_image && $assistant->profile_image_public_id) {
                    $cloudinary = $this->getCloudinary();
                    $cloudinary->uploadApi()->destroy($assistant->profile_image_public_id);
                }
            }

            // Update the assistant record
            $assistant->update($validatedData);

            // Sync schools with the assistant
            $assistant->schools()->sync($request->schools ?? []);
            
            // Restore the session variables if they existed
            if ($currentSchoolId) {
                session([
                    'school_id' => $currentSchoolId,
                    'school_name' => $currentSchoolName
                ]);
            }

            // Check if this is a form update
            $isFormUpdate = $request->has('is_form_update');
            
            // For form updates, always stay on the assistant's profile page
            if ($isFormUpdate) {
                return redirect()->route('assistants.show', $assistant->id)->with('success', 'Assistant updated successfully.');
            }
            
            // Check if this is an admin viewing as another user
            $isViewingAs = session()->has('admin_user_id');
            
            // For other types of updates, apply the admin view logic
            if ($isViewingAs) {
                // If admin is viewing as assistant, redirect to dashboard
                return redirect()->route('dashboard')->with('success', 'Assistant updated successfully.');
            } else {
                // For normal updates, redirect to assistant's show page
                return redirect()->route('assistants.show', $assistant->id)->with('success', 'Assistant updated successfully.');
            }
        } catch (ValidationException $e) {
            return redirect()->back()
                ->withErrors($e->errors())
                ->withInput();
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'An error occurred while updating the assistant: ' . $e->getMessage())
                ->withInput();
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Assistant $assistant)
    {
        try {
            // if ($assistant->profile_image) {
            //     Storage::disk('public')->delete($assistant->profile_image);
            // }

            $assistant->delete();

            return redirect()->route('assistants.index')->with('success', 'Assistant deleted successfully.');
        } catch (\Exception $e) {
            return redirect()->back()
                ->with('error', 'An error occurred while deleting the assistant: ' . $e->getMessage());
        }
    }
}