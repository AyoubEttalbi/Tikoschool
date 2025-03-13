<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MembershipController extends Controller
{
    /**
     * Display a listing of memberships.
     */
    public function index()
    {
        $memberships = Membership::with(['student', 'offer'])->paginate(10);

        return Inertia::render('Menu/SingleStudentPage', [
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
        $membership = Membership::with(['student', 'offer'])->findOrFail($id);

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
        DB::beginTransaction();

        try {
            // Log the incoming request data
            Log::info('Update Membership Request:', $request->all());

            // Validate the incoming request
            $validated = $request->validate([
                'student_id' => 'required|integer',
                'offer_id' => 'required|integer',
                'teachers' => 'required|array',
                'teachers.*.teacherId' => 'required|string',
                'teachers.*.amount' => 'required|numeric',
            ]);

            // Find the membership
            $membership = Membership::findOrFail($id);

            // Decrement the wallet for old teachers
            foreach ($membership->teachers as $oldTeacher) {
                $teacher = Teacher::find($oldTeacher['teacherId']);
                if ($teacher) {
                    $teacher->decrement('wallet', $oldTeacher['amount']);
                }
            }

            // Update the membership with new data
            $membership->update([
                'student_id' => $validated['student_id'],
                'offer_id' => $validated['offer_id'],
                'teachers' => $validated['teachers'],
            ]);

            // Increment the wallet for new teachers
            foreach ($validated['teachers'] as $newTeacher) {
                $teacher = Teacher::find($newTeacher['teacherId']);
                if ($teacher) {
                    $teacher->increment('wallet', $newTeacher['amount']);
                }
            }

            DB::commit();
            
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating membership:', ['error' => $e->getMessage()]);
            return redirect()->back()->withErrors(['error' => 'An error occurred while updating the membership.']);
        }
    }

    /**
     * Remove the specified membership from the database.
     */
    public function destroy($id)
    {
        DB::beginTransaction();

        try {
            // Find the membership
            $membership = Membership::findOrFail($id);

            // Decrement the wallet for associated teachers
            foreach ($membership->teachers as $teacherData) {
                $teacher = Teacher::find($teacherData['teacherId']);
                if ($teacher) {
                    $teacher->decrement('wallet', $teacherData['amount']);
                }
            }

            // Delete the membership
            $membership->delete();

            DB::commit();
            return redirect()->back()->with('success', 'Membership deleted successfully.');
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error deleting membership:', ['error' => $e->getMessage()]);
            return redirect()->back()->withErrors(['error' => 'An error occurred while deleting the membership.']);
        }
    }
}