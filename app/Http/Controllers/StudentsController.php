<?php
namespace App\Http\Controllers;

use App\Models\Student;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Storage;
use App\Models\Level;
class StudentsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
 
    public function index()
    {
        // Fetch paginated students from the database
        $students = Student::paginate(10)->through(function ($student) {
            return [
                'id' => $student->id,
                'name' => $student->firstName . ' ' . $student->lastName,
                'studentId' => $student->massarCode,
                'grade' => $student->class,
                'phone' => $student->phoneNumber,
                'address' => $student->address,
                'photo' => $student->profileImage ? URL::asset('storage/' . $student->profileImage) : null,
                'class' => $student->class,

                
                'firstName' => $student->firstName,
                'lastName' => $student->lastName,
                'dateOfBirth' => $student->dateOfBirth,
                'billingDate' => $student->billingDate,
                'address' => $student->address,
                'guardianName' => $student->guardianName,
                'CIN' => $student->CIN,
                'phoneNumber' => $student->phoneNumber,
                'email' => $student->email,
                'massarCode' => $student->massarCode,
                'levelId' => $student->levelId,
                'class' => $student->class,
                'status' => $student->status,
                'assurance' => $student->assurance,
                'profileImage' => $student->profileImage ? URL::asset('storage/' . $student->profileImage) : null,
            ];
            
        });
        $levels = Level::all(); 
        // Render the Inertia view with the students data
        return Inertia::render('Menu/StudentListPage', [
            'students' => $students,
            'levels' => $levels,
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
        'guardianName' => 'nullable|string|max:255',
        'CIN' => 'required|string|max:50|unique:students,CIN',
        'phoneNumber' => 'nullable|string|max:20',
        'email' => 'required|string|email|max:255|unique:students,email',
        'massarCode' => 'required|string|max:50|unique:students,massarCode',
        'levelId' => 'nullable|integer',
        'class' => 'nullable|string',
        'status' => 'required|in:active,inactive',
        'assurance' => 'required|boolean',
    ]);

    Student::create($validatedData);

    return redirect()->route('students.index')->with('success', 'Student created successfully.');
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
        'firstName' => $student->firstName,
        'lastName' => $student->lastName,
        'dateOfBirth' => $student->dateOfBirth,
        'billingDate' => $student->billingDate,
        'address' => $student->address,
        'guardianName' => $student->guardianName,
        'CIN' => $student->CIN,
        'phoneNumber' => $student->phoneNumber,
        'email' => $student->email,
        'massarCode' => $student->massarCode,
        'levelId' => $student->levelId,
        'class' => $student->class,
        'status' => $student->status,
        'assurance' => $student->assurance,
        'profileImage' => $student->profileImage ? URL::asset('storage/' . $student->profileImage) : null,
        'created_at' => $student->created_at,
    ];

    // Render the Inertia view with the student data and levels
    return Inertia::render('Menu/SingleStudentPage', [
        'student' => $studentData,
        'levels' => $levels, // Pass levels to the page
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
        'guardianName' => 'nullable|string|max:255',
        'CIN' => 'required|string|max:50|unique:students,CIN,' . $student->id,
        'phoneNumber' => 'nullable|string|max:20',
        'email' => 'required|string|email|max:255|unique:students,email,' . $student->id,
        'massarCode' => 'required|string|max:50|unique:students,massarCode,' . $student->id,
        'levelId' => 'nullable|integer',
        'class' => 'nullable|string',
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