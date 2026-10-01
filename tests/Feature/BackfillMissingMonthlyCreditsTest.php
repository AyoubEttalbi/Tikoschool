<?php

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;
use Illuminate\Support\Carbon;

/*
 * BACKFILL FOR THE OCTOBER-2026 STALE-SCHEDULER INCIDENT.
 *
 * A second scheduler credited wallets with no ledger rows and marked tracker
 * records settled. `wallet:check --repair` alone would have robbed teachers
 * of real earnings (their records read paid, the ledger holds nothing), so
 * this command restores the missing schedule.monthly rows first:
 *
 *   php artisan wallet:backfill-monthly           # DRY RUN (default)
 *   php artisan wallet:backfill-monthly --apply   # write the rows
 *
 * A month qualifies only when ALL of these hold:
 *  - the tracker record is active and the service month has arrived,
 *  - the tracker treats the month as settled (not queued),
 *  - the ledger holds NOTHING for (teacher, invoice, month),
 *  - the invoice was born after the ledger started recording (era guard:
 *    pre-ledger history has no rows by design and must not be "fixed"),
 *  - the invoice is a plain full-month shape (partial-month splits stay
 *    manual — the command reports them instead of guessing).
 *
 * Re-runs are no-ops: every written row carries the standard idempotency key.
 */

afterEach(fn () => Carbon::setTestNow());

function backfillSeed(bool $preLedger = false, bool $partial = false): array
{
    // Frozen in October 2026 so the 2026-10 service month has always arrived,
    // no matter when the suite runs.
    Carbon::setTestNow('2026-10-05 10:00:00');
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $student = Student::factory()->create();
    $offer = Offer::factory()->create([
        'price' => 300,
        'subjects' => ['Math'],
        'percentage' => ['Math' => 50],
    ]);
    $membership = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);

    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'billDate' => '2026-10-01',
        'selected_months' => ['2026-10'],
        'totalAmount' => 300,
        'amountPaid' => 300,
        'rest' => 0,
        'includePartialMonth' => $partial,
        'partialMonthAmount' => $partial ? 100 : null,
    ]);

    if ($preLedger) {
        // Born before the ledger era (see the marker row below): history with
        // no rows by design, never a backfill candidate.
        $invoice->forceFill(['created_at' => Carbon::parse('2019-05-05 10:00:00')])->save();
    }

    // Ledger-era marker: the era guard measures invoice age against the oldest
    // ledger row. Pinned in 2020 so the test never depends on wall-clock time.
    $marker = TeacherWalletEntry::create([
        'teacher_id' => $teacher->id,
        'invoice_id' => null,
        'amount' => 1.0,
        'balance_after' => 1.0,
        'reason' => TeacherWalletEntry::REASON_ADJUSTMENT,
        'month' => '2020-02',
        'teacher_subject' => 'Math',
    ]);
    $marker->forceFill(['created_at' => Carbon::parse('2020-06-01 10:00:00')])->save();
    $teacher->forceFill(['wallet' => 1.0])->save();

    $record = TeacherMembershipPayment::create([
        'teacher_id' => $teacher->id,
        'invoice_id' => $invoice->id,
        'student_id' => $student->id,
        'membership_id' => $membership->id,
        'teacher_subject' => 'Math',
        'teacher_percentage' => 50,
        'total_teacher_amount' => 150.0,
        'total_paid_to_teacher' => 150.0,
        'monthly_teacher_amount' => 150.0,
        'selected_months' => ['2026-10'],
        'months_rest_not_paid_yet' => [],
        'payment_percentage' => 100,
        'is_active' => true,
    ]);

    return [$teacher, $invoice, $record];
}

test('dry run lists a settled month the ledger never saw, and changes nothing', function () {
    [$teacher, $invoice] = backfillSeed();

    $this->artisan('wallet:backfill-monthly', ['--teacher' => $teacher->id])
        ->assertSuccessful();

    expect(TeacherWalletEntry::where('teacher_id', $teacher->id)->where('invoice_id', $invoice->id)->count())->toBe(0)
        ->and((float) $teacher->fresh()->wallet)->toBe(1.0);
});

