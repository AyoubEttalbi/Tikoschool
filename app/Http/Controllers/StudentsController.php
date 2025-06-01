<?php
namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Storage;
use App\Models\Level;
use App\Models\Classes;
use App\Models\School;
use App\Models\Subject;
use App\Models\Offer;
use App\Models\Teacher;
use App\Models\Membership;
use App\Models\Invoice;
use App\Models\Attendance;
use App\Models\Result;
use Cloudinary\Cloudinary;
use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;
use Illuminate\Support\Facades\DB;
use Barryvdh\DomPDF\Facade\Pdf;

class StudentsController extends Controller
{
    /**
     * Download a student's information as a PDF.
     */
    public function downloadPdf($id)
    {
        $student = \App\Models\Student::with(['level', 'class', 'school', 'memberships.offer'])
            ->findOrFail($id);
        
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('students_pdf', [
            'student' => $student
        ]);
        $fileName = 'student_' . $student->id . '_' . now()->format('Ymd_His') . '.pdf';
        return $pdf->download($fileName);
    }
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
    private function uploadToCloudinary($file, $folder = 'students', $width = 300, $height = 300)
    {
        $cloudinary = $this->getCloudinary();
        $uploadApi = $cloudinary->uploadApi();
        
        // Get file info
        $fileSize = $file->getSize();
        $fileExtension = $file->extension();
        
        // Set quality based on file size
        $quality = 'auto';
        if ($fileSize > 1000000) {
            $quality = 'auto:low';
        }
        
        // Upload parameters
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
                    'quality' => $quality,
                    'fetch_format' => 'auto',
                ],
            ],
            'public_id' => 'student_' . time() . '_' . random_int(1000, 9999),
            'resource_type' => 'image',
            'flags' => 'lossy',
            'context' => 'source=laravel-app|user=student',
        ];
        
        // Upload to Cloudinary
        $result = $uploadApi->upload($file->getRealPath(), $options);
        
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
         // Initialize the query with eager loading for relationships
         $query = Student::with(['class', 'school', 'level']);
         
         // Get the selected school from session and apply filter
         $selectedSchoolId = session('school_id');
         if ($selectedSchoolId) {
             // Show students for the selected school OR students with no school assigned
             $query->where(function($q) use ($selectedSchoolId) {
                 $q->where('schoolId', $selectedSchoolId)
                   ->orWhereNull('schoolId');
             });
             \Log::info('Students filtered by school (including unassigned)', [
                 'school_id' => $selectedSchoolId,
                 'user_role' => $request->user()->role
             ]);
         }
     
         // Apply search filter if search term is provided
         if ($request->has('search') && !empty($request->search)) {
             $this->applySearchFilter($query, $request->search);
         }
     
         // Apply additional filters (e.g., school, class, level)
         $this->applyFilters($query, $request->only(['school', 'class', 'level']));
     
         // Fetch paginated and filtered students, newest first
        $students = $query->orderBy('created_at', 'desc')->paginate(10)->withQueryString()->through(function ($student) {
            return $this->transformStudentData($student);
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
             'filters' => $request->only(['school', 'class', 'level']),
             'Allmemberships' => Membership::all(),
             'selectedSchool' => $selectedSchoolId ? [
                 'id' => $selectedSchoolId,
                 'name' => session('school_name')
             ] : null
         ]);
     }

/**
 * Apply search filter to the query.
 */
