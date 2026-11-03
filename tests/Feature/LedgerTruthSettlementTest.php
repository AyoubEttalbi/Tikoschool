<?php

use App\Models\Level;
use App\Models\TeacherWalletEntry;
use App\Services\TeacherWalletService;
use Illuminate\Support\Carbon;
use Tests\Support\PaymentScenario;

/*
 * LEDGER-TRUTH SETTLEMENT — every edit settles the teacher to what is DUE TO
 * DATE, measured against what the ledger already holds for the record.
 *
 * Each test replays a production case from the Nov 2026 wallet audit:
 *
 *   A  Tiko 646 / CRC 7502 — the monthly job paid the month, then an edit paid
 *      the whole share again (old code diffed "immediate" amounts: 0 -> 70).
 *   B  CRC 7647 / Tiko 345 — lowering the paid amount on a past-month invoice
 *      moved no money, raising it back paid again.
 *   C  CRC 7065 — reconcile credits carried no subject, so the delete reversal
 *      only saw half of what the invoice held.
 *   D  Tiko 1456 — a lowering debit on an empty wallet was silently dropped.
 *      It is now recorded as a debt (negative wallet), never lost.
 *   F  Tiko 796 — a teacher swap reversed kept teachers on a past-month
 *      invoice and the reprocess never paid them back.
 */