test('apply writes the missing monthly row once, and re-runs are no-ops', function () {
    [$teacher, $invoice] = backfillSeed();

    $this->artisan('wallet:backfill-monthly', ['--teacher' => $teacher->id, '--apply' => true])
        ->assertSuccessful();

    $rows = TeacherWalletEntry::where('teacher_id', $teacher->id)->where('invoice_id', $invoice->id)->get();

    expect($rows->count())->toBe(1)
        ->and((float) $rows->first()->amount)->toEqual(150.0)
        ->and($rows->first()->month)->toBe('2026-10')
        ->and($rows->first()->reason)->toBe(TeacherWalletEntry::REASON_MONTHLY)
        ->and((float) $teacher->fresh()->wallet)->toBe(151.0);

    $this->artisan('wallet:backfill-monthly', ['--teacher' => $teacher->id, '--apply' => true])
        ->assertSuccessful();

    expect(TeacherWalletEntry::where('teacher_id', $teacher->id)->where('invoice_id', $invoice->id)->count())->toBe(1);
});

test('a month the ledger already holds is never listed', function () {
    [$teacher, $invoice] = backfillSeed();

    TeacherWalletEntry::create([
        'teacher_id' => $teacher->id,
        'invoice_id' => $invoice->id,
        'amount' => 150.0,
        'balance_after' => 151.0,
        'reason' => TeacherWalletEntry::REASON_MONTHLY,
        'month' => '2026-10',
        'teacher_subject' => 'Math',
    ]);

    $this->artisan('wallet:backfill-monthly', ['--teacher' => $teacher->id, '--apply' => true])
        ->assertSuccessful();

    expect(TeacherWalletEntry::where('teacher_id', $teacher->id)->where('invoice_id', $invoice->id)->count())->toBe(1);
});

test('pre-ledger history is left alone', function () {
    [$teacher, $invoice] = backfillSeed(preLedger: true);

    $this->artisan('wallet:backfill-monthly', ['--teacher' => $teacher->id, '--apply' => true])
        ->assertSuccessful();

    expect(TeacherWalletEntry::where('teacher_id', $teacher->id)->where('invoice_id', $invoice->id)->count())->toBe(0);
});

test('partial-month invoices are reported, not guessed', function () {
    [$teacher, $invoice] = backfillSeed(partial: true);

    $this->artisan('wallet:backfill-monthly', ['--teacher' => $teacher->id, '--apply' => true])
        ->assertSuccessful();

    expect(TeacherWalletEntry::where('teacher_id', $teacher->id)->where('invoice_id', $invoice->id)->count())->toBe(0);
});

test('an inflated paid-total on a fully-matured record is reset to the ledger figure', function () {
    // The incident's second wound: the outside writer also bumped paid, so the
    // monthly guard would skip months the teacher was never given.
    [$teacher, $invoice, $record] = backfillSeed();

    TeacherWalletEntry::create([
        'teacher_id' => $teacher->id,
        'invoice_id' => $invoice->id,
        'amount' => 150.0,
        'balance_after' => 151.0,
        'reason' => TeacherWalletEntry::REASON_MONTHLY,
        'month' => '2026-10',
        'teacher_subject' => 'math',
    ]);
    $record->forceFill(['total_paid_to_teacher' => 300.0])->save();

    $this->artisan('wallet:backfill-monthly', ['--teacher' => $teacher->id, '--apply' => true])
        ->assertSuccessful();

    expect((float) $record->fresh()->total_paid_to_teacher)->toBe(150.0)
        ->and(TeacherWalletEntry::where('teacher_id', $teacher->id)->where('invoice_id', $invoice->id)->count())->toBe(1);
});

test('a month reversed to zero is settled by the reversal, never re-credited', function () {
    [$teacher, $invoice] = backfillSeed();

    TeacherWalletEntry::create([
        'teacher_id' => $teacher->id,
        'invoice_id' => $invoice->id,
        'amount' => 150.0,
        'balance_after' => 151.0,
        'reason' => TeacherWalletEntry::REASON_MONTHLY,
        'month' => '2026-10',
        'teacher_subject' => 'math',
    ]);
    TeacherWalletEntry::create([
        'teacher_id' => $teacher->id,
        'invoice_id' => $invoice->id,
        'amount' => -150.0,
        'balance_after' => 1.0,
        'reason' => TeacherWalletEntry::REASON_REVERSAL,
        'month' => '2026-10',
        'teacher_subject' => 'math',
    ]);

    $this->artisan('wallet:backfill-monthly', ['--teacher' => $teacher->id, '--apply' => true])
        ->assertSuccessful();

    expect(TeacherWalletEntry::where('teacher_id', $teacher->id)->where('invoice_id', $invoice->id)->count())->toBe(2);
});

