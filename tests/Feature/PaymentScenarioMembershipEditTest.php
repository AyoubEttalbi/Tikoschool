<?php

use App\Models\Level;
use App\Services\TeacherMembershipPaymentService;
use Illuminate\Support\Carbon;
use Tests\Support\PaymentScenario;

/*
 * SCENARIO SUITE 4 — editing a membership's teachers, then re-saving the invoice.
 *
 * THE BUG (prod, Sept 2026 — teacher "Zakaria kassab", wallet 2082.20 vs gains 2282.0)
 * ------------------------------------------------------------------------------------
 * Editing a membership reverses every invoice's teacher pay (wallet debited, record
 * deactivated) — but the reversal never touched the record's own money fields. When the
 * invoice was re-saved seconds later, the update path diffed the new immediate amount
 * against the record's STALE immediate amount, saw a delta of zero, logged "No wallet
 * change needed" and credited nothing. End state: record active + paid-in-full on a
 * live invoice + counted in gains — with the wallet short. No monitor fired:
 * the cron skips fully-paid records, payouts:audit needs an underpaid total or a
 * deleted invoice, and wallet:check only compares the cache against a ledger that is
 * itself missing the money.
 *
 * THE RULE
 * --------
 * A reversal must keep the record's totals truthful (decrement what was actually
 * taken), so that any later reprocessing heals the wallet through the normal delta.
 * Removing a teacher must keep their record dead; deleting an invoice must stay dead.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-04 15:00:00');
    Level::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

test('reversing then re-saving an invoice restores the wallet', function () {
    $s = PaymentScenario::make(['FR' => 100]);
    $s->bill(months: ['2026-09'], total: 350, paid: 350, billDate: '2026-09-01');

    expect($s->wallet('FR'))->toBe(350.0);

    // The membership edit: every invoice's teacher pay is reversed.
    (new TeacherMembershipPaymentService)->reverseInvoicePayments($s->invoice);
    expect($s->wallet('FR'))->toBe(0.0);

    // The invoice re-save seconds later, amounts unchanged (the prod sequence).
    $s->editInvoice(newTotal: 350, newPaid: 350);

    expect($s->wallet('FR'))->toBe(350.0, 'Reprocessing must heal what the reversal took.')
        ->and($s->record('FR')->is_active)->toBeTrue()
        ->and($s->ledgerTotal('FR'))->toBe(350.0);
});

test('a reversal keeps the record totals truthful', function () {
    $s = PaymentScenario::make(['FR' => 100]);
    $s->bill(months: ['2026-09'], total: 350, paid: 350, billDate: '2026-09-01');

    (new TeacherMembershipPaymentService)->reverseInvoicePayments($s->invoice);

    $record = $s->record('FR');
    expect((float) $record->total_paid_to_teacher)->toBe(0.0)
        ->and((float) $record->immediate_wallet_amount)->toBe(0.0)
        ->and($record->is_active)->toBeFalse();
});

test('a removed teacher stays dead while kept teachers heal', function () {
    $s = PaymentScenario::make(['Math' => 50, 'FR' => 50]);
    $s->bill(months: ['2026-09'], total: 700, paid: 700, billDate: '2026-09-01');

    expect($s->wallet('Math'))->toBe(350.0)
        ->and($s->wallet('FR'))->toBe(350.0);

    $service = new TeacherMembershipPaymentService;
    $service->reverseInvoicePayments($s->invoice);

    // The edit drops FR from the membership (prod invoice 7027 shape).
    $frId = $s->teachers['FR']->id;
    $s->membership->update(['teachers' => [
        ['teacherId' => $s->teachers['Math']->id, 'subject' => 'Math'],
    ]]);
    unset($s->teachers['FR']);

    $s->editInvoice(newTotal: 700, newPaid: 700);

    expect($s->wallet('Math'))->toBe(350.0, 'The kept teacher must be healed.');

    $frRecord = \App\Models\TeacherMembershipPayment::where('invoice_id', $s->invoice->id)
        ->where('teacher_id', $frId)
        ->first();
    expect($frRecord->is_active)->toBeFalse('The removed teacher must stay dead.')
        ->and(round((float) \App\Models\TeacherWalletEntry::where('teacher_id', $frId)->sum('amount'), 2))
        ->toBe(0.0, 'The removed teacher must not be re-credited.');
});

test('re-saving twice pays once', function () {
    $s = PaymentScenario::make(['FR' => 100]);
    $s->bill(months: ['2026-09'], total: 350, paid: 350, billDate: '2026-09-01');

    (new TeacherMembershipPaymentService)->reverseInvoicePayments($s->invoice);

    $s->editInvoice(newTotal: 350, newPaid: 350);
    $s->editInvoice(newTotal: 350, newPaid: 350);

    expect($s->wallet('FR'))->toBe(350.0)
        ->and($s->ledgerTotal('FR'))->toBe(350.0, 'A second re-save must converge, not double-pay.');
});

test('reactivation only resurrects assigned teachers whose money is whole', function () {
    $s = PaymentScenario::make(['Math' => 50, 'FR' => 50]);
    $s->bill(months: ['2026-09'], total: 700, paid: 700, billDate: '2026-09-01');

    $service = new TeacherMembershipPaymentService;
    $service->reverseInvoicePayments($s->invoice);

    // FR is removed from the membership before anyone reactivates.
    $s->membership->update(['teachers' => [
        ['teacherId' => $s->teachers['Math']->id, 'subject' => 'Math'],
    ]]);

    $result = $service->reactivatePaymentRecords($s->invoice->fresh());

    $frRecord = \App\Models\TeacherMembershipPayment::where('invoice_id', $s->invoice->id)
        ->where('teacher_id', $s->teachers['FR']->id)
        ->first();

    // Math was reversed (money taken) so it is NOT whole: reactivation must not
    // pretend it back into paid-in-full either — only the money path may heal it.
    $mathRecord = $s->record('Math');

    expect($frRecord->is_active)->toBeFalse('Removed teachers are never resurrected.')
        ->and($mathRecord->is_active)->toBeFalse('Reversed money is restored by reprocessing, not by flipping the flag.')
        ->and($result['reactivated_records'])->toBe(0);
});

test('reactivation still heals an assigned record that was deactivated with money intact', function () {
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-09'], total: 1000, paid: 1000, billDate: '2026-09-01');

    // Past the deadline: no claw-back, money stays, record only deactivated.
    Carbon::setTestNow(now()->copy()->addDays(TeacherMembershipPaymentService::REVERSAL_DEADLINE_DAYS + 1));
    (new TeacherMembershipPaymentService)->reverseInvoicePayments($s->invoice);

    expect($s->wallet('Math'))->toBe(500.0);

    $result = (new TeacherMembershipPaymentService)->reactivatePaymentRecords($s->invoice->fresh());

    expect($s->record('Math')->is_active)->toBeTrue()
        ->and($result['reactivated_records'])->toBe(1);
});

// ----------------------------------------------------------------- membership reprocess

test('editing a membership heals kept teachers without touching the invoice', function () {
    // Prod invoice 7027 shape, had the invoice been reprocessed: kept teachers must
    // come back whole through the membership edit alone, with no invoice re-save.
    $s = PaymentScenario::make(['Math' => 50, 'FR' => 50]);
    $s->bill(months: ['2026-09'], total: 700, paid: 700, billDate: '2026-09-01');

    $service = new TeacherMembershipPaymentService;
    $service->reverseInvoicePayments($s->invoice);

    expect($s->wallet('Math'))->toBe(0.0);

    $result = $service->reprocessMembershipInvoices($s->membership->fresh());

    expect($result['success'])->toBeTrue()
        ->and($s->wallet('Math'))->toBe(350.0, 'The kept teacher is healed by the edit itself.')
        ->and($s->wallet('FR'))->toBe(350.0)
        ->and($s->ledgerTotal('Math'))->toBe(350.0);
});

test('editing a membership pays the added teacher and never resurrects the removed one', function () {    // Prod invoices 7053/7064 shape: Primaire moves from one teacher to another.
    $s = PaymentScenario::make(['Primaire' => 100]);
    $s->bill(months: ['2026-09'], total: 150, paid: 150, billDate: '2026-09-01');

    $oldId = $s->teachers['Primaire']->id;
    expect($s->wallet('Primaire'))->toBe(150.0);

    $service = new TeacherMembershipPaymentService;
    $service->reverseInvoicePayments($s->invoice);

    $replacement = \App\Models\Teacher::factory()->create(['wallet' => 0]);
    $s->membership->update(['teachers' => [
        ['teacherId' => $replacement->id, 'subject' => 'Primaire'],
    ]]);

    $result = $service->reprocessMembershipInvoices($s->membership->fresh());

    $oldLedger = round((float) \App\Models\TeacherWalletEntry::where('teacher_id', $oldId)->sum('amount'), 2);
    $oldRecord = \App\Models\TeacherMembershipPayment::where('invoice_id', $s->invoice->id)
        ->where('teacher_id', $oldId)
        ->first();

    expect($result['success'])->toBeTrue()
        ->and(round((float) $replacement->fresh()->wallet, 2))->toBe(150.0, 'The added teacher is paid.')
        ->and($oldLedger)->toBe(0.0, 'The removed teacher took the reversal and nothing more.')
        ->and($oldRecord->is_active)->toBeFalse('The removed teacher stays dead.');
});

// ----------------------------------------------------------------- the invariant

test('the ledger-vs-record invariant sees a reversed-never-restored row', function () {
    // Current code can no longer PRODUCE this state (the reversal now decrements
    // the totals), so the fixture rebuilds the legacy prod row through the real
    // path first: bill, reverse (money taken, record zeroed and deactivated),
    // then restore the stale totals the old code used to leave behind.
    $s = PaymentScenario::make(['FR' => 100]);
    $s->bill(months: ['2026-09'], total: 350, paid: 350, billDate: '2026-09-01');

    (new TeacherMembershipPaymentService)->reverseInvoicePayments($s->invoice);

    $s->record('FR')->update([
        'total_paid_to_teacher' => 350.0,
        'immediate_wallet_amount' => 350.0,
    ]);

    $found = \App\Support\LedgerShortfall::find()->firstWhere(fn ($row) => $row['invoice_id'] === $s->invoice->id);

    expect($found)->not->toBeNull()
        ->and($found['teacher_id'])->toBe($s->teachers['FR']->id)
        ->and($found['shortfall'])->toBe(350.0)
        ->and($found['assigned'])->toBeTrue()
        ->and($found['shape'])->toBe('dead-but-owed');

    // The legacy prod row (Sept 2026): a later re-save reactivated the record for
    // free, so the same shortfall presents as active + paid-in-full.
    $found['record']->update(['is_active' => true]);

    $zombie = \App\Support\LedgerShortfall::find()->firstWhere(fn ($row) => $row['invoice_id'] === $s->invoice->id);

    expect($zombie)->not->toBeNull()
        ->and($zombie['shortfall'])->toBe(350.0)
        ->and($zombie['shape'])->toBe('active-zombie');
});

test('the ledger-vs-record invariant is quiet when the money is whole', function () {
    $s = PaymentScenario::make(['FR' => 100]);
    $s->bill(months: ['2026-09'], total: 350, paid: 350, billDate: '2026-09-01');

    $found = \App\Support\LedgerShortfall::find()->firstWhere(fn ($row) => $row['invoice_id'] === $s->invoice->id);

    expect($found)->toBeNull();
});

test('the ledger-vs-record invariant flags a dead-but-owed row for a kept teacher', function () {    // Prod invoice 7027 shape: membership edited, invoice never re-saved — the kept
    // teacher's record sits inactive on a live invoice with the money taken.
    $s = PaymentScenario::make(['Math' => 50, 'FR' => 50]);
    $s->bill(months: ['2026-09'], total: 700, paid: 700, billDate: '2026-09-01');

    (new TeacherMembershipPaymentService)->reverseInvoicePayments($s->invoice);

    // Legacy 7027 shape: stale full totals on an inactive record.
    $s->record('Math')->update([
        'total_paid_to_teacher' => 350.0,
        'immediate_wallet_amount' => 350.0,
    ]);

    $found = \App\Support\LedgerShortfall::find()->firstWhere(fn ($row) => $row['invoice_id'] === $s->invoice->id
        && $row['teacher_id'] === $s->teachers['Math']->id);

    expect($found)->not->toBeNull()
        ->and($found['shortfall'])->toBe(350.0)
        ->and($found['assigned'])->toBeTrue()
        ->and($found['shape'])->toBe('dead-but-owed');
});

test('repair credits are idempotent per invoice-month-subject', function () {
    // The repair command reuses one stable tuple per row; a re-run must be
    // refused by the idempotency key instead of paying twice. (A NULL month
    // would be treated as distinct by MySQL and exempt the row — the command
    // therefore always sends a real month.)
    $s = PaymentScenario::make(['FR' => 100]);
    $s->bill(months: ['2026-09'], total: 350, paid: 350, billDate: '2026-09-01');

    $service = new App\Services\TeacherWalletService;
    $teacher = $s->teachers['FR'];

    $args = [$teacher, 50.0, \App\Models\TeacherWalletEntry::REASON_REPAIR, 999999, '2026-09', $s->invoice->id, 'test repair', 'FR'];

    expect($service->credit(...$args))->toBeTrue('First repair credit applies.')
        ->and($service->credit(...$args))->toBeFalse('Repeat repair credit is refused.')
        ->and($s->wallet('FR'))->toBe(400.0, 'Paid exactly once: 350 commission + 50 repair.');
});

test('repair retires removed-teacher zombies without moving money', function () {    // Legacy prod row for a removed teacher: stale full totals, active, wallet
    // short, teacher no longer on the membership (prod invoices 7053/7064).
    $s = PaymentScenario::make(['Math' => 50, 'FR' => 50]);
    $s->bill(months: ['2026-09'], total: 700, paid: 700, billDate: '2026-09-01');

    (new TeacherMembershipPaymentService)->reverseInvoicePayments($s->invoice);

    $frId = $s->teachers['FR']->id;
    $s->membership->update(['teachers' => [
        ['teacherId' => $s->teachers['Math']->id, 'subject' => 'Math'],
    ]]);

    $frRecord = \App\Models\TeacherMembershipPayment::where('invoice_id', $s->invoice->id)
        ->where('teacher_id', $frId)
        ->first();
    $frRecord->update([
        'total_paid_to_teacher' => 350.0,
        'immediate_wallet_amount' => 350.0,
        'is_active' => true,
    ]);

    $exit = \Illuminate\Support\Facades\Artisan::call('payouts:repair-shortfall', [
        '--deactivate-review' => true,
        '--force' => true,
    ]);

    expect($exit)->toBe(0)
        ->and($frRecord->fresh()->is_active)->toBeFalse('Zombie retired.')
        ->and(round((float) \App\Models\TeacherWalletEntry::where('teacher_id', $frId)->sum('amount'), 2))
        ->toBe(0.0, 'Not a dirham moved.')
        ->and($s->record('Math')->is_active)->toBeFalse('Rows outside the REVIEW set are untouched.');
});

test('the invariant compares per subject and flags a removed subject for review', function () {
    // One teacher holding two subjects: a shortfall on one must not be hidden by
    // the other's ledger, and dropping one subject must read REVIEW (not AUTO).
    $s = PaymentScenario::make(['Math' => 30, 'Physique' => 30]);
    $s->membership->update(['teachers' => [
        ['teacherId' => $s->teachers['Math']->id, 'subject' => 'Math'],
        ['teacherId' => $s->teachers['Math']->id, 'subject' => 'Physique'],
    ]]);
    $s->teachers['Physique'] = $s->teachers['Math'];

    $s->bill(months: ['2026-09'], total: 1000, paid: 1000, billDate: '2026-09-01');
    expect($s->wallet('Math'))->toBe(600.0);

    (new TeacherMembershipPaymentService)->reverseInvoicePayments($s->invoice);

    // Legacy zombies: stale full totals, reactivated for free.
    foreach (['Math', 'Physique'] as $subject) {
        $s->record($subject)->update([
            'total_paid_to_teacher' => 300.0,
            'immediate_wallet_amount' => 300.0,
            'is_active' => true,
        ]);
    }

    // Drop Physique, keep Math.
    $s->membership->update(['teachers' => [
        ['teacherId' => $s->teachers['Math']->id, 'subject' => 'Math'],
    ]]);

    $rows = \App\Support\LedgerShortfall::find()
        ->where('invoice_id', $s->invoice->id)
        ->keyBy('subject');

    expect($rows['Math']['shortfall'])->toBe(300.0, 'One subject short is flagged, not netted.')
        ->and($rows['Math']['assigned'])->toBeTrue()
        ->and($rows['Physique']['shortfall'])->toBe(300.0)
        ->and($rows['Physique']['assigned'])->toBeFalse('Removed subject goes to review, never auto-credit.');
});
