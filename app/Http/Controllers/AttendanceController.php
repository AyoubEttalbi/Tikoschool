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
use Carbon\Carbon;
use WasenderApi\Facades\WasenderApi;

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
            Log::info('Attendance filtered by school', [
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
            Log::debug('Filtering students by school ID', ['schoolId' => $selectedSchoolId]);
        }
        
        if ($classId) {
            $studentsQuery->where('classId', $classId);
            Log::debug('Filtering students by class ID', ['classId' => $classId]);
            
            if ($search) {
                $studentsQuery->where(function ($query) use ($search) {
                    $query->where('firstName', 'like', "%{$search}%")
                        ->orWhere('lastName', 'like', "%{$search}%");
                });
                Log::debug('Filtering students by search', ['search' => $search]);
            }
            
            $students = $studentsQuery->get();
            Log::debug('Students found', ['count' => $students->count()]);
        } else {
            $students = collect();
            Log::debug('No class ID provided, returning empty student collection');
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
                    
                    Log::info('Updated existing attendance record', [
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
                    
                    Log::info('Created new attendance record', [
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
            Log::info('Attendance saved', [
                'class_id' => $validated['class_id'],
                'date' => $validated['date'],
                'student_count' => count($validated['attendances']),
                'non_present_count' => $filtered->count(),
                'processed_student_ids' => $processedStudentIds
            ]);
            
            // Verify students can be retrieved for this class
            $studentCount = Student::where('classId', $validated['class_id'])->count();
            Log::info('Found students for this class', [
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
            Log::error('Error saving attendance', [
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
            Log::error('Error showing attendance record', [
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
        // Accept date range and school_id from request
        $startDate = $request->input('start_date') ?? now()->subMonth()->toDateString();
        $endDate = $request->input('end_date') ?? now()->toDateString();
        $schoolId = $request->input('school_id');

        // Limit range to 90 days for performance
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);
        if ($end->diffInDays($start) > 90) {
            $start = $end->copy()->subDays(90);
        }

        // Query attendance, join classes for school filter
        $query = DB::table('attendances')
            ->join('classes', 'attendances.classId', '=', 'classes.id')
            ->select(
                DB::raw('DATE(attendances.date) as date'),
                'attendances.status',
                DB::raw('COUNT(*) as count')
            )
            ->whereBetween('attendances.date', [$start, $end]);
        if ($schoolId && $schoolId !== 'all') {
            $query->where('classes.school_id', $schoolId);
        }
        $rows = $query->groupBy('date', 'attendances.status')->orderBy('date')->get();

        // Pivot to chart-friendly format
        $dates = $rows->pluck('date')->unique()->sort()->values();
        $result = $dates->map(function($date) use ($rows) {
            $statuses = ['present' => 0, 'absent' => 0, 'late' => 0];
            foreach ($rows->where('date', $date) as $row) {
                $statuses[$row->status] = $row->count;
            }
            return array_merge(['date' => $date], $statuses);
        });

        return response()->json($result);
    }

    /**
     * Show the Absence Log page (admin/assistant only)
     */
    public function absenceLogPage(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'assistant'])) {
            abort(403);
        }
        return Inertia::render('Menu/AbsenceLog');
    }

    /**
     * Return paginated absences/lates, filterable by date/range (admin/assistant only)
     */
    public function absenceLogData(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'assistant'])) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $query = Attendance::with(['student', 'class', 'recordedBy'])
            ->whereIn('status', ['absent', 'late']);

        // Date filtering: always filter by date, default to today if not provided
        $date = $request->input('date', now()->toDateString());
        $query->whereDate('date', $date);

        // Optional: class or student filter
        if ($request->filled('class_id')) {
            $query->where('classId', $request->input('class_id'));
        }
        if ($request->filled('student_id')) {
            $query->where('student_id', $request->input('student_id'));
        }

        $perPage = $request->input('per_page', 20);
        $absences = $query->orderByDesc('date')->paginate($perPage);

        // Format for frontend
        $data = $absences->through(function($attendance) {
            return [
                'id' => $attendance->id,
                'student_id' => $attendance->student ? $attendance->student->id : null,
                'student_name' => $attendance->student ? $attendance->student->firstName . ' ' . $attendance->student->lastName : 'Unknown',
                'class_id' => $attendance->class ? $attendance->class->id : null,
                'class_name' => $attendance->class ? $attendance->class->name : 'Unknown',
                'date' => $attendance->date,
                'status' => $attendance->status,
                'reason' => $attendance->reason,
                'recorded_by_name' => $attendance->recordedBy ? $attendance->recordedBy->name : '-',
            ];
        });

        return response()->json([
            'data' => $data,
            'current_page' => $absences->currentPage(),
            'last_page' => $absences->lastPage(),
            'per_page' => $absences->perPage(),
            'total' => $absences->total(),
        ]);
    }

    public function notifyParent($studentId)
    {
        $student = Student::findOrFail($studentId);
        // Use guardianNumber as the parent's phone number
        $fatherPhone = $student->guardianNumber;
        if (empty($fatherPhone)) {
            return back()->with('error', "Le numéro de téléphone du tuteur n'est pas renseigné.");
        }
        $studentName = trim($student->firstName . ' ' . $student->lastName);
        WasenderApi::sendText($fatherPhone, "Bonjour, votre enfant {$studentName} est absent aujourd’hui.");
        return back()->with('success', 'WhatsApp message envoyé au parent.');
    }

    /**
     * Télécharger la liste de présence PDF pour une classe et un enseignant
     */
    public function downloadAbsenceList(Request $request)
    {
        $request->validate([
            'teacher_id' => 'required|exists:teachers,id',
            'class_id' => 'required|exists:classes,id',
            'date' => 'nullable|date',
        ]);
        $teacher = \App\Models\Teacher::findOrFail($request->teacher_id);
        $class = \App\Models\Classes::with('level')->findOrFail($request->class_id);
        $students = $class->students()->orderBy('lastName')->get();
        $date = $request->input('date', now()->format('d/m/Y'));
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('absence_list_pdf', [
            'teacher' => $teacher,
            'class' => $class,
            'students' => $students,
            'date' => $date,
        ])->setPaper('A4', 'landscape');
        $filename = 'Liste-absence-' . $class->name . '-' . $teacher->last_name . '-' . now()->format('Ymd_His') . '.pdf';
        return $pdf->download($filename);
    }

    /**
     * Page de sélection pour la liste de présence (frontend)
     */
    public function absenceListPage()
    {
        $teachers = \App\Models\Teacher::select('id', 'first_name', 'last_name')->get();
        $classes = \App\Models\Classes::with(['teachers:id,first_name,last_name'])->get()->map(function($class) {
            return [
                'id' => $class->id,
                'name' => $class->name,
                'teachers' => $class->teachers->map(function($t) {
                    return [
                        'id' => $t->id,
                        'first_name' => $t->first_name,
                        'last_name' => $t->last_name,
                    ];
                })->toArray(),
            ];
        });
        return \Inertia\Inertia::render('Menu/AbsenceListPage', [
            'teachers' => $teachers,
            'classes' => $classes,
        ]);
    }
}