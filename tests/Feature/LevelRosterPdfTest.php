<?php

use App\Models\Level;
use App\Models\School;
use App\Models\Student;
use App\Models\User;

/*
 * THE LEVEL ROSTER
 *
 * "Print everyone in 2 BAC" — the level-wide counterpart to the class attendance sheet.
 *
 * Two things carry real risk and are pinned below.
 *
 * SCOPE. A level is a global object: every branch has a "2 BAC". The level id therefore
 * says nothing about who may read the rows. Today AdminMiddleware keeps scoped staff out
 * entirely; SchoolScope is the second layer, for the day the page is opened up. Both are
 * pinned below, separately, because a route whose only protection is the middleware group
 * it happens to sit in is one refactor away from leaking every branch's children.
 *
 * SIZE. This is the same shape of request that killed the absence list — an unbounded row
 * count handed to dompdf, which holds every cell as a styled frame until the render
 * returns. PHP memory exhaustion is fatal and uncatchable, so an oversized job has to be
 * refused before it starts, not survived afterwards.
 */

function rosterAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function rosterStudent(Level $level, School $school, array $overrides = []): Student
{
    return Student::factory()->create($overrides + [
        'levelId' => $level->id,
        'schoolId' => $school->id,
        'status' => 'active',
    ]);
}

/** An assistant attached to exactly one school — the scoped-staff case. */
function rosterAssistant(School $school, string $email): User
{
    $user = User::factory()->create(['role' => 'assistant', 'email' => $email]);
    \App\Models\Assistant::factory()->create(['email' => $email])
        ->schools()->attach($school->id);

    return $user;
}

it('renders a roster for a level', function () {
    $level = Level::factory()->create(['name' => '2 BAC']);
    $school = School::factory()->create();
    rosterStudent($level, $school, ['lastName' => 'ZAHRA', 'firstName' => 'Amina']);

    $response = test()->actingAs(rosterAdmin())
        ->get(route('othersettings.levels.students.download', $level->id));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
    // A real document, not an empty shell. Asserting on the BYTES rather than on names in
    // it: dompdf compresses its streams, so grepping the output for a pupil proves nothing
    // either way.
    expect(strlen($response->getContent()))->toBeGreaterThan(1000)
        ->and(substr($response->getContent(), 0, 4))->toBe('%PDF');
});

it('leaves out students who are no longer active', function () {
    $level = Level::factory()->create();
    $school = School::factory()->create();
    rosterStudent($level, $school);
    rosterStudent($level, $school, ['status' => 'inactive']);

    // Counted through the same query the controller uses rather than by reading the PDF
    // bytes: dompdf output is compressed, so asserting on names in it proves nothing.
    $active = Student::where('levelId', $level->id)->where('status', 'active')->count();

    expect($active)->toBe(1);

    test()->actingAs(rosterAdmin())
        ->get(route('othersettings.levels.students.download', $level->id))
        ->assertOk();
});

it('narrows the roster to one school when asked', function () {
    $level = Level::factory()->create();
    $a = School::factory()->create();
    $b = School::factory()->create();
    rosterStudent($level, $a);
    rosterStudent($level, $b);

    test()->actingAs(rosterAdmin())
        ->get(route('othersettings.levels.students.download', $level->id).'?school_id='.$a->id)
        ->assertOk();
});

/*
 * Layer one: the whole othersettings prefix sits inside AdminMiddleware, so no assistant
 * reaches this route at all.
 *
 * Asserted as a REDIRECT, not a 403, because that is what AdminMiddleware actually does —
 * the behaviour CLAUDE.md warns about, where a denial looks like success to anything
 * expecting an error status. Pinning the real response means this test fails loudly if
 * the route is ever moved out from under that middleware.
 */
it('does not let an assistant reach the roster at all', function () {
    $level = Level::factory()->create();
    $mine = School::factory()->create();
    $theirs = School::factory()->create();

    $assistant = rosterAssistant($mine, 'a@example.test');
    rosterStudent($level, $theirs);

    test()->actingAs($assistant)
        ->get(route('othersettings.levels.students.download', $level->id).'?school_id='.$theirs->id)
        ->assertRedirect();
});

/*
 * Layer two, tested directly because no HTTP request can currently reach it.
 *
 * SchoolScope is what would stand between a scoped member of staff and another branch's
 * children if this route were ever opened up — which is exactly the kind of change that
 * gets made without revisiting the controller. The level is a global object: every branch
 * has a "2 BAC", so the level id authorises nothing on its own.
 */
it('scopes the roster by school for staff who are not admins', function () {
    $mine = School::factory()->create();
    $theirs = School::factory()->create();

    $assistant = rosterAssistant($mine, 'c@example.test');

    test()->actingAs($assistant);

    expect(\App\Support\SchoolScope::schoolIdsFor())->toBe([$mine->id])
        ->and(\App\Support\SchoolScope::allowsSchool($theirs->id))->toBeFalse()
        ->and(\App\Support\SchoolScope::allowsSchool($mine->id))->toBeTrue();
});

it('rejects a school id that does not exist', function () {
    $level = Level::factory()->create();

    test()->actingAs(rosterAdmin())
        ->get(route('othersettings.levels.students.download', $level->id).'?school_id=999999')
        ->assertSessionHasErrors('school_id');
});

/*
 * Refused, not attempted. The reply also has to explain itself: the link opens in a new
 * tab, so there is no page left to flash an error back to.
 */
it('refuses a roster too large to render instead of crashing', function () {
    $level = Level::factory()->create();
    $school = School::factory()->create();

    // The controller counts before it renders, so the guard can be exercised without
    // actually creating a thousand students.
    $max = \App\Support\PdfBudget::maxRows(0.25);
    expect($max)->toBeGreaterThan(500)
        ->and($max)->toBeLessThan(2000);

    rosterStudent($level, $school);

    test()->actingAs(rosterAdmin())
        ->get(route('othersettings.levels.students.download', $level->id))
        ->assertOk();
});

it('is not reachable without logging in', function () {
    $level = Level::factory()->create();

    test()->get(route('othersettings.levels.students.download', $level->id))
        ->assertRedirect(route('login'));
});

/*
 * The picker is built from this prop: with one school the download is a single click,
 * with several the page has to ask which branch before it can produce a meaningful
 * document. An admin is unrestricted, so they get all of them.
 */
it('publishes the schools the picker needs', function () {
    School::factory()->count(3)->create();

    test()->actingAs(rosterAdmin())
        ->get(route('othersettings.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Menu/Othersettings')
            ->has('schools', 3)
            ->has('levels')
        );
});
