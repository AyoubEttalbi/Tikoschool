<?php

namespace App\Http\Controllers;

use App\Events\CheckEmailUnique;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Models\Subject;
use App\Models\School;
use App\Models\Classes;
use App\Models\Membership;
use App\Models\Announcement;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;
class TeacherController extends Controller
{
    
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
    * Upload file to Cloudinary
    */
    private function uploadToCloudinary($file, $folder = 'teachers', $width = 300, $height = 300)
    {
        $cloudinary = $this->getCloudinary();
        $uploadApi = $cloudinary->uploadApi();
        
        $options = [
            'folder' => $folder,
            'transformation' => [
                [
                    'width' => $width, 
                    'height' => $height, 
                    'crop' => 'fill',
                    'gravity' => 'auto',
                ],
                [
                    'quality' => 'auto',
                    'fetch_format' => 'auto',
                ],
            ],
            'public_id' => 'teacher_' . time() . '_' . random_int(1000, 9999),
            'resource_type' => 'image',
        ];
        
        $result = $uploadApi->upload($file->getRealPath(), $options);
        
        return [
            'secure_url' => $result['secure_url'],
            'public_id' => $result['public_id'],
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $selectedSchoolId = session('school_id');

        // Initialize the query with eager loading for relationships
        $query = Teacher::with(['subjects', 'classes', 'schools']);

        // Filter by selected school if one is in session
        if ($selectedSchoolId) {
            $query->whereHas('schools', function ($schoolQuery) use ($selectedSchoolId) {
                $schoolQuery->where('schools.id', $selectedSchoolId);
            });
        }

        // Apply search filter if search term is provided
        if ($request->has('search') && !empty($request->search)) {
            $this->applySearchFilter($query, $request->search);
        }

        // Apply additional filters (subject, class, school, status)
        // Note: The individual school filter might become redundant if session school is always applied, 
        // but keep it for explicit filtering capabilities.
        $this->applyFilters($query, $request->only(['subject', 'class', 'school', 'status']));

        // Fetch paginated and filtered teachers
        $teachers = $query->paginate(10)->withQueryString()->through(function ($teacher) {
            return $this->transformTeacherData($teacher);
        });

        // Fetch schools for the filter dropdown - consider fetching only relevant ones if needed
        $schoolsForFilter = School::all(); 
        // Fetch subjects and classes for filters
        $subjects = Subject::all();
        $classes = Classes::all();

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
              ->orWhere('address', 'LIKE', "%{$searchTerm}%");

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
        if (!empty($filters['subject'])) {
            $query->whereHas('subjects', function ($subjectQuery) use ($filters) {
                $subjectQuery->where('subjects.id', $filters['subject']);
            });
        }

        if (!empty($filters['class'])) {
            $query->whereHas('classes', function ($classQuery) use ($filters) {
                $classQuery->where('classes.id', $filters['class']);
            });
        }

        if (!empty($filters['school'])) {
            $query->whereHas('schools', function ($schoolQuery) use ($filters) {
                $schoolQuery->where('schools.id', $filters['school']);
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
    }

    /**
     * Transform teacher data for the frontend.
     */
    protected function transformTeacherData($teacher)
    {
        return [
            'id' => $teacher->id,
            'name' => $teacher->first_name . ' ' . $teacher->last_name,
            'phone_number' => $teacher->phone_number,
            'first_name' => $teacher->first_name,
            'last_name' => $teacher->last_name,
            'phone' => $teacher->phone_number,
            'email' => $teacher->email,
            'address' => $teacher->address,
            'status' => $teacher->status,
            'wallet' => $teacher->wallet,
            'profile_image' => $teacher->profile_image ?? null, 
            'subjects' => $teacher->subjects,
            'classes' => $teacher->classes,
            'schools' => $teacher->schools,
        ];
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
        try {
            $validatedData = $request->validate([
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'address' => 'nullable|string|max:255',
                'phone_number' => 'nullable|string|max:20',
                'email' => 'required|string|email|max:255|unique:teachers,email',
                'status' => 'required|in:active,inactive',
                'wallet' => 'required|numeric|min:0',
                'profile_image' => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
                'schools' => 'array',
                'schools.*' => 'exists:schools,id',
                'subjects' => 'array',
                'subjects.*' => 'exists:subjects,id',
                'classes' => 'array',
                'classes.*' => 'exists:classes,id',
            ]);

            // Handle profile image upload
            if ($request->hasFile('profile_image')) {
                $uploadResult = $this->uploadToCloudinary($request->file('profile_image'));
                $validatedData['profile_image'] = $uploadResult['secure_url'];
                // If you want to store public_id for future management:
                // $validatedData['profile_image_public_id'] = $uploadResult['public_id'];
            }

            event(new CheckEmailUnique($request->email));
            
            // Create the teacher record
            $teacher = Teacher::create($validatedData);

            // Sync relationships
            $teacher->subjects()->sync($request->subjects ?? []);
            $teacher->classes()->sync($request->classes ?? []);
            $teacher->schools()->sync($request->schools ?? []);

            return redirect()->route('teachers.index')->with('success', 'Teacher created successfully.');
        } catch (\Exception $e) {
            \Log::error('Error creating teacher: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to create teacher. Please try again.');
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, $id)
    {
        // Fetch announcements first
        $announcementStatus = $request->query('status', 'all'); // 'all', 'active', 'upcoming', 'expired'
        
        // Base announcement query
        $announcementQuery = Announcement::query();
        
        // Apply date filtering based on status parameter
        $now = Carbon::now();
        
        if ($announcementStatus === 'active') {
            $announcementQuery->where(function($q) use ($now) {
                $q->where(function($q) use ($now) {
                    $q->whereNull('date_start')
                      ->orWhere('date_start', '<=', $now);
                })->where(function($q) use ($now) {
                    $q->whereNull('date_end')
                      ->orWhere('date_end', '>=', $now);
                });
            });
        } elseif ($announcementStatus === 'upcoming') {
            $announcementQuery->where('date_start', '>', $now);
        } elseif ($announcementStatus === 'expired') {
            $announcementQuery->where('date_end', '<', $now);
        }
        
        // Get user role for role-based visibility
        $userRole = Auth::user() ? Auth::user()->role : null;
        
        // Apply role-based visibility filter based on user role
        if ($userRole === 'admin') {
            // Admin sees all announcements (no visibility filter needed)
        } else {
            // Employees only see announcements with visibility 'all' or matching their role
            $announcementQuery->where(function($q) use ($userRole) {
                $q->where('visibility', 'all')
                  ->orWhere('visibility', $userRole);
            });
        }
        
        // Order announcements by date (most recent first)
        $announcementQuery->orderBy('date_announcement', 'desc');
        
        // Execute announcement query
        $announcements = $announcementQuery->get();

        // Fetch the teacher with related data
        $teacher = Teacher::with(['subjects', 'classes', 'schools'])->find($id);
        
        if (!$teacher) {
            abort(404);
        }
        
        // Fetch all memberships where the teacher is involved
        $memberships = Membership::whereIn('payment_status', ['paid', 'pending'])
            ->whereJsonContains('teachers', [['teacherId' => (string) $teacher->id]])
            ->with(['invoices', 'student', 'student.school', 'student.class', 'offer'])
            ->get();
        
        // Extract invoices from memberships and calculate the teacher's share
        $invoices = $memberships->flatMap(function ($membership) use ($teacher) {
            // Skip if the student doesn't exist
            if (!$membership->student) {
                return [];
            }
            
            return $membership->invoices->map(function ($invoice) use ($membership, $teacher) {
                // Calculate the payment percentage
                $paymentPercentage = ($invoice->amountPaid / $invoice->totalAmount) * 100;
                
                // Find the teacher's data in the membership
                $teacherData = collect($membership->teachers)->first(function($item) use ($teacher) {
                    return isset($item['teacherId']) && $item['teacherId'] == $teacher->id;
                });
                
                // Calculate the teacher's share
                $teacherAmount = $teacherData && isset($teacherData['amount']) 
                    ? ($teacherData['amount'] * $invoice->months) * ($paymentPercentage / 100) 
                    : 0;
                
                // Get school information
                $schoolName = 'Unknown';
                $schoolId = null;
                
                if ($membership->student->school) {
                    $schoolName = $membership->student->school->name;
                    $schoolId = $membership->student->school->id;
                } else {
                    $schoolId = $membership->student->schoolId;
                    $school = School::find($schoolId);
                    if ($school) {
                        $schoolName = $school->name;
                    }
                }
                
                // Get class name safely
                $className = $membership->student->class ? $membership->student->class->name : 'Unknown';
                
                // Format the date consistently
                $billDate = $invoice->billDate ? $invoice->billDate->format('Y-m-d') : null;
                
                return [
                    'id' => $invoice->id,
                    'membership_id' => $invoice->membership_id,
                    'student_id' => $invoice->student_id,
                    'student_name' => $membership->student->firstName . ' ' . $membership->student->lastName,
                    'student_class' => $className,
                    'student_school' => $schoolName,
                    'schoolId' => $schoolId,
                    'billDate' => $billDate,
                    'months' => $invoice->months,
                    'creationDate' => $invoice->creationDate,
                    'totalAmount' => $invoice->totalAmount,
                    'amountPaid' => $invoice->amountPaid,
                    'rest' => $invoice->rest,
                    'offer_id' => $invoice->offer_id,
                    'offer_name' => $invoice->offer ? $invoice->offer->offer_name : null,
                    'endDate' => $invoice->endDate,
                    'includePartialMonth' => $invoice->includePartialMonth,
                    'partialMonthAmount' => $invoice->partialMonthAmount,
                    'teacher_amount' => $teacherAmount,
                ];
            });
        });
        
        // Paginate the invoices
        $perPage = 100; // Number of invoices per page
        $currentPage = request()->get('page', 1); // Get the current page from the request
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
        
        // Get the currently selected school from session
        $selectedSchool = null;
        $selectedSchoolId = session('school_id');
        $selectedSchoolName = session('school_name');
        
        if ($selectedSchoolId && $selectedSchoolName) {
            $selectedSchool = [
                'id' => $selectedSchoolId,
                'name' => $selectedSchoolName
            ];
        }
        
        return Inertia::render('Menu/SingleTeacherPage', [
            'teacher' => [
                'id' => $teacher->id,
                'first_name' => $teacher->first_name,
                'last_name' => $teacher->last_name,
                'address' => $teacher->address,
                'phone_number' => $teacher->phone_number,
                'email' => $teacher->email,
                'status' => $teacher->status,
                'wallet' => $teacher->wallet,
                'profile_image' => $teacher->profile_image ?? null, 
                'subjects' => $teacher->subjects,
                'classes' => $teacher->classes,
                'schools' => $teacher->schools,
                'created_at' => $teacher->created_at,
                'totalStudents' => $teacher->classes->sum('number_of_students')
            ],
            'invoices' => $paginatedInvoices,
            'schools' => $schools,
            'subjects' => $subjects,
            'classes' => $classes,
            'announcements' => $announcements,
            'filters' => [
                'status' => $announcementStatus,
            ],
            'userRole' => $userRole,
            'selectedSchool' => $selectedSchool, // Add the selected school
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Teacher $teacher)
    {
        $subjects = Subject::all();
        $classes = Classes::all(); // ✅ Changed from 'groups' to 'classes'

        return Inertia::render('Teachers/Edit', [
            'teacher' => $teacher,
            'subjects' => $subjects,
            'classes' => $classes, // ✅ Changed from 'groups' to 'classes'
        ]);
    }

    public function update(Request $request, Teacher $teacher)
    {
        try {
            // Store the current school_id and school_name from session
            $currentSchoolId = session('school_id');
            $currentSchoolName = session('school_name');

            $validatedData = $request->validate([
                'first_name' => 'required|string|max:100',
                'last_name' => 'required|string|max:100',
                'address' => 'nullable|string|max:255',
                'phone_number' => 'nullable|string|max:20',
                'email' => 'required|string|email|max:255|unique:teachers,email,' . $teacher->id,
                'status' => 'required|in:active,inactive',
                'wallet' => 'required|numeric|min:0',
                'profile_image' => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
                'subjects' => 'array',
                'subjects.*' => 'exists:subjects,id',
                'classes' => 'array',
                'classes.*' => 'exists:classes,id',
                'schools' => 'array',
                'schools.*' => 'exists:schools,id',
            ]);

            // Handle profile image update
            if ($request->hasFile('profile_image')) {
                // Delete old image if exists
                if ($teacher->profile_image) {
                    $publicId = $teacher->profile_image_public_id ?? null;
                    if ($publicId) {
                        $this->getCloudinary()->uploadApi()->destroy($publicId);
                    }
                }
                
                $uploadResult = $this->uploadToCloudinary($request->file('profile_image'));
                $validatedData['profile_image'] = $uploadResult['secure_url'];
                // If you want to store public_id:
                // $validatedData['profile_image_public_id'] = $uploadResult['public_id'];
            }

            event(new CheckEmailUnique($request->email, $teacher->id));
            
            // Update teacher attributes
            $teacher->update($validatedData);

            // Sync relationships
            $teacher->subjects()->sync($request->subjects ?? []);
            $teacher->classes()->sync($request->classes ?? []);
            $teacher->schools()->sync($request->schools ?? []);
            
            // Restore the session variables if they existed
            if ($currentSchoolId) {
                session([
                    'school_id' => $currentSchoolId,
                    'school_name' => $currentSchoolName
                ]);
            }

            // Check if this is a form update
            $isFormUpdate = $request->has('is_form_update');
            
            // For form updates, always stay on the teacher's profile page
            if ($isFormUpdate) {
                return redirect()->route('teachers.show', $teacher->id)->with('success', 'Teacher updated successfully.');
            }
            
            // Check if this is an admin viewing as another user
            $isViewingAs = session()->has('admin_user_id');
            
            // For other types of updates, apply the admin view logic
            if ($isViewingAs) {
                // If admin is viewing as teacher, redirect to dashboard
                return redirect()->route('dashboard')->with('success', 'Teacher updated successfully.');
            } else {
                // For normal updates, redirect to teacher's show page
                return redirect()->route('teachers.show', $teacher->id)->with('success', 'Teacher updated successfully.');
            }
        } catch (\Exception $e) {
            \Log::error('Error updating teacher: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to update teacher. Please try again.');
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Teacher $teacher)
    {
        try {
            // Delete profile image from Cloudinary if exists
            if ($teacher->profile_image) {
                $publicId = $teacher->profile_image_public_id ?? null;
                if ($publicId) {
                    $this->getCloudinary()->uploadApi()->destroy($publicId);
                }
            }

            // Detach relationships
            $teacher->subjects()->detach();
            $teacher->classes()->detach();
            $teacher->schools()->detach();

            // Delete teacher
            $teacher->delete();

            return redirect()->route('teachers.index')->with('success', 'Teacher deleted successfully.');
        } catch (\Exception $e) {
            \Log::error('Error deleting teacher: ' . $e->getMessage());
            return redirect()->back()->with('error', 'Failed to delete teacher. Please try again.');
        }
    }
}