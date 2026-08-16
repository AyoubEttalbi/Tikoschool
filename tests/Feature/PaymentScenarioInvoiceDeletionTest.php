<?php

use App\Models\Level;
use App\Models\TeacherWalletEntry;
use App\Services\TeacherMembershipPaymentService;
use Illuminate\Support\Carbon;
use Tests\Support\PaymentScenario;

/*
 * SCENARIO SUITE 3 — deleting an invoice.
 *
 * THE RULE
 * --------
 * Deleting an invoice is always allowed. What is time-limited is the CLAW-BACK.
 *
 *   within REVERSAL_DEADLINE_DAYS of the billing date
 *       -> the invoice goes, and every dirham credited to the teachers for it is taken
 *          back out of their wallets.
 *
 *   after REVERSAL_DEADLINE_DAYS
 *       -> the invoice goes, but the teachers KEEP what they were already paid. The
 *          scheduled months that were never paid are cancelled, so the cron will not pay
 *          them later.
 *
 * In both cases the caller gets back a structured outcome naming each teacher, the amount,
 * and the reason — because "the invoice was deleted but Majid keeps his 240 DH" is
 * something the person clicking delete has to be told, not something to bury in a log.
 *
 * The deadline lives in ONE place, so these tests read it rather than hardcoding 7.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-08-10 09:00:00');
    Level::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

$deadline = fn () => TeacherMembershipPaymentService::REVERSAL_DEADLINE_DAYS;

// ------------------------------------------------------------------ inside the deadline

test('deleting the same day takes the money back', function () {
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-08'], total: 1000, paid: 1000, billDate: '2026-08-10');

    expect($s->wallet('Math'))->toBe(500.0);

    $outcome = $s->deleteInvoice();

    expect($s->wallet('Math'))->toBe(0.0)
        ->and($outcome['reversed'])->toBeTrue()
        ->and($outcome['total_reversed'])->toBe(500.0)
        ->and($outcome['within_deadline'])->toBeTrue();
});

test('deleting on the last allowed day still takes the money back', function () use ($deadline) {
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-08'], total: 1000, paid: 1000, billDate: '2026-08-01');

    // The boundary is inclusive: "if the 7 days are still running, you can remove it."
    Carbon::setTestNow(Carbon::parse('2026-08-01')->addDays($deadline())->setTime(9, 0));

    $outcome = $s->deleteInvoice();

    expect($s->wallet('Math'))->toBe(0.0, 'Day '.$deadline().' is still inside the window.')
        ->and($outcome['within_deadline'])->toBeTrue();
});

test('a half-paid invoice only claws back the half that was actually credited', function () {
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-08'], total: 1000, paid: 400, billDate: '2026-08-10');

    expect($s->wallet('Math'))->toBe(200.0);

    $outcome = $s->deleteInvoice();

    expect($s->wallet('Math'))->toBe(0.0)
        ->and($outcome['total_reversed'])->toBe(200.0);
});

test('deleting an unpaid invoice reverses nothing and says so plainly', function () {
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-08'], total: 1000, paid: 0, billDate: '2026-08-10');

    $outcome = $s->deleteInvoice();

    expect($s->wallet('Math'))->toBe(0.0)
        ->and($outcome['total_reversed'])->toBe(0.0)
        ->and($outcome['reversed'])->toBeFalse();
});

// ----------------------------------------------------------------- outside the deadline

test('after the deadline the teacher keeps the money and the caller is told why', function () use ($deadline) {
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-08'], total: 1000, paid: 1000, billDate: '2026-08-01');

    // The window follows the PAYMENT (made "now", 2026-08-10), not the 1st-of-month
    // billing anchor.
    Carbon::setTestNow(now()->copy()->addDays($deadline() + 1)->setTime(9, 0));

    $outcome = $s->deleteInvoice();

    expect($s->wallet('Math'))->toBe(500.0, 'Past the deadline the credit stands.')
        ->and($outcome['within_deadline'])->toBeFalse()
        ->and($outcome['reversed'])->toBeFalse()
        ->and($outcome['blocked'])->toHaveCount(1)
        ->and($outcome['blocked'][0]['amount'])->toBe(500.0)
        ->and($outcome['blocked'][0]['reason'])->toBe('deadline_passed')
        ->and($outcome['blocked'][0]['teacher_name'])->not->toBeEmpty()
        ->and($outcome['messages'][0])->toContain((string) $deadline());
});

test('an invoice billed in advance is comfortably inside the window', function () {
    // The window follows the PAYMENT, not the bill: this invoice is paid today for a
    // period starting on the 20th, so the payment is zero days old and the claw-back
    // must be allowed. (There is no null-billDate case to test: invoices.billDate is
    // NOT NULL, so the null branch in reverseInvoicePayments() is unreachable
    // defensive code.)
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-09'], total: 1000, paid: 1000, billDate: '2026-09-20');

    $outcome = $s->deleteInvoice();

    expect($outcome['days_since_payment'])->toBe(0)
        ->and($outcome['within_deadline'])->toBeTrue()
        ->and($s->wallet('Math'))->toBe(0.0);
});

test('the window counts from the payment, not the billing anchor', function () use ($deadline) {
    /*
     * The reported bug, exactly. A pupil pays on the 12th for a month billed on the
     * 1st; the invoice is deleted the same day. Measuring from billDate read "11
     * days since billing" the moment the money landed — every deletion was already
     * past the deadline and no wallet was ever corrected.
     */
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-08'], total: 1000, paid: 1000, billDate: '2026-08-01');

    Carbon::setTestNow(Carbon::parse('2026-08-01')->addDays(12)->setTime(9, 0));

    $outcome = $s->deleteInvoice();

    expect($outcome['days_since_payment'])->toBeLessThanOrEqual($deadline())
        ->and($outcome['within_deadline'])->toBeTrue()
        ->and($outcome['reversed'])->toBeTrue()
        ->and($s->wallet('Math'))->toBe(0.0);
});

