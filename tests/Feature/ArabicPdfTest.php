<?php

use App\Models\Level;
use App\Models\School;
use App\Models\Student;
use App\Models\User;

/*
 * ARABIC NAMES ON PRINTED DOCUMENTS
 *
 * The admin mixes French and Arabic level names ("1 AC - الأولى إعدادي"), and dompdf
 * has no Arabic shaping and no bidi algorithm — left alone the raw name prints as
 * disconnected left-to-right letters, which is the garbled "TC - عدجلا كرتش" the client
 * reported on the rosters, the absence sheet and the factures.
 *
 * The fix shapes the name into Unicode presentation forms (U+FE70..U+FEFF) before
 * dompdf sees it (App\Support\ArabicPdfText — see that class for the whole story).
 *
 * These tests go through the real routes with a real Arabic level name, because the
 * unit contract alone does not prove the views actually call the shaper: a view that
 * prints `{{ $level->name }}` untouched still passes every ArabicPdfText unit test.
 *
 * HOW THE PDF IS READ
 * -------------------
 * dompdf compresses its content streams and writes text as two-byte glyph codes inside
 * TJ/Tj runs. dompdf embeds its TrueType subsets with Identity-H encoding and an
 * identity ToUnicode map, so those two bytes ARE the Unicode codepoints (verified
 * against real output: the /W width array of the embedded descendant font indexes by
 * codepoint, and the ToUnicode CMap is the identity).
 *
 * THE ASSERTIONS, AND WHY NOT "NO BASE CODEPOINTS ANYWHERE"
 * ---------------------------------------------------------
 * A shaped run still legitimately contains base 0x06XX codes: letters that never join
 * forward (alef, waw, dal, reh) have the same drawing as their isolated presentation
 * form, and Ar-PHP leaves a dual-joining letter base-coded when it stands in isolated
 * position (e.g. the final ya of "إعدادي" — the alef before it does not join forward).
 * A generic "no base codes" assertion would false-positive on correct output, and an
 * adjacency heuristic cannot tell isolated position from mid-word once the run is in
 * visual draw order. So the detector is exact instead, byte-for-byte:
 *
 *   1. The lam-alef ligature MUST be present. "الأولى" contains lam+alef-hamza, and
 *      lam-alef ligation is mandatory in every correct Arabic rendering — there is no
 *      legitimate way to draw that pair unligated. Its presence proves shaping ran.
 *   2. The raw logical-order byte sequence the bug wrote MUST be absent. The broken
 *      render emitted exactly "06 27 06 44 06 23" (alef, lam, alef-hamza — the first
 *      three letters of "الأولى" in memory order) inside a single text run. That byte
 *      soup only ever appears when the unshaped string reached dompdf.
 */

/** A level whose name mixes French and Arabic, like the client's real data. */
function arabicRosterLevel(): Level
{
    return Level::factory()->create(['name' => '1 AC - الأولى إعدادي']);
}

it('renders a level roster whose Arabic name is shaped, not raw base letters', function () {
    $level = arabicRosterLevel();
    $school = School::factory()->create();
    Student::factory()->create([
        'levelId' => $level->id,
        'schoolId' => $school->id,
        'status' => 'active',
    ]);

    $response = test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('othersettings.levels.students.download', $level->id));

    $response->assertOk();
    expect(arabicPdfTextIsShaped($response->getContent()))->toBeTrue();
});

it('renders the class roster and the absence sheet with the same guarantee', function () {
    $level = arabicRosterLevel();
    $school = School::factory()->create();
    $class = \App\Models\Classes::factory()->create([
        'school_id' => $school->id,
        'level_id' => $level->id,
    ]);

    $student = Student::factory()->create([
        'classId' => $class->id,
        'schoolId' => $school->id,
        'status' => 'active',
    ]);

    $roster = test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('classes.students.download', $class->id));
    $roster->assertOk();
    expect(arabicPdfTextIsShaped($roster->getContent()))->toBeTrue();

    $teacher = \App\Models\Teacher::factory()->create();
    \App\Models\Membership::factory()->create([
        'student_id' => $student->id,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Maths']],
    ]);

    $absence = test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('absence-list.download', [
            'teacher_id' => $teacher->id,
            'class_id' => $class->id,
            'date' => '2026-08-01',
        ]));
    $absence->assertOk();
    expect(arabicPdfTextIsShaped($absence->getContent()))->toBeTrue();
});

/**
 * The ligature codepoints for lam+alef-hamza (the pair in "الأولى"), as they appear in
 * the PDF's glyph stream: the high byte 0xFE followed by the ligature's low byte.
 * U+FEF7 lam-alef-with-hamza-above (isolated) and U+FEF8 (final). Ar-PHP emits one of
 * these for the pair; either proves shaping ran.
 */
const LAM_ALEF_HAMZA_HIGH_BYTES = ["\xFE\xF7", "\xFE\xF8"];

/**
 * The unshaped fingerprint: alef (U+0627), lam (U+0644), alef-hamza (U+0623) as
 * consecutive two-byte glyph codes in logical order — the exact bytes the broken
 * render wrote for the start of "الأولى" (captured from a real garbled PDF).
 */
const UNSHAPED_ALIF_LAM_ALIF_HAMZA = "\x06\x27\x06\x44\x06\x23";

/**
 * Does this PDF print "الأولى إعدادي" shaped?
 *
 * True when the lam-alef ligature is present AND the raw logical-order byte sequence
 * is absent. Anything else — missing ligature, or the raw sequence still in a text
 * run — is the bug.
 */
function arabicPdfTextIsShaped(string $pdf): bool
{
    preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $streams);

    $inflated = '';
    foreach ($streams[1] as $chunk) {
        $out = @gzuncompress($chunk);
        if ($out !== false) {
            $inflated .= $out;
        }
    }

    // A PDF whose streams never inflated would pass both checks vacuously —
    // refuse that instead of reporting success on an unreadable document.
    if ($inflated === '') {
        return false;
    }

    $hasLigature = false;
    foreach (LAM_ALEF_HAMZA_HIGH_BYTES as $pair) {
        if (str_contains($inflated, $pair)) {
            $hasLigature = true;
            break;
        }
    }

    return $hasLigature && ! str_contains($inflated, UNSHAPED_ALIF_LAM_ALIF_HAMZA);
}
