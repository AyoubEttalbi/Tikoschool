<?php

namespace App\Http\Controllers;

use App\Events\CheckEmailUnique;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Storage;
use App\Models\Subject;
use App\Models\School;
use App\Models\Classes;

class TeacherController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
{
    $schools = School::all();
    $classes = Classes::all();
    $subjects = Subject::all();
    $query = Teacher::query();

    // Apply search filter if search term is provided
    if ($request->has('search') && !empty($request->search)) {
        $searchTerm = $request->search;

        $query->where(function ($q) use ($searchTerm) {
            // Search by teacher fields
            $q->where('first_name', 'LIKE', "%{$searchTerm}%")
              ->orWhere('last_name', 'LIKE', "%{$searchTerm}%")
              ->orWhere('phone_number', 'LIKE', "%{$searchTerm}%")
              ->orWhere('email', 'LIKE', "%{$searchTerm}%")
              ->orWhere('address', 'LIKE', "%{$searchTerm}%");

            // Search by subject name
            $q->orWhereHas('subjects', function ($subjectQuery) use ($searchTerm) {
                $subjectQuery->where('name', 'LIKE', "%{$searchTerm}%");
            });

            // Search by class name
            $q->orWhereHas('classes', function ($classQuery) use ($searchTerm) {
                $classQuery->where('name', 'LIKE', "%{$searchTerm}%");
            });

            // Search by school name
            $q->orWhereHas('schools', function ($schoolQuery) use ($searchTerm) {
                $schoolQuery->where('name', 'LIKE', "%{$searchTerm}%");
            });
        });
    }

    // Fetch paginated and filtered teachers
    $teachers = $query->paginate(10)->withQueryString()->through(function ($teacher) {
        return [
            'id' => $teacher->id,
            'name' => $teacher->first_name . ' ' . $teacher->last_name,
            'phone_number' => $teacher->phone_number,
            'first_name' => $teacher->first_name,
            'last_name' => $teacher->last_name,
            'phone' => $teacher->phone_number,
            'email' => $teacher->email,
            'address' => $teacher->address,
            'status' => $teacher->status,
            'wallet' => $teacher->wallet,
            'profile_image' => $teacher->profile_image ? URL::asset('storage/' . $teacher->profile_image) : null,
            'subjects' => $teacher->subjects,
            'classes' => $teacher->classes,
            'schools' => $teacher->schools,
        ];
    });

    return Inertia::render('Menu/TeacherListPage', [
        'teachers' => $teachers,
        'schools' => $schools,
        'subjects' => $subjects,
        'classes' => $classes,
        'search' => $request->search
    ]);
}

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $subjects = Subject::all();
        $classes = Classes::all();

        return Inertia::render('Teachers/Create', [
            'subjects' => $subjects,
            'classes' => $classes, 
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'address' => 'nullable|string|max:255',
            'phone_number' => 'nullable|string|max:20',
            'email' => 'required|string|email|max:255|unique:teachers,email',
            'status' => 'required|in:active,inactive',
            'wallet' => 'required|numeric|min:0',
            'profile_image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'schools' => 'array',
            'schools.*' => 'exists:schools,id',
            'subjects' => 'array',
            'subjects.*' => 'exists:subjects,id',
            'classes' => 'array', // ✅ Changed from 'groups' to 'classes'
            'classes.*' => 'exists:classes,id', // ✅ Validation fix
        ]);

        // Handle profile image upload (if any)
        if ($request->hasFile('profile_image')) {
            $validatedData['profile_image'] = $request->file('profile_image')->store('teachers', 'public');
        }
        event(new CheckEmailUnique($request->email));
        // Create the teacher record
        $teacher = Teacher::create($validatedData);

        // Sync the subjects and classes with the teacher (many-to-many relation)
        $teacher->subjects()->sync($request->subjects);
        $teacher->classes()->sync($request->classes); // ✅ Changed from 'groups' to 'classes'
        $teacher->schools()->sync($request->schools);
        return redirect()->route('teachers.index')->with('success', 'Teacher created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $teacher = Teacher::with(['subjects', 'classes', 'schools'])->find($id); 
        $schools = School::all();
        $classes = Classes::all();
        $subjects = Subject::all();
        if (!$teacher) {
            abort(404);
        }

        return Inertia::render('Menu/SingleTeacherPage', [
            'teacher' => [
                'id' => $teacher->id,
                'first_name' => $teacher->first_name,
                'last_name' => $teacher->last_name,
                'address' => $teacher->address,
                'phone_number' => $teacher->phone_number,
                'email' => $teacher->email,
                'status' => $teacher->status,
                'wallet' => $teacher->wallet,
                'profile_image' => $teacher->profile_image ? URL::asset('storage/' . $teacher->profile_image) : null,
                'subjects' => $teacher->subjects,
                'classes' => $teacher->classes, 
                'schools' => $teacher->schools,
                'created_at' => $teacher->created_at,
            ],
            'schools' => $schools,
            'subjects' => $subjects,
            'classes' => $classes,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Teacher $teacher)
    {
        $subjects = Subject::all();
        $classes = Classes::all(); // ✅ Changed from 'groups' to 'classes'

        return Inertia::render('Teachers/Edit', [
            'teacher' => $teacher,
            'subjects' => $subjects,
            'classes' => $classes, // ✅ Changed from 'groups' to 'classes'
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Teacher $teacher)
    {
        $validatedData = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'address' => 'nullable|string|max:255',
            'phone_number' => 'nullable|string|max:20',
            'email' => 'required|string|email|max:255|unique:teachers,email,' . $teacher->id,
            'status' => 'required|in:active,inactive',
            'wallet' => 'required|numeric|min:0',
            'profile_image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'subjects' => 'array',
            'subjects.*' => 'exists:subjects,id',
            'classes' => 'array',
            'classes.*' => 'exists:classes,id',
            'schools' => 'array',
            'schools.*' => 'exists:schools,id',
        ]);
    
        // Handle profile image upload
        if ($request->hasFile('profile_image')) {
            if ($teacher->profile_image) {
                Storage::disk('public')->delete($teacher->profile_image);
            }
            $validatedData['profile_image'] = $request->file('profile_image')->store('teachers', 'public');
        }
        event(new CheckEmailUnique($request->email, $teacher->id));
        // Update only teacher's own attributes
        $teacher->update([
            'first_name' => $validatedData['first_name'],
            'last_name' => $validatedData['last_name'],
            'address' => $validatedData['address'] ?? null,
            'phone_number' => $validatedData['phone_number'] ?? null,
            'email' => $validatedData['email'],
            'status' => $validatedData['status'],
            'wallet' => $validatedData['wallet'],
            'profile_image' => $validatedData['profile_image'] ?? $teacher->profile_image, 
        ]);
    
        // Sync relationships (prevent NULL issues by using `?? []`)
        $teacher->subjects()->sync($request->subjects ?? []);
        $teacher->classes()->sync($request->classes ?? []);
        $teacher->schools()->sync($request->schools ?? []);
    
        return redirect()->route('teachers.show', $teacher->id)->with('success', 'Teacher updated successfully.');
    }
    

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Teacher $teacher)
    {
        if ($teacher->profile_image) {
            Storage::disk('public')->delete($teacher->profile_image);
        }

        $teacher->subjects()->detach();
        $teacher->classes()->detach(); // ✅ Changed from 'groups' to 'classes'
        $teacher->delete();

        return redirect()->route('teachers.index')->with('success', 'Teacher deleted successfully.');
    }
}