// ------------------------------------------------------------------------- multi-month

test('a multi-month invoice deleted early claws back every month already credited', function () {
    $s = PaymentScenario::make(['Math' => 30]);
    $s->bill(months: ['2026-08', '2026-09', '2026-10'], total: 3000, paid: 3000, billDate: '2026-08-10');

    expect($s->wallet('Math'))->toBe(300.0, 'Only August has been credited so far.');

    $outcome = $s->deleteInvoice();

    expect($s->wallet('Math'))->toBe(0.0)
        ->and($outcome['total_reversed'])->toBe(300.0);
});

test('a multi-month invoice deleted early after the cron has run claws back all of it', function () {
    $s = PaymentScenario::make(['Math' => 30]);
    $s->bill(months: ['2026-08', '2026-09', '2026-10'], total: 3000, paid: 3000, billDate: '2026-09-01');

    // September and October both run, then the clerk deletes. Three weeks have passed
    // since the original payment, so the window only still be open because money moved
    // again: the pupil tops up on the 1st (exactly what InvoiceController::update()
    // stamps), and the clerk deletes the invoice the same day.
    $s->advanceToMonth('2026-09', dayOfMonth: 1);
    expect($s->wallet('Math'))->toBeGreaterThan(0.0);

    $s->invoice->update(['last_payment_date' => now()]);

    $walletBefore = $s->wallet('Math');
    $outcome = $s->deleteInvoice();

    expect($s->wallet('Math'))->toBe(0.0, 'Everything credited for this invoice comes back.')
        ->and($outcome['total_reversed'])->toBe($walletBefore);
});

