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
class StudentsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
 
     public function index(Request $request)
     {
         $query = Student::query();
     
         if ($request->has('search') && !empty($request->search)) {
             $searchTerm = $request->search;
     
             $query->where(function ($q) use ($searchTerm) {
                 $q->where('firstName', 'LIKE', "%{$searchTerm}%")
                   ->orWhere('lastName', 'LIKE', "%{$searchTerm}%")
                   ->orWhere('massarCode', 'LIKE', "%{$searchTerm}%")
                   ->orWhere('phoneNumber', 'LIKE', "%{$searchTerm}%")
                   ->orWhere('email', 'LIKE', "%{$searchTerm}%")
                   ->orWhere('address', 'LIKE', "%{$searchTerm}%")
                   ->orWhere('classId', 'LIKE', "%{$searchTerm}%");
                   
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
     
         $levels = Level::all();
         $classes = Classes::all();
         $schools = School::all();

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

    // Fetch all levels (or adjust this to your actual logic for fetching levels)
    $levels = Level::all();
    
    // Format the student data
    $studentData = [
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
        'created_at' => $student->created_at,
    ];
    $schools = School::all();
    $classes = Classes::all();

    // Render the Inertia view with the student data and levels
    return Inertia::render('Menu/SingleStudentPage', [
        'student' => $studentData,
        'Alllevels' => $levels, 
        'Allclasses' => $classes,
        'Allschools' => $schools
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