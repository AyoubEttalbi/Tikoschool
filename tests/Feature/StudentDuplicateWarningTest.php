<?php

use App\Models\Student;
use App\Models\User;
use App\Support\StudentName;

/*
 * SUITE — duplicate-student warning on create/update.
 *
 * Matching is normalized-exact (case, accents, spacing ignored), deliberately
 * NOT phonetic: names differing by a letter (zaynab vs Zaineb) do NOT match.
 * A match warns with a confirm dialog — never blocks. Exactly one indexed
 * query at submit time; nothing per keystroke.
 */

function makeNamedStudent(string $first, string $last, array $extra = []): Student
{
    $school = App\Models\School::factory()->create();
    $level = App\Models\Level::factory()->create();
    $class = App\Models\Classes::factory()->create(['level_id' => $level->id, 'school_id' => $school->id]);

    return Student::factory()->create(array_merge([
        'firstName' => $first,
        'lastName' => $last,
        'schoolId' => $school->id,
        'levelId' => $level->id,
        'classId' => $class->id,
    ], $extra));
}

function studentPayload(Student $template, string $first, string $last): array
{
    return [
        'firstName' => $first,
        'lastName' => $last,
        'dateOfBirth' => '2015-04-12',
        'billingDate' => '2026-09-01',
        'guardianName' => 'Parent Test',
        'levelId' => $template->levelId,
        'classId' => $template->classId,
        'schoolId' => $template->schoolId,
        'status' => 'active',
        'assurance' => 0,
    ];
}

test('normalization ignores case, accents and spacing', function () {
    expect(StudentName::normalize('  FATIMA   EZZAHRA '))
        ->toBe(StudentName::normalize('FatimaEzzahra'))
        ->and(StudentName::normalize('BENLHBIB'))->toBe(StudentName::normalize('benlhbib'))
        ->and(StudentName::normalize('Aït Chîleh'))->toBe(StudentName::normalize('ait chileh'))
        ->and(StudentName::normalize('Jean-Pierre'))->toBe(StudentName::normalize('jeanpierre'))
        ->and(StudentName::normalize("O'Brien"))->toBe(StudentName::normalize('obrien'));
});

test('names differing by a letter do not match (no phonetics by design)', function () {
    expect(StudentName::normalize('zaynab'))->not->toBe(StudentName::normalize('Zaineb'));
});

test('creating a normalized duplicate warns with a candidate link and creates nothing', function () {
    $existing = makeNamedStudent('Zaineb', 'BENLHBIB');
    $admin = User::factory()->create(['role' => 'admin']);
    $countBefore = Student::count();

    $this->actingAs($admin)->post('/students', studentPayload($existing, '  zaineb ', 'benlhbib'))->assertRedirect();

    $notice = session('payment_notice');

    expect(Student::count())->toBe($countBefore, 'First submit writes nothing.')
        ->and($notice['tone'] ?? null)->toBe('warning')
        ->and(implode(' ', $notice['messages'] ?? []))->toContain('existe')
        ->and($notice['details'][0]['label'] ?? '')->toContain('Zaineb')
        ->and($notice['details'][0]['url'] ?? '')->toBe("/students/{$existing->id}", 'Direct link to the existing record.')
        ->and($notice['actions'][0]['data']['confirm_duplicate'] ?? null)->toBeTrue('Confirm resubmits the full payload.');
});

test('confirming the duplicate warning creates the student', function () {
    $existing = makeNamedStudent('Zaineb', 'BENLHBIB');
    $admin = User::factory()->create(['role' => 'admin']);
    // Flat shape, exactly what the dialog's confirm action resubmits.
    $payload = array_merge(studentPayload($existing, 'zaineb', 'benlhbib'), ['confirm_duplicate' => true]);
    $countBefore = Student::count();

    $this->actingAs($admin)->post('/students', $payload)->assertRedirect();

    expect(Student::count())->toBe($countBefore + 1, 'Confirm really creates.')
        ->and(Student::where('firstName', 'zaineb')->where('lastName', 'benlhbib')->exists())->toBeTrue();
});

