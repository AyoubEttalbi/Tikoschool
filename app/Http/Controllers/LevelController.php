<?php

namespace App\Http\Controllers;

use App\Models\Level;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Support\PdfBudget;
use App\Support\SchoolScope;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia; // Import Inertia

class LevelController extends Controller
{
    /**
     * Cost of one roster row, in megabytes. MEASURED, not estimated.
     *
     * Rendered against the real 2 BAC level on production data:
     *
     *       1 row   120 MB peak    3.0 s
     *      55 rows  132 MB peak    3.1 s
     *     110 rows  146 MB peak    3.4 s
     *
     * which is ~0.24 MB per row on top of the ~120 MB floor (DejaVu Sans plus a booted
     * framework). Rounded up to 0.25 because the floor is what PdfBudget already subtracts
     * and the row cost is the part that scales. The absence sheet costs ~0.75 MB per row
     * because it is 35 cells wide; this document is five columns.
     *
     * Measured rather than reasoned on purpose: guessing at dompdf's appetite is what
     * produced the original crash that PdfBudget exists to prevent.
     */
    private const PDF_ROW_COST_MB = 0.25;

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $levels = Level::all();
        $subjects = Subject::all();

        /*
         * Schools the CALLER may see, which for an admin is all of them.
         *
         * The whole othersettings prefix currently sits behind AdminMiddleware, so this
         * filter changes nothing today. It is here because the list is rendered into the
         * page for the roster picker: the moment this page is opened to assistants —
         * Othersettings.jsx already has role checks anticipating exactly that — School::all()
         * would publish the name of every branch in the product to somebody scoped to one.
         */
        $allowedSchoolIds = SchoolScope::schoolIdsFor();
        $schools = School::query()
            ->when($allowedSchoolIds !== null, fn ($q) => $q->whereIn('id', $allowedSchoolIds))
            ->orderBy('name')
            ->get();

        return Inertia::render('Menu/Othersettings', [
            'levels' => $levels,
            'subjects' => $subjects,
            'schools' => $schools,
        ]);
    }

    /**
     * Every active student in one level, as a printable roster.
     *
     * Asked for as the level-wide counterpart to the class attendance sheet: "print me
     * everyone in 2 BAC", rather than one class at a time.
     *
     * Two things are load-bearing here.
     *
     * SCOPE. The level is a global object — every school has a "2 BAC" — so the level id
     * alone says nothing about who may read the rows. AdminMiddleware is what actually
     * keeps scoped staff out today; the SchoolScope calls below are the second layer, for
     * the day this page is opened to assistants. A route whose only protection is the
     * middleware group it happens to sit in is one refactor away from leaking every
     * branch's children.
     *
     * MEMORY. This is the same shape of request that killed the absence list: an unbounded
     * row count fed to dompdf, which holds every cell as a styled frame object until the
     * render returns. PHP memory exhaustion is fatal and uncatchable, so the size is
     * refused before the render starts rather than survived afterwards.
     */
    public function downloadStudents(Request $request, Level $level)
    {
        $validated = $request->validate([
            'school_id' => 'nullable|integer|exists:schools,id',
        ]);

        $schoolId = $validated['school_id'] ?? null;

        // Before the query, not after: a school id arriving in the query string is caller
        // input, and $level tells us nothing about which branch's children to hand over.
        if ($schoolId !== null) {
            SchoolScope::authorizeSchool($schoolId);
        }

        $allowedSchoolIds = SchoolScope::schoolIdsFor();

        $query = Student::query()
            ->where('levelId', $level->id)
            ->where('status', 'active')
            // Both eager loads are consumed by the Blade. Without them this is two queries
            // per student — the N+1 the absence sheet had to fix for the same reason.
            ->with(['memberships.offer'])
            ->orderBy('lastName')
            ->orderBy('firstName');

        if ($allowedSchoolIds !== null) {
            $query->whereIn('schoolId', $allowedSchoolIds);
        }

        if ($schoolId !== null) {
            $query->where('schoolId', $schoolId);
        }

        $maxRows = PdfBudget::maxRows(self::PDF_ROW_COST_MB);
        $count = (clone $query)->count();

        /*
         * Refuse rather than run into the ceiling. Opened in a new tab from an anchor, so
         * there is no page left to flash an error back to — the response has to carry the
         * message or the user gets a blank tab and no idea why.
         */
        if ($count > $maxRows) {
            return response(
                '<!doctype html><html lang="fr"><meta charset="utf-8">'
                .'<title>Liste trop longue</title>'
                .'<body style="font-family:sans-serif;max-width:34em;margin:4em auto;line-height:1.5">'
                .'<h1 style="font-size:1.25em">Liste trop longue</h1>'
                .'<p>Ce niveau compte '.$count.' élèves actifs. Un document est limité à '
                .$maxRows.' élèves.</p>'
                .'<p>Choisissez un établissement pour réduire la liste, ou passez en '
                .'inactifs les élèves qui ne suivent plus les cours.</p></body></html>',
                413
            )->header('Content-Type', 'text/html; charset=utf-8');
        }

        // Raise the ceiling for this render only — see App\Support\PdfBudget.
        PdfBudget::apply();

        $school = $schoolId !== null ? School::find($schoolId) : null;

        $pdf = Pdf::loadView('level_students_pdf', [
            'level' => $level,
            'school' => $school,
            'students' => $query->get(),
            'generatedAt' => now(),
        ]);

        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $level->name) ?: 'niveau';

        // ->stream(), not ->download(): output() renders the document, and calling both
        // rendered it twice — the bug that doubled the peak on the invoice endpoints.
        return $pdf->stream('eleves-'.trim($slug, '-').'-'.now()->format('Ymd').'.pdf');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return Inertia::render('othersettings/Create'); // Return the create form view
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        // Validate the request data
        $validatedData = $request->validate([
            'name' => 'required|string|max:255|unique:levels,name',
        ]);

        // Create a new level
        Level::create($validatedData);

        // Redirect to the levels index page with a success message
        return redirect()->route('othersettings.index')->with('success', 'Level created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Level $level)
    {
        return Inertia::render('othersettings/Show', [
            'level' => $level, // Pass the level to the frontend
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Level $level)
    {
        return Inertia::render('othersettings/Edit', [
            'level' => $level, // Pass the level to the frontend
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Level $level)
    {
        // Validate the request data
        $validatedData = $request->validate([
            'name' => 'required|string|max:255|unique:levels,name,'.$level->id,
        ]);

        // Update the level
        $level->update($validatedData);

        // Redirect to the levels index page with a success message
        return redirect()->route('othersettings.index')->with('success', 'Level updated successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Level $level)
    {
        // Delete the level
        $level->delete();

        // Redirect to the levels index page with a success message
        return redirect()->route('othersettings.index')->with('success', 'Level deleted successfully.');
    }
}
