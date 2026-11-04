<?php

use App\Models\Invoice;
use App\Models\Level;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;
use App\Models\User;
use App\Services\TeacherMembershipPaymentService;
use App\Services\TeacherWalletService;
use App\Support\PayoutSchedule;
use App\Support\TeacherEarnings;
use Illuminate\Support\Carbon;
use Tests\Support\PaymentScenario;

/*
 * The wallet ledger is the single truth; « Gains Mensuels » is its statement.
 *
 * Every scenario below replays a real money path, then asserts the two rules
 * that make the statement trustworthy:
 *   - Gagné à ce jour − Versé = Solde = teachers.wallet, for every teacher;
 *   - ledger:invariants (I1-I4, I6) passes — or fails exactly where it must.
 *
 * Nov 2026: the old statement re-derived shares from today's data and drifted
 * from the wallet for 11 of 14 Tiko teachers.
 */

beforeEach(fn () => Level::query()->exists() || Level::factory()->create());
afterEach(fn () => Carbon::setTestNow());

/** Every teacher's statement reconciles with the wallet, and the nightly check is clean. */
function expectLedgerTruth($test): void
{
    foreach (Teacher::all() as $teacher) {
        $summary = TeacherEarnings::statement($teacher)['summary'];

        expect($summary['reconciles'])->toBeTrue("Teacher {$teacher->id}: balance {$summary['balance']} vs wallet {$summary['wallet']}")
            ->and($summary['balance'])->toBe(round((float) $teacher->fresh()->wallet, 2));
    }

    $test->artisan('ledger:invariants')->assertSuccessful();
}

function statementRows(Teacher $teacher): Illuminate\Support\Collection
{
    return TeacherEarnings::statement($teacher)['rows'];
}

/** Math (kept) + PC (changed) on a one-month invoice billed now, paid in full. */
function statementSwapFixture(): array
{
    $school = App\Models\School::factory()->create();
    $admin = User::factory()->create(['role' => 'admin']);
    $kept = Teacher::factory()->create(['wallet' => 0]);
    $removed = Teacher::factory()->create(['wallet' => 0]);
    $replacement = Teacher::factory()->create(['wallet' => 0]);

    foreach ([$kept, $removed, $replacement] as $t) {
        $t->schools()->attach($school->id);
    }

    $student = App\Models\Student::factory()->create(['schoolId' => $school->id]);
    $offer = Offer::factory()->create(['price' => 300.0, 'subjects' => ['Math', 'PC'], 'percentage' => ['Math' => 20, 'PC' => 20]]);
    $membership = Membership::factory()->create([
        'student_id' => $student->id, 'offer_id' => $offer->id, 'payment_status' => 'paid', 'is_active' => true,
        'teachers' => [['teacherId' => $kept->id, 'subject' => 'Math'], ['teacherId' => $removed->id, 'subject' => 'PC']],
    ]);
    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id, 'student_id' => $student->id, 'offer_id' => $offer->id,
        'totalAmount' => 300.0, 'amountPaid' => 300.0, 'rest' => 0, 'months' => 1,
        'selected_months' => [now()->format('Y-m')], 'billDate' => now()->startOfMonth(), 'endDate' => now()->endOfMonth(),
        'includePartialMonth' => false, 'last_payment_date' => now(),
    ]);

    (new TeacherMembershipPaymentService)->processInvoicePayment($invoice, [
        'totalAmount' => 300.0, 'amountPaid' => 300.0, 'rest' => 0, 'includePartialMonth' => false, 'partialMonthAmount' => 0.0,
    ]);

    return compact('school', 'admin', 'kept', 'removed', 'replacement', 'student', 'offer', 'membership', 'invoice');
}

function statementChangeTeachers($test, array $f, array $teachers, ?Offer $offer = null): void
{
    $test->actingAs($f['admin'])->put("/memberships/{$f['membership']->id}", [
        'confirm_teachers_change' => true,
        'payload' => [
            'student_id' => $f['student']->id,
            'offer_id' => ($offer ?? $f['offer'])->id,
            'teachers' => $teachers,
        ],
    ])->assertRedirect();
}

