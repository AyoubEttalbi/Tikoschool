<?php

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

/*
 * SUITE — settled membership delete.
 *
 * Money has a lifecycle: fresh (< 7 days, REVERSAL_DEADLINE_DAYS) vs settled.
 * Fresh paid memberships keep the hard block (void the invoice first). Settled
 * ones — teachers taught the classes long ago — must be deletable WITHOUT
 * touching wallets or the ledger, with a tombstone in the activity log and the
 * paid invoices kept as financial history.
 */

function makeSettledFlowMembership(int $daysSincePayment = 10): array
{
    Carbon::setTestNow('2026-08-03 10:00:00');

    $school = App\Models\School::factory()->create();
    $admin = User::factory()->create(['role' => 'admin']);

    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $teacher->schools()->attach($school->id);

    $student = Student::factory()->create(['schoolId' => $school->id]);
    $offer = Offer::factory()->create([
        'price' => 200.0,
        'subjects' => ['Math'],
        'percentage' => ['Math' => 50],
    ]);

    $membership = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'payment_status' => 'paid',
        'is_active' => true,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);

    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'totalAmount' => 200.0,
        'amountPaid' => 200.0,
        'rest' => 0,
        'months' => 1,
        'selected_months' => [now()->format('Y-m')],
        'billDate' => now()->startOfMonth(),
        'endDate' => now()->addMonth(),
        'includePartialMonth' => false,
    ]);

    (new App\Services\TeacherMembershipPaymentService)->processInvoicePayment($invoice, [
        'totalAmount' => 200.0,
        'amountPaid' => 200.0,
        'rest' => 0.0,
        'includePartialMonth' => false,
        'partialMonthAmount' => 0.0,
    ]);

    expect((float) $teacher->fresh()->wallet)->toBe(100.0, 'Fixture is wrong — no commission was paid.');

    // Age the payment past the deadline without moving any money.
    Invoice::whereKey($invoice->id)->update(['last_payment_date' => now()->subDays($daysSincePayment)]);

    return compact('school', 'admin', 'teacher', 'student', 'offer', 'membership', 'invoice');
}

function settledMoneySnapshot(Teacher $teacher): array
{
    return [
        'wallet' => round((float) $teacher->fresh()->wallet, 2),
        'ledger_count' => TeacherWalletEntry::count(),
        'ledger_sum' => round((float) TeacherWalletEntry::sum('amount'), 2),
        'records' => TeacherMembershipPayment::orderBy('id')->get()->map(fn ($r) => [
            $r->id, $r->is_active, round((float) $r->total_paid_to_teacher, 2),
            round((float) $r->immediate_wallet_amount, 2), $r->months_rest_not_paid_yet,
        ])->all(),
    ];
}

afterEach(function () {
    Carbon::setTestNow();
});

test('fresh paid membership delete is still blocked and changes nothing', function () {
    $f = makeSettledFlowMembership(2);
    extract($f);

    $before = settledMoneySnapshot($teacher);

    $this->actingAs($admin)->delete("/memberships/{$membership->id}")->assertRedirect();

    expect(session('payment_notice')['tone'] ?? null)->toBe('error', 'Fresh money keeps the hard block.')
        ->and($membership->fresh()->trashed())->toBeFalse('Nothing deleted.')
        ->and(settledMoneySnapshot($teacher))->toBe($before, 'No money moved, no ledger noise.');
});

