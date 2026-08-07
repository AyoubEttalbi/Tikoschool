<?php

use App\Models\Level;
use Illuminate\Support\Carbon;
use Tests\Support\PaymentScenario;

/*
 * SCENARIO SUITE 2 — several months on one invoice, and the clock moving.
 *
 * The design is: the current month is credited immediately, and each remaining month is
 * credited by teachers:process-monthly-payments when that month arrives.
 *
 * Two invariants hold no matter how the clerk sequences things:
 *
 *   1. SOLVENCY   — a teacher never ends up holding more than
 *                   (what the student paid) x (their percentage).
 *   2. COMPLETION — once every selected month has passed, the teacher holds exactly that
 *                   amount. Money must not be stranded in a queue nobody drains.
 *
 * Most payout bugs in this codebase were a violation of one of those two, so they are
 * asserted directly rather than through intermediate bookkeeping columns.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-08-10 09:00:00');
    Level::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('three months paid in full: one third now, the rest as each month arrives', function () {
    $s = PaymentScenario::make(['Math' => 30]);

    $s->bill(months: ['2026-08', '2026-09', '2026-10'], total: 3000, paid: 3000);

    // 30% of 3000 = 900 total, spread over three months = 300 per month.
    expect($s->wallet('Math'))->toBe(300.0, 'Only the current month is credited up front.');

    $s->advanceToMonth('2026-09');
    expect($s->wallet('Math'))->toBe(600.0);

    $s->advanceToMonth('2026-10');
    expect($s->wallet('Math'))->toBe(900.0, 'After the last month the teacher holds the whole commission.');
});

test('three months paid in HALF: the teacher tracks the half, month by month', function () {
    $s = PaymentScenario::make(['Math' => 30]);

    $s->bill(months: ['2026-08', '2026-09', '2026-10'], total: 3000, paid: 1500);

    // 30% of 1500 = 450 total, 150 per month.
    expect($s->wallet('Math'))->toBe(150.0);

    $s->advanceToMonth('2026-09');
    $s->advanceToMonth('2026-10');

    expect($s->wallet('Math'))->toBe(450.0, 'Commission follows what was paid, never the invoice total.');
});

test('three months paid nothing: no month ever credits anything', function () {
    $s = PaymentScenario::make(['Math' => 30]);

    $s->bill(months: ['2026-08', '2026-09', '2026-10'], total: 3000, paid: 0);

    $s->advanceToMonth('2026-09');
    $s->advanceToMonth('2026-10');
    $s->advanceToMonth('2026-11');

    expect($s->wallet('Math'))->toBe(0.0, 'An unpaid invoice must never credit a teacher, however many months pass.')
        ->and($s->ledgerTotal('Math'))->toBe(0.0);
});

test('the student pays one month now and the rest next month', function () {
    // The scenario the user described: three months selected, only the first one paid,
    // then the family comes back in September and settles the rest.
    $s = PaymentScenario::make(['Math' => 30]);

    $s->bill(months: ['2026-08', '2026-09', '2026-10'], total: 3000, paid: 1000);

    // 30% of 1000 = 300 owed in total so far.
    expect($s->wallet('Math'))->toBe(100.0, 'One third of the commission on what was actually paid.');

    // September arrives; the cron pays the September slice of what is already owed.
    $s->advanceToMonth('2026-09');
    expect($s->wallet('Math'))->toBe(200.0);

    // Now the family pays the remaining 2000.
    $s->editInvoice(newTotal: 3000, newPaid: 3000);

    // 30% of 3000 = 900. The teacher must never be ahead of that.
    expect($s->wallet('Math'))
        ->toBeLessThanOrEqual(900.0, 'SOLVENCY: the teacher is holding more than the student paid for.');

    $s->advanceToMonth('2026-10');
    $s->advanceToMonth('2026-11');

    expect($s->wallet('Math'))->toBe(900.0, 'COMPLETION: once every month has passed the commission is fully delivered.');
});

test('adding months to an existing invoice extends the payout schedule', function () {
    $s = PaymentScenario::make(['Math' => 50]);

    $s->bill(months: ['2026-08'], total: 1000, paid: 1000);
    expect($s->wallet('Math'))->toBe(500.0);

    // The family extends to three months and pays the difference.
    $s->editInvoice(newTotal: 3000, newPaid: 3000, months: ['2026-08', '2026-09', '2026-10']);

    expect($s->wallet('Math'))
        ->toBeLessThanOrEqual(1500.0, 'SOLVENCY: 50% of 3000 is the ceiling.');

    $s->advanceToMonth('2026-09');
    $s->advanceToMonth('2026-10');
    $s->advanceToMonth('2026-11');

    expect($s->wallet('Math'))->toBe(1500.0, 'COMPLETION: the extended months must all be delivered.');
});

test('the cron can be run many times for the same month without paying twice', function () {
    $s = PaymentScenario::make(['Math' => 30]);

    $s->bill(months: ['2026-08', '2026-09'], total: 2000, paid: 2000);

    Carbon::setTestNow('2026-09-01 09:00:00');
    foreach (range(1, 4) as $ignored) {
        $s->runMonthlyCron('2026-09');
    }

    expect($s->wallet('Math'))->toBe(600.0, '30% of 2000 — four cron runs must not pay four times.');
});

test('running the cron for a month that is not on the invoice pays nothing', function () {
    $s = PaymentScenario::make(['Math' => 30]);

    $s->bill(months: ['2026-08', '2026-09'], total: 2000, paid: 2000);
    $before = $s->wallet('Math');

    Carbon::setTestNow('2026-12-01 09:00:00');
    $s->runMonthlyCron('2026-12');

    expect($s->wallet('Math'))->toBe($before);
});

test('two teachers over three months both land on their exact share', function () {
    $s = PaymentScenario::make(['Math' => 40, 'Physique' => 20]);

    $s->bill(months: ['2026-08', '2026-09', '2026-10'], total: 3000, paid: 3000);

    $s->advanceToMonth('2026-09');
    $s->advanceToMonth('2026-10');

    expect($s->wallet('Math'))->toBe(1200.0)
        ->and($s->wallet('Physique'))->toBe(600.0)
        ->and($s->wallet('Math') + $s->wallet('Physique'))
        ->toBeLessThanOrEqual(3000.0, 'The teachers together can never exceed what the student paid.');
});

test('an amount that does not divide evenly still delivers the exact total', function () {
    // 1000 / 3 = 333.333... — rounding must not leak or invent a dirham.
    $s = PaymentScenario::make(['Math' => 100]);

    $s->bill(months: ['2026-08', '2026-09', '2026-10'], total: 1000, paid: 1000);

    $s->advanceToMonth('2026-09');
    $s->advanceToMonth('2026-10');

    expect($s->wallet('Math'))->toBe(1000.0, 'Rounding must not strand the remainder.');
});

test('settling a multi-month invoice early still releases the commission month by month', function () {
    // The family pays the whole 3-month plan in September. The teacher must NOT receive all
    // three months in September — a multi-month invoice releases month by month whatever the
    // student does. Reconciliation used to hand over the full commission on any edit that
    // moved the paid amount, quietly bypassing the schedule.
    $s = PaymentScenario::make(['Math' => 30]);

    $s->bill(months: ['2026-08', '2026-09', '2026-10'], total: 3000, paid: 1000);
    expect($s->wallet('Math'))->toBe(100.0);

    $s->advanceToMonth('2026-09');
    expect($s->wallet('Math'))->toBe(200.0);

    // Pays the balance. Commission becomes 900; two of the three months have come round.
    $s->editInvoice(newTotal: 3000, newPaid: 3000);

    expect($s->wallet('Math'))
        ->toBe(600.0, 'Two months due out of three: 900 x 2/3. October is not payable yet.')
        ->and($s->record('Math')->months_rest_not_paid_yet)
        ->toBe(['2026-10'], 'The remaining month stays queued for the cron.');

    $s->advanceToMonth('2026-10');

    expect($s->wallet('Math'))->toBe(900.0, 'The last month completes the commission.');
});

test('a single-month invoice paid late is still settled immediately', function () {
    // The month-by-month rule is about multi-month plans. One month means one payment, and
    // topping it up must land in the wallet straight away rather than waiting for a cron.
    $s = PaymentScenario::make(['Math' => 50]);

    $s->bill(months: ['2026-08'], total: 1000, paid: 200);
    expect($s->wallet('Math'))->toBe(100.0);

    $s->editInvoice(newTotal: 1000, newPaid: 1000);

    expect($s->wallet('Math'))->toBe(500.0, 'Nothing is left to schedule on a one-month invoice.')
        ->and($s->record('Math')->months_rest_not_paid_yet)->toBe([]);
});

test('each remaining month pays an equal share after the total changes', function () {
    // monthly_teacher_amount is set when the record is created and was never revisited, so
    // after a raise the middle months stayed short and the balance arrived in one lump on
    // the final month. The teacher should see an even release.
    $s = PaymentScenario::make(['Math' => 30]);

    $s->bill(months: ['2026-08', '2026-09', '2026-10', '2026-11'], total: 4000, paid: 1000);
    $s->editInvoice(newTotal: 4000, newPaid: 4000);

    // 30% of 4000 = 1200 over four months = 300 each; August is due, three months remain.
    expect($s->wallet('Math'))->toBe(300.0);

    $s->advanceToMonth('2026-09');
    expect($s->wallet('Math'))->toBe(600.0, 'An even step, not a short month.');

    $s->advanceToMonth('2026-10');
    expect($s->wallet('Math'))->toBe(900.0);

    $s->advanceToMonth('2026-11');
    expect($s->wallet('Math'))->toBe(1200.0);
});

test('the wallet column never drifts from the ledger across a full multi-month life', function () {
    $s = PaymentScenario::make(['Math' => 35]);

    $s->bill(months: ['2026-08', '2026-09', '2026-10'], total: 2400, paid: 800);
    $s->advanceToMonth('2026-09');
    $s->editInvoice(newTotal: 2400, newPaid: 1600);
    $s->advanceToMonth('2026-10');
    $s->editInvoice(newTotal: 2400, newPaid: 2400);
    $s->advanceToMonth('2026-11');

    expect($s->wallet('Math'))->toBe($s->ledgerTotal('Math'), 'The cached wallet and the append-only ledger must agree.')
        ->and($s->wallet('Math'))->toBe(840.0, '35% of 2400.');
});
