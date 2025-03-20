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

class AttendanceController extends Controller
{
    public function index(Request $request)
    {
        $teacherId = $request->input('teacher_id');
        $classId = $request->input('class_id');

        $levels = Level::all();
        $assistants = Assistant::with(['schools'])->get();
        $teachers = Teacher::with(['subjects', 'classes', 'schools'])->get();
        $schools = School::all();
        // Get classes for selected teacher
        $classes = $teacherId
            ? Classes::whereHas('teachers', function ($q) use ($teacherId) {
                $q->where('classes_teacher.teacher_id', $teacherId);
            })->get()
            : [];

        // Get students for selected class
        $students = $classId
            ? Student::where('classId', $classId)->get()
            : [];

        // Get attendances for selected class
        $attendances = $classId
            ? Attendance::with(['student', 'classe', 'recordedBy'])
            ->where('classId', $classId)
            ->latest()
            ->paginate(10)
            : [];

        return Inertia::render('Menu/AttendancePage', [
            'teachers' => $teachers,
            'levels' => $levels,
            'schools' => $schools,
            'assistants' => $assistants,
            'classes' => $classes,
            'students' => $students,
            'attendances' => $attendances,
            'filters' => $request->all('teacher_id', 'class_id'),
        ]);
    }

    public function show($id)
    {
        // Fetch the specific attendance record
        $attendance = Attendance::with(['student', 'classe', 'recordedBy'])
            ->findOrFail($id);

        // Fetch all attendance records for the student
        $studentAttendances = Attendance::with(['classe', 'recordedBy'])
            ->where('student_id', $attendance->student_id)
            ->latest()
            ->paginate(10);

        return Inertia::render('Menu/SingleRecord', [
            'attendance' => $attendance,
            'studentAttendances' => $studentAttendances,
        ]);
    }
    public function store(Request $request)
    {
        // Validate the request data
        $validated = $request->validate([
            'attendances' => 'required|array|min:1',
            'attendances.*.student_id' => 'required|exists:students,id',
            'attendances.*.status' => 'required|in:present,absent,late',
            'attendances.*.reason' => 'nullable|string|max:255',
            'attendances.*.date' => 'required|date',
            'attendances.*.class_id' => 'required|exists:classes,id',
        ]);

        // Log the validated data for debugging
        Log::info('Validated Attendance Data:', $validated);

        try {
            DB::beginTransaction();

            // Loop through each attendance record and create it
            foreach ($validated['attendances'] as $attendance) {
                $record = Attendance::create([
                    'student_id' => $attendance['student_id'],
                    'classId' => $attendance['class_id'],
                    'status' => $attendance['status'],
                    'reason' => $attendance['status'] !== 'present' ? $attendance['reason'] : null,
                    'date' => $attendance['date'],
                    'recorded_by' => auth()->id(),
                ]);

                // Log each created record for debugging
                Log::info('Created Attendance Record:', $record->toArray());
            }

            DB::commit();

            return redirect()->back()->with('success', 'Attendance records created successfully');
        } catch (\Exception $e) {
            DB::rollBack();

            // Log the error for debugging
            Log::error('Error creating attendance records:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return redirect()->back()->with('error', 'Failed to create attendance records: ' . $e->getMessage());
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

            // Update the record
            $attendance->update([
                'student_id' => $validated['student_id'],
                'classId' => $validated['class_id'],
                'status' => $validated['status'],
                'reason' => $validated['status'] !== 'present' ? $validated['reason'] : null,
                'date' => $validated['date'],
                'recorded_by' => auth()->id(),
            ]);

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
}