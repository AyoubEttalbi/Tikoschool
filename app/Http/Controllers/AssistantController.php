<?php
namespace App\Http\Controllers;

use App\Models\Assistant;
use App\Models\School;
use App\Models\Subject;
use App\Models\Classes;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Storage;

class AssistantController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $schools = School::all();
        $assistants = Assistant::paginate(10)->through(function ($assistant) {
            return [
                'id' => $assistant->id,
                'name' => $assistant->first_name . ' ' . $assistant->last_name,
                'phone_number' => $assistant->phone_number,
                'first_name' => $assistant->first_name,
                'last_name' => $assistant->last_name,
                'email' => $assistant->email,
                'address' => $assistant->address,
                'status' => $assistant->status,
                'salary' => $assistant->salary,
                'profile_image' => $assistant->profile_image ? URL::asset('storage/' . $assistant->profile_image) : null,
                'schools_assistant' => $assistant->schools,
            ];
        });

        return Inertia::render('Menu/AssistantsListPage', [
            'assistants' => $assistants,
            'schools' => $schools,

        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $subjects = Subject::all();
        $classes = Classes::all();
        $schools = School::all();

        return Inertia::render('AssistantsListPage/Create', [
            'subjects' => $subjects,
            'classes' => $classes,
            'schools' => $schools,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */public function store(Request $request)
{
    $validatedData = $request->validate([
        'first_name' => 'required|string|max:100',
        'last_name' => 'required|string|max:100',
        'email' => 'required|string|email|max:255|unique:assistants,email',
        'phone_number' => 'nullable|string|max:20',
        'address' => 'nullable|string|max:255',
        'profile_image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        'salary' => 'required|numeric|min:0',
        'status' => 'required|in:active,inactive',
        'schools' => 'required|array', // Ensure schools is required and an array
        'schools.*' => 'exists:schools,id', // Ensure each school ID exists in the schools table
    ]);

    // Handle profile image upload (if any)
    if ($request->hasFile('profile_image')) {
        $validatedData['profile_image'] = $request->file('profile_image')->store('assistants', 'public');
    }

    // Create the assistant record
    $assistant = Assistant::create($validatedData);

    // Sync schools with the assistant
    $assistant->schools()->sync($request->schools);

    return redirect()->route('assistants.index')->with('success', 'Assistant created successfully.');
}
    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $assistant = Assistant::with(['schools'])->find($id);
        $schools = School::all();
        $classes = Classes::all();
        $subjects = Subject::all();

        if (!$assistant) {
            abort(404);
        }

        return Inertia::render('Menu/SingleAssistantPage', [
            'assistant' => [
                'id' => $assistant->id,
                'first_name' => $assistant->first_name,
                'last_name' => $assistant->last_name,
                'email' => $assistant->email,
                'phone_number' => $assistant->phone_number,
                'address' => $assistant->address,
                'status' => $assistant->status,
                'salary' => $assistant->salary,
                'profile_image' => $assistant->profile_image ? URL::asset('storage/' . $assistant->profile_image) : null,
                'schools_assistant' => $assistant->schools,
                'created_at' => $assistant->created_at,
            ],
            'schools' => $schools,
            'subjects' => $subjects,
            'classes' => $classes,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Assistant $assistant)
    {
        $subjects = Subject::all();
        $classes = Classes::all();
        $schools = School::all();

        return Inertia::render('Assistants/Edit', [
            'assistant' => $assistant,
            'subjects' => $subjects,
            'classes' => $classes,
            'schools' => $schools,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Assistant $assistant)
{
    $validatedData = $request->validate([
        'first_name' => 'required|string|max:100',
        'last_name' => 'required|string|max:100',
        'email' => 'required|string|email|max:255|unique:assistants,email,' . $assistant->id,
        'phone_number' => 'nullable|string|max:20',
        'address' => 'nullable|string|max:255',
        'profile_image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        'salary' => 'required|numeric|min:0',
        'status' => 'required|in:active,inactive',
        'schools' => 'array', // Add validation for schools
        'schools.*' => 'exists:schools,id',
    ]);

    // Handle profile image upload
    if ($request->hasFile('profile_image')) {
        if ($assistant->profile_image) {
            Storage::disk('public')->delete($assistant->profile_image);
        }
        $validatedData['profile_image'] = $request->file('profile_image')->store('assistants', 'public');
    }

    // Update the assistant record
    $assistant->update($validatedData);

    $assistant->schools()->sync($request->schools ?? []);

    return redirect()->route('assistants.show', $assistant->id)->with('success', 'Assistant updated successfully.');
}
    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Assistant $assistant)
    {
        if ($assistant->profile_image) {
            Storage::disk('public')->delete($assistant->profile_image);
        }


        $assistant->delete();

        return redirect()->route('assistants.index')->with('success', 'Assistant deleted successfully.');
    }
}