test('confirming a rename onto an existing name applies the update', function () {
    $existing = makeNamedStudent('Zaineb', 'BENLHBIB');
    $other = makeNamedStudent('Salma', 'REDOUANE', ['schoolId' => $existing->schoolId]);
    $admin = User::factory()->create(['role' => 'admin']);
    $payload = array_merge(studentPayload($other, 'ZAINEB', 'benlhbib'), ['confirm_duplicate' => true]);

    $this->actingAs($admin)->put("/students/{$other->id}", $payload)->assertRedirect();

    expect($other->fresh()->firstName)->toBe('ZAINEB', 'Confirmed rename applies.');
});

test('same name in another school warns nothing', function () {
    $existing = makeNamedStudent('Zaineb', 'BENLHBIB');
    $admin = User::factory()->create(['role' => 'admin']);

    // A different school: candidates are school-scoped.
    $elsewhere = makeNamedStudent('Karim', 'BERRADA');
    $this->actingAs($admin)->post('/students', studentPayload($elsewhere, 'Zaineb', 'BENLHBIB'))->assertRedirect();

    expect(session()->has('payment_notice'))->toBeFalse('Other schools are not our business.');
});

test('untransliterable names never warn', function () {
    $school = App\Models\School::factory()->create();
    $level = App\Models\Level::factory()->create();
    $class = App\Models\Classes::factory()->create(['level_id' => $level->id, 'school_id' => $school->id]);
    $admin = User::factory()->create(['role' => 'admin']);
    $base = ['schoolId' => $school->id, 'levelId' => $level->id, 'classId' => $class->id];

    Student::factory()->create(array_merge($base, ['firstName' => 'محمد', 'lastName' => 'العلوي']));

    $this->actingAs($admin)->post('/students', array_merge(studentPayload(
        Student::factory()->make(array_merge($base, ['firstName' => 'x', 'lastName' => 'y'])),
        'محمد', 'العلوي'
    )))->assertRedirect();

    // Empty keys never match: no warning storm, and the row still creates.
    expect(session()->has('payment_notice'))->toBeFalse()
        ->and(Student::where('firstName', 'محمد')->count())->toBe(2);
});

test('distinct names create silently with no dialog', function () {
    $existing = makeNamedStudent('Zaineb', 'BENLHBIB');
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->post('/students', studentPayload($existing, 'Salma', 'REDOUANE'))->assertRedirect();

    expect(session()->has('payment_notice'))->toBeFalse('No dialog when nothing matches.');
});

test('renaming onto an existing name warns, excluding self', function () {
    $existing = makeNamedStudent('Zaineb', 'BENLHBIB');
    $other = makeNamedStudent('Salma', 'REDOUANE', ['schoolId' => $existing->schoolId]);
    $admin = User::factory()->create(['role' => 'admin']);

    // Saving unchanged (self-match only) warns nothing.
    $this->actingAs($admin)->put("/students/{$other->id}", studentPayload($other, 'Salma', 'REDOUANE'))->assertRedirect();
    expect(session()->has('payment_notice'))->toBeFalse('Self never warns.');

    // Renaming onto the other student warns and writes nothing.
    $this->actingAs($admin)->put("/students/{$other->id}", studentPayload($other, 'ZAINEB', 'benlhbib'))->assertRedirect();

    expect($other->fresh()->firstName)->toBe('Salma', 'Blocked rename writes nothing.')
        ->and(session('payment_notice')['tone'] ?? null)->toBe('warning');
});

test('trashed candidates are flagged without a link', function () {
    $existing = makeNamedStudent('Zaineb', 'BENLHBIB');
    $existing->delete();
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->post('/students', studentPayload($existing, 'zaineb', 'benlhbib'))->assertRedirect();

    $notice = session('payment_notice');

    expect($notice['details'][0]['note'] ?? '')->toContain('Supprim')
        ->and($notice['details'][0]['url'] ?? null)->toBeNull('No link into a trashed record.');
});

test('name keys fill on save and backfill covers legacy rows', function () {
    $student = makeNamedStudent('Fatima', 'EZZAHRA');

    expect($student->fresh()->firstNameKey)->toBe(StudentName::normalize('Fatima'))
        ->and($student->fresh()->lastNameKey)->toBe(StudentName::normalize('EZZAHRA'));

    // Simulate a pre-migration row, then backfill.
    Student::whereKey($student->id)->update(['firstNameKey' => null, 'lastNameKey' => null]);
    $this->artisan('students:backfill-name-keys')->assertSuccessful();

    expect($student->fresh()->firstNameKey)->toBe(StudentName::normalize('Fatima'));
});
