<?php

namespace App\Http\Controllers;

use App\Models\Assistant;
use App\Models\Attendance;
use App\Models\Classes;
use App\Models\Level;
use App\Models\OutboundMessage;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\OutboundMessageService;
use App\Support\PdfBudget;
use App\Support\SchoolScope;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Spatie\Activitylog\Models\Activity;

class AttendanceController extends Controller
{
    /**
     * Rows this sheet may carry. The absence grid is the most expensive document in the
     * app at ~0.75 MB per student (35 cells wide), so it gets the tightest guard; the
     * measurements and the reasoning behind the ceiling live in App\Support\PdfBudget.
     * 300 sits well under what PdfBudget::LIMIT covers and far above any real class.
     */
    private const PDF_MAX_STUDENTS = 300;

    /** Scope an existing attendance row by its student and class. */
    private function authorizeAttendance(Attendance $attendance): void
    {
        if ($attendance->student) {
            SchoolScope::authorizeStudent($attendance->student);
        }

        if ($attendance->class) {
            SchoolScope::authorizeClass($attendance->class);
        }
    }

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
            $teachersQuery->whereHas('schools', function ($query) use ($selectedSchoolId) {
                $query->where('schools.id', $selectedSchoolId);
            });

            // Log the filtering
            Log::info('Attendance filtered by school', [
                'school_id' => $selectedSchoolId,
                'user_role' => $request->user()->role,
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

        // For teachers, show only their classes
        if ($request->user()->role === 'teacher') {
            $teacher = Teacher::where('email', $request->user()->email)->first();
            if ($teacher) {
                $classesQuery->whereHas('teachers', fn ($q) => $q->where('teacher_id', $teacher->id));
            }
        } elseif ($teacherId) {
            // For admins/assistants, use the teacher_id from request
            $classesQuery->whereHas('teachers', fn ($q) => $q->where('teacher_id', $teacherId));
        }

        $classes = $classesQuery->get();

        // Get students for selected class (with search filter).
        // `memberships` is eager-loaded here because this roster is walked three separate
        // times below; each pass used the relation METHOD ($student->memberships()->get()),
        // which bypasses eager loading entirely and issued one query per student per pass.
        $studentsQuery = Student::with(['class', 'memberships'])->where('status', 'active');

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

        // Merge students with attendance status and class info
        $currentTeacherId = null;
        if ($request->user()->role === 'teacher') {
            // For teachers, get their ID from the teacher record
            $teacher = Teacher::where('email', $request->user()->email)->first();
            $currentTeacherId = $teacher ? $teacher->id : null;

            // Verify that the teacher_id from request matches the logged-in teacher
            $requestedTeacherId = $request->input('teacher_id');
            if ($requestedTeacherId && $requestedTeacherId != $currentTeacherId) {
                Log::warning('Teacher ID mismatch', [
                    'logged_in_teacher_id' => $currentTeacherId,
                    'requested_teacher_id' => $requestedTeacherId,
                    'user_email' => $request->user()->email,
                ]);
                // Use the logged-in teacher's ID for security
                $currentTeacherId = $teacher ? $teacher->id : null;
            }

            Log::info('Teacher login attendance access', [
                'user_email' => $request->user()->email,
                'teacher_found' => (bool) $teacher,
                'teacher_id' => $currentTeacherId,
                'requested_teacher_id' => $requestedTeacherId,
                'class_id' => $classId,
            ]);
        } else {
            // For admins/assistants, use the teacher_id from request
            $currentTeacherId = $request->input('teacher_id');
        }

        // Get existing attendance for selected date and class
        $existingAttendances = $classId
            ? Attendance::with('class')
                ->where('classId', $classId)
                ->whereDate('date', $date)
                ->when($currentTeacherId, function ($query) use ($currentTeacherId) {
                    return $query->where('teacher_id', $currentTeacherId);
                })
                ->get()
                ->groupBy(function ($att) {
                    return $att->student_id.'|'.$att->teacher_id.'|'.$att->subject;
                })
            : collect();

        // Resolve every "recorded by" name in ONE query, keyed by id.
        // The roster loop below reads this map instead of calling User::find() per student.
        $recordedByNames = $existingAttendances
            ->flatten()
            ->pluck('recorded_by')
            ->filter()
            ->unique();

        $recordedByNames = $recordedByNames->isEmpty()
            ? []
            : \App\Models\User::whereIn('id', $recordedByNames)->pluck('name', 'id')->all();

        $selectedSubject = $request->input('subject');

        // Debug: Log all attendance keys available for this request (after $existingAttendances is defined)
        if (config('app.debug')) {
            $attendanceKeys = $existingAttendances->keys();
            Log::info('Attendance lookup debug', [
                'teacher_id' => $currentTeacherId,
                'subject' => $selectedSubject,
                'attendance_keys' => $attendanceKeys,
            ]);
        }

        // Filter students to only include those taught by the current teacher
        $filteredStudents = $students->filter(function ($student) use ($currentTeacherId) {
            if (! $currentTeacherId) {
                Log::debug('No current teacher ID, skipping student', [
                    'student_id' => $student->id,
                    'student_name' => $student->firstName.' '.$student->lastName,
                ]);

                return false;
            }

            // Include all memberships regardless of membership active status; rely on student status instead
            $memberships = $student->memberships; // property form: uses the eager-loaded relation
            Log::debug('Checking student memberships', [
                'student_id' => $student->id,
                'student_name' => $student->firstName.' '.$student->lastName,
                'memberships_count' => $memberships->count(),
                'teacher_id' => $currentTeacherId,
            ]);

            foreach ($memberships as $membership) {
                $teacherArr = is_array($membership->teachers)
                    ? $membership->teachers
                    : json_decode($membership->teachers, true);
                if (is_array($teacherArr)) {
                    foreach ($teacherArr as $t) {
                        if ((string) ($t['teacherId'] ?? null) === (string) $currentTeacherId) {
                            Log::debug('Student is taught by teacher', [
                                'student_id' => $student->id,
                                'student_name' => $student->firstName.' '.$student->lastName,
                                'teacher_id' => $currentTeacherId,
                                'membership_id' => $membership->id,
                                'teacher_data' => $t,
                            ]);

                            return true; // Student is taught by this teacher
                        }
                    }
                }
            }
            Log::debug('Student is NOT taught by teacher', [
                'student_id' => $student->id,
                'student_name' => $student->firstName.' '.$student->lastName,
                'teacher_id' => $currentTeacherId,
                'memberships_data' => $memberships->map(function ($m) {
                    return [
                        'id' => $m->id,
                        'teachers' => $m->teachers,
                    ];
                }),
            ]);

            return false; // Student is not taught by this teacher
        });

        Log::info('Student filtering results', [
            'total_students_in_class' => $students->count(),
            'filtered_students_by_teacher' => $filteredStudents->count(),
            'teacher_id' => $currentTeacherId,
            'class_id' => $classId,
            'user_role' => $request->user()->role,
            'user_email' => $request->user()->email,
        ]);

        $studentsWithAttendance = $filteredStudents->map(function ($student) use ($existingAttendances, $date, $currentTeacherId, $selectedSubject, $recordedByNames) {
            // Debug: Log the attendance key being looked up for each student
            $attendanceKey = $student->id.'|'.$currentTeacherId.'|'.$selectedSubject;
            if (config('app.debug')) {
                Log::info('Attendance lookup for student', [
                    'student_id' => $student->id,
                    'attendance_key' => $attendanceKey,
                    'selected_subject' => $selectedSubject,
                ]);
            }

            // Try to find attendance for this specific teacher and subject
            $attendance = null;
            if ($selectedSubject) {
                $attendanceList = $existingAttendances[$attendanceKey] ?? collect();
                $attendance = $attendanceList->first();
            } else {
                // If no subject selected, find any attendance for this student/teacher combination
                $attendance = $existingAttendances->filter(function ($attendanceList) use ($student, $currentTeacherId) {
                    return $attendanceList->first() &&
                           $attendanceList->first()->student_id == $student->id &&
                           $attendanceList->first()->teacher_id == $currentTeacherId;
                })->first()?->first();
            }
            // $recordedByNames is resolved once before this loop — User::find() here was
            // one query per student on every roster render.
            $recordedByName = null;
            if ($attendance && $attendance->recorded_by) {
                $recordedByName = $recordedByNames[$attendance->recorded_by] ?? null;
            }
            // Get all subjects for this student/teacher
            $memberships = $student->memberships; // property form: uses the eager-loaded relation
            $subjects = collect();
            foreach ($memberships as $membership) {
                $teacherArr = is_array($membership->teachers)
                    ? $membership->teachers
                    : json_decode($membership->teachers, true);
                if (is_array($teacherArr)) {
                    foreach ($teacherArr as $t) {
                        if ((string) ($t['teacherId'] ?? null) === (string) $currentTeacherId && ! empty($t['subject'])) {
                            $subjects->push($t['subject']);
                        }
                    }
                }
            }
            $subjects = $subjects->unique()->values()->all();

            // Debug logging for attendance status
            if (config('app.debug')) {
                Log::info('Student attendance data', [
                    'student_id' => $student->id,
                    'student_name' => $student->firstName.' '.$student->lastName,
                    'attendance_found' => (bool) $attendance,
                    'attendance_status' => $attendance ? $attendance->status : 'none',
                    'attendance_reason' => $attendance?->reason,
                    'final_status' => $attendance ? $attendance->status : 'present',
                ]);
            }

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
                'exists_in_db' => (bool) $attendance,
                'recorded_by_name' => $recordedByName,
                'subjects' => $subjects,
                'teacher_id' => $currentTeacherId, // Add teacher_id to student data
            ];
        })->values();

        // Fetch assistants and levels data
        $assistants = Assistant::with('schools')->get();
        $levels = Level::all();

        // Collect all subjects for the selected class and teacher, regardless of filter
        $allSubjects = collect();
        if ($classId && $teacherId) {
            // ->with('memberships'): $student->memberships is read in the loop below, and
            // without eager loading that lazy-loads once per student — the exact N+1 the
            // comment at the top of this method says was already fixed for the OTHER
            // student query. Only one of the two queries was actually changed.
            $classStudents = Student::with('memberships')
                ->where('classId', $classId)
                ->where('status', 'active')
                ->get();
            foreach ($classStudents as $student) {
                $memberships = $student->memberships; // property form: uses the eager-loaded relation
                foreach ($memberships as $membership) {
                    $teacherArr = is_array($membership->teachers)
                        ? $membership->teachers
                        : json_decode($membership->teachers, true);
                    if (is_array($teacherArr)) {
                        foreach ($teacherArr as $t) {
                            if ((string) ($t['teacherId'] ?? null) === (string) $teacherId && ! empty($t['subject'])) {
                                $allSubjects->push($t['subject']);
                            }
                        }
                    }
                }
            }
            $allSubjects = $allSubjects->unique()->values()->all();
        }

        return Inertia::render('Menu/AttendancePage', [
            'teachers' => $teachers->map(fn ($teacher) => [
                'id' => $teacher->id,
                'name' => $teacher->first_name.' '.$teacher->last_name,
                'first_name' => $teacher->first_name,
                'last_name' => $teacher->last_name,
                'email' => $teacher->email,
                'schools' => $teacher->schools,
                'classes' => $teacher->classes,
            ]),
            'classes' => $classes->map(fn ($class) => [
                'id' => $class->id,
                'name' => $class->name,
                'level_id' => $class->level_id,
                'school_id' => $class->school_id,
            ]),
            'students' => $studentsWithAttendance,
            'allSubjects' => $allSubjects,
            'filters' => [
                'date' => $date,
                'teacher_id' => $teacherId,
                'class_id' => $classId,
                'search' => $search,
                'subject' => $selectedSubject,
            ],
            'selectedSchool' => $selectedSchoolId ? [
                'id' => $selectedSchoolId,
                'name' => session('school_name'),
            ] : null,
            'assistants' => $assistants->map(fn ($assistant) => [
                'id' => $assistant->id,
                'first_name' => $assistant->first_name,
                'last_name' => $assistant->last_name,
                'email' => $assistant->email,
                'schools' => $assistant->schools,
            ]),
            'levels' => $levels,
            'schools' => School::all(),
        ]);
    }

