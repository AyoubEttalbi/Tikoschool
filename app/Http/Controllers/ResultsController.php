<?php

namespace App\Http\Controllers;

use App\Models\Classes;
use App\Models\Level;
use App\Models\Result;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;

class ResultsController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {   
        $user = $request->user();
        $role = $user->role;
        
        $levels = Level::all();
        $subjects = Subject::all();
        $schools = School::all();
        
        // Get teachers based on role
        if ($role === 'teacher') {
            $teachers = Teacher::where('email', $user->email)->get();
            
            // Debug log to see if teacher is found
            \Log::info('Teacher lookup by email', [
                'user_email' => $user->email,
                'teachers_found' => $teachers->count(),
                'teacher_ids' => $teachers->pluck('id')->toArray()
            ]);
            
            // For teachers, get their classes directly
            $classes = $teachers->first() ? $teachers->first()->classes : collect();
            
            // Get the subject IDs that this teacher teaches
            $teacherSubjectIds = [];
            if ($teachers->first()) {
                $teacherSubjectIds = $teachers->first()->subjects->pluck('id')->toArray();
            }
        } elseif ($role === 'assistant') {
            $assistant = $user->assistant;
            $teachers = Teacher::whereHas('schools', function($query) use ($assistant) {
                $query->whereIn('schools.id', $assistant->schools->pluck('id'));
            })->get();
            $classes = collect();
            $teacherSubjectIds = [];
        } else {
            $teachers = Teacher::with('schools')->get();
            $classes = collect();
            $teacherSubjectIds = [];
        }
        
        // Get students and results if class is selected
        $students = collect();
        $results = collect();
        if ($request->has('class_id')) {
            $class = Classes::find($request->class_id);
            if ($class) {
                $students = $class->students;
                $results = Result::with(['student', 'subject'])
                    ->where('class_id', $request->class_id)
                    ->get()
                    ->groupBy('student_id');
            }
        }
        
        // Debug information
        \Log::info('Teachers count: ' . $teachers->count());
        \Log::info('Classes count: ' . $classes->count());
        