// ---- The schedule -----------------------------------------------------------

test('the schedule splits a commission into months that add up to what is due', function () {
    $plain = new Invoice(['includePartialMonth' => false]);
    $thirds = PayoutSchedule::split(100, ['2026-11', '2026-09', '2026-10'], $plain, 20);

    expect($thirds)->toBe(['2026-09' => 33.33, '2026-10' => 33.34, '2026-11' => 33.33])
        ->and(round(array_sum($thirds), 2))->toBe(100.0)
        ->and(PayoutSchedule::dueAt(100, ['2026-09', '2026-10', '2026-11'], $plain, 20, '2026-10'))->toBe(66.67);

    // Partial first month: its own share (100 x 50%), the rest evenly over the full months.
    $partial = new Invoice(['includePartialMonth' => true, 'partialMonthAmount' => 100, 'billDate' => '2026-09-15']);
    expect(PayoutSchedule::split(300, ['2026-09', '2026-10', '2026-11'], $partial, 50))
        ->toBe(['2026-09' => 50.0, '2026-10' => 125.0, '2026-11' => 125.0]);
});

// ---- Ordinary money paths ---------------------------------------------------

test('a multi-month plan: months that came round are paid, later ones are upcoming and not counted', function () {
    Carbon::setTestNow('2026-10-01 10:00:00');
    $s = PaymentScenario::make(['Math' => 20]);
    $s->bill(months: ['2026-10', '2026-11', '2026-12'], total: 900, paid: 900, billDate: '2026-10-01');
    $s->advanceToMonth('2026-11', 4);

    $rows = statementRows($s->teachers['Math']);

    expect($rows->where('status', TeacherEarnings::PAID)->pluck('teacher_amount')->all())->toBe([60.0, 60.0])
        ->and($rows->where('status', TeacherEarnings::UPCOMING)->pluck('teacher_amount')->all())->toBe([60.0])
        ->and(TeacherEarnings::statement($s->teachers['Math'])['summary']['earned_to_date'])->toBe(120.0)
        ->and($s->wallet('Math'))->toBe(120.0);

    expectLedgerTruth($this);
});

test('a top-up after the monthly job is earned once (Tiko 646 class)', function () {
    Carbon::setTestNow('2026-09-29 21:00:00');
    $s = PaymentScenario::make(['Math' => 20]);
    $s->bill(months: ['2026-10'], total: 350, paid: 200, billDate: '2026-10-01');
    $s->advanceToMonth('2026-10');
    $s->editInvoice(newTotal: 350, newPaid: 350);

    expect($s->wallet('Math'))->toBe(70.0)
        ->and(statementRows($s->teachers['Math'])->sum('teacher_amount'))->toBe(70.0);

    expectLedgerTruth($this);
});

test('a partial first month shows its own share, then the full months', function () {
    Carbon::setTestNow('2026-09-15 10:00:00');
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-09', '2026-10'], total: 300, paid: 300, billDate: '2026-09-15', includePartialMonth: true, partialMonthAmount: 100);
    $s->advanceToMonth('2026-10');

    $rows = statementRows($s->teachers['Math'])->where('status', TeacherEarnings::PAID);
    expect($rows->sum('teacher_amount'))->toBe($s->wallet('Math'));

    expectLedgerTruth($this);
});

test('a cash payout and a manual adjustment: Gagné − Versé = Solde', function () {
    Carbon::setTestNow('2026-10-02 10:00:00');
    $s = PaymentScenario::make(['Math' => 20]);
    $s->bill(months: ['2026-10'], total: 300, paid: 300, billDate: '2026-10-01');

    $wallet = new TeacherWalletService;
    $wallet->credit($s->teachers['Math'], 25, TeacherWalletEntry::REASON_ADJUSTMENT, null, null, null, 'prime');
    $wallet->debit($s->teachers['Math'], 50, TeacherWalletEntry::REASON_PAYOUT, null, null, null, 'cash');

    $statement = TeacherEarnings::statement($s->teachers['Math']);

    expect($statement['summary']['earned_to_date'])->toBe(85.0)
        ->and($statement['summary']['paid_out'])->toBe(50.0)
        ->and($statement['summary']['balance'])->toBe(35.0)
        ->and(collect($statement['other'])->pluck('amount')->all())->toBe([25.0])
        ->and(collect($statement['payouts'])->pluck('amount')->all())->toBe([-50.0]);

    expectLedgerTruth($this);
});

