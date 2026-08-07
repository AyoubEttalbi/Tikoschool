<?php

use App\Models\Level;
use App\Models\Membership;
use App\Models\Teacher;
use Illuminate\Support\Carbon;
use Tests\Support\PaymentScenario;

/*
 * SCENARIO SUITE 4 — the setup is wrong.
 *
 * Everything up to here assumed a correctly configured offer and membership. This suite is
 * about the other half: a clerk picks an offer whose percentages do not match the teachers
 * on the membership, or a teacher was removed, or the subject was typed differently.
 *
 * THE STANDARD APPLIED HERE
 * -------------------------
 * A misconfiguration must do exactly one of two things:
 *
 *   (a) resolve to a defensible number and log why, or
 *   (b) refuse, and produce a message naming what is wrong and where to fix it.
 *
 * What it must never do is (c): quietly pay 0, or quietly pay too much. Every test below
 * pins down which of (a) or (b) applies, and asserts the message is actually usable —
 * because these are the messages the popup will show.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-08-10 09:00:00');
    Level::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

$thisMonth = '2026-08';

test('a membership with no teachers refuses and names the membership', function () use ($thisMonth) {
    $s = PaymentScenario::make(['Math' => 50]);
    $s->membership->update(['teachers' => []]);
    $s->membership->refresh();

    $s->bill(months: [$thisMonth], total: 1000, paid: 1000);

    // The clerk must be told the membership carries no teacher, and where to fix it.
    expect($s->lastResult['success'])->toBeFalse()
        ->and($s->lastResult['errors'])->not->toBeEmpty()
        ->and(implode(' ', $s->lastResult['errors']))->toContain('Aucun enseignant')
        ->and(implode(' ', $s->lastResult['errors']))->toContain('adhésion');
});

test('an offer whose percentages exceed 100 refuses before any money moves', function () use ($thisMonth) {
    $s = PaymentScenario::make(['Math' => 70, 'Physique' => 60]);

    $s->bill(months: [$thisMonth], total: 1000, paid: 1000);

    expect($s->lastResult['success'])->toBeFalse()
        ->and($s->wallet('Math'))->toBe(0.0, 'Nothing may be credited from an over-allocated offer.')
        ->and($s->wallet('Physique'))->toBe(0.0)
        ->and(implode(' ', $s->lastResult['errors']))->toContain('100');
});

test('two teachers sharing exactly 100 percent is allowed', function () use ($thisMonth) {
    // The boundary must not be off by one — 100% is a legitimate configuration.
    $s = PaymentScenario::make(['Math' => 60, 'Physique' => 40]);

    $s->bill(months: [$thisMonth], total: 1000, paid: 1000);

    expect($s->lastResult['success'])->toBeTrue()
        ->and($s->wallet('Math') + $s->wallet('Physique'))->toBe(1000.0);
});

test('a subject typed in a different case still resolves to the right percentage', function () use ($thisMonth) {
    // Found in production: membership 222 stored "math" against an offer declaring "Math".
    // The lookup used to be a plain array index, so it read 0% and fell through to the
    // equal-distribution fallback — a different number, silently.
    $s = PaymentScenario::make(percentages: ['Math' => 60], teacherSubjects: ['Math']);

    $s->membership->update(['teachers' => [
        ['teacherId' => $s->teachers['Math']->id, 'subject' => '  math '],
    ]]);
    $s->membership->refresh();

    $s->bill(months: [$thisMonth], total: 1000, paid: 1000);

    expect(round((float) $s->teachers['Math']->fresh()->wallet, 2))
        ->toBe(600.0, 'Case and stray whitespace must not change what a teacher is paid.');
});

test('a subject the offer never mentions gets the unallocated remainder', function () use ($thisMonth) {
    // Offer allocates 60% to Math; the membership also carries a Physique teacher the offer
    // says nothing about. The remaining 40% is theirs — a defensible number, not a silent 0.
    $s = PaymentScenario::make(percentages: ['Math' => 60], teacherSubjects: ['Math', 'Physique']);

    $s->bill(months: [$thisMonth], total: 1000, paid: 1000);

    expect($s->wallet('Math'))->toBe(600.0)
        ->and($s->wallet('Physique'))->toBe(400.0, 'The unallocated 40% goes to teachers with no declared share.')
        ->and($s->wallet('Math') + $s->wallet('Physique'))->toBe(1000.0);
});

test('an offer listing more subjects than the student takes is NOT a misconfiguration', function () use ($thisMonth) {
    // A three-subject offer where this student only has a Math teacher is ordinary. The old
    // count-based check refused it outright — a false positive that blocked real invoicing.
    $s = PaymentScenario::make(
        percentages: ['Math' => 40, 'Physique' => 30, 'SVT' => 30],
        teacherSubjects: ['Math'],
    );

    $s->bill(months: [$thisMonth], total: 1000, paid: 1000);

    expect($s->lastResult['success'])->toBeTrue()
        ->and($s->wallet('Math'))->toBe(400.0);
});

test('a teacher who would be paid 0% is refused, naming the teacher and the subject', function () use ($thisMonth) {
    // Math already takes 100%, so the Physique teacher would work for nothing. That is a
    // configuration mistake somebody has to fix, not a number to quietly write to the
    // database — and the message has to say who and what.
    $s = PaymentScenario::make(percentages: ['Math' => 100], teacherSubjects: ['Math', 'Physique']);

    $s->bill(months: [$thisMonth], total: 1000, paid: 1000);

    $message = implode(' ', $s->lastResult['errors']);

    expect($s->lastResult['success'])->toBeFalse()
        ->and($s->wallet('Math'))->toBe(0.0, 'Nothing is credited while the offer is broken.')
        ->and($s->wallet('Physique'))->toBe(0.0)
        ->and($message)->toContain('Physique')
        ->and($message)->toContain('0 DH')
        // Name the teacher, not their id — the secretary has to know which row to open.
        ->and($message)->toContain($s->teachers['Physique']->first_name);
});

test('a teacher deleted from the system is skipped without crashing the invoice', function () use ($thisMonth) {
    $s = PaymentScenario::make(['Math' => 60, 'Physique' => 40]);
    $missingId = Teacher::max('id') + 500;

    $s->membership->update(['teachers' => [
        ['teacherId' => $s->teachers['Math']->id, 'subject' => 'Math'],
        ['teacherId' => $missingId, 'subject' => 'Physique'],
    ]]);
    $s->membership->refresh();

    $s->bill(months: [$thisMonth], total: 1000, paid: 1000);

    expect($s->wallet('Math'))->toBe(600.0, 'The teacher who does exist is still paid correctly.')
        ->and($s->record('Math'))->not->toBeNull();
});

test('an invoice covering no months is refused rather than left unpayable', function () {
    // processInvoicePayment() carries a "fall back to the billing month" branch, but
    // validation refuses first, so that branch is unreachable. Refusing is the better of
    // the two: a record whose schedule was guessed pays a teacher for a month nobody chose.
    $s = PaymentScenario::make(['Math' => 50]);

    $s->bill(months: [], total: 1000, paid: 1000, billDate: '2026-08-10');

    expect($s->lastResult['success'])->toBeFalse()
        ->and(implode(' ', $s->lastResult['errors']))->toContain('mois')
        ->and($s->wallet('Math'))->toBe(0.0);
});

test('an offer with an empty percentage map refuses with a clear reason', function () use ($thisMonth) {
    // An offer saved before anyone filled the percentages in. Left to the fallback it would
    // hand the sole teacher 100% — a payout decision nobody made. (offers.percentage is NOT
    // NULL, so an empty map is the reachable form of "not configured".)
    $s = PaymentScenario::make(['Math' => 50]);
    $s->offer->update(['percentage' => []]);
    $s->membership->refresh();

    $s->bill(months: [$thisMonth], total: 1000, paid: 1000);

    expect($s->lastResult['success'])->toBeFalse()
        ->and(implode(' ', $s->lastResult['errors']))->toContain('pourcentages')
        ->and($s->wallet('Math'))->toBe(0.0);
});

test('every refusal message is in French, like the rest of the interface', function () use ($thisMonth) {
    // These strings go straight into a dialog the secretary reads. English leaked out of
    // validateBeforeProcessing() for a long time precisely because nothing displayed them.
    $s = PaymentScenario::make(['Math' => 50]);
    $s->membership->update(['teachers' => []]);
    $s->membership->refresh();

    $s->bill(months: [$thisMonth], total: 1000, paid: 1000);

    foreach ($s->lastResult['errors'] as $error) {
        expect($error)->not->toMatch('/\b(has no|invalid|failed|assigned|exceed)\b/i', "Untranslated message: {$error}");
    }
});

test('a refused invoice leaves no half-written payout record behind', function () use ($thisMonth) {
    // The important property of a refusal: it is all-or-nothing. A record written before the
    // refusal would be picked up by the monthly cron later.
    $s = PaymentScenario::make(['Math' => 70, 'Physique' => 60]);

    $s->bill(months: [$thisMonth], total: 1000, paid: 1000);

    expect($s->lastResult['success'])->toBeFalse()
        ->and(App\Models\TeacherMembershipPayment::where('invoice_id', $s->invoice->id)->count())
        ->toBe(0, 'A refused invoice must not leave a payout record for the cron to find.');
});
