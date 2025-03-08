<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use App\Models\Classes;
use App\Models\School;

use App\Models\Subject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class TeacherController extends Controller
{
    // Display list view with Inertia
    public function index(Request $request)
    {
        $teachers = Teacher::with(['school', 'subjects', 'classes'])
            ->when($request->trashed, fn($q) => $q->onlyTrashed())
            ->when($request->search, fn($q, $search) => $q
                ->where('first_name', 'LIKE', "%$search%")
                ->orWhere('last_name', 'LIKE', "%$search%")
                ->orWhere('email', 'LIKE', "%$search%")
            )
            ->paginate(10);

        return Inertia::render('Menu/TeacherListPage', [
            'teachers' => $teachers,
            'filters' => $request->only(['search', 'trashed'])
        ]);
    }

    // Show create form
    public function create()
    {
        return Inertia::render('Teachers/Create', [
            'schools' => School::all(['id', 'name']),
            'subjects' => Subject::all(['id', 'name']),
            'classes' => Classes::all(['id', 'name'])
        ]);
    }

    // Store new teacher
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // ... same validation rules as before ...
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        // ... same storage logic as before ...

        return redirect()->route('teachers.index')
            ->with('success', 'Teacher created successfully');
    }

    // Show edit form
    public function edit(Teacher $teacher)
    {
        return Inertia::render('Teachers/Edit', [
            'teacher' => $teacher->load('school', 'subjects', 'classes'),
            'schools' => School::all(['id', 'name']),
            'subjects' => Subject::all(['id', 'name']),
            'classes' => Classes::all(['id', 'name'])
        ]);
    }

    // Update teacher
    public function update(Request $request, Teacher $teacher)
    {
        // ... same update logic as before ...
    }

    // Delete/Restore actions
    public function destroy(Teacher $teacher)
    {
        $teacher->delete();
        return back()->with('success', 'Teacher moved to trash');
    }

    public function restore($id)
    {
        // ... same restore logic ...
    }
}