    public function store(Request $request, OutboundMessageService $messages)
    {
        $validated = $request->validate([
            'attendances' => 'required|array|min:1',
            'attendances.*.student_id' => 'required|exists:students,id',
            'attendances.*.status' => 'required|in:present,absent,late',
            'attendances.*.reason' => 'nullable|string|max:255',
            'attendances.*.subject' => 'required|string|max:255',
            'date' => 'required|date',
            'class_id' => 'required|exists:classes,id',
            'teacher_id' => 'required|exists:teachers,id',
        ]);

        // Attendance IS a teacher surface, so the route stays open to all three roles —
        // which makes the object-level check the only thing constraining a teacher to
        // their own classes. `exists:` proves the class is real, not that it is theirs.
        SchoolScope::authorizeClass(Classes::findOrFail($validated['class_id']));

        /*
         * Authorising the CLASS was not enough.
         *
         * Every attendances.*.student_id is validated with `exists:students,id`, which
         * proves a student exists somewhere in the product — not that the caller may touch
         * them. A teacher or assistant scoped to school A could put a school-B student_id
         * into the same POST that saves their own class sheet, and the loop below would
         * write a fabricated absence for that child AND send a real WhatsApp message,
         * naming them, to their guardian's actual phone.
         *
         * update() in this same controller already does this per student; store() simply
         * never did. Checked here, before the transaction opens, so a denial cannot leave
         * half a sheet written — and note SchoolScope throws AccessDeniedException, which
         * extends \Error precisely so the `catch (\Exception)` below cannot swallow it.
         */
        $students = Student::whereIn('id', collect($validated['attendances'])->pluck('student_id'))
            ->get()
            ->keyBy('id');

        foreach ($students as $student) {
            SchoolScope::authorizeStudent($student);
        }

        try {
            DB::beginTransaction();

            // Process ALL attendance records, including present ones
            $processedStudentIds = [];
            $noticesRecorded = 0;

            // Always get teacher_id from validated or request
            $teacherId = $validated['teacher_id'] ?? $request->input('teacher_id');

            foreach ($validated['attendances'] as $attendance) {
                $studentId = $attendance['student_id'];
                $processedStudentIds[] = $studentId;

                $subjectName = $attendance['subject'] ?? null;
                $teacherIdForRecord = $attendance['teacher_id'] ?? $teacherId;

                // If subject is missing, try to get the only available subject for this student/teacher/class
                if (empty($subjectName)) {
                    $student = \App\Models\Student::find($studentId);
                    $memberships = $student ? $student->memberships()->where('is_active', 1)->get() : collect();
                    $subjects = collect();
                    foreach ($memberships as $membership) {
                        $teacherArr = is_array($membership->teachers)
                            ? $membership->teachers
                            : json_decode($membership->teachers, true);
                        if (is_array($teacherArr)) {
                            foreach ($teacherArr as $t) {
                                if ((string) ($t['teacherId'] ?? null) === (string) $teacherIdForRecord && ! empty($t['subject'])) {
                                    $subjects->push($t['subject']);
                                }
                            }
                        }
                    }
                    $subjects = $subjects->unique()->values();
                    if ($subjects->count() === 1) {
                        $subjectName = $subjects->first();
                    }
                }

                // Fetch attendance for this student, teacher, subject, class, and date
                $attendanceModel = Attendance::where('student_id', $studentId)
                    ->where('date', $validated['date'])
                    ->where('classId', $validated['class_id'])
                    ->where('teacher_id', $teacherIdForRecord)
                    ->where('subject', $subjectName)
                    ->first();

                if ($attendance['status'] === 'present') {
                    // If status is present and a record exists, delete it
                    if ($attendanceModel) {
                        // The waiting notice, if any, described an absence this save now
                        // declares never happened. Withdrawn before the row goes — after
                        // the delete the FK would orphan it to a null attendance_id and
                        // "release everything for the day" would happily send it anyway.
                        $messages->cancelForAttendance($attendanceModel->id);

                        $attendanceModel->delete();
                        Log::info('Deleted attendance record (marked as present)', [
                            'student_id' => $studentId,
                            'teacher_id' => $teacherIdForRecord,
                            'subject' => $subjectName,
                        ]);
                    }

                    // Do not create a record for present
                    continue;
                }

                // Only absent or late are recorded
                if ($attendanceModel) {
                    // absent → late: the absence the waiting notice describes has been
                    // downgraded by the person re-saving the sheet. It is not "held', it
                    // is wrong — withdraw it (a no-op when no notice is waiting).
                    if ($attendanceModel->status === 'absent' && $attendance['status'] !== 'absent') {
                        $messages->cancelForAttendance($attendanceModel->id);
                    }

                    $attendanceModel->update([
                        'status' => $attendance['status'],
                        'reason' => $attendance['reason'],
                        'recorded_by' => Auth::id(),
                        'teacher_id' => $teacherIdForRecord,
                        'subject' => $subjectName,
                    ]);
                    Log::info('Updated existing attendance record', [
                        'student_id' => $studentId,
                        'status' => $attendance['status'],
                        'teacher_id' => $teacherIdForRecord,
                        'subject' => $subjectName,
                    ]);
                } else {
                    // Logged like every other creation: the assistant profile's
                    // activity journal reads these to show who recorded which
                    // absence. A logging hiccup must never roll back the sheet
                    // itself — this sits inside the save transaction.
                    $created = Attendance::create([
                        'student_id' => $studentId,
                        'date' => $validated['date'],
                        'classId' => $validated['class_id'],
                        'status' => $attendance['status'],
                        'reason' => $attendance['reason'],
                        'recorded_by' => Auth::id(),
                        'teacher_id' => $teacherIdForRecord,
                        'subject' => $subjectName,
                    ]);

                    try {
                        $this->logActivity('created', $created);
                    } catch (\Throwable $logError) {
                        Log::warning('Attendance creation logging failed (sheet saved anyway)', [
                            'attendance_id' => $created->id,
                            'error' => $logError->getMessage(),
                        ]);
                    }

                    Log::info('Created new attendance record', [
                        'student_id' => $studentId,
                        'status' => $attendance['status'],
                        'teacher_id' => $teacherIdForRecord,
                        'subject' => $subjectName,
                    ]);
                }

                if ($attendance['status'] === 'absent') {
                    /*
                     * This block used to build a ~25-line Arabic message inline and
                     * dispatch it, and it sits OUTSIDE the create/update branch above —
                     * so re-saving a class sheet to fix a single typo re-sent a WhatsApp
                     * message to every absent student's parent in that class. The service
                     * keys the notice on the attendance row's id, which is stable across
                     * re-saves, so the second save now creates nothing.
                     *
                     * It also dropped students with no guardian number into a Log::warning
                     * nobody reads. Those are recorded as skipped rows now.
                     *
                     * AWAITING APPROVAL: the notice is recorded, not sent. One wrong
                     * checkbox on this screen used to reach a real parent within seconds,
                     * from the school's own number; now an admin or assistant releases
                     * the day's notices from the absence log once they are satisfied the
                     * sheet is right.
                     */
                    $record = Attendance::where('student_id', $studentId)
                        ->where('classId', $validated['class_id'])
                        ->whereDate('date', $validated['date'])
                        ->where('teacher_id', $teacherIdForRecord)
                        ->where('subject', $subjectName)
                        ->first();

                    if ($record) {
                        $noticesRecorded += $messages->createForAbsence($record, awaitApproval: true) !== null;
                    }
                }
            }

            // Remove the problematic line that deletes present records
            // Attendance::where('classId', $validated['class_id'])
            //     ->whereDate('date', $validated['date'])
            //     ->where('status', 'present')
            //     ->delete();

            DB::commit();

            Log::info('Attendance saved', [
                'class_id' => $validated['class_id'],
                'date' => $validated['date'],
                'student_count' => count($validated['attendances']),
                'processed_student_ids' => $processedStudentIds,
            ]);

            $studentCount = Student::where('classId', $validated['class_id'])->count();
            Log::info('Found students for this class', [
                'class_id' => $validated['class_id'],
                'student_count' => $studentCount,
            ]);

            // Redirect with explicit parameters to ensure data is properly loaded
            return redirect()->route('attendances.index', [
                'date' => $validated['date'],
                'class_id' => $validated['class_id'],
                'teacher_id' => $teacherId,
                '_timestamp' => time(),
            ])->with('success', $noticesRecorded > 0
                ? 'Registre enregistré. Les notifications aux parents attendent la validation de l\'administration.'
                : 'Attendance saved successfully');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error saving attendance', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // The exception text is logged above, not handed to the browser: a raw
            // Eloquent or PDO message leaks table and column names to any teacher who
            // manages to trip it.
            return redirect()->back()->with('error', "Erreur lors de l'enregistrement de la présence.");
        }
    }