test('settled membership deletes freely with money frozen and a tombstone', function () {
    $f = makeSettledFlowMembership(10);
    extract($f);

    $before = settledMoneySnapshot($teacher);

    $this->actingAs($admin)->delete("/memberships/{$membership->id}")->assertRedirect();

    $notice = session('payment_notice');

    expect($membership->fresh()->trashed())->toBeTrue('Settled membership must be deletable.')
        ->and($notice['tone'] ?? null)->toBe('warning', 'Keeping money is dialog-worthy.')
        ->and(implode(' ', $notice['messages'] ?? []))->toContain('gardent')
        ->and((float) $teacher->fresh()->wallet)->toBe($before['wallet'], 'Teacher keeps the settled money.')
        ->and(TeacherWalletEntry::count())->toBe($before['ledger_count'], 'No ledger rows in, none out.')
        ->and(round((float) TeacherWalletEntry::sum('amount'), 2))->toBe($before['ledger_sum']);

    $record = TeacherMembershipPayment::where('membership_id', $membership->id)->first();
    expect($record->is_active)->toBeFalse('Frozen rows must leave the cron selection.')
        ->and($record->months_rest_not_paid_yet)->toBe([], 'No queued month may survive.')
        ->and(round((float) $record->total_paid_to_teacher, 2))->toBe(100.0, 'Totals frozen, not zeroed.')
        ->and(round((float) $record->immediate_wallet_amount, 2))->toBe(100.0);

    // The paid invoice stays as financial history, still linked.
    $invoice = $invoice->fresh();
    expect($invoice->trashed())->toBeFalse('Paid invoices are history, never deleted with the membership.')
        ->and(round((float) $invoice->amountPaid, 2))->toBe(200.0)
        ->and($invoice->membership->id)->toBe($membership->id, 'membership() must resolve through withTrashed.');

    // Tombstone in the activity log.
    $activity = Activity::where('subject_type', Membership::class)
        ->where('subject_id', $membership->id)
        ->latest('id')->first();
    expect($activity)->not->toBeNull('Every settled delete leaves a trace.')
        ->and($activity->properties['settled_delete']['membership_id'] ?? null)->toBe($membership->id)
        ->and($activity->properties['settled_delete']['invoices'][0]['id'] ?? null)->toBe($invoice->id)
        ->and($activity->properties['settled_delete']['deadline_days'] ?? null)->toBe(7);
});

test('null last_payment_date falls back to creation date', function () {
    $f = makeSettledFlowMembership(10);
    extract($f);

    // Old creation, no payment stamp: settled by fallback.
    Invoice::whereKey($invoice->id)->update([
        'last_payment_date' => null,
        'created_at' => now()->subDays(10),
    ]);

    $this->actingAs($admin)->delete("/memberships/{$membership->id}")->assertRedirect();
    expect($membership->fresh()->trashed())->toBeTrue('Old creation with no payment stamp is settled.');

    // Fresh creation with no payment stamp: still blocked.
    $f2 = makeSettledFlowMembership(10);
    $membership2 = $f2['membership'];
    $invoice2 = $f2['invoice'];
    Invoice::whereKey($invoice2->id)->update(['last_payment_date' => null]);

    $this->actingAs($f2['admin'])->delete("/memberships/{$membership2->id}")->assertRedirect();
    expect($membership2->fresh()->trashed())->toBeFalse('Fresh creation with no payment stamp stays blocked.');
});

test('partial but old payment is settled, teacher keeps the partial', function () {
    $f = makeSettledFlowMembership(10);
    extract($f);

    Invoice::whereKey($invoice->id)->update(['amountPaid' => 120.0, 'rest' => 80.0]);
    TeacherMembershipPayment::where('membership_id', $membership->id)
        ->update(['total_paid_to_teacher' => 60.0, 'immediate_wallet_amount' => 60.0]);

    $this->actingAs($admin)->delete("/memberships/{$membership->id}")->assertRedirect();

    expect($membership->fresh()->trashed())->toBeTrue('Old partial payments are equally settled.')
        ->and(round((float) TeacherMembershipPayment::where('membership_id', $membership->id)->first()->total_paid_to_teacher, 2))->toBe(60.0);
});

test('one fresh invoice vetoes the settled delete', function () {
    $f = makeSettledFlowMembership(10);
    extract($f);

    $fresh = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'totalAmount' => 200.0,
        'amountPaid' => 200.0,
        'rest' => 0,
        'months' => 1,
        'selected_months' => [now()->format('Y-m')],
        'billDate' => now()->startOfMonth(),
        'endDate' => now()->addMonth(),
        'includePartialMonth' => false,
    ]);

    $this->actingAs($admin)->delete("/memberships/{$membership->id}")->assertRedirect();

    expect($membership->fresh()->trashed())->toBeFalse('One fresh invoice vetoes.')
        ->and(session('payment_notice')['tone'] ?? null)->toBe('error');
});