test('an over-credit recovered after a payout leaves a debt the statement explains', function () {
    Carbon::setTestNow('2026-09-29 21:00:00');
    $s = PaymentScenario::make(['Math' => 20]);
    $s->bill(months: ['2026-10'], total: 350, paid: 350, billDate: '2026-10-01');
    $s->advanceToMonth('2026-10');
    $record = $s->record('Math');
    (new TeacherWalletService)->credit($s->teachers['Math'], 70, TeacherWalletEntry::REASON_ADJUSTMENT, $record->id, null, $s->invoice->id, 'old edit bug', 'Math');
    (new TeacherWalletService)->debit($s->teachers['Math'], 140, TeacherWalletEntry::REASON_PAYOUT, null, null, null, 'cash');

    $this->artisan('payouts:settle-to-ledger --confirm')->assertSuccessful();

    expect($s->wallet('Math'))->toBe(-70.0)
        ->and(TeacherEarnings::statement($s->teachers['Math'])['summary']['balance'])->toBe(-70.0);

    expectLedgerTruth($this);
});

// ---- Invoice deletes --------------------------------------------------------

test('an invoice deleted inside the window shows nothing earned', function () {
    Carbon::setTestNow('2026-10-02 10:00:00');
    $s = PaymentScenario::make(['Math' => 20]);
    $s->bill(months: ['2026-10'], total: 300, paid: 300, billDate: '2026-10-01');
    $s->deleteInvoice();

    expect(statementRows($s->teachers['Math']))->toBeEmpty()
        ->and($s->wallet('Math'))->toBe(0.0);

    expectLedgerTruth($this);
});

test('an invoice deleted past the window keeps its money, labelled as kept', function () {
    Carbon::setTestNow('2026-09-02 10:00:00');
    $s = PaymentScenario::make(['Math' => 20]);
    $s->bill(months: ['2026-09'], total: 300, paid: 300, billDate: '2026-09-01');
    Carbon::setTestNow('2026-10-20 10:00:00');
    $s->deleteInvoice();

    $rows = statementRows($s->teachers['Math']);

    expect($s->wallet('Math'))->toBe(60.0)
        ->and($rows->pluck('status')->unique()->values()->all())->toBe([TeacherEarnings::KEPT_DELETED])
        ->and($rows->sum('teacher_amount'))->toBe(60.0);

    expectLedgerTruth($this);
});

// ---- Teacher and subject changes --------------------------------------------

test('a swap on a finished invoice past the window: the removed teacher keeps the month, labelled as kept', function () {
    Carbon::setTestNow('2026-09-03 10:00:00');
    $f = statementSwapFixture();

    Carbon::setTestNow('2026-10-20 10:00:00');
    statementChangeTeachers($this, $f, [
        ['teacherId' => $f['kept']->id, 'subject' => 'Math', 'amount' => 60],
        ['teacherId' => $f['replacement']->id, 'subject' => 'PC', 'amount' => 60],
    ]);

    $removedRows = statementRows($f['removed']);

    expect(round((float) $f['removed']->fresh()->wallet, 2))->toBe(60.0)
        ->and($removedRows->pluck('status')->unique()->values()->all())->toBe([TeacherEarnings::KEPT_REMOVED])
        ->and($removedRows->sum('teacher_amount'))->toBe(60.0)
        ->and(statementRows($f['replacement']))->toBeEmpty();

    expectLedgerTruth($this);
});

test('a late swap on a running invoice moves the month to the replacement', function () {
    Carbon::setTestNow('2026-10-01 10:00:00');
    $f = statementSwapFixture();

    Carbon::setTestNow('2026-10-20 10:00:00');
    statementChangeTeachers($this, $f, [
        ['teacherId' => $f['kept']->id, 'subject' => 'Math', 'amount' => 60],
        ['teacherId' => $f['replacement']->id, 'subject' => 'PC', 'amount' => 60],
    ]);

    expect(statementRows($f['removed']))->toBeEmpty('Reversed: nothing earned, nothing shown.')
        ->and(statementRows($f['replacement'])->sum('teacher_amount'))->toBe(60.0);

    expectLedgerTruth($this);
});

