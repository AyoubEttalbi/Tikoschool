<?php

use App\Models\Level;
use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;
use App\Services\TeacherWalletService;
use Illuminate\Support\Carbon;
use Tests\Support\PaymentScenario;

/*
 * payouts:settle-to-ledger — the one-off correction of the Nov 2026 audit.
 * Each test rebuilds a broken shape prod holds today (the way the old code left
 * it), then checks the dry run writes nothing and --confirm settles it once.
 */

beforeEach(fn () => Level::factory()->create());
afterEach(fn () => Carbon::setTestNow());

/** The old edit path's extra credit, exactly as prod recorded it. */
function oldBugCredit(PaymentScenario $s, string $subject, float $amount): void
{
    $record = $s->record($subject);

    (new TeacherWalletService)->credit(
        $s->teachers[$subject], $amount, TeacherWalletEntry::REASON_ADJUSTMENT,
        $record->id, null, $s->invoice->id,
        'immediate amount adjusted on invoice update (record '.$record->id.')', $subject
    );
}

test('an edit double credit is recovered once, and a dry run writes nothing', function () {
    // Tiko 646 shape: monthly job 70, then the old edit +70, record clamped to 70.
    Carbon::setTestNow('2026-09-29 21:00:00');
    $s = PaymentScenario::make(['Math' => 20]);
    $s->bill(months: ['2026-10'], total: 350, paid: 350, billDate: '2026-10-01');
    $s->advanceToMonth('2026-10');
    oldBugCredit($s, 'Math', 70);
    $s->record('Math')->update(['total_teacher_amount' => 70, 'total_paid_to_teacher' => 70]);
    expect($s->wallet('Math'))->toBe(140.0);

    Carbon::setTestNow('2026-11-04 10:00:00');
    $before = TeacherWalletEntry::count();
    $this->artisan('payouts:settle-to-ledger')->assertSuccessful();
    expect(TeacherWalletEntry::count())->toBe($before, 'Dry run must not write.');

    $this->artisan('payouts:settle-to-ledger --confirm')->assertSuccessful();

    expect($s->wallet('Math'))->toBe(70.0)
        ->and(TeacherWalletEntry::latest('id')->first()->reason)->toBe(TeacherWalletEntry::REASON_ADJUSTMENT);

    $after = TeacherWalletEntry::count();
    $this->artisan('payouts:settle-to-ledger --confirm')->assertSuccessful();
    expect(TeacherWalletEntry::count())->toBe($after, 'A second run finds nothing to do.');
});

test('an over-credit already paid out becomes a debt', function () {
    Carbon::setTestNow('2026-09-29 21:00:00');
    $s = PaymentScenario::make(['Math' => 20]);
    $s->bill(months: ['2026-10'], total: 350, paid: 350, billDate: '2026-10-01');
    $s->advanceToMonth('2026-10');
    oldBugCredit($s, 'Math', 70);
    $s->record('Math')->update(['total_paid_to_teacher' => 70]);

    (new TeacherWalletService)->debit($s->teachers['Math'], 140, TeacherWalletEntry::REASON_PAYOUT, null, null, null, 'cash');
    expect($s->wallet('Math'))->toBe(0.0);

    Carbon::setTestNow('2026-11-04 10:00:00');
    $this->artisan('payouts:settle-to-ledger --confirm')->assertSuccessful();

    expect($s->wallet('Math'))->toBe(-70.0, 'Shown as a debt, absorbed by the next commissions.');
});

test('money left on a deleted invoice after its reversal is recovered', function () {
    // CRC 7065 shape: immediate 20 + subject-less reconcile 20, reversal took 20.
    Carbon::setTestNow('2026-09-03 18:00:00');
    $s = PaymentScenario::make(['Math' => 10]);
    $s->bill(months: ['2026-09'], total: 400, paid: 200, billDate: '2026-09-01');
    $record = $s->record('Math');
    $wallet = new TeacherWalletService;
    $wallet->credit($s->teachers['Math'], 20, TeacherWalletEntry::REASON_RECONCILE, $record->id, null, $s->invoice->id, 'reconcile to 40.00');
    $wallet->debit($s->teachers['Math'], 20, TeacherWalletEntry::REASON_REVERSAL, $record->id, null, $s->invoice->id, 'invoice deleted', 'Math');
    $record->update(['is_active' => false, 'total_paid_to_teacher' => 0]);
    $s->invoice->delete();
    expect($s->wallet('Math'))->toBe(20.0);

    $this->artisan('payouts:settle-to-ledger --confirm')->assertSuccessful();

    expect($s->wallet('Math'))->toBe(0.0);
});

test('deliberate rate-raise back-pay is kept and the record follows it', function () {
    // CRC Sep 12: 70 paid at 14%, admin back-paid +5 to the raised 15%.
    Carbon::setTestNow('2026-09-03 10:00:00');
    $s = PaymentScenario::make(['ENG' => 14]);
    $s->bill(months: ['2026-09'], total: 500, paid: 500, billDate: '2026-09-01');
    $s->offer->update(['percentage' => ['ENG' => 15]]);
    (new TeacherWalletService)->credit(
        $s->teachers['ENG'], 5, TeacherWalletEntry::REASON_ADJUSTMENT, null, '2026-09', $s->invoice->id,
        'rate-raise back-pay Sep-2026 (record pins billing rate)', 'ENG'
    );
    expect($s->wallet('ENG'))->toBe(75.0);

    $before = TeacherWalletEntry::count();
    $this->artisan('payouts:settle-to-ledger --confirm')->assertSuccessful();

    expect($s->wallet('ENG'))->toBe(75.0, 'The back-pay stays.')
        ->and(TeacherWalletEntry::count())->toBe($before, 'No money moves.')
        ->and((float) $s->record('ENG')->total_teacher_amount)->toBe(75.0, 'The record now agrees with the ledger.');
});

