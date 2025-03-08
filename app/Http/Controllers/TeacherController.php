<?php

namespace App\Http\Controllers;

use App\Models\Teacher;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Storage;
use App\Models\Subject;
use App\Models\Classes;


class TeacherController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {   
        $Classes = Classes::all();
        $subjects = Subject::all();
        $teachers = Teacher::paginate(10)->through(function ($teacher) {
            return [
                'id' => $teacher->id,
                'name' => $teacher->first_name . ' ' . $teacher->last_name,
                'phone' => $teacher->phone_number,
                'email' => $teacher->email,
                'address' => $teacher->address,
                'status' => $teacher->status,
                'wallet' => $teacher->wallet,
                'profile_image' => $teacher->profile_image ? URL::asset('storage/' . $teacher->profile_image) : null,
            ];
        });

        return Inertia::render('Menu/TeacherListPage', [
            'teachers' => $teachers,
            'subjects' => $subjects,
            'groups' => $Classes
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $subjects = Subject::all();
        $groups = Group::all();

        return Inertia::render('Teachers/Create', [
            'subjects' => $subjects,
            'groups' => $groups,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'school_id' => 'required|integer|exists:schools,id',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'address' => 'nullable|string|max:255',
            'phone_number' => 'nullable|string|max:20',
            'email' => 'required|string|email|max:255|unique:teachers,email',
            'status' => 'required|in:active,inactive',
            'wallet' => 'required|numeric|min:0',
            'profile_image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'subjects' => 'array',
            'subjects.*' => 'exists:subjects,id',
            'groups' => 'array',
            'groups.*' => 'exists:groups,id',
        ]);

        // Handle profile image upload
        if ($request->hasFile('profile_image')) {
            $validatedData['profile_image'] = $request->file('profile_image')->store('teachers', 'public');
        }

        $teacher = Teacher::create($validatedData);
        $teacher->subjects()->sync($request->subjects);
        $teacher->groups()->sync($request->groups);

        return redirect()->route('teachers.index')->with('success', 'Teacher created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $teacher = Teacher::with(['subjects', 'groups'])->find($id);

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
                'groups' => $teacher->groups,
            ],
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Teacher $teacher)
    {
        $subjects = Subject::all();
        $groups = Group::all();

        return Inertia::render('Teachers/Edit', [
            'teacher' => $teacher,
            'subjects' => $subjects,
            'groups' => $groups,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Teacher $teacher)
    {
        $validatedData = $request->validate([
            'school_id' => 'required|integer|exists:schools,id',
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
            'groups' => 'array',
            'groups.*' => 'exists:groups,id',
        ]);

        // Handle profile image upload
        if ($request->hasFile('profile_image')) {
            if ($teacher->profile_image) {
                Storage::disk('public')->delete($teacher->profile_image);
            }
            $validatedData['profile_image'] = $request->file('profile_image')->store('teachers', 'public');
        }

        $teacher->update($validatedData);
        $teacher->subjects()->sync($request->subjects);
        $teacher->groups()->sync($request->groups);

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
        $teacher->groups()->detach();
        $teacher->delete();

        return redirect()->route('teachers.index')->with('success', 'Teacher deleted successfully.');
    }
}