test('the offer changes to another subject on a running invoice: the old subject gives back, the new one is paid, never both', function () {
    Carbon::setTestNow('2026-10-01 10:00:00');
    $f = statementSwapFixture();
    $newOffer = Offer::factory()->create(['price' => 300.0, 'subjects' => ['Math', 'SVT'], 'percentage' => ['Math' => 20, 'SVT' => 20]]);

    Carbon::setTestNow('2026-10-05 10:00:00');
    statementChangeTeachers($this, $f, [
        ['teacherId' => $f['kept']->id, 'subject' => 'Math', 'amount' => 60],
        ['teacherId' => $f['replacement']->id, 'subject' => 'SVT', 'amount' => 60],
    ], $newOffer);

    $held = round((float) TeacherWalletEntry::where('invoice_id', $f['invoice']->id)->whereNotIn('reason', ['payout'])->sum('amount'), 2);

    expect(round((float) $f['removed']->fresh()->wallet, 2))->toBe(0.0, 'The PC teacher gives the month back.')
        ->and(round((float) $f['replacement']->fresh()->wallet, 2))->toBe(60.0, 'The SVT teacher is paid.')
        ->and(round((float) $f['kept']->fresh()->wallet, 2))->toBe(60.0)
        ->and($held)->toBeLessThanOrEqual(300.0);

    expectLedgerTruth($this);
});

test('a teacher removed while the old reconcile re-credited them is recovered by the repair (CRC 7096)', function () {
    // The old shape: the swap reversal took PC back, then the old reconcile paid it
    // again to the removed teacher; the new subject's teacher was paid too.
    Carbon::setTestNow('2026-09-07 20:00:00');
    $s = PaymentScenario::make(['Math' => 20, 'PC' => 20]);
    $s->bill(months: ['2026-09'], total: 300, paid: 300, billDate: '2026-09-01');
    $record = $s->record('PC');
    $wallet = new TeacherWalletService;
    $wallet->debit($s->teachers['PC'], 60, TeacherWalletEntry::REASON_REVERSAL, $record->id, null, $s->invoice->id, 'invoice deleted', 'PC');
    $record->update(['is_active' => false, 'total_paid_to_teacher' => 0]);
    $s->membership->update(['teachers' => [['teacherId' => $s->teachers['Math']->id, 'subject' => 'Math']]]);
    $wallet->credit($s->teachers['PC'], 60, TeacherWalletEntry::REASON_RECONCILE, $record->id, null, $s->invoice->id, 'reconcile to 60.00');
    $record->update(['total_paid_to_teacher' => 60]);
    expect($s->wallet('PC'))->toBe(60.0);

    Carbon::setTestNow('2026-11-04 10:00:00');
    $this->artisan('payouts:settle-to-ledger')->expectsOutputToContain('re-credited after its reversal')->assertSuccessful();
    $this->artisan('ledger:invariants')->expectsOutputToContain('I2')->assertFailed();

    $this->artisan('payouts:settle-to-ledger --confirm')->assertSuccessful();

    expect($s->wallet('PC'))->toBe(0.0)
        ->and($s->wallet('Math'))->toBe(60.0, 'The kept teacher is untouched.');

    expectLedgerTruth($this);
});

test('a billed subject whose teacher left the membership is flagged, never silently dropped (CRC 8128)', function () {
    Carbon::setTestNow('2026-10-27 19:00:00');
    $s = PaymentScenario::make(['Math' => 20, 'ENG' => 20]);
    $s->bill(months: ['2026-11'], total: 300, paid: 300, billDate: '2026-11-01');
    $s->membership->update(['teachers' => [['teacherId' => $s->teachers['Math']->id, 'subject' => 'Math']]]);

    Carbon::setTestNow('2026-11-04 10:00:00');
    $statement = TeacherEarnings::statement($s->teachers['ENG']);

    expect(collect($statement['anomalies'])->pluck('kind')->all())->toBe(['billed_subject_unassigned'])
        ->and($statement['summary']['owed'])->toBe(60.0)
        ->and($statement['summary']['reconciles'])->toBeTrue();

    $this->artisan('ledger:invariants')
        ->expectsOutputToContain('billed_subject_unassigned')
        ->assertFailed();
});