test('a removed teacher holding a month the replacement was paid for gives it back', function () {
    // CRC 7502 shape: PC teacher swapped past the window, replacement paid too.
    Carbon::setTestNow('2026-10-01 10:00:00');
    $s = PaymentScenario::make(['Math' => 20, 'PC' => 20]);
    $s->bill(months: ['2026-10'], total: 300, paid: 300, billDate: '2026-10-01');

    $replacement = App\Models\Teacher::factory()->create(['wallet' => 0]);
    $s->record('PC')->update(['is_active' => false]);
    $s->membership->update(['teachers' => [
        ['teacherId' => $s->teachers['Math']->id, 'subject' => 'Math'],
        ['teacherId' => $replacement->id, 'subject' => 'PC'],
    ]]);
    (new App\Services\TeacherMembershipPaymentService)->processInvoicePayment($s->invoice->fresh(), [
        'totalAmount' => 300, 'amountPaid' => 300, 'rest' => 0, 'includePartialMonth' => false, 'partialMonthAmount' => 0,
    ]);
    expect(round((float) $replacement->fresh()->wallet, 2))->toBe(60.0)
        ->and($s->wallet('PC'))->toBe(60.0, 'Fixture: the PC month is paid twice.');

    Carbon::setTestNow('2026-10-20 10:00:00');
    $this->artisan('payouts:settle-to-ledger --confirm')->assertSuccessful();

    expect($s->wallet('PC'))->toBe(0.0)
        ->and(round((float) $replacement->fresh()->wallet, 2))->toBe(60.0)
        ->and($s->wallet('Math'))->toBe(60.0);
});

test('a kept teacher reversed and never paid back is paid', function () {
    // Tiko 796 shape: record active, reversed to 0, owed 60.
    Carbon::setTestNow('2026-10-02 10:00:00');
    $s = PaymentScenario::make(['Math' => 20]);
    $s->bill(months: ['2026-10'], total: 300, paid: 300, billDate: '2026-10-01');
    $record = $s->record('Math');
    (new TeacherWalletService)->debit($s->teachers['Math'], 60, TeacherWalletEntry::REASON_REVERSAL, $record->id, null, $s->invoice->id, 'invoice deleted', 'Math');
    $record->update(['total_paid_to_teacher' => 0, 'immediate_wallet_amount' => 0]);

    Carbon::setTestNow('2026-11-04 10:00:00');
    $this->artisan('payouts:settle-to-ledger --confirm')->assertSuccessful();

    expect($s->wallet('Math'))->toBe(60.0)
        ->and((float) TeacherMembershipPayment::find($record->id)->total_paid_to_teacher)->toBe(60.0);
});

test('the invoice audit flags a ledger holding more than the commission', function () {
    Carbon::setTestNow('2026-09-29 21:00:00');
    $s = PaymentScenario::make(['Math' => 20]);
    $s->bill(months: ['2026-10'], total: 350, paid: 350, billDate: '2026-10-01');
    $s->advanceToMonth('2026-10');
    oldBugCredit($s, 'Math', 70);
    // Record and ledger AGREE at 140 — only the commission check can see it.
    $s->record('Math')->update(['total_paid_to_teacher' => 140]);

    $this->artisan('wallet:audit-invoices')
        ->expectsOutputToContain('over commission by 70.00')
        ->assertFailed();

    $this->artisan('payouts:settle-to-ledger --confirm')->assertSuccessful();
    $this->artisan('wallet:audit-invoices')->assertSuccessful();
});

test('the payout audit reports a recovery debt as explained', function () {
    Carbon::setTestNow('2026-11-03 10:00:00');
    $s = PaymentScenario::make(['Math' => 60]);
    $s->bill(months: ['2026-11'], total: 150, paid: 150, billDate: '2026-11-01');
    (new TeacherWalletService)->debit($s->teachers['Math'], 90, TeacherWalletEntry::REASON_PAYOUT, null, null, null, 'cash');
    $s->editInvoice(newTotal: 150, newPaid: 110);
    expect($s->wallet('Math'))->toBe(-24.0);

    $this->artisan('payouts:audit')
        ->expectsOutputToContain('Recovery debts')
        ->doesntExpectOutputToContain('Unexplained negative wallets')
        ->assertSuccessful();
});

test('a deleted membership keeps the commission but gives back a double credit', function () {
    // CRC 7187 shape: membership deleted (settled), record frozen, ledger over.
    Carbon::setTestNow('2026-09-07 10:00:00');
    $s = PaymentScenario::make(['Math' => 20]);
    $s->bill(months: ['2026-09'], total: 300, paid: 300, billDate: '2026-09-01');
    oldBugCredit($s, 'Math', 10);
    $s->record('Math')->update(['is_active' => false]);
    $s->membership->delete();
    expect($s->wallet('Math'))->toBe(70.0);

    Carbon::setTestNow('2026-11-04 10:00:00');
    $this->artisan('payouts:settle-to-ledger --confirm')->assertSuccessful();

    expect($s->wallet('Math'))->toBe(60.0, 'The earned 60 stays, the extra 10 comes back.');
});
