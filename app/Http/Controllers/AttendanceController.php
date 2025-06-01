<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use App\Models\Attendance;
use App\Models\Classes;
use App\Models\Level;
use App\Models\Assistant;
use Illuminate\Support\Facades\Log;
use App\Models\Student;
use App\Models\School;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        // Get parameters from request
        $date = $request->input('date', now()->format('Y-m-d'));
        $classId = $request->input('class_id');
        $teacherId = $request->input('teacher_id');
        $search = $request->input('search');
        
        // Get the selected school from session
        $selectedSchoolId = session('school_id');
        
        // Get teachers with classes (filtered by school if applicable)
        $teachersQuery = Teacher::with('classes');
        
        if ($selectedSchoolId) {
            $teachersQuery->whereHas('schools', function($query) use ($selectedSchoolId) {
                $query->where('schools.id', $selectedSchoolId);
            });
            
            // Log the filtering
            \Log::info('Attendance filtered by school', [
                'school_id' => $selectedSchoolId,
                'user_role' => $request->user()->role
            ]);
        }
        
        // If user is a teacher, only show their own record
        if ($request->user()->role === 'teacher') {
            $teachersQuery->where('email', $request->user()->email);
        }
        
        $teachers = $teachersQuery->get();

        // Get classes for selected teacher (filtered by school if applicable)
        $classesQuery = Classes::query();
        
        if ($selectedSchoolId) {
            $classesQuery->where('school_id', $selectedSchoolId);
        }
        
        if ($teacherId) {
            $classesQuery->whereHas('teachers', fn($q) => $q->where('teacher_id', $teacherId));
        }
        
        $classes = $classesQuery->get();

        // Get students for selected class (with search filter)
        $studentsQuery = Student::with('class');
        
        if ($selectedSchoolId) {
            $studentsQuery->where('schoolId', $selectedSchoolId);
            \Log::debug('Filtering students by school ID', ['schoolId' => $selectedSchoolId]);
        }
        
        if ($classId) {
            $studentsQuery->where('classId', $classId);
            \Log::debug('Filtering students by class ID', ['classId' => $classId]);
            
            if ($search) {
                $studentsQuery->where(function ($query) use ($search) {
                    $query->where('firstName', 'like', "%{$search}%")
                        ->orWhere('lastName', 'like', "%{$search}%");
                });
                \Log::debug('Filtering students by search', ['search' => $search]);
            }
            
            $students = $studentsQuery->get();
            \Log::debug('Students found', ['count' => $students->count()]);
        } else {
            $students = collect();
            \Log::debug('No class ID provided, returning empty student collection');
        }

        // Get existing attendance for selected date and class
        $existingAttendances = $classId
            ? Attendance::with('class')
                ->where('classId', $classId)
                ->whereDate('date', $date)
                ->get()
                ->keyBy('student_id')
            : collect();

        // Merge students with attendance status and class info
        $studentsWithAttendance = $students->map(function ($student) use ($existingAttendances, $date) {
            $attendance = $existingAttendances->get($student->id);

            return [
                'id' => $attendance ? $attendance->id : null,
                'student_id' => $student->id,
                'firstName' => $student->firstName,
                'lastName' => $student->lastName,
                'status' => $attendance ? $attendance->status : 'present',
                'reason' => $attendance?->reason,
                'date' => $date,
                'classId' => $student->classId,
                'class' => $student->class,
                'exists_in_db' => (bool)$attendance
            ];
        });

        // Fetch assistants and levels data
        $assistants = Assistant::with('schools')->get();
        $levels = Level::all();

        return Inertia::render('Menu/AttendancePage', [
            'teachers' => $teachers->map(fn($teacher) => [
                'id' => $teacher->id, 
                'name' => $teacher->first_name . ' ' . $teacher->last_name,
                'first_name' => $teacher->first_name,
                'last_name' => $teacher->last_name,
                'email' => $teacher->email,
                'schools' => $teacher->schools,
                'classes' => $teacher->classes
            ]),
            'classes' => $classes->map(fn($class) => [
                'id' => $class->id, 
                'name' => $class->name,
                'level_id' => $class->level_id,
                'school_id' => $class->school_id
            ]),
            'students' => $studentsWithAttendance,
            'filters' => [
                'date' => $date,
                'teacher_id' => $teacherId,
                'class_id' => $classId,
                'search' => $search,
            ],
            'selectedSchool' => $selectedSchoolId ? [
                'id' => $selectedSchoolId,
                'name' => session('school_name')
            ] : null,
            'assistants' => $assistants->map(fn($assistant) => [
                'id' => $assistant->id,
                'first_name' => $assistant->first_name,
                'last_name' => $assistant->last_name,
                'email' => $assistant->email,
                'schools' => $assistant->schools
            ]),
            'levels' => $levels,
            'schools' => School::all()
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'attendances' => 'required|array|min:1',
            'attendances.*.student_id' => 'required|exists:students,id',
            'attendances.*.status' => 'required|in:present,absent,late',
            'attendances.*.reason' => 'nullable|string|max:255',
            'date' => 'required|date',
            'class_id' => 'required|exists:classes,id',
            'teacher_id' => 'nullable|exists:teachers,id',
        ]);
 
        try {
            DB::beginTransaction();

            $filtered = collect($validated['attendances'])->filter(fn($att) => $att['status'] !== 'present');
            
            // Track which students have records
            $processedStudentIds = [];

            foreach ($filtered as $attendance) {
                $studentId = $attendance['student_id'];
                $processedStudentIds[] = $studentId;
                
                // Add classId condition
                $attendanceModel = Attendance::where('student_id', $studentId)
                    ->where('date', $validated['date'])
                    ->where('classId', $validated['class_id'])
                    ->first();
                    
                if ($attendanceModel) {
                    // Update existing record
                    $attendanceModel->update([
                        'status' => $attendance['status'],
                        'reason' => $attendance['reason'],
                        'recorded_by' => auth()->id()
                    ]);
                    
                    \Log::info('Updated existing attendance record', [
                        'student_id' => $studentId,
                        'status' => $attendance['status']
                    ]);
                } else {
                    // Create new record
                    Attendance::create([
                        'student_id' => $studentId,
                        'date' => $validated['date'],
                        'classId' => $validated['class_id'],
                        'status' => $attendance['status'],
                        'reason' => $attendance['reason'],
                        'recorded_by' => auth()->id()
                    ]);
                    
                    \Log::info('Created new attendance record', [
                        'student_id' => $studentId,
                        'status' => $attendance['status']
                    ]);
                }
            }

            // Remove any existing present records
            Attendance::where('classId', $validated['class_id'])
                ->whereDate('date', $validated['date'])
                ->where('status', 'present')
                ->delete();

            DB::commit();

            // Log summary of what was processed
            \Log::info('Attendance saved', [
                'class_id' => $validated['class_id'],
                'date' => $validated['date'],
                'student_count' => count($validated['attendances']),
                'non_present_count' => $filtered->count(),
                'processed_student_ids' => $processedStudentIds
            ]);
            
            // Verify students can be retrieved for this class
            $studentCount = Student::where('classId', $validated['class_id'])->count();
            \Log::info('Found students for this class', [
                'class_id' => $validated['class_id'],
                'student_count' => $studentCount
            ]);

            // Get the teacher_id, either from validation or request
            $teacherId = $validated['teacher_id'] ?? $request->input('teacher_id');

            // Redirect with explicit parameters to ensure data is properly loaded
            return redirect()->route('attendances.index', [
                'date' => $validated['date'],
                'class_id' => $validated['class_id'],
                'teacher_id' => $teacherId,
                '_timestamp' => time() // Add timestamp to prevent caching
            ])->with('success', 'Attendance saved successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Error saving attendance', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return redirect()->back()->with('error', 'Error saving attendance: ' . $e->getMessage());
        }
    }

    public function show($id)
    {
        try {
            // Fetch the specific attendance record
            $attendance = Attendance::with(['student', 'class', 'recordedBy'])
                ->findOrFail($id);
    
            // Fetch all attendance records for the student
            $studentAttendances = Attendance::with(['class', 'recordedBy'])
                ->where('student_id', $attendance->student_id)
                ->latest()
                ->paginate(10);
    
            // Make sure we're returning the properly structured data
            return Inertia::render('Menu/SingleRecord', [
                'attendance' => [
                    'id' => $attendance->id,
                    'student' => [
                        'id' => $attendance->student->id,
                        'firstName' => $attendance->student->firstName,
                        'lastName' => $attendance->student->lastName,
                    ],
                    'class' => [
                        'id' => $attendance->class->id,
                        'name' => $attendance->class->name,
                    ],
                    'status' => $attendance->status,
                    'reason' => $attendance->reason,
                    'date' => $attendance->date,
                    'recordedBy' => $attendance->recordedBy ? [
                        'id' => $attendance->recordedBy->id,
                        'name' => $attendance->recordedBy->name,
                        'role' => $attendance->recordedBy->role,
                    ] : null,
                ],
                'studentAttendances' => $studentAttendances,
            ]);
        } catch (\Exception $e) {
            // Log the error
            \Log::error('Error showing attendance record', [
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Redirect with error message
            return redirect()->route('attendances.index')->with('error', 'Error viewing attendance record: ' . $e->getMessage());
        }
    }


    public function update(Request $request, $id)
    {
        // Validate the request data
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'status' => 'required|in:present,absent,late',
            'reason' => 'nullable|string|max:255',
            'date' => 'required|date',
            'class_id' => 'required|exists:classes,id',
        ]);
    
        try {
            // Find the attendance record
            $attendance = Attendance::findOrFail($id);
    
            // Capture old data before update
            $oldData = $attendance->toArray();
    
            // If status is "present", delete the record
            if ($validated['status'] === 'present') {
                $attendance->delete();
                $this->logActivity('deleted', $attendance, $oldData, null);
                return redirect()->back()->with('success', 'Attendance record removed (marked as present)');
            }
    
            // Update the record for "absent" or "late"
            $attendance->update([
                'student_id' => $validated['student_id'],
                'classId' => $validated['class_id'],
                'status' => $validated['status'],
                'reason' => $validated['status'] !== 'present' ? $validated['reason'] : null,
                'date' => $validated['date'],
                'recorded_by' => auth()->id(),
            ]);
    
            // Log the activity for the updated record
            $this->logActivity('updated', $attendance, $oldData, $attendance->toArray());
    
            return redirect()->back()->with('success', 'Attendance record updated successfully');
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error('Error updating attendance record:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
    
            return redirect()->back()->with('error', 'Failed to update attendance record: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        try {
            // Find the attendance record
            $attendance = Attendance::findOrFail($id);

            // Log the activity before deletion
            $this->logActivity('deleted', $attendance, $attendance->toArray(), null);

            // Delete the record
            $attendance->delete();

            return redirect()->back()->with('success', 'Attendance record deleted successfully');
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error('Error deleting attendance record:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->back()->with('error', 'Failed to delete attendance record: ' . $e->getMessage());
        }
    }

    /**
     * Log activity for a model.
     */
    protected function logActivity($action, $model, $oldData = null, $newData = null)
{
    $description = ucfirst($action) . ' ' . class_basename($model) . ' (' . $model->id . ')';
    $tableName = $model->getTable();

    $properties = [
        'TargetName' => $model->student->firstName . ' ' . $model->student->lastName,
        'action' => $action,
        'table' => $tableName,
        'user' => auth()->user()->name,
    ];

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

    if ($action === 'deleted') {
        $properties['deleted_data'] = [
            'student_id' => $oldData['student_id'],
            'classId' => $oldData['classId'],
            'status' => $oldData['status'],
            'date' => $oldData['date'],
        ];
    }

    activity()
        ->causedBy(auth()->user())
        ->performedOn($model)
        ->withProperties($properties)
        ->log($description);
}

    public function getStats(Request $request)
    {
        $selectedSchoolId = session('school_id');
        $lastWeek = now()->subWeek();
        $today = now();

        // First get total active students for the school
        $studentsQuery = \App\Models\Student::where('status', 'active')
            ->when($selectedSchoolId, function ($q) use ($selectedSchoolId) {
                $q->where('schoolId', $selectedSchoolId);
            });
        
        $totalActiveStudents = $studentsQuery->count();

        // Query builder for attendance records from the last week
        $query = Attendance::query()
            ->whereBetween('date', [$lastWeek, $today])
            ->when($selectedSchoolId, function ($q) use ($selectedSchoolId) {
                $q->whereHas('class', function($query) use ($selectedSchoolId) {
                    $query->where('school_id', $selectedSchoolId);
                });
            });

        // Get stats by day of week
        $stats = $query->get()
            ->groupBy(function($record) {
                return \Carbon\Carbon::parse($record->date)->format('D'); // Returns Mon, Tue, etc.
            })
            ->map(function($records) use ($totalActiveStudents) {
                $absentCount = $records->where('status', 'absent')->count();
                $lateCount = $records->where('status', 'late')->count();
                
                return [
                    'present' => $totalActiveStudents - ($absentCount + $lateCount),
                    'absent' => $absentCount,
                    'late' => $lateCount,
                    'total' => $totalActiveStudents
                ];
            })
            ->map(function($stats, $day) {
                return array_merge(['name' => $day], $stats);
            })
            ->values();

        return response()->json($stats);
    }
}