beforeEach(function () {
    Level::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Pay a teacher out in cash, the way TransactionController does. */
function payOut(PaymentScenario $s, string $subject, float $amount): void
{
    (new TeacherWalletService)->debit(
        $s->teachers[$subject],
        $amount,
        TeacherWalletEntry::REASON_PAYOUT,
        null,
        null,
        null,
        'test payout'
    );
}

test('A: topping up after the monthly job paid the month pays only the difference', function () {
    // Tiko 646: billed for October on Sep 29 with 300 of 350 paid.
    Carbon::setTestNow('2026-09-29 21:00:00');
    $s = PaymentScenario::make(['Math' => 20]);
    $s->bill(months: ['2026-10'], total: 350, paid: 300, billDate: '2026-10-01');

    expect($s->wallet('Math'))->toBe(0.0, 'Future month: nothing paid at billing.');

    $s->advanceToMonth('2026-10');
    expect($s->wallet('Math'))->toBe(60.0, 'The monthly job pays 20% of 300.');

    // Oct 3: the family pays the remaining 50.
    Carbon::setTestNow('2026-10-03 17:47:00');
    $s->editInvoice(newTotal: 350, newPaid: 350);

    expect($s->wallet('Math'))->toBe(70.0, '20% of 350 — not 60 + 70.')
        ->and($s->ledgerTotal('Math'))->toBe(70.0)
        ->and((float) $s->record('Math')->total_paid_to_teacher)->toBe(70.0);
});

test('A: re-saving with no change after the monthly job moves no money', function () {
    // CRC 7502: an admin re-save with changed_fields [] paid the month again.
    Carbon::setTestNow('2026-09-25 21:40:00');
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-10'], total: 350, paid: 350, billDate: '2026-10-01');
    $s->advanceToMonth('2026-10');

    Carbon::setTestNow('2026-10-06 16:58:00');
    $s->editInvoice(newTotal: 350, newPaid: 350);

    expect($s->wallet('Math'))->toBe(175.0)
        ->and($s->ledgerReasons('Math'))->toBe([TeacherWalletEntry::REASON_MONTHLY]);
});

test('A: a top-up on a multi-month plan pays only the months that came round', function () {
    Carbon::setTestNow('2026-09-20 10:00:00');
    $s = PaymentScenario::make(['Math' => 30]);
    $s->bill(months: ['2026-10', '2026-11', '2026-12'], total: 900, paid: 600, billDate: '2026-10-01');

    $s->advanceToMonth('2026-10');
    expect($s->wallet('Math'))->toBe(60.0, 'October is a third of 30% of 600.');

    Carbon::setTestNow('2026-10-05 10:00:00');
    $s->editInvoice(newTotal: 900, newPaid: 900);

    expect($s->wallet('Math'))->toBe(90.0, 'Only October is due: a third of 30% of 900.');

    $s->advanceToMonth('2026-11');
    $s->advanceToMonth('2026-12');

    expect($s->wallet('Math'))->toBe(270.0, 'The plan lands exactly on 30% of 900.');
});

test('A: lowering the paid amount after the monthly job takes the difference back', function () {
    Carbon::setTestNow('2026-09-25 10:00:00');
    $s = PaymentScenario::make(['Math' => 20]);
    $s->bill(months: ['2026-10'], total: 350, paid: 350, billDate: '2026-10-01');
    $s->advanceToMonth('2026-10');

    Carbon::setTestNow('2026-10-04 10:00:00');
    $s->editInvoice(newTotal: 350, newPaid: 280);

    expect($s->wallet('Math'))->toBe(56.0, '20% of 280.');
});

test('B: lowering then raising a past-month invoice nets to one commission', function () {
    // CRC 7647: 400 -> 200 -> 400 within two minutes, a month after billing.
    Carbon::setTestNow('2026-09-03 10:00:00');
    $s = PaymentScenario::make(['Math' => 25]);
    $s->bill(months: ['2026-09'], total: 400, paid: 400, billDate: '2026-09-01');
    expect($s->wallet('Math'))->toBe(100.0);

    Carbon::setTestNow('2026-10-03 18:26:00');
    $s->editInvoice(newTotal: 200, newPaid: 200);
    expect($s->wallet('Math'))->toBe(50.0, 'Lowering must take the difference back.');

    Carbon::setTestNow('2026-10-03 18:28:00');
    $s->editInvoice(newTotal: 400, newPaid: 400);

    expect($s->wallet('Math'))->toBe(100.0, 'Raising back pays once, not twice.')
        ->and($s->ledgerTotal('Math'))->toBe(100.0);
});

test('C: reconcile credits carry the subject, so a delete takes everything back', function () {
    // CRC 7065: immediate + reconcile, then deleted within the window.
    Carbon::setTestNow('2026-09-03 18:00:00');
    $s = PaymentScenario::make(['Math' => 10]);
    $s->bill(months: ['2026-09'], total: 450, paid: 200, billDate: '2026-09-01');
    expect($s->wallet('Math'))->toBe(20.0);

    Carbon::setTestNow('2026-10-03 14:18:00');
    $s->editInvoice(newTotal: 400, newPaid: 400);
    // The controller stamps the payment moment on every amount change.
    $s->invoice->update(['last_payment_date' => now()]);
    expect($s->wallet('Math'))->toBe(40.0);

    TeacherWalletEntry::where('teacher_id', $s->teachers['Math']->id)
        ->where('reason', '!=', TeacherWalletEntry::REASON_IMMEDIATE)
        ->get()
        ->each(fn ($row) => expect($row->teacher_subject)->toBe('math', 'Every invoice-linked row names its subject.'));

    Carbon::setTestNow('2026-10-03 18:22:00');
    $s->deleteInvoice();

    expect($s->wallet('Math'))->toBe(0.0, 'Nothing may stay behind on a deleted invoice.');
});

test('C: a legacy reconcile row without a subject still counts toward its record', function () {
    Carbon::setTestNow('2026-09-03 18:00:00');
    $s = PaymentScenario::make(['Math' => 10]);
    $s->bill(months: ['2026-09'], total: 400, paid: 400, billDate: '2026-09-01');

    // The shape prod holds today: a reconcile row stamped with '' subject.
    (new TeacherWalletService)->credit(
        $s->teachers['Math'],
        20,
        TeacherWalletEntry::REASON_RECONCILE,
        $s->record('Math')->id,
        null,
        $s->invoice->id,
        'legacy reconcile'
    );
    expect($s->wallet('Math'))->toBe(60.0);

    Carbon::setTestNow('2026-09-05 10:00:00');
    $s->deleteInvoice();

    expect($s->wallet('Math'))->toBe(0.0);
});

test('D: a lowering edit on an empty wallet records a debt instead of losing it', function () {
    // Tiko 1456: 150 -> 110 while the teacher had already been paid out.
    Carbon::setTestNow('2026-11-03 19:38:00');
    $s = PaymentScenario::make(['Math' => 60]);
    $s->bill(months: ['2026-11'], total: 150, paid: 150, billDate: '2026-11-01');
    expect($s->wallet('Math'))->toBe(90.0);

    payOut($s, 'Math', 90);
    expect($s->wallet('Math'))->toBe(0.0);

    Carbon::setTestNow('2026-11-03 19:39:00');
    $s->editInvoice(newTotal: 150, newPaid: 110);

    expect($s->wallet('Math'))->toBe(-24.0, 'The 24 over-credit is owed back, shown as a negative wallet.')
        ->and($s->ledgerTotal('Math'))->toBe(-24.0)
        ->and(TeacherWalletEntry::where('teacher_id', $s->teachers['Math']->id)->latest('id')->value('reason'))
        ->toBe(TeacherWalletEntry::REASON_ADJUSTMENT);
});

test('D: cash payouts never count as money the invoice still holds', function () {
    Carbon::setTestNow('2026-11-03 10:00:00');
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-11'], total: 200, paid: 200, billDate: '2026-11-01');
    payOut($s, 'Math', 60);

    Carbon::setTestNow('2026-11-04 10:00:00');
    $s->editInvoice(newTotal: 200, newPaid: 200);

    expect($s->wallet('Math'))->toBe(40.0, 'A re-save after a payout moves nothing.');
});
