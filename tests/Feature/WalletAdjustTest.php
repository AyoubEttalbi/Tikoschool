<?php

use App\Models\Teacher;
use App\Models\TeacherWalletEntry;

/*
 * wallet:adjust — the only sanctioned way to move a wallet by hand.
 *
 * Manual corrections used to go through tinker (no audit trail of intent, no
 * idempotency unless the operator remembered it). This command is a thin,
 * quoteless-safe wrapper over TeacherWalletService: dry-run unless --confirm,
 * duplicate runs refused by the ledger idempotency key.
 */

/** A teacher rostered on a membership holding one invoice (adjust requires the link). */
function linkedAdjustFixture(float $wallet = 0.0): array
{
    $teacher = Teacher::factory()->create(['wallet' => $wallet]);
    $student = \App\Models\Student::factory()->create();
    $membership = \App\Models\Membership::factory()->create([
        'student_id' => $student->id,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);
    $invoice = \App\Models\Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
    ]);

    return [$teacher, $invoice];
}

test('a dry run writes nothing', function () {
    [$teacher, $invoice] = linkedAdjustFixture(100.0);

    $this->artisan('wallet:adjust', [
        '--teacher' => $teacher->id,
        '--amount' => 50,
        '--reason' => 'manual.adjustment',
        '--invoice' => $invoice->id,
        '--note' => 'dry-run-probe',
    ])->assertExitCode(0);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(100.0)
        ->and(TeacherWalletEntry::where('teacher_id', $teacher->id)->count())->toBe(0);
});

test('a confirmed credit moves the wallet and writes the ledger row', function () {
    [$teacher, $invoice] = linkedAdjustFixture(100.0);

    $this->artisan('wallet:adjust', [
        '--teacher' => $teacher->id,
        '--amount' => 50,
        '--reason' => 'manual.adjustment',
        '--invoice' => $invoice->id,
        '--subject' => 'Math',
        '--note' => 'test-credit',
        '--confirm' => true,
    ])->assertExitCode(0);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(150.0);

    $entry = TeacherWalletEntry::where('teacher_id', $teacher->id)->latest('id')->first();

    expect($entry->reason)->toBe('manual.adjustment')
        ->and((float) $entry->amount)->toBe(50.0)
        ->and((int) $entry->invoice_id)->toBe($invoice->id)
        ->and($entry->teacher_subject)->toBe('math', 'Ledger subjects are stored normalised.')
        ->and((float) $entry->balance_after)->toBe(150.0);
});

test('a confirmed debit respects the zero floor', function () {
    [$teacher, $invoice] = linkedAdjustFixture(30.0);

    $this->artisan('wallet:adjust', [
        '--teacher' => $teacher->id,
        '--amount' => 100,
        '--debit' => true,
        '--invoice' => $invoice->id,
        '--note' => 'test-debit',
        '--confirm' => true,
    ])->assertExitCode(0);

    // Service clamps at zero rather than driving negative.
    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(0.0);
});

test('an identical rerun is refused, not double-applied', function () {
    [$teacher, $invoice] = linkedAdjustFixture(0.0);

    $args = [
        '--teacher' => $teacher->id,
        '--amount' => 100,
        '--reason' => 'manual.adjustment',
        '--invoice' => $invoice->id,
        '--month' => '2026-09',
        '--subject' => 'Math',
        '--note' => 'test-idempotent',
        '--confirm' => true,
    ];

    $this->artisan('wallet:adjust', $args)->assertExitCode(0);
    $this->artisan('wallet:adjust', $args)->assertExitCode(1);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(100.0)
        ->and(TeacherWalletEntry::where('teacher_id', $teacher->id)->count())->toBe(1);
});

test('unknown teacher, missing invoice and bad amounts fail closed', function () {
    $invoiceId = \App\Models\Invoice::factory()->create()->id;

    $this->artisan('wallet:adjust', [
        '--teacher' => 999999,
        '--amount' => 10,
        '--invoice' => $invoiceId,
        '--confirm' => true,
    ])->assertExitCode(1);

    $teacher = Teacher::factory()->create(['wallet' => 10.0]);

    // No invoice: unattributed hand money is refused, not written.
    $this->artisan('wallet:adjust', [
        '--teacher' => $teacher->id,
        '--amount' => 10,
        '--confirm' => true,
    ])->assertExitCode(1);

    $this->artisan('wallet:adjust', [
        '--teacher' => $teacher->id,
        '--amount' => 0,
        '--invoice' => $invoiceId,
        '--confirm' => true,
    ])->assertExitCode(1);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(10.0)
        ->and(TeacherWalletEntry::where('teacher_id', $teacher->id)->count())->toBe(0);
});