test('settled delete leaves the audit clean', function () {
    $f = makeSettledFlowMembership(30);
    extract($f);

    $this->actingAs($admin)->delete("/memberships/{$membership->id}")->assertRedirect();
    expect($membership->fresh()->trashed())->toBeTrue();

    expect(App\Support\LedgerShortfall::find())->toBeEmpty('No new ledger-vs-record divergence.')
        ->and(TeacherMembershipPayment::where('membership_id', $membership->id)->where('is_active', true)->count())->toBe(0, 'Nothing left for the cron or the owed bucket.');
});

test('settled invoice plus stranded row on a trashed invoice stays blocked', function () {
    // The live invoice is settled, but a paid row survived its own invoice's
    // deletion — payment moment unmeasurable, so this needs manual review.
    $f = makeSettledFlowMembership(10);
    extract($f);

    $invoice->delete();

    $this->actingAs($admin)->delete("/memberships/{$membership->id}")->assertRedirect();

    expect(session('payment_notice')['tone'] ?? null)->toBe('error')
        ->and(implode(' ', session('payment_notice')['messages'] ?? []))->toContain('manuellement')
        ->and($membership->fresh()->trashed())->toBeFalse('Nothing deleted.');
});

test('settled invoice plus paid row on an edited-down zero invoice stays blocked', function () {
    // Invoice edited down to zero after payment, record still holding money:
    // the two signals diverge, so this needs manual review, never a silent go.
    $f = makeSettledFlowMembership(10);
    extract($f);

    Invoice::whereKey($invoice->id)->update(['amountPaid' => 0.0, 'rest' => 200.0]);

    $this->actingAs($admin)->delete("/memberships/{$membership->id}")->assertRedirect();

    expect(session('payment_notice')['tone'] ?? null)->toBe('error')
        ->and($membership->fresh()->trashed())->toBeFalse('Nothing deleted.')
        ->and((float) $teacher->fresh()->wallet)->toBe(100.0, 'No money moved.');
});

test('deleting twice does not tombstone twice', function () {
    $f = makeSettledFlowMembership(10);
    extract($f);

    $this->actingAs($admin)->delete("/memberships/{$membership->id}")->assertRedirect();
    expect($membership->fresh()->trashed())->toBeTrue();

    $this->actingAs($admin)->delete("/memberships/{$membership->id}")->assertRedirect();

    expect(Activity::where('subject_type', Membership::class)->where('subject_id', $membership->id)->count())
        ->toBe(1, 'One delete, one tombstone.');
});

test('invoice store ignores a client-supplied backdated payment clock', function () {
    // H1: last_payment_date drives the reversal deadline, so the store must
    // stamp it server-side — otherwise a crafted POST converts the fresh-money
    // block into a settled delete that lets teachers keep fresh money.
    Carbon::setTestNow('2026-08-03 10:00:00');

    $school = App\Models\School::factory()->create();
    $admin = User::factory()->create(['role' => 'admin']);
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $teacher->schools()->attach($school->id);
    $student = Student::factory()->create(['schoolId' => $school->id]);
    $offer = Offer::factory()->create([
        'price' => 200.0,
        'subjects' => ['Math'],
        'percentage' => ['Math' => 50],
    ]);
    $membership = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);

    $this->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 1,
        'selected_months' => ['2026-08'],
        'billDate' => '2026-08-01',
        'creationDate' => '2026-08-03',
        'totalAmount' => 200.0,
        'amountPaid' => 200.0,
        'rest' => 0,
        'includePartialMonth' => false,
        // The attack: claim the money moved a month ago.
        'last_payment_date' => '2026-07-01 10:00:00',
    ]);

    $invoice = Invoice::latest('id')->first();

    expect($invoice)->not->toBeNull()
        ->and($invoice->last_payment_date)->not->toBeNull('A paid invoice stamps the clock.')
        ->and(Carbon::parse($invoice->last_payment_date)->format('Y-m-d'))->toBe('2026-08-03', 'Server time wins, not the posted backdate.');
});
