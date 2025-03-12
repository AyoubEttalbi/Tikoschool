<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
class MembershipController extends Controller
{
    /**
     * Display a listing of memberships.
     */
    public function index()
    {
        $memberships = Membership::with(['student', 'offer', 'teacher'])->paginate(10);

        return Inertia::render('Memberships/Index', [
            'memberships' => $memberships,
        ]);
    }

    /**
     * Show the form for creating a new membership.
     */
    public function create()
    {
        // Fetch necessary data for creating a membership
        $students = \App\Models\Student::all();
        $offers = \App\Models\Offer::with('subjects')->get();
        $teachers = \App\Models\Teacher::all();

        return Inertia::render('Memberships/Create', [
            'students' => $students,
            'offers' => $offers,
            'teachers' => $teachers,
        ]);
    }

    /**
     * Store a newly created membership in the database.
     */
    public function store(Request $request)
    {
        DB::beginTransaction();
    
        try {
            // Validate the incoming request
            $validated = $request->validate([
                'student_id' => 'required|integer',
                'offer_id' => 'required|integer',
                'teachers' => 'required|array',
                'teachers.*.subject' => 'required|string',
                'teachers.*.teacherId' => 'required|string',
                'teachers.*.amount' => 'required|numeric',
            ]);
    
            // Create the membership record
            $membership = Membership::create($validated);
    
            // Update teachers' wallets
            foreach ($validated['teachers'] as $teacherData) {
                $teacher = Teacher::find($teacherData['teacherId']);
                if ($teacher) {
                    $teacher->increment('wallet', $teacherData['amount']);
                }
            }
            // $teacher->decrement('wallet', $teacherData['percentage']);
            DB::commit();
            return redirect()->back()->with('success', 'Membership created successfully!');
        } catch (\Exception $e) {
            DB::rollBack();
            return redirect()->back()->withErrors(['error' => 'An error occurred while processing your request.']);
        }
    }

    /**
     * Display the specified membership.
     */
    public function show($id)
    {
        $membership = Membership::with(['student', 'offer', 'teacher'])->findOrFail($id);

        return Inertia::render('Memberships/Show', [
            'membership' => $membership,
        ]);
    }

    /**
     * Show the form for editing the specified membership.
     */
    public function edit($id)
    {
        $membership = Membership::findOrFail($id);
        $students = \App\Models\Student::all();
        $offers = \App\Models\Offer::with('subjects')->get();
        $teachers = \App\Models\Teacher::all();

        return Inertia::render('Memberships/Edit', [
            'membership' => $membership,
            'students' => $students,
            'offers' => $offers,
            'teachers' => $teachers,
        ]);
    }

    /**
     * Update the specified membership in the database.
     */
    public function update(Request $request, $id)
    {
        // Validate the incoming request
        $validated = $request->validate([
            'student_id' => 'required|exists:students,id',
            'offer_id' => 'required|exists:offers,id',
            'teachers' => 'required|array',
            'teachers.*.subject' => 'required|string',
            'teachers.*.teacherId' => 'required|exists:teachers,id',
            'teachers.*.percentage' => 'required|numeric|min:1|max:100',
        ]);

        // Find the membership and update it
        $membership = Membership::findOrFail($id);
        $membership->updateMembership($validated);

        return redirect()->route('memberships.index')->with('success', 'Membership updated successfully.');
    }

    /**
     * Remove the specified membership from the database.
     */
    public function destroy($id)
    {
        $membership = Membership::findOrFail($id);
        $membership->delete();

        return redirect()->route('memberships.index')->with('success', 'Membership deleted successfully.');
    }
}