// ---- The old blind spots ----------------------------------------------------

test('the commission pinned at billing is shown, not one re-rated from a later offer', function () {
    // Prod 796/902/1034: the membership's offer changed after billing; the invoice
    // keeps the old one (a trigger pins invoice.offer_id to the membership's at write).
    Carbon::setTestNow('2026-10-02 10:00:00');
    $s = PaymentScenario::make(['Math' => 20]);
    $s->bill(months: ['2026-10'], total: 300, paid: 300, billDate: '2026-10-01');
    $s->membership->update(['offer_id' => Offer::factory()->create(['percentage' => ['Math' => 50]])->id]);

    expect(statementRows($s->teachers['Math'])->sum('teacher_amount'))->toBe(60.0);

    expectLedgerTruth($this);
});

test('months paid before the ledger existed are history, outside Gagné à ce jour', function () {
    Carbon::setTestNow('2026-08-31 21:00:00');
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    (new TeacherWalletService)->credit($teacher, 60, TeacherWalletEntry::REASON_ADJUSTMENT, null, null, null, 'opening balance');
    (new TeacherWalletService)->debit($teacher, 60, TeacherWalletEntry::REASON_PAYOUT, null, null, null, 'last year');

    $membership = Membership::factory()->create(['teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']]]);
    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id, 'student_id' => $membership->student_id, 'amountPaid' => 300,
        'selected_months' => ['2026-06'], 'billDate' => '2026-06-01', 'includePartialMonth' => false,
    ]);
    $invoice->forceFill(['created_at' => '2026-06-01 10:00:00'])->saveQuietly();
    TeacherMembershipPayment::create([
        'student_id' => $membership->student_id, 'teacher_id' => $teacher->id, 'membership_id' => $membership->id,
        'invoice_id' => $invoice->id, 'selected_months' => ['2026-06'], 'months_rest_not_paid_yet' => [],
        'total_teacher_amount' => 60, 'monthly_teacher_amount' => 60, 'teacher_subject' => 'Math',
        'teacher_percentage' => 20, 'payment_percentage' => 100, 'total_paid_to_teacher' => 60, 'is_active' => true,
    ]);

    Carbon::setTestNow('2026-11-04 10:00:00');
    $statement = TeacherEarnings::statement($teacher);

    expect($statement['rows']->pluck('status')->all())->toBe([TeacherEarnings::HISTORY])
        ->and($statement['summary']['earned_to_date'])->toBe(60.0, 'The opening balance, not the history row.')
        ->and($statement['summary']['balance'])->toBe(0.0)
        ->and($statement['summary']['reconciles'])->toBeTrue();
});

// ---- The pages --------------------------------------------------------------

test('the teacher profile and « Mes gains » both carry the reconciled statement', function () {
    Carbon::setTestNow('2026-10-02 10:00:00');
    $s = PaymentScenario::make(['Math' => 20]);
    $s->bill(months: ['2026-10'], total: 300, paid: 300, billDate: '2026-10-01');
    $teacher = $s->teachers['Math'];
    $this->withoutVite();

    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin)->get("/teachers/{$teacher->id}?date_filter=2026-10")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('earningsSummary.earned_to_date', 60)
            ->where('earningsSummary.reconciles', true)
            ->where('invoices.data.0.status', TeacherEarnings::PAID));

    $user = User::where('email', $teacher->email)->first() ?? User::factory()->create(['role' => 'teacher', 'email' => $teacher->email]);
    $user->forceFill(['role' => 'teacher'])->save();
    $this->actingAs($user)->get('/my-payments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('earningsSummary.balance', 60)
            ->where('earningsSummary.reconciles', true));
});