        return Inertia::render('Menu/ResultsPage', [
            'teachers' => $teachers,
            'classes' => $classes,
            'students' => $students,
            'results' => $results,
            'levels' => $levels,
            'subjects' => $subjects,
            'schools' => $schools,
            'role' => $role,
            'teacherSubjectIds' => $teacherSubjectIds
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $levels = Level::all();
        $classes = Classes::with('level')->get();
        $subjects = Subject::all();
        $schools = School::all();
        
        return Inertia::render('Menu/ResultsPage', [
            'levels' => $levels,
            'classes' => $classes,
            'subjects' => $subjects,
            'schools' => $schools
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Validate the request data
        $validatedData = $request->validate([
            'student_id' => 'required|exists:students,id',
            'subject_id' => 'required|exists:subjects,id',
            'class_id' => 'required|exists:classes,id',
            'grade1' => 'nullable|string|max:20',
            'grade2' => 'nullable|string|max:20',
            'grade3' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:255',
            'exam_date' => 'required|date',
        ]);

        // Create a new result
        $result = new Result($validatedData);
        
        // Calculate final grade based on the entered grades
        $result->calculateFinal();
        
        // Save the result
        $result->save();

        // Redirect to the results index page with a success message
        return redirect()->route('results.index')->with('success', 'Result added successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Result $result)
    {   
        $levels = Level::all();
        $classes = Classes::with('level')->get();
        $subjects = Subject::all();
        $schools = School::all();
        
        // Load the result with related data
        $result->load(['student', 'subject', 'class']);
        
        return Inertia::render('Menu/SingleResultPage', [
            'result' => $result,
            'levels' => $levels,
            'classes' => $classes,
            'subjects' => $subjects,
            'schools' => $schools
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Result $result)
    {
        $levels = Level::all();
        $classes = Classes::with('level')->get();
        $subjects = Subject::all();
        $schools = School::all();
        
        // Load the result with related data
        $result->load(['student', 'subject', 'class']);
        
        return Inertia::render('Menu/ResultsPage', [
            'result' => $result,
            'levels' => $levels,
            'classes' => $classes,
            'subjects' => $subjects,
            'schools' => $schools
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Result $result)
    {
        // Validate the request data
        $validatedData = $request->validate([
            'student_id' => 'required|exists:students,id',
            'subject_id' => 'required|exists:subjects,id',
            'class_id' => 'required|exists:classes,id',
            'grade1' => 'nullable|string|max:20',
            'grade2' => 'nullable|string|max:20',
            'grade3' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:255',
            'exam_date' => 'required|date',
        ]);

        // Update the result with the validated data
        $result->fill($validatedData);
        
        // Calculate final grade based on the entered grades
        $result->calculateFinal();
        
        // Save the updated result
        $result->save();

        // Redirect to the results index page with a success message
        return redirect()->route('results.index')->with('success', 'Result updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Result $result)
    {
        // Delete the result
        $result->delete();

        // Redirect to the results index page with a success message
        return redirect()->route('results.index')->with('success', 'Result deleted successfully.');
    }
    
    /**
     * Get classes by teacher.
     */
    public function getClassesByTeacher($teacher_id)
    {
        $teacher = Teacher::with(['classes.level', 'schools'])->find($teacher_id);
        
        if (!$teacher) {
            return response()->json([]);
        }
        
        $classes = $teacher->classes->map(function($class) use ($teacher) {
            $school = $teacher->schools->firstWhere('id', $class->school_id);
            return [
                'id' => $class->id,
                'name' => $class->name . ' - ' . ($school ? $school->name : ''),
                'level' => $class->level->name
            ];
        });
            
        return response()->json($classes);
    }
    
    /**
     * Get students by class.
     */
    public function getStudentsByClass($class_id)
    {
        \Log::info('Fetching students for class_id: ' . $class_id);
        
        if (!$class_id) {
            \Log::error('No class_id provided');
            return response()->json([]);
        }
        
        // Enable query logging
        \DB::enableQueryLog();
        
        $class = Classes::with('students')->find($class_id);
        
        // Log the executed queries
        $queries = \DB::getQueryLog();
        \Log::info('SQL Queries:', $queries);
        
        if (!$class) {
            \Log::error('Class not found for id: ' . $class_id);
            return response()->json([]);
        }
        
        \Log::info('Found class: ' . $class->name . ' with ' . $class->students->count() . ' students');
        
        // Debugging: Fetch students directly and log
        $directStudents = \DB::table('students')
            ->where('classId', $class_id)
            ->get();
        \Log::info('Direct query found ' . count($directStudents) . ' students');
        
        // Note: Student model uses firstName and lastName (camelCase) instead of first_name and last_name
        $students = $class->students->map(function($student) {
            return [
                'id' => $student->id,
                'first_name' => $student->firstName, // Map from camelCase to snake_case for frontend
                'last_name' => $student->lastName     // Map from camelCase to snake_case for frontend
            ];
        });
        
        \Log::info('Returning ' . count($students) . ' students');
            
        return response()->json($students);
    }
    
    /**
     * Get results by class.
     */
    public function getResultsByClass($class_id)
    {
        \Log::info('Fetching results for class_id: ' . $class_id);
        
        if (!$class_id) {
            \Log::error('No class_id provided');
            return response()->json([]);
        }
        
        // Enable query logging
        \DB::enableQueryLog();
        
        $results = Result::with(['student', 'subject'])
            ->where('class_id', $class_id)
            ->get();
        
        // Log the executed queries
        $queries = \DB::getQueryLog();
        \Log::info('Results SQL Queries:', $queries);
        
        \Log::info('Found ' . $results->count() . ' results for class ' . $class_id);
        
        $groupedResults = $results->groupBy('student_id');
        
        \Log::info('Returning results for ' . $groupedResults->count() . ' students');
            
        return response()->json($groupedResults);
    }
    
    /**
     * Get results by subject.
     */
    public function getResultsBySubject(Request $request)
    {
        $subjectId = $request->input('subject_id');
        
        $results = Result::with(['student', 'class'])
            ->where('subject_id', $subjectId)
            ->orderBy('exam_date', 'desc')
            ->get();
            
        return response()->json($results);
    }
    
    /**
     * Calculate grade based on score.
     */
    public function calculateGrade(Request $request)
    {
        $score = $request->input('score');
        
        $grade = 'F';
        if ($score >= 90) {
            $grade = 'A+';
        } elseif ($score >= 85) {
            $grade = 'A';
        } elseif ($score >= 80) {
            $grade = 'A-';
        } elseif ($score >= 75) {
            $grade = 'B+';
        } elseif ($score >= 70) {
            $grade = 'B';
        } elseif ($score >= 65) {
            $grade = 'B-';
        } elseif ($score >= 60) {
            $grade = 'C+';
        } elseif ($score >= 55) {
            $grade = 'C';
        } elseif ($score >= 50) {
            $grade = 'C-';
        } elseif ($score >= 45) {
            $grade = 'D+';
        } elseif ($score >= 40) {
            $grade = 'D';
        }
        
        return response()->json(['grade' => $grade]);
    }

    /**
     * Update grade for a specific result.
     */
    public function updateGrade(Request $request)
    {
        // Log the incoming request data
        \Log::info('Update grade request received:', $request->all());

        try {
            // Validate the request
            $validatedData = $request->validate([
                'student_id' => 'required|exists:students,id',
                'subject_id' => 'required|exists:subjects,id',
                'class_id' => 'required|exists:classes,id',
                'grade_field' => 'required|in:grade1,grade2,grade3,notes',
                'value' => 'required|string|max:20',
            ]);

            \Log::info('Validated data:', $validatedData);

            // Find existing result or create a new one
            $result = Result::firstOrNew([
                'student_id' => $validatedData['student_id'],
                'subject_id' => $validatedData['subject_id'],
                'class_id' => $validatedData['class_id'],
            ]);
            
            \Log::info('Result found or created:', [
                'exists' => $result->exists,
                'id' => $result->id,
                'current_values' => [
                    'grade1' => $result->grade1,
                    'grade2' => $result->grade2, 
                    'grade3' => $result->grade3,
                    'final_grade' => $result->final_grade,
                    'notes' => $result->notes
                ]
            ]);
            
            if (!$result->exists) {
                $result->exam_date = now();
                \Log::info('Setting exam_date for new result:', ['exam_date' => $result->exam_date]);
            }
            
            // Update the specified field
            $field = $validatedData['grade_field'];
            $value = $validatedData['value'];
            
            $oldValue = $result->$field;
            $result->$field = $value;
            
            \Log::info('Updated field value:', [
                'field' => $field,
                'old_value' => $oldValue,
                'new_value' => $value
            ]);
            
            // Recalculate final grade if a grade field was updated
            if ($field !== 'notes') {
                $oldFinalGrade = $result->final_grade;
                $result->calculateFinal();
                \Log::info('Recalculated final grade:', [
                    'old_final_grade' => $oldFinalGrade,
                    'new_final_grade' => $result->final_grade
                ]);
            }
            
            // Save the result
            try {
                $saveResult = $result->save();
                \Log::info('Result saved:', [
                    'success' => $saveResult, 
                    'id' => $result->id, 
                    'updated_values' => [
                        'grade1' => $result->grade1,
                        'grade2' => $result->grade2, 
                        'grade3' => $result->grade3,
                        'final_grade' => $result->final_grade,
                        'notes' => $result->notes
                    ]
                ]);
                
                // Return the updated result data
                return response()->json([
                    'success' => true,
                    'result' => $result,
                    'final_grade' => $result->final_grade,
                    'message' => 'Grade updated successfully'
                ]);
                
            } catch (\Exception $e) {
                \Log::error('Error saving result:', [
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to save grade: ' . $e->getMessage()
                ], 500);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            \Log::error('Validation failed:', [
                'errors' => $e->errors()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in updateGrade:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get subjects that a teacher teaches for a specific class
     */
    public function getSubjectsByTeacher($teacher_id, $class_id = null)
    {
        $teacher = Teacher::with('subjects')->find($teacher_id);
        
        if (!$teacher) {
            return response()->json([]);
        }
        
        $subjects = $teacher->subjects;
        
        if ($class_id) {
            // Filter results to only include subjects that have results for this class
            $subjectIds = Result::where('class_id', $class_id)->pluck('subject_id')->unique();
            $subjects = $subjects->whereIn('id', $subjectIds);
        }
            
        return response()->json($subjects);
    }
} 