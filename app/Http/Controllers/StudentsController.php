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
class StudentsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
 
     public function index(Request $request)
{
    // Initialize the query with eager loading for relationships
    $query = Student::with(['class', 'school', 'level']);

    // Apply search filter if search term is provided
    if ($request->has('search') && !empty($request->search)) {
        $this->applySearchFilter($query, $request->search);
    }

    // Apply additional filters (e.g., school, class, level)
    $this->applyFilters($query, $request->only(['school', 'class', 'level']));

    // Fetch paginated and filtered students
    $students = $query->paginate(10)->withQueryString()->through(function ($student) {
        return $this->transformStudentData($student);
    });

    // Return data to the Inertia frontend
    return Inertia::render('Menu/StudentListPage', [
        'students' => $students,
        'Alllevels' => Level::all(),
        'Allclasses' => Classes::all(),
        'Allschools' => School::all(),
        'search' => $request->search,
        'filters' => $request->only(['school', 'class', 'level']), // Pass filter values to the frontend
        'Allmemberships' => Membership::all(),
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
        $query->where('schoolId', $filters['school']);
    }

    if (!empty($filters['class'])) {
        $query->where('classId', $filters['class']);
    }

    if (!empty($filters['level'])) {
        $query->where('levelId', $filters['level']);
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
        'profileImage' => $student->profileImage ? URL::asset('storage/' . $student->profileImage) : null,
        'phoneNumber' => $student->phoneNumber,
    ];
}

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('Students/Create');
    }

    /**
     * Store a newly created resource in storage.
     */
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
                'levelId' => 'nullable|exists:levels,id',
                'classId' => 'nullable|exists:classes,id',
                'schoolId' => 'nullable|exists:schools,id',
                'status' => 'required|in:active,inactive',
                'assurance' => 'required|boolean',
            ]);

            // Create the student
            $student = Student::create($validatedData);

            // Log the activity
            $this->logActivity('created', $student, null, $student->toArray());

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
        ->with(['offer']) // Eager load related offer data
        ->get()
        ->map(function ($membership) {
            return [
                'id' => $membership->id,
                'offer_name' => optional($membership->offer)->offer_name, // Avoid errors if no offer
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
    $invoices = Invoice::where('student_id', $student->id ) // Get invoices for the specific student
    ->with(['offer']) // Eager load related offer data
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
    $attendances = Attendance::with(['classe', 'recordedBy'])
        ->where('student_id', $student->id)
        ->latest()
        ->get()
        ->map(function ($attendance) {
            return [
                'id' => $attendance->id,
                'date' => $attendance->date,
                'status' => $attendance->status,
                'classe' => $attendance->classe ? $attendance->classe->name : null,
                'recordedBy' => $attendance->recordedBy ? $attendance->recordedBy->name : null,
                'created_at' => $attendance->created_at,
                'reason' => $attendance->reason
            ];
        });

    // Format the student data, including memberships, invoices, and attendances
    $studentData = [
        'id' => $student->id,
        'name' => $student->firstName . ' ' . $student->lastName,
        'studentId' => $student->massarCode,
        'phone' => $student->phoneNumber,
        'phoneNumber' =>  $student->phoneNumber,
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
        'profileImage' => $student->profileImage ? URL::asset('storage/' . $student->profileImage) : null,
        'created_at' => $student->created_at,
        'memberships' => $memberships, // Embed memberships in the student data
        'invoices' => $invoices, // Include invoices in the response
        'attendances' => $attendances, // Include attendances in the response
    ];

    // Fetch schools and classes
    $schools = School::all();
    $classes = Classes::all();

    // Render the Inertia view with the student data, levels, and other resources
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
                'subjects' => $teacher->subjects->pluck('name'), // Extract subject names
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
            $oldData = $student->toArray(); // Capture old data before update

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
                'levelId' => 'nullable|integer',
                'classId' => 'nullable|integer',
                'schoolId' => 'nullable|integer',
                'status' => 'required|in:active,inactive',
                'assurance' => 'required|boolean',
            ]);

            $student->update($validatedData);

            // Log the activity with old and new data
            $this->logActivity('updated', $student, $oldData, $student->toArray());

            return redirect()->route('students.show', $student->id)->with('success', 'Student updated successfully.');
        } catch (\Exception $e) {
            \Log::error('Error updating student', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
                'data' => $request->all(),
            ]);

            return redirect()->back()->with('error', 'Failed to update student. Please try again.');
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Student $student)
    {
        // Delete the profile image if it exists
        if ($student->profileImage) {
            Storage::disk('public')->delete($student->profileImage);
        }

        // Delete the student
        $student->delete();

        // Redirect to the student list page with a success message
        return redirect()->route('students.index')->with('success', 'Student deleted successfully.');
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