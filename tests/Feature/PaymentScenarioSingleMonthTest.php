<?php

use App\Models\Level;
use Illuminate\Support\Carbon;
use Tests\Support\PaymentScenario;

/*
 * SCENARIO SUITE 1 — one month, one invoice.
 *
 * The rule the whole system rests on:
 *
 *     teacher commission = what the STUDENT ACTUALLY PAID x the teacher's percentage
 *
 * Not the invoice total. A student who pays nothing earns the teacher nothing; a student
 * who pays half earns the teacher half. Every test below is a restatement of that rule
 * under a different sequence of clerk actions.
 *
 * The clock is pinned to the 10th so "current month" is unambiguous and the reversal
 * deadline arithmetic has room on both sides of today.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-08-10 09:00:00');
    Level::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

$thisMonth = '2026-08';

test('student pays the invoice in full — teacher gets exactly their percentage', function () use ($thisMonth) {
    $s = PaymentScenario::make(['Math' => 50]);

    $s->bill(months: [$thisMonth], total: 1000, paid: 1000);

    expect($s->wallet('Math'))->toBe(500.0)
        ->and($s->ledgerTotal('Math'))->toBe(500.0, 'The wallet column and the ledger must agree.')
        ->and((float) $s->record('Math')->total_teacher_amount)->toBe(500.0)
        ->and((float) $s->record('Math')->total_paid_to_teacher)->toBe(500.0);
});

test('student pays half — teacher gets their percentage of the HALF, not of the total', function () use ($thisMonth) {
    $s = PaymentScenario::make(['Math' => 50]);

    $s->bill(months: [$thisMonth], total: 1000, paid: 500);

    expect($s->wallet('Math'))->toBe(250.0, 'Teacher must be paid on what the student handed over, not on the invoice total.')
        ->and($s->ledgerTotal('Math'))->toBe(250.0);
});

test('student pays nothing — teacher gets nothing and no ledger row is written', function () use ($thisMonth) {
    $s = PaymentScenario::make(['Math' => 50]);

    $s->bill(months: [$thisMonth], total: 1000, paid: 0);

    expect($s->wallet('Math'))->toBe(0.0)
        ->and($s->ledgerTotal('Math'))->toBe(0.0)
        ->and($s->ledgerReasons('Math'))->toBe([], 'A zero payment must not write a wallet movement at all.');
});

test('two teachers split the offer according to their own percentages', function () use ($thisMonth) {
    $s = PaymentScenario::make(['Math' => 60, 'Physique' => 40]);

    $s->bill(months: [$thisMonth], total: 1000, paid: 1000);

    expect($s->wallet('Math'))->toBe(600.0)
        ->and($s->wallet('Physique'))->toBe(400.0);
});

test('two teachers split a HALF payment proportionally', function () use ($thisMonth) {
    $s = PaymentScenario::make(['Math' => 60, 'Physique' => 40]);

    $s->bill(months: [$thisMonth], total: 1000, paid: 500);

    expect($s->wallet('Math'))->toBe(300.0)
        ->and($s->wallet('Physique'))->toBe(200.0)
        ->and($s->wallet('Math') + $s->wallet('Physique'))
        ->toBe(500.0, 'The two teachers together can never take more than 100% of what the student paid.');
});

test('student pays nothing, then comes back and pays in full', function () use ($thisMonth) {
    // The clerk creates the invoice with 0 paid, the family pays later the same month.
    $s = PaymentScenario::make(['Math' => 50]);

    $s->bill(months: [$thisMonth], total: 1000, paid: 0);
    expect($s->wallet('Math'))->toBe(0.0);

    $s->editInvoice(newTotal: 1000, newPaid: 1000);

    expect($s->wallet('Math'))->toBe(500.0, 'After the top-up the teacher holds their full commission.')
        ->and($s->ledgerTotal('Math'))->toBe(500.0, 'Wallet and ledger must still agree after an edit.');
});

test('student pays half, then tops up to full — the teacher is not paid twice', function () use ($thisMonth) {
    $s = PaymentScenario::make(['Math' => 50]);

    $s->bill(months: [$thisMonth], total: 1000, paid: 500);
    expect($s->wallet('Math'))->toBe(250.0);

    $s->editInvoice(newTotal: 1000, newPaid: 1000);

    expect($s->wallet('Math'))->toBe(500.0, 'The top-up must add 250, not another 500.')
        ->and($s->ledgerTotal('Math'))->toBe(500.0);
});

test('student pays in three instalments and lands exactly on the commission', function () use ($thisMonth) {
    $s = PaymentScenario::make(['Math' => 50]);

    $s->bill(months: [$thisMonth], total: 900, paid: 300);
    expect($s->wallet('Math'))->toBe(150.0);

    $s->editInvoice(newTotal: 900, newPaid: 600);
    expect($s->wallet('Math'))->toBe(300.0);

    $s->editInvoice(newTotal: 900, newPaid: 900);
    expect($s->wallet('Math'))->toBe(450.0);
});

test('a refund — the paid amount is revised DOWN — claws the commission back', function () use ($thisMonth) {
    $s = PaymentScenario::make(['Math' => 50]);

    $s->bill(months: [$thisMonth], total: 1000, paid: 1000);
    expect($s->wallet('Math'))->toBe(500.0);

    // The clerk corrects an over-recorded payment.
    $s->editInvoice(newTotal: 1000, newPaid: 400);

    expect($s->wallet('Math'))->toBe(200.0, 'Correcting the recorded payment down must correct the commission down too.');
});

test('editing an invoice without changing the amount moves no money', function () use ($thisMonth) {
    $s = PaymentScenario::make(['Math' => 50]);

    $s->bill(months: [$thisMonth], total: 1000, paid: 1000);
    $entriesBefore = count($s->ledgerReasons('Math'));

    $s->editInvoice(newTotal: 1000, newPaid: 1000);

    expect($s->wallet('Math'))->toBe(500.0)
        ->and(count($s->ledgerReasons('Math')))
        ->toBe($entriesBefore, 'A no-op edit must not write ledger rows.');
});

test('re-running the same edit repeatedly is stable', function () use ($thisMonth) {
    // Double-submitted forms and impatient clerks are the normal case, not the edge case.
    $s = PaymentScenario::make(['Math' => 50]);

    $s->bill(months: [$thisMonth], total: 1000, paid: 600);

    foreach (range(1, 5) as $ignored) {
        $s->editInvoice(newTotal: 1000, newPaid: 600);
    }

    expect($s->wallet('Math'))->toBe(300.0, 'Five identical submissions must leave the same balance as one.');
});
