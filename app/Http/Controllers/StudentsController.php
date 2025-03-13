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
class StudentsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
 
     public function index(Request $request)
{
    $query = Student::query();
    $levels = Level::all();
    $classes = Classes::all();
    $schools = School::all();
    // Apply search filter if search term is provided
    if ($request->has('search') && !empty($request->search)) {
        $searchTerm = $request->search;

        $query->where(function ($q) use ($searchTerm) {
            // Search by student fields
            $q->where('firstName', 'LIKE', "%{$searchTerm}%")
              ->orWhere('lastName', 'LIKE', "%{$searchTerm}%")
              ->orWhere('massarCode', 'LIKE', "%{$searchTerm}%")
              ->orWhere('phoneNumber', 'LIKE', "%{$searchTerm}%")
              ->orWhere('email', 'LIKE', "%{$searchTerm}%")
              ->orWhere('address', 'LIKE', "%{$searchTerm}%");

            // Search by class name
            $q->orWhereHas('class', function ($classQuery) use ($searchTerm) {
                $classQuery->where('name', 'LIKE', "%{$searchTerm}%");
            });

            // Search by school name
            $q->orWhereHas('school', function ($schoolQuery) use ($searchTerm) {
                $schoolQuery->where('name', 'LIKE', "%{$searchTerm}%");
            });

            // Search by level name
            $q->orWhereHas('level', function ($levelQuery) use ($searchTerm) {
                $levelQuery->where('name', 'LIKE', "%{$searchTerm}%");
            });
        });
    }

    // Fetch paginated and filtered students
    $students = $query->paginate(10)->withQueryString()->through(function ($student) {
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
            'address' => $student->address,
            'CIN' => $student->CIN,
            'phoneNumber' => $student->phoneNumber,
            'email' => $student->email,
            'massarCode' => $student->massarCode,
            'levelId' => $student->levelId,
            'status' => $student->status,
            'assurance' => $student->assurance,
            'guardianNumber' => $student->guardianNumber,
            'profileImage' => $student->profileImage ? URL::asset('storage/' . $student->profileImage) : null,
        ];
    });



    return Inertia::render('Menu/StudentListPage', [
        'students' => $students,
        'Alllevels' => $levels,
        'Allclasses' => $classes,
        'Allschools' => $schools,
        'search' => $request->search
    ]);
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
            'levelId' => 'nullable|exists:levels,id', // Ensure levelId exists in levels table
            'classId' => 'nullable|exists:classes,id', // Ensure classId exists in classes table
            'schoolId' => 'nullable|exists:schools,id', // Ensure schoolId exists in schools table
            'status' => 'required|in:active,inactive',
            'assurance' => 'required|boolean',
        ]);
    
        Student::create($validatedData);
    
        return redirect()->route('students.index')->with('success','true');
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

    // Fetch all levels, offers, and teachers with their subjects
    $levels = Level::all();
    $offers = Offer::all();
    $teachers = Teacher::with('subjects')->get(); // Eager load subjects for each teacher

    // Fetch memberships for the student
    $memberships = Membership::where('student_id', $student->id)
        ->with(['offer']) // Eager load related offer data
        ->get()
        ->map(function ($membership) {
            return [
                'id' => $membership->id,
                'offer_name' => $membership->offer->offer_name, // Get the offer name
                'offer_id' => $membership->offer->id, // Get the offer name
                'price' => $membership->offer->price, // Get the offer price
                'teachers' => $membership->teachers, // Teachers array (already cast to array)
                'created_at' => $membership->created_at,
            ];
        });

    // Format the student data, including memberships
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

    return redirect()->route('students.show', $student->id)->with('success', 'Student updated successfully.');
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
}