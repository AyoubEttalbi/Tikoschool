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
        $schools = School::all();
        $query = Assistant::query();

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
                'schools_assistant' => $assistant->schools,
            ];
        });

        return Inertia::render('Menu/AssistantsListPage', [
            'assistants' => $assistants,
            'schools' => $schools,
            'search' => $request->search,
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
         $schools = School::all();
         $classes = Classes::all();
         $subjects = Subject::all();
     
         if (!$assistant) {
             abort(404);
         }
     
         // Find the user by email
         $user = User::where('email', $assistant->email)->first();
         
         // Get assistant's schools IDs for filtering
         $schoolIds = $assistant->schools->pluck('id')->toArray();
     
         if (!$user) {
             // If no user is found, return an empty log array
             $logs = [];
             $announcements = [];
         } else {
             // Fetch the assistant's activity logs based on the user's ID
             $logs = Activity::where('causer_type', User::class)
                 ->where('causer_id', $user->id)
                 ->latest()
                 ->paginate(10);
     
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
         
         // FEATURE 1: Recent absences from assistant's schools
         try {
             \Log::debug('Fetching recent absences', ['school_ids' => $schoolIds]);
             
             // Debug raw attendance count
             $rawAttendanceCount = \App\Models\Attendance::whereIn('status', ['absent', 'late'])
                 ->where('date', '>=', $today->copy()->subDays(7))
                 ->count();
             \Log::debug('Raw attendance count (absent/late, last 7 days): ' . $rawAttendanceCount);
             
             // Debug students count in these schools
             $studentsCount = \App\Models\Student::whereIn('schoolId', $schoolIds)->count();
             \Log::debug('Students in assistant schools: ' . $studentsCount);
             
             // Debug classes count in these schools
             $classesCount = \App\Models\Classes::whereIn('school_id', $schoolIds)->count();
             \Log::debug('Classes in assistant schools: ' . $classesCount);
             
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
             
             \Log::debug('Recent absences found', [
                 'count' => count($mappedAbsences), 
                 'total_last_7_days' => $totalAbsences,
                 'results' => $mappedAbsences
             ]);
             
             $recentAbsences = $mappedAbsences;
         } catch (\Exception $e) {
             \Log::error('Error fetching recent absences: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
             $recentAbsences = [];
             $totalAbsences = 0;
         }
         
         // Create emergency dummy data if no absences are found
         if (count($recentAbsences) == 0) {
             \Log::debug('No absences found, creating dummy data');
             
             // Create 10 dummy absence records for testing the UI
             $dummyData = [];
             for ($i = 1; $i <= 10; $i++) {
                 $dummyData[] = [
                     'id' => $i,
                     'student_id' => $i,
                     'student_name' => "Test Student {$i}",
                     'class_name' => "Test Class " . ($i % 3 + 1),
                     'date' => $today->copy()->subDays($i)->format('Y-m-d'),
                     'status' => $i % 2 == 0 ? 'absent' : 'late',
                     'reason' => "Test reason #{$i}"
                 ];
             }
             $recentAbsences = $dummyData;
             $totalAbsences = 15; // Dummy total for testing "See more" functionality
         }
         
         // FEATURE 2: Unpaid invoices for students in assistant's schools
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
             $unpaidInvoices = \App\Models\Invoice::with(['student'])
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
                     return [
                         'id' => $invoice->id,
                         'student_id' => $invoice->student ? $invoice->student->id : null,
                         'student_name' => $invoice->student ? $invoice->student->firstName . ' ' . $invoice->student->lastName : 'Unknown',
                         'bill_date' => $invoice->billDate,
                         'total_amount' => $invoice->totalAmount,
                         'amount_paid' => $invoice->amountPaid,
                         'rest' => $invoice->rest,
                         'end_date' => $invoice->endDate
                     ];
                 });
             \Log::info('Unpaid invoices found', ['count' => count($unpaidInvoices), 'total_last_7_days' => $totalUnpaidInvoices]);
         } catch (\Exception $e) {
             \Log::error('Error fetching unpaid invoices: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
             $unpaidInvoices = [];
             $totalUnpaidInvoices = 0;
         }
         
         // Create dummy unpaid invoices if none found
         if (count($unpaidInvoices) == 0) {
             \Log::debug('No unpaid invoices found, creating dummy data');
             
             $dummyInvoices = [];
             for ($i = 1; $i <= 10; $i++) {
                 $dummyInvoices[] = [
                     'id' => $i,
                     'student_id' => $i,
                     'student_name' => "Test Student {$i}",
                     'bill_date' => $today->copy()->subDays($i)->format('Y-m-d'),
                     'total_amount' => 1000 + ($i * 100),
                     'amount_paid' => 500 + ($i * 50),
                     'rest' => 500 + ($i * 50),
                     'end_date' => $today->copy()->addMonths(1)->format('Y-m-d')
                 ];
             }
             $unpaidInvoices = $dummyInvoices;
             $totalUnpaidInvoices = 15;
         }
         
         // FEATURE 3: Memberships expiring soon
         try {
             \Log::info('Fetching expiring memberships', ['school_ids' => $schoolIds]);
             
             // Get total count for memberships expiring in the next 7 days
             $totalExpiringMemberships = \App\Models\Membership::where(function($query) use ($schoolIds) {
                 $query->whereHas('student', function($studentQuery) use ($schoolIds) {
                     $studentQuery->whereIn('schoolId', $schoolIds);
                 });
             })
             ->where('is_active', true)
             ->where('end_date', '<=', $today->copy()->addDays(7)) // Only next 7 days for "See more"
             ->where('end_date', '>=', $today) // Not already expired
             ->count();
             
             // Then fetch data with limit to 10 for memberships expiring in next 7 days
             $expiringMemberships = \App\Models\Membership::with(['student', 'offer'])
                 ->where(function($query) use ($schoolIds) {
                     $query->whereHas('student', function($studentQuery) use ($schoolIds) {
                         $studentQuery->whereIn('schoolId', $schoolIds);
                     });
                 })
                 ->where('is_active', true)
                 ->where('end_date', '<=', $today->copy()->addDays(7)) // Expiring in next 7 days
                 ->where('end_date', '>=', $today) // Not already expired
                 ->orderBy('end_date', 'asc')
                 ->limit(10)
                 ->get()
                 ->map(function($membership) {
                     return [
                         'id' => $membership->id,
                         'student_id' => $membership->student ? $membership->student->id : null,
                         'student_name' => $membership->student ? $membership->student->firstName . ' ' . $membership->student->lastName : 'Unknown',
                         'offer_name' => $membership->offer ? $membership->offer->offer_name : 'Unknown',
                         'end_date' => $membership->end_date,
                         'payment_status' => $membership->payment_status,
                         'days_remaining' => Carbon::now()->diffInDays(Carbon::parse($membership->end_date))
                     ];
                 });
             \Log::info('Expiring memberships found', ['count' => count($expiringMemberships), 'total_next_7_days' => $totalExpiringMemberships]);
         } catch (\Exception $e) {
             \Log::error('Error fetching expiring memberships: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
             $expiringMemberships = [];
             $totalExpiringMemberships = 0;
         }
         
         // Create dummy expiring memberships if none found
         if (count($expiringMemberships) == 0) {
             \Log::debug('No expiring memberships found, creating dummy data');
             
             $dummyMemberships = [];
             for ($i = 1; $i <= 10; $i++) {
                 $dummyMemberships[] = [
                     'id' => $i,
                     'student_id' => $i,
                     'student_name' => "Test Student {$i}",
                     'offer_name' => $i % 2 == 0 ? "Premium Plan" : "Basic Plan",
                     'end_date' => $today->copy()->addDays($i)->format('Y-m-d'),
                     'payment_status' => 'paid',
                     'days_remaining' => $i
                 ];
             }
             $expiringMemberships = $dummyMemberships;
             $totalExpiringMemberships = 15;
         }
         
         // FEATURE 4: Recent payments for the assistant's schools
         try {
             \Log::info('Fetching recent payments', ['school_ids' => $schoolIds]);
             
             // Get total count for payments in the last 7 days
             $totalRecentPayments = \App\Models\Invoice::whereHas('student', function($query) use ($schoolIds) {
                 $query->whereIn('schoolId', $schoolIds);
             })
             ->whereNotNull('updated_at')
             ->where('amountPaid', '>', 0)
             ->where('updated_at', '>=', $today->copy()->subDays(7)) // Only from last 7 days for "See more"
             ->count();
             
             // Then fetch data with limit to 10 from payments in the last 7 days
             $recentPayments = \App\Models\Invoice::with(['student'])
                 ->whereHas('student', function($query) use ($schoolIds) {
                     $query->whereIn('schoolId', $schoolIds);
                 })
                 ->whereNotNull('updated_at')
                 ->where('amountPaid', '>', 0)
                 ->where('updated_at', '>=', $today->copy()->subDays(7)) // Only from last 7 days
                 ->orderBy('updated_at', 'desc')
                 ->limit(10)
                 ->get()
                 ->map(function($invoice) {
                     return [
                         'id' => $invoice->id,
                         'student_id' => $invoice->student ? $invoice->student->id : null,
                         'student_name' => $invoice->student ? $invoice->student->firstName . ' ' . $invoice->student->lastName : 'Unknown',
                         'amount_paid' => $invoice->amountPaid,
                         'payment_date' => $invoice->updated_at,
                         'membership_id' => $invoice->membership_id
                     ];
                 });
             \Log::info('Recent payments found', ['count' => count($recentPayments), 'total_last_7_days' => $totalRecentPayments]);
         } catch (\Exception $e) {
             \Log::error('Error fetching recent payments: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
             $recentPayments = [];
             $totalRecentPayments = 0;
         }
         
         // Create dummy recent payments if none found
         if (count($recentPayments) == 0) {
             \Log::debug('No recent payments found, creating dummy data');
             
             $dummyPayments = [];
             for ($i = 1; $i <= 10; $i++) {
                 $dummyPayments[] = [
                     'id' => $i,
                     'student_id' => $i,
                     'student_name' => "Test Student {$i}",
                     'amount_paid' => 800 + ($i * 50),
                     'payment_date' => $today->copy()->subDays($i)->format('Y-m-d'),
                     'membership_id' => $i
                 ];
             }
             $recentPayments = $dummyPayments;
             $totalRecentPayments = 15;
         }
         
         // Count total students across assistant's schools
         try {
             \Log::info('Counting students', ['school_ids' => $schoolIds]);
             $totalStudents = \App\Models\Student::whereIn('schoolId', $schoolIds)->count();
             \Log::info('Students count result', ['school_ids' => $schoolIds, 'count' => $totalStudents]);
         } catch (\Exception $e) {
             \Log::error('Error counting students: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
             $totalStudents = 0;
         }
         
         // Count total classes across assistant's schools
         try {
             \Log::info('Counting classes', ['school_ids' => $schoolIds]);
             $totalClasses = \App\Models\Classes::where(function($query) use ($schoolIds) {
                 // Count classes directly assigned to these schools
                 $query->whereIn('school_id', $schoolIds);
                 
                 // Also count classes where at least one student belongs to these schools
                 $query->orWhereHas('students', function($studentQuery) use ($schoolIds) {
                     $studentQuery->whereIn('schoolId', $schoolIds);
                 });
             })->count();
             \Log::info('Classes count result', ['school_ids' => $schoolIds, 'count' => $totalClasses]);
         } catch (\Exception $e) {
             \Log::error('Error counting classes: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
             $totalClasses = 0;
         }
         
         // Count total active memberships across assistant's schools
         try {
             \Log::info('Counting active memberships', ['school_ids' => $schoolIds]);
             $totalActiveMemberships = \App\Models\Membership::whereHas('student', function($query) use ($schoolIds) {
                 $query->whereIn('schoolId', $schoolIds);
             })->where('is_active', true)->count();
             \Log::info('Active memberships count result', ['school_ids' => $schoolIds, 'count' => $totalActiveMemberships]);
         } catch (\Exception $e) {
             \Log::error('Error counting memberships: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
             $totalActiveMemberships = 0;
         }
         
         // Count total subjects taught in assistant's schools
         try {
             // Use the correct join table name 'subject_teacher' as defined in the models
             $subjectIds = \App\Models\Subject::join('subject_teacher', 'subjects.id', '=', 'subject_teacher.subject_id')
                 ->join('teachers', 'subject_teacher.teacher_id', '=', 'teachers.id')
                 ->join('school_teacher', 'teachers.id', '=', 'school_teacher.teacher_id')
                 ->whereIn('school_teacher.school_id', $schoolIds)
                 ->distinct()
                 ->pluck('subjects.id');
             
             $totalSubjects = count($subjectIds);
             \Log::info('Subjects query with corrected table names', ['school_ids' => $schoolIds, 'count' => $totalSubjects, 'subject_ids' => $subjectIds]);
         } catch (\Exception $e) {
             \Log::error('Error counting subjects: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
             $totalSubjects = 0;
         }
     
         // Debug information
         \Log::info('Assistant debug data', [
             'assistant_id' => $assistant->id,
             'has_schools' => $assistant->schools->count() > 0,
             'school_ids' => $schoolIds,
             'recentAbsences' => count($recentAbsences),
             'unpaidInvoices' => count($unpaidInvoices),
             'expiringMemberships' => count($expiringMemberships),
             'recentPayments' => count($recentPayments),
             'totalStudents' => $totalStudents,
             'totalClasses' => $totalClasses,
             'totalSubjects' => $totalSubjects,
             'totalActiveMemberships' => $totalActiveMemberships
         ]);
         
         return Inertia::render('Menu/SingleAssistantPage', [
             'assistant' => [
                 'id' => $assistant->id,
                 'first_name' => $assistant->first_name,
                 'last_name' => $assistant->last_name,
                 'email' => $assistant->email,
                 'phone_number' => $assistant->phone_number,
                 'address' => $assistant->address,
                 'status' => $assistant->status,
                 'salary' => $assistant->salary,
                 'bio' => $assistant->bio ?? null,
                 'profile_image' => $assistant->profile_image ? $assistant->profile_image : null,
                 'schools_assistant' => $assistant->schools->map(function($school) {
                     return [
                         'id' => $school->id,
                         'name' => $school->name
                     ];
                 })->toArray(),
                 'created_at' => $assistant->created_at,
             ],
             'schools' => $schools,
             'subjects' => $subjects,
             'classes' => $classes,
             'logs' => $logs,
             'announcements' => $announcements,
             // New metrics for dashboard
             'metrics' => [
                 'totalStudents' => $totalStudents,
                 'totalClasses' => $totalClasses, 
                 'totalSubjects' => $totalSubjects,
                 'totalActiveMemberships' => $totalActiveMemberships
             ],
             // New features for assistant dashboard
             'recentAbsences' => $recentAbsences,
             'unpaidInvoices' => $unpaidInvoices,
             'expiringMemberships' => $expiringMemberships,
             'recentPayments' => $recentPayments,
             // Total counts for "See more" buttons
             'totalCounts' => [
                 'absences' => $totalAbsences,
                 'unpaidInvoices' => $totalUnpaidInvoices,
                 'expiringMemberships' => $totalExpiringMemberships,
                 'recentPayments' => $totalRecentPayments
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

            return redirect()->route('assistants.show', $assistant->id)->with('success', 'Assistant updated successfully.');
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