protected function applySearchFilter($query, $searchTerm)
{
    $query->where(function ($q) use ($searchTerm) {
        // Search by student fields
        $q->where('firstName', 'LIKE', "%{$searchTerm}%")
          ->orWhere('lastName', 'LIKE', "%{$searchTerm}%")
          ->orWhere('massarCode', 'LIKE', "%{$searchTerm}%")
          ->orWhere('phoneNumber', 'LIKE', "%{$searchTerm}%")
          ->orWhere('email', 'LIKE', "%{$searchTerm}%")
          ->orWhere('address', 'LIKE', "%{$searchTerm}%");

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
    if (!empty($filters['school'])) {
        $query->where(DB::raw('"schoolId"'), $filters['school']);
    }

    if (!empty($filters['class'])) {
        $query->where(DB::raw('"classId"'), $filters['class']);
    }

    if (!empty($filters['level'])) {
        $query->where(DB::raw('"levelId"'), $filters['level']);
    }
}

/**
 * Transform student data for the frontend.
 */
protected function transformStudentData($student)
{
    return [
        'id' => $student->id,
        'name' => $student->firstName . ' ' . $student->lastName,
        'studentId' => $student->massarCode,
        'phone' => $student->phoneNumber,
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
        'guardianNumber' => $student->guardianNumber,
        'profile_image' => $student->profile_image ?? null,
        'phoneNumber' => $student->phoneNumber,
        'hasDisease' => $student->hasDisease,
        'diseaseName' => $student->diseaseName,
        'medication' => $student->medication,
    ];
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
        try {
            // Validate the incoming request data
            $validatedData = $request->validate([
                'firstName' => 'required|string|max:100',
                'lastName' => 'required|string|max:100',
                'dateOfBirth' => 'required|date',
                'billingDate' => 'required|date',
                'address' => 'nullable|string',
                'guardianNumber' => 'nullable|string|max:255',
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
                'profile_image' => 'nullable|image|mimes:jpg,jpeg,png|max:5120', // Added for image upload
            ]);

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

            // Handle profile image upload to Cloudinary
            if ($request->hasFile('profile_image')) {
                $uploadedFile = $request->file('profile_image');
                $uploadResult = $this->uploadToCloudinary($uploadedFile);
                $validatedData['profile_image'] = $uploadResult['secure_url'];
            }

            // If hasDisease is false (0), set diseaseName and medication to NULL
            if ($validatedData['hasDisease'] === 0) {
                $validatedData['diseaseName'] = null;
                $validatedData['medication'] = null;
            }

            // Create and save the new student
            $student = Student::create($validatedData);

            // Update class student count if assigned to a class
            if (!empty($validatedData['classId'])) {
                $class = Classes::find($validatedData['classId']);
                if ($class) {
                    $class->updateStudentCount();
                    
                    // Log the updated class count
                    \Log::info('Updated class student count after adding new student', [
                        'class_id' => $class->id,
                        'class_name' => $class->name,
                        'new_student_count' => $class->number_of_students,
                        'student_id' => $student->id
                    ]);
                }
            }

            // Log the activity
            $this->logActivity('created', $student);

            return redirect()->route('students.index')->with('success', 'Student created successfully.');
        } catch (\Exception $e) {
            \Log::error('Error creating student', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
                'data' => $request->all(),
            ]);

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
        // Fetch the student from the database
        $student = Student::find($id);

        // If the student doesn't exist, return a 404 error
        if (!$student) {
            abort(404);
        }

        // Fetch all levels, and teachers with their subjects
        $levels = Level::all();
        // Fetch offers based on student's level
        $offers = Offer::where('levelId', $student->levelId)->get();
        $teachers = Teacher::with('subjects')->get(); // Eager load subjects for each teacher

        // Fetch memberships for the student
        $memberships = Membership::where('student_id', $student->id)
            ->with(['offer'])
            ->get()
            ->map(function ($membership) {
                return [
                    'id' => $membership->id,
                    'offer_name' => optional($membership->offer)->offer_name,
                    'offer_id' => optional($membership->offer)->id,
                    'price' => optional($membership->offer)->price,
                    'teachers' => $membership->teachers,
                    'created_at' => $membership->created_at,
                    'payment_status' => $membership->payment_status,
                    'is_active' => $membership->is_active,
                    'start_date' => $membership->start_date,
                    'end_date' => $membership->end_date,
                ];
            });

        // Fetch invoices for all student
        $invoices = Invoice::where('student_id', $student->id)
            ->with(['offer'])
            ->get()
            ->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'membership_id' => $invoice->membership_id,
                    'months' => $invoice->months,
                    'billDate' => $invoice->billDate,
                    'creationDate' => $invoice->creationDate,
                    'totalAmount' => $invoice->totalAmount,
                    'amountPaid' => $invoice->amountPaid,
                    'rest' => $invoice->rest,
                    'endDate' => $invoice->endDate,
                    'includePartialMonth' => $invoice->includePartialMonth,
                    'partialMonthAmount' => $invoice->partialMonthAmount,
                    'last_payment' => $invoice->updated_at,
                    'created_at' => $invoice->created_at,
                ];
            });

        // Fetch attendance records for the student
        $attendances = Attendance::with(['class', 'recordedBy'])
            ->where('student_id', $student->id)
            ->latest()
            ->get()
            ->map(function ($attendance) {
                return [
                    'id' => $attendance->id,
                    'date' => $attendance->date,
                    'status' => $attendance->status,
                    'class' => $attendance->class ? $attendance->class->name : null,
                    'recordedBy' => $attendance->recordedBy ? $attendance->recordedBy->name : null,
                    'created_at' => $attendance->created_at,
                    'reason' => $attendance->reason
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

        // Format the student data with new disease fields
        $studentData = [
            'id' => $student->id,
            'name' => $student->firstName . ' ' . $student->lastName,
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
            'guardianNumber' => $student->guardianNumber,
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
        ];

        // Fetch schools and classes
        $schools = School::all();
        $classes = Classes::all();

        return Inertia::render('Menu/SingleStudentPage', [
            'student' => $studentData,
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
        return Inertia::render('Students/Edit', [
            'student' => $student,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Student $student)
    {
        try {
            $oldData = $student->toArray();
            $oldClassId = $student->classId;
            
            // Log the raw request data for debugging
            \Log::info('Student update raw request data:', [
                'student_id' => $student->id,
                'request_data' => $request->all(),
            ]);

            $validatedData = $request->validate([
                'firstName' => 'required|string|max:100',
                'lastName' => 'required|string|max:100',
                'dateOfBirth' => 'required|date',
                'billingDate' => 'required|date',
                'address' => 'nullable|string',
                'guardianNumber' => 'nullable|string|max:255',
                'CIN' => 'nullable|string|max:50|unique:students,CIN,' . $student->id,
                'phoneNumber' => 'nullable|string|max:20',
                'email' => 'nullable|string|email|max:255|unique:students,email,' . $student->id,
                'massarCode' => 'nullable|string|max:50|unique:students,massarCode,' . $student->id,
                'levelId' => 'nullable',
                'classId' => 'nullable',
                'schoolId' => 'nullable',
                'status' => 'required|in:active,inactive',
                'assurance' => 'required',
                'hasDisease' => 'sometimes',
                'diseaseName' => 'nullable|string|max:255',
                'medication' => 'nullable|string',
                'profile_image' => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
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
            
            // Handle profile image upload to Cloudinary
            if ($request->hasFile('profile_image')) {
                $uploadedFile = $request->file('profile_image');
                $uploadResult = $this->uploadToCloudinary($uploadedFile);
                $validatedData['profile_image'] = $uploadResult['secure_url'];
                
                // Delete old image if it exists
                if ($student->profile_image) {
                    $publicId = $student->profile_image_public_id ?? null;
                    if ($publicId) {
                        $cloudinary = $this->getCloudinary();
                        $cloudinary->uploadApi()->destroy($publicId);
                    }
                }
            }

            // Debug: Log the validated data before update
            \Log::info('Student update data before saving:', [
                'student_id' => $student->id,
                'validated_data' => $validatedData,
            ]);

            // Direct database update to ensure correct values are set
            // This bypasses Eloquent's casting which might be causing issues
            \DB::table('students')
                ->where('id', $student->id)
                ->update($validatedData);
                
            // Refresh the model from database
            $student->refresh();

            // Check if the class has changed
            $newClassId = $student->classId;
            if ($oldClassId != $newClassId) {
                // Update old class count if there was an old class
                if ($oldClassId) {
                    $oldClass = Classes::find($oldClassId);
                    if ($oldClass) {
                        $oldClass->updateStudentCount();
                        \Log::info('Updated old class student count', [
                            'class_id' => $oldClass->id,
                            'class_name' => $oldClass->name,
                            'new_student_count' => $oldClass->number_of_students
                        ]);
                    }
                }
                
                // Update new class count if there is a new class
                if ($newClassId) {
                    $newClass = Classes::find($newClassId);
                    if ($newClass) {
                        $newClass->updateStudentCount();
                        \Log::info('Updated new class student count', [
                            'class_id' => $newClass->id,
                            'class_name' => $newClass->name,
                            'new_student_count' => $newClass->number_of_students
                        ]);
                    }
                }
            }

            // Log the activity with old and new data
            $this->logActivity('updated', $student, $oldData, $student->toArray());

            return redirect()->route('students.show', $student->id)->with('success', 'Student updated successfully.');
        } catch (\Exception $e) {
            \Log::error('Error updating student', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => auth()->id(),
                'request_data' => $request->all(),
                'student_id' => $student->id,
                'student_data' => $student->toArray(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return redirect()->back()->with('error', 'Failed to update student: ' . $e->getMessage());
        }
    }

     /**
     * Remove the specified resource from storage.
     */
    public function destroy(Student $student)
    {
        try {
            // Save class ID before deleting student
            $classId = $student->classId;
            
            // Delete the profile image from Cloudinary if it exists
            if ($student->profile_image) {
                $publicId = $student->profile_image_public_id ?? null;
                if ($publicId) {
                    $cloudinary = $this->getCloudinary();
                    $cloudinary->uploadApi()->destroy($publicId);
                }
            }

            // Delete the student
            $student->delete();

            // Update class student count if student was assigned to a class
            if ($classId) {
                $class = Classes::find($classId);
                if ($class) {
                    $class->updateStudentCount();
                    
                    // Log the updated class count
                    \Log::info('Updated class student count after removing student', [
                        'class_id' => $class->id,
                        'class_name' => $class->name,
                        'new_student_count' => $class->number_of_students
                    ]);
                }
            }

            return redirect()->route('students.index')->with('success', 'Student deleted successfully.');
        } catch (\Exception $e) {
            \Log::error('Error deleting student', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
            ]);

            return redirect()->back()->with('error', 'Failed to delete student. Please try again.');
        }
    }
    protected function logActivity($action, $model, $oldData = null, $newData = null)
{
    $description = ucfirst($action) . ' ' . class_basename($model) . ' (' . $model->id . ')';
    $tableName = $model->getTable();

    // Define the properties to log
    $properties = [
        'TargetName' => $model->firstName . ' ' . $model->lastName, // Name of the target entity
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