<?php

use App\Models\Invoice;
use App\Models\InvoicePaymentLog;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * THE DAILY REGISTER AGAINST THE EVENT LOG
 *
 * The reported bug, exactly: a pupil pays 200 on 01-12, the rest (100) on 11-12 — and
 * the day's sheet says 300, because it summed the invoice's cumulative amountPaid
 * instead of the money that moved that day.
 *
 * The cashier now reads invoice_payment_logs: one row per movement, signed, stamped.
 * Below, the scenario is replayed through recordDelta() — the same call the invoice
 * controller makes on create and update — and asserted through the page's totals.
 */

beforeEach(function () {
    Carbon::setTestNow('2025-12-01 10:00:00');
});

afterEach(fn () => Carbon::setTestNow());

function cashStudent(array $overrides = []): Student
{
    $school = $overrides['school'] ?? School::factory()->create();
    unset($overrides['school']);

    return Student::factory()->create($overrides + [
        'schoolId' => $school->id,
        'status' => 'active',
    ]);
}

/** An invoice and the event its creation logged — the store() path in miniature. */
function paidInvoice(Student $student, float $paid): Invoice
{
    $invoice = Invoice::factory()->create([
        'student_id' => $student->id,
        'amountPaid' => $paid,
        'rest' => max(0, 500 - $paid),
        'totalAmount' => 500,
    ]);

    InvoicePaymentLog::recordDelta($invoice, 0, User::factory()->create(['role' => 'admin'])->id);

    return $invoice;
}

/** The update() path in miniature: amountPaid moves, the delta is logged. */
function payTheRest(Invoice $invoice, float $newAmountPaid): void
{
    $previous = $invoice->amountPaid;
    $invoice->update(['amountPaid' => $newAmountPaid, 'rest' => max(0, 500 - $newAmountPaid)]);

    InvoicePaymentLog::recordDelta($invoice, $previous, User::factory()->create(['role' => 'admin'])->id);
}

it('counts each day\'s money on the day it arrived', function () {
    $student = cashStudent();

    // 200 on the 1st…
    $invoice = paidInvoice($student, 200);

    // …the remaining 100 on the 11th.
    Carbon::setTestNow('2025-12-11 15:00:00');
    payTheRest($invoice, 300);

    $admin = User::factory()->create(['role' => 'admin']);

    $first = test()->actingAs($admin)->get(route('cashier.daily', ['date' => '2025-12-01']));
    expect($first->inertiaPage()['props']['totalPaid'])->toEqual(200.0);

    $eleventh = test()->actingAs($admin)->get(route('cashier.daily', ['date' => '2025-12-11']));
    expect($eleventh->inertiaPage()['props']['totalPaid'])->toEqual(100.0);
});

it('shows one row per payment event, not the invoice balance', function () {
    $student = cashStudent();
    $invoice = paidInvoice($student, 200);

    Carbon::setTestNow('2025-12-11 15:00:00');
    payTheRest($invoice, 300);

    $page = test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('cashier.daily', ['date' => '2025-12-11']))
        ->inertiaPage();

    $rows = $page['props']['invoices'];

    expect(count($rows))->toBe(1)
        ->and((float) $rows[0]['amountPaid'])->toEqual(100.0)
        // The details and receipt actions need the invoice, not the event.
        ->and($rows[0]['invoice_id'])->toBe($invoice->id);
});

it('logs a correction that reduces amountPaid as a negative event', function () {
    $student = cashStudent();
    $invoice = paidInvoice($student, 300);

    Carbon::setTestNow('2025-12-02 09:00:00');
    payTheRest($invoice, 250);   // the 300 was a typo

    $page = test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('cashier.daily', ['date' => '2025-12-02']))
        ->inertiaPage();

    expect($page['props']['totalPaid'])->toEqual(-50.0);
});

it('logs nothing when an edit does not change amountPaid', function () {
    $student = cashStudent();
    $invoice = paidInvoice($student, 200);

    // An edit that reprices or renames but pays nothing new: no movement, no row.
    payTheRest($invoice, 200);

    expect(InvoicePaymentLog::count())->toBe(1);
});

it('rounds to cents before deciding whether money moved', function () {
    $student = cashStudent();
    $invoice = paidInvoice($student, 200);

    // The float artefact the money columns historically produce. A repricing that
    // arrives as 200.0000001 is not a payment and must not become one.
    $previous = $invoice->amountPaid;
    $invoice->update(['amountPaid' => 200.000001]);

    expect(InvoicePaymentLog::recordDelta($invoice, $previous))->toBeNull()
        ->and(InvoicePaymentLog::count())->toBe(1);
});

it('keeps another school\'s payments out of an assistant\'s register', function () {
    $mine = School::factory()->create();
    $theirs = School::factory()->create();

    $user = User::factory()->create(['role' => 'assistant', 'email' => 'cash@example.test']);
    \App\Models\Assistant::factory()->create(['email' => 'cash@example.test'])
        ->schools()->attach($mine->id);

    paidInvoice(cashStudent(['school' => $mine]), 150);
    paidInvoice(cashStudent(['school' => $theirs]), 400);

    $page = test()->actingAs($user)
        ->get(route('cashier.daily', ['date' => '2025-12-01']))
        ->inertiaPage();

    expect($page['props']['totalPaid'])->toEqual(150.0)
        ->and(count($page['props']['invoices']))->toBe(1);
});

/*
 * The migration backfills one event per already-paid invoice, so history keeps the
 * totals the screen showed before the switch (no unexplainable jumps). Replayed here
 * through the same code, because a restored dump has to be able to repeat it.
 */
it('backfills one event per paid invoice and never twice', function () {
    paidInvoice(cashStudent(), 200);
    Invoice::factory()->create(['amountPaid' => 0, 'rest' => 500, 'totalAmount' => 500]);

    // Wipe the store()-path events to imitate invoices that predate the table.
    InvoicePaymentLog::query()->delete();

    expect(InvoicePaymentLog::backfillExisting())->toBe(1)
        ->and(InvoicePaymentLog::backfillExisting())->toBe(0)
        ->and(InvoicePaymentLog::count())->toBe(1)
        ->and((float) InvoicePaymentLog::sole()->amount)->toBe(200.0);
});

// ------------------------------------------------- deleting the invoice withdraws its money --

/*
 * Invoices are SOFT deleted, so the logs' invoice_id cascade never fires — the reported
 * bug: a deleted invoice's payments kept counting on the day's register. Deletion now
 * voids the events (they stay, as the audit of what was withdrawn) and the register
 * sums only live events.
 */
it('stops counting a deleted invoice\'s events on the day they happened', function () {
    $student = cashStudent();
    $invoice = paidInvoice($student, 250);

    $invoice->delete();

    expect($invoice->fresh()->trashed())->toBeTrue()
        ->and(InvoicePaymentLog::count())->toBe(1, 'the event stays as the audit')
        ->and(InvoicePaymentLog::sole()->voided_at)->not->toBeNull();

    $page = test()->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('cashier.daily', ['date' => '2025-12-01']))
        ->inertiaPage();

    expect($page['props']['totalPaid'])->toEqual(0)
        ->and(count($page['props']['invoices']))->toBe(0);
});

it('does not resurrect a deleted invoice\'s money when the backfill replays', function () {
    $student = cashStudent();
    $invoice = paidInvoice($student, 200);
    $invoice->delete();

    // The rows vanish (a restore replay starts from an empty log table); the soft-deleted
    // invoice is still "paid" and must not get its register money back.
    InvoicePaymentLog::query()->delete();

    expect(InvoicePaymentLog::backfillExisting())->toBe(0);
});