test('a multi-month invoice deleted late keeps paid months and cancels the unpaid ones', function () use ($deadline) {
    $s = PaymentScenario::make(['Math' => 30]);
    $s->bill(months: ['2026-08', '2026-09', '2026-10'], total: 3000, paid: 3000, billDate: '2026-08-01');

    $creditedSoFar = $s->wallet('Math');
    expect($creditedSoFar)->toBe(300.0);

    // Past the window of the PAYMENT ("now" = 2026-08-10), not of the bill anchor.
    Carbon::setTestNow(now()->copy()->addDays($deadline() + 1)->setTime(9, 0));
    $outcome = $s->deleteInvoice();

    expect($s->wallet('Math'))->toBe($creditedSoFar, 'What was already paid stays paid.')
        ->and($outcome['within_deadline'])->toBeFalse();

    // The crucial half: the cancelled months must never be paid afterwards.
    (new TeacherMembershipPaymentService)->processMonthlyPayments('2026-09');
    (new TeacherMembershipPaymentService)->processMonthlyPayments('2026-10');

    expect($s->wallet('Math'))->toBe($creditedSoFar, 'A deleted invoice must not keep paying out month after month.');
});

// ------------------------------------------------------------------------ safety rails

test('deleting the same invoice twice never debits twice', function () {
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-08'], total: 1000, paid: 1000, billDate: '2026-08-10');

    $service = new TeacherMembershipPaymentService;
    $service->reverseInvoicePayments($s->invoice);
    $service->reverseInvoicePayments($s->invoice);

    expect($s->wallet('Math'))->toBe(0.0)
        ->and($s->ledgerTotal('Math'))->toBe(0.0, 'A second reversal must not push the ledger negative.');
});

test('a reversal can never drive a wallet below zero', function () {
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-08'], total: 1000, paid: 1000, billDate: '2026-08-10');

    // The teacher is paid out in cash before the invoice is deleted.
    (new App\Services\TeacherWalletService)->debit(
        $s->teachers['Math'],
        500,
        TeacherWalletEntry::REASON_ADJUSTMENT,
        null,
        null,
        null,
        'monthly payout to teacher'
    );
    expect($s->wallet('Math'))->toBe(0.0);

    $outcome = $s->deleteInvoice();

    expect($s->wallet('Math'))
        ->toBe(0.0, 'A negative wallet permanently blocks future payouts — it must be impossible.')
        ->and($outcome['blocked'])
        ->not->toBeEmpty('The clerk must be told the claw-back could not be taken.');
});

test('both subjects are reversed when one teacher teaches two on the same membership', function () {
    $s = PaymentScenario::make(['Math' => 30, 'Physique' => 30]);
    // Point the second subject at the SAME teacher — legitimate, and it used to collide.
    $s->membership->update(['teachers' => [
        ['teacherId' => $s->teachers['Math']->id, 'subject' => 'Math'],
        ['teacherId' => $s->teachers['Math']->id, 'subject' => 'Physique'],
    ]]);
    $s->teachers['Physique'] = $s->teachers['Math'];

    $s->bill(months: ['2026-08'], total: 1000, paid: 1000, billDate: '2026-08-10');
    expect($s->wallet('Math'))->toBe(600.0, '30% + 30% of 1000, both to the same teacher.');

    $outcome = $s->deleteInvoice();

    expect($s->wallet('Math'))->toBe(0.0)
        ->and($outcome['total_reversed'])->toBe(600.0, 'Both subject records must be reversed, not just the first.');
});

test('the outcome names every affected teacher so the UI can list them', function () use ($deadline) {
    $s = PaymentScenario::make(['Math' => 40, 'Physique' => 20]);
    $s->bill(months: ['2026-08'], total: 1000, paid: 1000, billDate: '2026-08-01');

    Carbon::setTestNow(now()->copy()->addDays($deadline() + 1)->setTime(9, 0));
    $outcome = $s->deleteInvoice();

    expect($outcome['blocked'])->toHaveCount(2)
        ->and(array_sum(array_column($outcome['blocked'], 'amount')))->toBe(600.0)
        ->and(array_column($outcome['blocked'], 'subject'))->toEqualCanonicalizing(['Math', 'Physique']);
});
