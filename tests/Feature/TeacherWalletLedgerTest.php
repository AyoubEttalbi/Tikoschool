<?php

use App\Models\Invoice;
use App\Models\Teacher;
use App\Models\TeacherWalletEntry;
use App\Services\TeacherWalletService;

/*
 * The wallet ledger is the structural fix for double-paying teachers.
 *
 * Before it, `teachers.wallet` was a single number mutated from ~10 places with no record of
 * why, so a duplicate credit (reconcile + monthly cron both paying the same month) was
 * invisible and unrecoverable. Now a repeat credit is refused by a database constraint.
 */

test('a credit moves the wallet and records a ledger entry', function () {
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $invoice = Invoice::factory()->create();

    $ok = (new TeacherWalletService())->credit(
        $teacher, 150.0, TeacherWalletEntry::REASON_MONTHLY, null, '2025-09', $invoice->id
    );

    $teacher->refresh();

    expect($ok)->toBeTrue()
        ->and((float) $teacher->wallet)->toBe(150.0)
        ->and(TeacherWalletEntry::where('teacher_id', $teacher->id)->count())->toBe(1);
});

test('the same month cannot be credited twice for the same invoice', function () {
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $invoice = Invoice::factory()->create();
    $service = new TeacherWalletService();

    $first = $service->credit($teacher, 150.0, TeacherWalletEntry::REASON_MONTHLY, null, '2025-09', $invoice->id);
    // Exactly the scenario that caused the 167% overpayment: a second credit for the same
    // (teacher, invoice, month, reason).
    $second = $service->credit($teacher, 150.0, TeacherWalletEntry::REASON_MONTHLY, null, '2025-09', $invoice->id);

    $teacher->refresh();

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and((float) $teacher->wallet)->toBe(150.0)
        ->and(TeacherWalletEntry::where('teacher_id', $teacher->id)->count())->toBe(1);
});

test('different months for the same invoice are both credited', function () {
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $invoice = Invoice::factory()->create();
    $service = new TeacherWalletService();

    $service->credit($teacher, 100.0, TeacherWalletEntry::REASON_MONTHLY, null, '2025-09', $invoice->id);
    $service->credit($teacher, 100.0, TeacherWalletEntry::REASON_MONTHLY, null, '2025-10', $invoice->id);

    $teacher->refresh();

    expect((float) $teacher->wallet)->toBe(200.0);
});

test('repeat payouts are allowed because they carry no invoice', function () {
    $teacher = Teacher::factory()->create(['wallet' => 500]);
    $service = new TeacherWalletService();

    // invoice_id is NULL for payouts; MySQL treats NULLs as distinct in a unique index,
    // so these are deliberately exempt from the idempotency constraint.
    $service->debit($teacher, 100.0, TeacherWalletEntry::REASON_PAYOUT);
    $service->debit($teacher, 100.0, TeacherWalletEntry::REASON_PAYOUT);

    $teacher->refresh();

    expect((float) $teacher->wallet)->toBe(300.0)
        ->and(TeacherWalletEntry::where('teacher_id', $teacher->id)->count())->toBe(2);
});

test('a debit is clamped so the wallet never goes negative', function () {
    $teacher = Teacher::factory()->create(['wallet' => 40]);

    (new TeacherWalletService())->debit($teacher, 100.0, TeacherWalletEntry::REASON_REVERSAL);

    $teacher->refresh();

    expect((float) $teacher->wallet)->toBe(0.0);
});

test('the ledger sum matches the cached wallet', function () {
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $invoice = Invoice::factory()->create();
    $service = new TeacherWalletService();

    $service->credit($teacher, 300.0, TeacherWalletEntry::REASON_IMMEDIATE, null, '2025-09', $invoice->id);
    $service->debit($teacher, 50.0, TeacherWalletEntry::REASON_PAYOUT);

    $teacher->refresh();

    expect($service->ledgerBalance($teacher))->toBe((float) $teacher->wallet)
        ->and($service->drift())->toBeEmpty();
});

test('drift is detected when the wallet is changed behind the ledger', function () {
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $service = new TeacherWalletService();

    $service->credit($teacher, 100.0, TeacherWalletEntry::REASON_ADJUSTMENT);

    // Simulate a direct write that bypasses the service — exactly what the old teacher
    // edit form did on every save.
    Teacher::whereKey($teacher->id)->update(['wallet' => 999]);

    expect($service->drift())->toHaveCount(1);
});

test('an opening balance is only ever recorded once', function () {
    $teacher = Teacher::factory()->create(['wallet' => 250]);
    $service = new TeacherWalletService();

    expect($service->recordOpeningBalance($teacher))->toBeTrue()
        ->and($service->recordOpeningBalance($teacher))->toBeFalse()
        ->and($service->ledgerBalance($teacher))->toBe(250.0)
        ->and($service->drift())->toBeEmpty();
});
