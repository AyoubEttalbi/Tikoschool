<?php

namespace App\Http\Controllers;

use App\Models\Classes;
use App\Models\Level;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
class ClassesController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {   
        $levels = Level::all();
        $classes = Classes::with('level')->get(); // Eager load the level relationship

        return Inertia::render('Menu/ClassesPage', [
            'classes' => $classes,
            'levels' => $levels
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $levels = Level::all(); // Fetch all levels for the dropdown
        return Inertia::render('Menu/ClassesPage', [
            'levels' => $levels,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Validate the request data
        $validatedData = $request->validate([
            'name' => 'required|string|max:255|unique:classes,name',
            'level_id' => 'required|exists:levels,id',
            'number_of_students' => 'nullable|integer|min:0',
            'number_of_teachers' => 'nullable|integer|min:0',
        ]);

        // Create a new class
        Classes::create($validatedData);

        // Redirect to the classes index page with a success message
        return redirect()->route('classes.index')->with('success', 'Class created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Classes $class)
    {   
        $schools = School::all();
        $classes = Classes::all();
        $levels = Level::all();
        $teachers = DB::table('classes_teacher')->where('classes_id', $class->id);
        $students = DB::table('students')->where('classId', $class->id);
        return Inertia::render('Menu/SingleClassPage', [
            'class' => $class->load('level'), 
            'students' => $students->get(),
            'Alllevels' => $levels,
            'Allclasses' => $classes,
            'className' => $class->name,
            'Allschools' => $schools,
            'teachers' => $teachers->get()
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Classes $class)
    {
        $levels = Level::all(); // Fetch all levels for the dropdown
        return Inertia::render('Classes/Edit', [
            'class' => $class,
            'levels' => $levels,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Classes $class)
    {
        // Validate the request data
        $validatedData = $request->validate([
            'name' => 'required|string|max:255|unique:classes,name,' . $class->id,
            'level_id' => 'required|exists:levels,id',
            'number_of_students' => 'nullable|integer|min:0',
            'number_of_teachers' => 'nullable|integer|min:0',
        ]);

        // Update the class
        $class->update($validatedData);

        // Redirect to the classes index page with a success message
        return redirect()->route('classes.index')->with('success', 'Class updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Classes $class)
    {
        // Delete the class
        $class->delete();

        // Redirect to the classes index page with a success message
        return redirect()->route('classes.index')->with('success', 'Class deleted successfully.');
    }
    function removeStudent(Student $student){
        $student->delete();
       
    }
  
}