test('reconcile top-ups with no month do not trigger a second monthly credit', function () {
    // reconcilePaidMonthsForInvoice() writes month = NULL lumps. A per-month
    // sum cannot see them; the invoice-level net must.
    [$teacher, $invoice, $record] = backfillSeed();

    TeacherWalletEntry::create([
        'teacher_id' => $teacher->id,
        'invoice_id' => $invoice->id,
        'amount' => 150.0,
        'balance_after' => 151.0,
        'reason' => TeacherWalletEntry::REASON_RECONCILE,
        'month' => null,
        'teacher_subject' => 'math',
    ]);

    $this->artisan('wallet:backfill-monthly', ['--teacher' => $teacher->id, '--apply' => true])
        ->assertSuccessful();

    expect(TeacherWalletEntry::where('teacher_id', $teacher->id)->where('invoice_id', $invoice->id)->count())->toBe(1);
});

test('trashed invoices, unpaid bills and removed teachers are never backfilled', function () {
    [$teacher, $invoice] = backfillSeed();
    $invoice->delete();

    $this->artisan('wallet:backfill-monthly', ['--teacher' => $teacher->id, '--apply' => true])
        ->assertSuccessful();

    expect(TeacherWalletEntry::where('teacher_id', $teacher->id)->where('invoice_id', $invoice->id)->count())->toBe(0);

    [$teacher2, $invoice2] = backfillSeed();
    $invoice2->forceFill(['amountPaid' => 100.0, 'rest' => 200.0])->save();

    $this->artisan('wallet:backfill-monthly', ['--teacher' => $teacher2->id, '--apply' => true])
        ->assertSuccessful();

    expect(TeacherWalletEntry::where('teacher_id', $teacher2->id)->where('invoice_id', $invoice2->id)->count())->toBe(0);

    [$teacher3, $invoice3, $record3] = backfillSeed();
    $membership = $record3->membership;
    $membership->forceFill(['teachers' => []])->save();

    $this->artisan('wallet:backfill-monthly', ['--teacher' => $teacher3->id, '--apply' => true])
        ->assertSuccessful();

    expect(TeacherWalletEntry::where('teacher_id', $teacher3->id)->where('invoice_id', $invoice3->id)->count())->toBe(0);
});

test('normalisation leaves partial-month records for a human', function () {
    [$teacher, $invoice, $record] = backfillSeed(partial: true);
    $record->forceFill(['total_paid_to_teacher' => 500.0])->save();

    $this->artisan('wallet:backfill-monthly', ['--teacher' => $teacher->id, '--apply' => true])
        ->assertSuccessful();

    expect((float) $record->fresh()->total_paid_to_teacher)->toBe(500.0);
});

test('a listed candidate reversed before apply is skipped', function () {
    [$teacher, $invoice] = backfillSeed();

    $this->artisan('wallet:backfill-monthly', ['--teacher' => $teacher->id])
        ->assertSuccessful();

    // A reversal for the same month arrives between scan and apply.
    TeacherWalletEntry::create([
        'teacher_id' => $teacher->id,
        'invoice_id' => $invoice->id,
        'amount' => 150.0,
        'balance_after' => 151.0,
        'reason' => TeacherWalletEntry::REASON_MONTHLY,
        'month' => '2026-10',
        'teacher_subject' => 'math',
    ]);
    TeacherWalletEntry::create([
        'teacher_id' => $teacher->id,
        'invoice_id' => $invoice->id,
        'amount' => -150.0,
        'balance_after' => 1.0,
        'reason' => TeacherWalletEntry::REASON_REVERSAL,
        'month' => '2026-10',
        'teacher_subject' => 'math',
    ]);

    $this->artisan('wallet:backfill-monthly', ['--teacher' => $teacher->id, '--apply' => true])
        ->assertSuccessful();

    expect(TeacherWalletEntry::where('teacher_id', $teacher->id)->where('invoice_id', $invoice->id)->count())->toBe(2);
});
