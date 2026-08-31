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
 * WHAT COUNTS AS "GARBLED" IN THE CONTENT STREAM
 * ----------------------------------------------
 * dompdf compresses its content streams and writes text as two-byte glyph codes inside
 * TJ/Tj runs. Reading those runs gives the exact codepoints the document prints:
 *
 *   - Shaped Arabic appears as presentation forms (0xFExx pairs).
 *   - UNshaped Arabic — the bug — appears as base-block codepoints (0x06xx pairs).
 *
 * But not every base 0x06xx code is the bug: Arabic letters that never join to a
 * following letter (alef, waw, dal, reh...) have the SAME drawing as their isolated
 * presentation form, and Ar-PHP legitimately emits them as base codepoints. The
 * letters that DO join (beh, lam, seen...) are only ever printed as base codepoints
 * when shaping did not happen. So the detector is: a dual-joining letter appearing
 * as a base codepoint inside a text run. Verified by hand: with the heading reverted
 * to raw output the stream contains 06 27 06 44 06 23 (alef LAM alef-hamza — lam is
 * the joiner); shaped, the same word is FE F0 FE DF (lam-alef ligatures).
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

    // The word الأولى contains ل (lam), a dual-joining letter. Shaped, lam can only
    // appear as a presentation form; base-coded lam in the stream is the garble.
    expect(arabicPdfUnshapedJoiners($response->getContent()))->toBeEmpty();
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
    expect(arabicPdfUnshapedJoiners($roster->getContent()))->toBeEmpty();

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
    expect(arabicPdfUnshapedJoiners($absence->getContent()))->toBeEmpty();
});

/**
 * Dual-joining Arabic letters: they connect to both neighbours, so correct rendering
 * NEVER prints them as their base codepoint — only as one of the presentation forms.
 * Base-coded joiners in a text run are the fingerprint of unshaped Arabic.
 */
function arabicJoiningLetters(): array
{
    return [
        0x0626, // yeh with hamza
        0x0628, 0x062A, 0x062B, 0x062C, 0x062D, 0x062E, // beh..khah
        0x0633, 0x0634, 0x0635, 0x0636, 0x0637, 0x0638, // seen..zah
        0x0639, 0x063A, 0x0641, 0x0642, 0x0643, 0x0644, // ain..lam
        0x0645, 0x0646, 0x0647, 0x064A, // meem, noon, heh, yeh
    ];
}

/**
 * Text runs in the PDF that print a dual-joining Arabic letter as a base codepoint —
 * i.e. the garbled, unshaped Arabic that shipped to the client.
 *
 * The two-byte glyph codes in a TJ/Tj run ARE the Unicode codepoints here: dompdf
 * embeds its TrueType subsets with Identity-H encoding and an identity ToUnicode map
 * (verified against the real output). A base codepoint 0x06XX is written as the byte
 * pair 06 XX inside a parenthesised run string. The same bytes outside a text run are
 * positioning constants, not glyphs — hence per-run parsing.
 */
function arabicPdfUnshapedJoiners(string $pdf): array
{
    preg_match_all('/stream\r?\n(.*?)endstream/s', $pdf, $streams);

    $inflated = '';
    foreach ($streams[1] as $chunk) {
        $out = @gzuncompress($chunk);
        if ($out !== false) {
            $inflated .= $out;
        }
    }

    // A PDF whose streams never inflated would pass vacuously — refuse that instead.
    expect($inflated)->not->toBe('');

    preg_match_all('/\[(.*?)\]\s*TJ|\((.*?)\)\s*Tj/s', $inflated, $runs, PREG_SET_ORDER);

    $joiners = [];
    foreach (arabicJoiningLetters() as $cp) {
        $joiners[mb_chr($cp, 'UTF-8')] = true;
    }

    $offenders = [];
    foreach ($runs as $run) {
        $body = $run[1] !== '' ? $run[1] : ($run[2] ?? '');
        if ($body === '' || ! preg_match_all('/\((.*?)\)/s', $body, $strings)) {
            continue;
        }

        foreach ($strings[1] as $string) {
            $chars = mb_str_split($string, 1, 'UTF-8');
            for ($i = 0; $i < count($chars) - 1; $i++) {
                if ($chars[$i] === "\x06" && isset($joiners[$chars[$i + 1]])) {
                    $offenders[] = sprintf(
                        'U+%04X',
                        mb_ord("\x06".$chars[$i + 1], 'UTF-8')
                    );
                }
            }
        }
    }

    return array_values(array_unique($offenders));
}
