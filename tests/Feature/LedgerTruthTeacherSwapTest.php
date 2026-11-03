<?php

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherWalletEntry;
use App\Models\User;
use App\Services\TeacherMembershipPaymentService;
use App\Services\TeacherWalletService;
use Illuminate\Support\Carbon;

/*
 * Teacher swaps under ledger-truth settlement.
 *
 *   F    Tiko 796: a swap on an invoice for a PAST month reversed the kept
 *        teachers and the reprocess never paid them back (the edit path only
 *        recomputed a current-month "immediate", which was zero).
 *   Late CRC 7502: a swap past the 7-day window left the removed teacher with
 *        the month AND paid the replacement for it — the school paid the
 *        subject twice. Rule (Nov 2026): the month is recovered from the
 *        removed teacher, as a debt if it was already paid out.
 */

afterEach(fn () => Carbon::setTestNow());

/** Math (kept) + PC (swapped) on a one-month invoice billed now, paid in full. */
function makeSwapFixture(float $paid = 300.0): array
{
    \App\Models\Level::first() ?? \App\Models\Level::factory()->create();
    $school = App\Models\School::factory()->create();
    $admin = User::factory()->create(['role' => 'admin']);

    $kept = Teacher::factory()->create(['wallet' => 0]);
    $removed = Teacher::factory()->create(['wallet' => 0]);
    $replacement = Teacher::factory()->create(['wallet' => 0]);

    foreach ([$kept, $removed, $replacement] as $t) {
        $t->schools()->attach($school->id);
    }

    $student = Student::factory()->create(['schoolId' => $school->id]);
    $offer = Offer::factory()->create([
        'price' => 300.0,
        'subjects' => ['Math', 'PC'],
        'percentage' => ['Math' => 20, 'PC' => 20],
    ]);

    $membership = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'payment_status' => 'paid',
        'is_active' => true,
        'teachers' => [
            ['teacherId' => $kept->id, 'subject' => 'Math'],
            ['teacherId' => $removed->id, 'subject' => 'PC'],
        ],
    ]);

    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'totalAmount' => 300.0,
        'amountPaid' => $paid,
        'rest' => round(300 - $paid, 2),
        'months' => 1,
        'selected_months' => [now()->format('Y-m')],
        'billDate' => now()->startOfMonth(),
        'endDate' => now()->endOfMonth(),
        'includePartialMonth' => false,
        'last_payment_date' => now(),
    ]);

    (new TeacherMembershipPaymentService)->processInvoicePayment($invoice, [
        'totalAmount' => 300.0,
        'amountPaid' => $paid,
        'rest' => round(300 - $paid, 2),
        'includePartialMonth' => false,
        'partialMonthAmount' => 0.0,
    ]);

    return compact('school', 'admin', 'kept', 'removed', 'replacement', 'student', 'offer', 'membership', 'invoice');
}

function confirmSwap($test, array $f): void
{
    $test->actingAs($f['admin'])->put("/memberships/{$f['membership']->id}", [
        'confirm_teachers_change' => true,
        'payload' => [
            'student_id' => $f['student']->id,
            'offer_id' => $f['offer']->id,
            'teachers' => [
                ['teacherId' => $f['kept']->id, 'subject' => 'Math', 'amount' => 60],
                ['teacherId' => $f['replacement']->id, 'subject' => 'PC', 'amount' => 60],
            ],
        ],
    ])->assertRedirect();
}

function walletOf(Teacher $t): float
{
    return round((float) $t->fresh()->wallet, 2);
}

test('F: a swap on a past-month invoice pays the kept teacher back', function () {
    Carbon::setTestNow('2026-10-02 21:19:00');
    $f = makeSwapFixture();
    expect(walletOf($f['kept']))->toBe(60.0);

    // November, a few days after the last payment — inside the window.
    $f['invoice']->update(['last_payment_date' => Carbon::parse('2026-10-31 19:48:00')]);
    Carbon::setTestNow('2026-11-03 22:05:00');
    confirmSwap($this, $f);

    expect(walletOf($f['kept']))->toBe(60.0, 'Kept teacher: reversed and paid back, net unchanged.')
        ->and(walletOf($f['removed']))->toBe(0.0)
        ->and(walletOf($f['replacement']))->toBe(60.0, 'October has come round: the replacement holds its share.');
});

test('late swap: the month moves from the removed teacher to the replacement', function () {
    Carbon::setTestNow('2026-10-01 10:00:00');
    $f = makeSwapFixture();
    expect(walletOf($f['removed']))->toBe(60.0);

    Carbon::setTestNow('2026-10-20 10:00:00'); // 19 days later — past the window
    confirmSwap($this, $f);

    expect(walletOf($f['removed']))->toBe(0.0, 'The removed teacher gives the month back.')
        ->and(walletOf($f['replacement']))->toBe(60.0)
        ->and(walletOf($f['kept']))->toBe(60.0, 'The kept teacher is untouched.')
        ->and(walletOf($f['removed']) + walletOf($f['replacement']))->toBe(60.0, 'The school pays the subject once.');
});

test('late swap: a removed teacher already paid out ends with a debt', function () {
    Carbon::setTestNow('2026-10-01 10:00:00');
    $f = makeSwapFixture();

    (new TeacherWalletService)->debit($f['removed'], 60, TeacherWalletEntry::REASON_PAYOUT, null, null, null, 'cash payout');
    expect(walletOf($f['removed']))->toBe(0.0);

    Carbon::setTestNow('2026-10-20 10:00:00');
    confirmSwap($this, $f);

    expect(walletOf($f['removed']))->toBe(-60.0, 'Already paid out: recorded as a debt, shown negative.')
        ->and(walletOf($f['replacement']))->toBe(60.0);
});

test('a swap on a finished invoice past the window moves no money', function () {
    Carbon::setTestNow('2026-09-03 10:00:00');
    $f = makeSwapFixture();

    Carbon::setTestNow('2026-10-20 10:00:00'); // September is over, payment 47 days ago
    confirmSwap($this, $f);

    expect(walletOf($f['removed']))->toBe(60.0, 'Settled history: the teacher who taught September keeps it.')
        ->and(walletOf($f['replacement']))->toBe(0.0, 'Nobody new is paid for a finished month.')
        ->and(walletOf($f['kept']))->toBe(60.0);
});

test('late swap preview writes nothing', function () {
    Carbon::setTestNow('2026-10-01 10:00:00');
    $f = makeSwapFixture();

    Carbon::setTestNow('2026-10-20 10:00:00');
    $before = TeacherWalletEntry::count();

    $this->actingAs($f['admin'])->put("/memberships/{$f['membership']->id}", [
        'student_id' => $f['student']->id,
        'offer_id' => $f['offer']->id,
        'teachers' => [
            ['teacherId' => $f['kept']->id, 'subject' => 'Math', 'amount' => 60],
            ['teacherId' => $f['replacement']->id, 'subject' => 'PC', 'amount' => 60],
        ],
    ])->assertRedirect();

    expect(TeacherWalletEntry::count())->toBe($before)
        ->and(session('payment_notice'))->not->toBeNull('The dialog must announce the recovery.');
});