    public function show($id)
    {
        $attendance = Attendance::with(['student', 'class', 'recordedBy'])->findOrFail($id);
        $this->authorizeAttendance($attendance);

        try {
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
                'trace' => $e->getTraceAsString(),
            ]);

            // Redirect with error message
            return redirect()->route('attendances.index')->with('error', 'Error viewing attendance record: '.$e->getMessage());
        }
    }

    public function update(Request $request, $id, OutboundMessageService $messages)
    {
        // Validate the request data
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'status' => 'required|in:present,absent,late',
            'reason' => 'nullable|string|max:255',
            'date' => 'required|date',
            'class_id' => 'required|exists:classes,id',
            'teacher_id' => 'nullable|exists:teachers,id',
            'subject' => 'nullable|string|max:255',
        ]);

        $attendance = Attendance::with(['student', 'class'])->findOrFail($id);
        $this->authorizeAttendance($attendance);
        SchoolScope::authorizeClass(Classes::findOrFail($validated['class_id']));
        SchoolScope::authorizeStudent(Student::findOrFail($validated['student_id']));

        try {
            // Capture old data before update
            $oldData = $attendance->toArray();

            // If status is "present", delete the record
            if ($validated['status'] === 'present') {
                // A waiting notice for an absence now declared not to have happened must
                // be withdrawn before the row goes — see the identical call in store().
                $messages->cancelForAttendance($attendance->id);
                $attendance->delete();
                $this->logActivity('deleted', $attendance, $oldData, null);

                return redirect()->back()->with('success', 'Attendance record removed (marked as present)');
            }

            // Absent downgraded to late: the waiting notice says "absent" and would send
            // a wrong message if the day were released after this edit.
            if ($attendance->status === 'absent' && $validated['status'] !== 'absent') {
                $messages->cancelForAttendance($attendance->id);
            }

            // Update the record for "absent" or "late"
            $attendance->update([
                'student_id' => $validated['student_id'],
                'classId' => $validated['class_id'],
                'status' => $validated['status'],
                'reason' => $validated['status'] !== 'present' ? $validated['reason'] : null,
                'date' => $validated['date'],
                'recorded_by' => Auth::id(),
                'teacher_id' => $validated['teacher_id'] ?? $attendance->teacher_id,
                'subject' => $validated['subject'] ?? $attendance->subject,
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

            return redirect()->back()->with('error', 'Failed to update attendance record: '.$e->getMessage());
        }
    }

    public function destroy($id, OutboundMessageService $messages)
    {
        $attendance = Attendance::with(['student', 'class'])->findOrFail($id);
        $this->authorizeAttendance($attendance);

        try {
            // Log the activity before deletion
            $this->logActivity('deleted', $attendance, $attendance->toArray(), null);

            // The correction path of the approval workflow: deleting the wrong absence
            // withdraws its waiting notice so releasing the day cannot send it.
            $messages->cancelForAttendance($attendance->id);

            // Delete the record
            $attendance->delete();

            return redirect()->back()->with('success', 'Attendance record deleted successfully');
        } catch (\Exception $e) {
            // Log the error for debugging
            Log::error('Error deleting attendance record:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->back()->with('error', 'Failed to delete attendance record: '.$e->getMessage());
        }
    }

    /**
     * Log activity for a model.
     */
    protected function logActivity($action, $model, $oldData = null, $newData = null)
    {
        $description = ucfirst($action).' '.class_basename($model).' ('.$model->id.')';
        $tableName = $model->getTable();

        $properties = [
            'TargetName' => $model->student->firstName.' '.$model->student->lastName,
            'action' => $action,
            'table' => $tableName,
            'user' => Auth::user()->name,
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
            ->causedBy(Auth::user())
            ->performedOn($model)
            ->withProperties($properties)
            ->log($description);
    }

    public function getStats(Request $request)
    {
        // Default to last 7 days instead of last month
        $endDate = $request->input('end_date') ?? now()->toDateString();
        $startDate = $request->input('start_date') ?? now()->subDays(6)->toDateString();
        $schoolId = $request->input('school_id');

        // Ensure we get exactly 7 days of data
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        // If no specific dates provided, default to last 7 days
        if (! $request->has('start_date') && ! $request->has('end_date')) {
            $end = Carbon::now();
            $start = $end->copy()->subDays(6);
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

        // Generate all dates in the range (last 7 days)
        $dates = collect();
        $currentDate = $start->copy();
        while ($currentDate <= $end) {
            $dates->push($currentDate->format('Y-m-d'));
            $currentDate->addDay();
        }

        // Pivot to chart-friendly format with all dates included
        $result = $dates->map(function ($date) use ($rows) {
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
        if (! in_array($user->role, ['admin', 'assistant'])) {
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
        if (! in_array($user->role, ['admin', 'assistant'])) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $query = Attendance::with(['student', 'class', 'recordedBy', 'teacher']);

        // Status filter: narrow to one status, or (default) show absent + late.
        $status = $request->input('status');
        if (in_array($status, ['absent', 'late', 'present'], true)) {
            $query->where('status', $status);
        } else {
            $query->whereIn('status', ['absent', 'late']);
        }

        /*
         * The caller's schools, as a boundary. This endpoint predates the approval
         * workflow and showed every school's absences to any assistant; that was a
         * tolerable read gap until "valider et envoyer tout" made the page the source of
         * a send decision — the rows and the release must agree on scope or a reviewer
         * releases absences they cannot even see.
         */
        $allowedSchoolIds = SchoolScope::schoolIdsFor();
        if ($allowedSchoolIds !== null) {
            $query->whereHas('class', fn ($q) => $q->whereIn('school_id', $allowedSchoolIds));
        }

        // Date filtering: optional — when omitted the log shows all days, newest first.
        $date = $request->input('date');
        if ($date) {
            $query->whereDate('date', $date);
        }

        // Optional: class or student filter
        if ($request->filled('class_id')) {
            $query->where('classId', $request->input('class_id'));
        }
        if ($request->filled('student_id')) {
            $query->where('student_id', $request->input('student_id'));
        }

        $perPage = $request->input('per_page', 20);
        $absences = $query->orderByDesc('date')->orderByDesc('id')->paginate($perPage);

        /*
         * Whether each of these absences has already been reported to the parent.
         *
         * One query for the page, not one per row. Without it the "Envoyer WhatsApp"
         * button had no idea, so the only way to find out was to press it — and pressing
         * it on an already-reported absence used to send the parent a second copy.
         */
        $notifications = OutboundMessage::summaryForAttendances(
            $absences->pluck('id')->all()
        );

        // Format for frontend
        $data = $absences->through(function ($attendance) use ($notifications) {
            return [
                'id' => $attendance->id,
                'notification' => $notifications[$attendance->id] ?? null,
                'student_id' => $attendance->student ? $attendance->student->id : $attendance->student_id,
                'student_name' => $attendance->student ? $attendance->student->firstName.' '.$attendance->student->lastName : 'Unknown',
                'class_id' => $attendance->class ? $attendance->class->id : null,
                'class_name' => $attendance->class ? $attendance->class->name : 'Unknown',
                'teacher_id' => $attendance->teacher ? $attendance->teacher->id : null,
                'teacher_name' => $attendance->teacher ? $attendance->teacher->first_name.' '.$attendance->teacher->last_name : '-',
                'subject' => $attendance->subject ?: '-',
                'date' => $attendance->date,
                'status' => $attendance->status,
                'reason' => $attendance->reason,
                'recorded_by_name' => $attendance->recordedBy ? $attendance->recordedBy->name : '-',
            ];
        });

        // How many notices wait for approval — drives the "valider et envoyer tout" bar.
        // When no date is selected the count covers today (the actionable set); when a
        // specific date is picked the bar matches the visible rows.
        $awaitingDate = $date ?? now()->toDateString();
        $awaitingCount = OutboundMessage::query()
            ->awaitingApproval()
            ->whereHas('attendance', fn ($q) => $q->whereDate('date', $awaitingDate))
            ->when($allowedSchoolIds !== null, fn ($q) => $q->whereIn('school_id', $allowedSchoolIds))
            ->count();

        return response()->json([
            'data' => $data,
            'awaiting' => $awaitingCount,
            'current_page' => $absences->currentPage(),
            'last_page' => $absences->lastPage(),
            'per_page' => $absences->perPage(),
            'total' => $absences->total(),
        ]);
    }

    public function notifyParent(Request $request, OutboundMessageService $messages, $studentId)
    {
        $student = Student::findOrFail($studentId);

        // The route is now admin/assistant only, but an assistant is scoped to their own
        // schools — without this, one school's assistant could send a WhatsApp message to
        // any student's parent in the product.
        SchoolScope::authorizeStudent($student);

        /*
         * This method had no validation at all, and `subject` goes straight into the body
         * of a message sent from the school's own WhatsApp number to a parent's phone.
         * Blade escapes HTML, which is irrelevant here — WhatsApp renders *bold*, _italic_
         * and links, so unvalidated free text is a way to put arbitrary formatted content,
         * including a link, into what a parent reads as an official school notice.
         */
        $validated = $request->validate([
            'subject' => 'nullable|string|max:120',
            'teacher_id' => 'nullable|integer|exists:teachers,id',
            'attendance_id' => 'nullable|integer|exists:attendances,id',
        ]);

        /*
         * Everything else that used to live here — resolving the guardian's number,
         * building the Arabic message, computing the attendance rate, dispatching the job
         * — is now OutboundMessageService, which also records the outcome and refuses to
         * send the same notice twice. The message body itself was a verbatim copy of the
         * one in store(); there is one copy now, in a Blade file.
         *
         * WHICH absence, not just which pupil.
         *
         * The button sits on a row of the absence log, so it means "tell this parent about
         * THIS absence" — and routing it through createForAbsence() makes it share one key
         * with the register's automatic notice. Before, the two used different keys, so
         * pressing the button on an absence the register had already reported created a
         * second row and sent the parent a duplicate. It also dated the notice `now()`,
         * which told a parent their child was absent today when the row was from last week.
         *
         * The student-level fallback below still exists for callers with no absence row in
         * scope; it keeps its own once-per-day key.
         */
        if (! empty($validated['attendance_id'])) {
            // Scoped to this student on purpose: $student is authorised above, an id from
            // the request body is not. Without the constraint, any absence in the database
            // could be attached to a pupil the caller happens to be allowed to see.
            $attendance = Attendance::where('id', $validated['attendance_id'])
                ->where('student_id', $student->id)
                ->firstOrFail();

            $message = $messages->createForAbsence($attendance);
        } else {
            $message = $messages->createManual($student, [
                'subject' => $validated['subject'] ?? null,
                'date' => now(),
                'teacher_id' => $validated['teacher_id'] ?? null,
                'class_id' => $student->classId,
            ]);
        }

        /*
         * null means the idempotency key already exists: this parent has already been sent
         * this exact notice today. It is a refusal, not a failure — but it MUST reach the
         * screen, because the alternative is a button that reports success for a message
         * that was never created. WhatsAppButton renders this text itself.
         */
        if ($message === null) {
            return back()->with(
                'warning',
                'Ce parent a déjà été notifié pour cette absence.'
            );
        }

        if ($message->status === \App\Models\OutboundMessage::STATUS_SKIPPED) {
            return back()->with('error', $message->reason());
        }

        return back()->with('success', 'Notification envoyée au parent (mise en file d\'attente).');
    }

    /**
     * The end-of-day motion: approve and queue every waiting notice for one day.
     *
     * The route is admin+assistant like the notify button — releasing a day's notices is
     * the same decision, made once instead of row by row. Scoped to the caller's
     * schools for the same reason notifyParent is: an assistant of school A does not
     * send messages to school B's parents, however correct school B's register is.
     */
    public function releaseNotifications(Request $request, OutboundMessageService $messages)
    {
        $validated = $request->validate([
            'date' => 'nullable|date',
        ]);

        $date = $validated['date'] ?? now()->toDateString();

        $result = $messages->releaseAwaitingForDate($date, SchoolScope::schoolIdsFor());

        if ($result['released'] === 0 && $result['skipped'] === 0) {
            return back()->with('warning', 'Aucune notification en attente pour cette journée.');
        }

        $message = $result['released'].' notification'.($result['released'] > 1 ? 's' : '').' envoyée'
            .($result['released'] > 1 ? 's' : '').' (mise'.($result['released'] > 1 ? 's' : '').' en file d\'attente).';

        // Not an error — but a row that could not go out needs its reason on the screen,
        // or "valider tout" looks like it worked while a parent was silently not told.
        if ($result['skipped'] > 0) {
            $message .= ' '.$result['skipped'].' ignorée'.($result['skipped'] > 1 ? 's' : '')
                .' (numéro manquant, invalide ou notifications désactivées).';
        }

        return back()->with('success', $message);
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

        // Raise the ceiling for this render only — see App\Support\PdfBudget.
        PdfBudget::apply();

        // `with('memberships')`: BOTH the teacher filter below and the ST column in the
        // Blade walk $student->memberships. Without this the relation was lazy-loaded
        // twice per student — two queries per row, on top of the render cost.
        $allStudents = $class->students()
            ->where('status', 'active')
            ->with('memberships')
            ->orderBy('lastName')
            ->get();

        // Filter students to only include those taught by the selected teacher through memberships
        $students = $allStudents->filter(function ($student) use ($teacher) {
            $memberships = $student->memberships; // property form: uses the eager-loaded relation
            foreach ($memberships as $membership) {
                $teacherArr = is_array($membership->teachers)
                    ? $membership->teachers
                    : json_decode($membership->teachers, true);
                if (is_array($teacherArr)) {
                    foreach ($teacherArr as $t) {
                        if ((string) ($t['teacherId'] ?? null) === (string) $teacher->id) {
                            return true; // Student is taught by this teacher
                        }
                    }
                }
            }

            return false; // Student is not taught by this teacher
        });

        // Refuse rather than run into the ceiling. This route is opened in a new tab by an
        // <a> element, so there is no page left to flash an error back to — the reply has
        // to carry the message itself or the admin gets a blank tab.
        if ($students->count() > self::PDF_MAX_STUDENTS) {
            return response(
                '<!doctype html><html lang="fr"><meta charset="utf-8">'
                .'<title>Liste trop longue</title>'
                .'<body style="font-family:sans-serif;max-width:34em;margin:4em auto;line-height:1.5">'
                .'<h1 style="font-size:1.25em">Liste trop longue</h1>'
                .'<p>Cette classe compte '.$students->count().' élèves actifs pour cet '
                .'enseignant. La liste de présence est limitée à '.self::PDF_MAX_STUDENTS
                .' élèves par document.</p>'
                .'<p>Choisissez une classe plus petite, ou passez en inactifs les élèves '
                .'qui ne suivent plus les cours.</p></body></html>',
                413
            )->header('Content-Type', 'text/html; charset=utf-8');
        }

        $date = $request->filled('date') ? $request->input('date') : now()->format('Y-m-d');

        // This was cal_days_in_month(CAL_GREGORIAN, ...). That function lives in
        // ext-calendar, which ships enabled on Windows but is NOT one of the extensions
        // the Dockerfile installs — so the whole route was a fatal "call to undefined
        // function" in production while working perfectly on a dev machine. Carbon gives
        // the same number with no extension to install or forget on the next host.
        $monthStart = Carbon::parse($date)->startOfMonth();
        $daysInMonth = $monthStart->daysInMonth;

        // Half-open range rather than whereYear()+whereMonth(): those wrap the column in
        // functions and cannot use the index on `date`.
        $absences = \App\Models\Attendance::where('classId', $class->id)
            ->where('status', 'absent')
            ->where('date', '>=', $monthStart)
            ->where('date', '<', $monthStart->copy()->addMonth())
            ->get();

        // Build a map: [student_id][day] = true if absent
        $studentAbsences = [];
        foreach ($absences as $absence) {
            $day = (int) date('j', strtotime($absence->date));
            $studentAbsences[$absence->student_id][$day] = true;
        }

        // NOTE: config/dompdf.php sets 'enable_font_subsetting' => false, against dompdf's
        // own default of true. Turning it on here shrinks this sheet from ~930 KB to
        // ~93 KB and changes nothing about the memory cost — the font is loaded either
        // way, only the embedded copy shrinks. Left off because it alters what lands in a
        // document the school prints, and that needs an eyeball on the paper, not a test.
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('absence_list_pdf', [
            'teacher' => $teacher,
            'class' => $class,
            'students' => $students,
            'date' => $date,
            'studentAbsences' => $studentAbsences,
            'daysInMonth' => $daysInMonth,
        ])->setPaper('A4', 'landscape');
        $filename = 'Liste-absence-'.$class->name.'-'.$teacher->last_name.'-'.now()->format('Ymd_His').'.pdf';

        return $pdf->download($filename);
    }

    /**
     * Page de sélection pour la liste de présence (frontend)
     */
    public function absenceListPage()
    {
        $teachers = \App\Models\Teacher::with(['schools:id,name'])
            ->select('id', 'first_name', 'last_name')
            ->get()
            ->map(function ($teacher) {
                return [
                    'id' => $teacher->id,
                    'first_name' => $teacher->first_name,
                    'last_name' => $teacher->last_name,
                    'schools' => $teacher->schools->map(function ($school) {
                        return [
                            'id' => $school->id,
                            'name' => $school->name,
                        ];
                    })->toArray(),
                ];
            });

        $classes = \App\Models\Classes::with(['teachers:id,first_name,last_name'])->get()->map(function ($class) {
            return [
                'id' => $class->id,
                'name' => $class->name,
                'teachers' => $class->teachers->map(function ($t) {
                    return [
                        'id' => $t->id,
                        'first_name' => $t->first_name,
                        'last_name' => $t->last_name,
                    ];
                })->toArray(),
            ];
        });

        // ✅ distinct schools list
        $schools = \App\Models\School::select('id', 'name')->get();

        return \Inertia\Inertia::render('Menu/AbsenceListPage', [
            'teachers' => $teachers,
            'classes' => $classes,
            'schools' => $schools,
        ]);
    }
}
