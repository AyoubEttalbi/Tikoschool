<?php

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherWalletEntry;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * SUITE 5 — the membership teacher-change flow.
 *
 * Editing a membership's teachers on a paid membership moves real wallet money, so
 * saving must be a two-step: preview first (nothing written), explicit confirm to
 * execute. Edits that change no teacher move nothing at all. And a teacher whose
 * subject is not in the (new) offer is a hard block — that shape is prod invoice
 * 7027, where a French teacher sat on a MATH+PC+SVT offer.
 */

function makeFlowMembership(): array
{
    Carbon::setTestNow('2026-08-03 10:00:00');

    $school = App\Models\School::factory()->create();
    $admin = User::factory()->create(['role' => 'admin']);

    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $teacher->schools()->attach($school->id);

    $replacement = Teacher::factory()->create(['wallet' => 0]);
    $replacement->schools()->attach($school->id);

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

    return compact('school', 'admin', 'teacher', 'replacement', 'student', 'offer', 'membership', 'invoice');
}

afterEach(function () {
    Carbon::setTestNow();
});

test('saving with unchanged teachers moves no money at all', function () {
    $f = makeFlowMembership();
    extract($f);

    $ledgerBefore = TeacherWalletEntry::count();

    // Same teacher, same subject — only float-dust in the amount, exactly what the
    // form resubmits (prod rows carry 49.999998999999995).
    $this->actingAs($admin)->put("/memberships/{$membership->id}", [
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math', 'amount' => 49.999998999999995]],
    ])->assertRedirect();

    expect((float) $teacher->fresh()->wallet)->toBe(100.0, 'No reversal may run when no teacher changed.')
        ->and(TeacherWalletEntry::count())->toBe($ledgerBefore, 'Not even ledger noise: no rows in, none out.')
        ->and(session()->has('payment_notice'))->toBeFalse('No dialog for a moneyless save.');
});

test('swapping a teacher without confirm previews and writes nothing', function () {
    $f = makeFlowMembership();
    extract($f);

    $this->actingAs($admin)->put("/memberships/{$membership->id}", [
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'teachers' => [['teacherId' => $replacement->id, 'subject' => 'Math', 'amount' => 100]],
    ])->assertRedirect();

    expect($membership->fresh()->teachers[0]['teacherId'])->toBe((int) $teacher->id, 'Preview must not save.')
        ->and((float) $teacher->fresh()->wallet)->toBe(100.0, 'Preview must not move money.')
        ->and((float) $replacement->fresh()->wallet)->toBe(0.0);

    $notice = session('payment_notice');
    expect($notice)->not->toBeNull('The preview dialog must open.')
        ->and($notice['actions'][0]['data']['confirm_teachers_change'] ?? null)->toBeTrue()
        ->and($notice['actions'][0]['data']['payload']['teachers'][0]['teacherId'] ?? null)
        ->toBe($replacement->id, 'The confirm action must carry the full payload back.');
});

test('confirming the preview executes the swap', function () {
    $f = makeFlowMembership();
    extract($f);

    $payload = [
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'teachers' => [['teacherId' => $replacement->id, 'subject' => 'Math', 'amount' => 100]],
    ];

    $this->actingAs($admin)->put("/memberships/{$membership->id}", [
        'confirm_teachers_change' => true,
        'payload' => $payload,
    ])->assertRedirect();

    expect($membership->fresh()->teachers[0]['teacherId'])->toBe((int) $replacement->id)
        ->and((float) $teacher->fresh()->wallet)->toBe(0.0, 'Old teacher reversed on confirm.')
        ->and((float) $replacement->fresh()->wallet)->toBe(100.0, 'Replacement credited on confirm.');
});

test('a teacher whose subject is not in the offer is a hard block', function () {
    $f = makeFlowMembership();
    extract($f);

    $this->actingAs($admin)->put("/memberships/{$membership->id}", [
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'teachers' => [['teacherId' => $replacement->id, 'subject' => 'FR', 'amount' => 100]],
    ])->assertRedirect();

    $notice = session('payment_notice');

    expect($notice['tone'] ?? null)->toBe('error', 'The block must be visible, not a silent log line.')
        ->and(implode(' ', $notice['messages'] ?? []))->toContain('FR')
        ->and($membership->fresh()->teachers[0]['subject'])->toBe('Math', 'Nothing saved.')
        ->and((float) $teacher->fresh()->wallet)->toBe(100.0, 'No money moved.');
});

test('changing the offer without fixing the teachers is blocked and names the subject', function () {
    $f = makeFlowMembership();
    extract($f);

    $otherOffer = Offer::factory()->create([
        'price' => 300.0,
        'subjects' => ['PC'],
        'percentage' => ['PC' => 100],
    ]);

    // Stale Math teacher carried onto a PC-only offer — the suspected 7027 shape.
    $this->actingAs($admin)->put("/memberships/{$membership->id}", [
        'student_id' => $student->id,
        'offer_id' => $otherOffer->id,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math', 'amount' => 100]],
    ])->assertRedirect();

    $notice = session('payment_notice');

    expect($notice['tone'] ?? null)->toBe('error')
        ->and(implode(' ', $notice['messages'] ?? []))->toContain('Math')
        ->and((int) $membership->fresh()->offer_id)->toBe((int) $offer->id, 'Offer change not saved.');
});

test('swapping teachers on an unpaid membership saves directly with no dialog', function () {
    // No paid invoice and no paid rows anywhere: there is no money to protect,
    // whatever the status flag says.
    $school = App\Models\School::factory()->create();
    $admin = User::factory()->create(['role' => 'admin']);

    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $teacher->schools()->attach($school->id);
    $replacement = Teacher::factory()->create(['wallet' => 0]);
    $replacement->schools()->attach($school->id);

    $student = Student::factory()->create(['schoolId' => $school->id]);
    $offer = Offer::factory()->create([
        'price' => 200.0,
        'subjects' => ['Math'],
        'percentage' => ['Math' => 50],
    ]);

    $membership = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'payment_status' => 'pending',
        'is_active' => true,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);

    $this->actingAs($admin)->put("/memberships/{$membership->id}", [
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'teachers' => [['teacherId' => $replacement->id, 'subject' => 'Math', 'amount' => 100]],
    ])->assertRedirect();

    expect($membership->fresh()->teachers[0]['teacherId'])->toBe((int) $replacement->id)
        ->and(session()->has('payment_notice'))->toBeFalse('No money ever moved: no dialog.');
});

test('a stale pending flag does not disarm the money path when invoices are paid', function () {
    // The gate keys on paid invoices, never on payment_status: the flag goes
    // stale (paid rows on a pending membership), and gating on it let swaps on
    // expired memberships skip the reversal, the dialog and the reprocess
    // while the cron kept paying whoever was removed (prod Sept 2026).
    $f = makeFlowMembership();
    extract($f);

    $membership->update(['payment_status' => 'pending']);

    $this->actingAs($admin)->put("/memberships/{$membership->id}", [
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'teachers' => [['teacherId' => $replacement->id, 'subject' => 'Math', 'amount' => 100]],
    ])->assertRedirect();

    expect(session()->has('payment_notice'))->toBeTrue('Paid invoices exist: the confirm dialog must open.');

    $this->actingAs($admin)->put("/memberships/{$membership->id}", [
        'confirm_teachers_change' => true,
        'payload' => [
            'student_id' => $student->id,
            'offer_id' => $offer->id,
            'teachers' => [['teacherId' => $replacement->id, 'subject' => 'Math', 'amount' => 100]],
        ],
    ])->assertRedirect();

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(0.0, 'Old teacher reversed on confirm.')
        ->and(round((float) $replacement->fresh()->wallet, 2))->toBe(100.0, 'Replacement credited on confirm.');
});

test('a partially recovered invoice still reprocesses on confirm', function () {
    // The teacher already cashed out most of the commission, so the reversal can
    // only take part of it (wallet_insufficient remainder). The kept teacher's
    // taken share must still be restored — skipping the invoice would short them.
    $school = App\Models\School::factory()->create();
    $admin = User::factory()->create(['role' => 'admin']);

    $math = Teacher::factory()->create(['wallet' => 0]);
    $math->schools()->attach($school->id);
    $fr = Teacher::factory()->create(['wallet' => 0]);
    $fr->schools()->attach($school->id);

    $student = Student::factory()->create(['schoolId' => $school->id]);
    $offer = Offer::factory()->create([
        'price' => 1400.0,
        'subjects' => ['Math', 'FR'],
        'percentage' => ['Math' => 50, 'FR' => 50],
    ]);

    $membership = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'payment_status' => 'paid',
        'is_active' => true,
        'teachers' => [
            ['teacherId' => $math->id, 'subject' => 'Math'],
            ['teacherId' => $fr->id, 'subject' => 'FR'],
        ],
    ]);

    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'totalAmount' => 700.0,
        'amountPaid' => 700.0,
        'rest' => 0,
        'months' => 1,
        'selected_months' => [now()->format('Y-m')],
        'billDate' => now()->startOfMonth(),
        'endDate' => now()->addMonth(),
        'includePartialMonth' => false,
    ]);

    (new App\Services\TeacherMembershipPaymentService)->processInvoicePayment($invoice, [
        'totalAmount' => 700.0,
        'amountPaid' => 700.0,
        'rest' => 0.0,
        'includePartialMonth' => false,
        'partialMonthAmount' => 0.0,
    ]);

    expect((float) $math->fresh()->wallet)->toBe(350.0);

    // Cash payout of 300: only 50 can still be recovered.
    (new App\Services\TeacherWalletService)->debit(
        $math->fresh(), 300, App\Models\TeacherWalletEntry::REASON_PAYOUT, null, null, null, 'cash payout'
    );
    expect((float) $math->fresh()->wallet)->toBe(50.0);

    // Drop FR, keep Math. First request previews.
    $payload = [
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'teachers' => [['teacherId' => $math->id, 'subject' => 'Math', 'amount' => 100]],
    ];

    $this->actingAs($admin)->put("/memberships/{$membership->id}", $payload)->assertRedirect();
    expect(session('payment_notice'))->not->toBeNull('Partial recovery still warns.');

    $this->actingAs($admin)->put("/memberships/{$membership->id}", [
        'confirm_teachers_change' => true,
        'payload' => $payload,
    ])->assertRedirect();

    // Commission 350 = 300 cash already out + 50 taken and restored.
    // Wallet reads 50; the blocked 300 remainder stays flagged in the notice.
    expect((float) $math->fresh()->wallet)->toBe(50.0, 'Taken share restored despite the blocked remainder.')
        ->and(App\Models\TeacherMembershipPayment::where('membership_id', $membership->id)
            ->where('teacher_id', $math->id)->where('is_active', true)->count())->toBe(1)
        ->and(App\Models\TeacherMembershipPayment::where('membership_id', $membership->id)
            ->where('teacher_id', $fr->id)->where('is_active', true)->count())->toBe(0, 'Removed stays dead.');
});

// ----------------------------------------------------------------- delete guard
//
// Prod tikoschool invoice 32: membership deleted first (records deactivated with
// no reversal), invoice deleted 6 seconds later (nothing active left to reverse).
// Teachers kept 270 DH and earned it again on the replacement invoice. Deleting a
// membership with live paid invoices must therefore be blocked until those
// invoices are voided through their own preview + confirm.

test('deleting a membership with live paid invoices is blocked and changes nothing', function () {
    $f = makeFlowMembership();
    extract($f);

    $this->actingAs($admin)->delete("/memberships/{$membership->id}")->assertRedirect();

    $notice = session('payment_notice');

    expect($notice['tone'] ?? null)->toBe('error', 'The block must be visible.')
        ->and(implode(' ', array_column($notice['details'] ?? [], 'label')))->toContain((string) $invoice->id)
        ->and($membership->fresh()->trashed())->toBeFalse('Nothing deleted.')
        ->and((float) $teacher->fresh()->wallet)->toBe(100.0, 'No money moved.')
        ->and(App\Models\TeacherMembershipPayment::where('membership_id', $membership->id)->where('is_active', true)->count())->toBe(1, 'Records untouched.');
});

test('deleting a membership with only unpaid invoices proceeds', function () {
    $f = makeFlowMembership();
    extract($f);

    // Truly unpaid: nothing ever credited, records at zero.
    $membership->update(['payment_status' => 'pending']);
    $invoice->update(['amountPaid' => 0.0, 'rest' => 200.0]);
    App\Models\TeacherMembershipPayment::where('membership_id', $membership->id)
        ->update(['total_paid_to_teacher' => 0.0, 'immediate_wallet_amount' => 0.0]);

    $this->actingAs($admin)->delete("/memberships/{$membership->id}")->assertRedirect();

    expect($membership->fresh()->trashed())->toBeTrue();
});

test('deleting a membership with stranded pay on voided invoices is blocked', function () {
    // Legacy shape: invoice gone, but an active record still claims paid money
    // (pre-truthful-totals rows, edited-down amounts). No invoice left to void,
    // so this needs manual review — never a silent deactivate.
    $f = makeFlowMembership();
    extract($f);

    $invoice->delete();

    $this->actingAs($admin)->delete("/memberships/{$membership->id}")->assertRedirect();

    $notice = session('payment_notice');

    expect($notice['tone'] ?? null)->toBe('error')
        ->and(implode(' ', $notice['messages'] ?? []))->toContain('manuellement')
        ->and($membership->fresh()->trashed())->toBeFalse('Nothing deleted.')
        ->and(App\Models\TeacherMembershipPayment::where('membership_id', $membership->id)->where('is_active', true)->count())->toBe(1, 'Records untouched.');
});

test('membership-first then invoice no longer leaks: void the invoice, then delete', function () {
    // The tikoschool order, made safe: the membership delete is refused while the
    // paid invoice lives; voiding the invoice claws the money back; only then the
    // membership delete goes through. Net effect for the teacher: zero.
    $f = makeFlowMembership();
    extract($f);

    $this->actingAs($admin)->delete("/memberships/{$membership->id}")->assertRedirect();
    expect($membership->fresh()->trashed())->toBeFalse('First delete must be blocked.');

    // Void the invoice through its own flow (within the deadline: full claw-back).
    $this->actingAs($admin)->delete("/invoices/{$invoice->id}")->assertRedirect();
    expect((float) $teacher->fresh()->wallet)->toBe(0.0, 'Invoice void reverses the credit.');

    $this->actingAs($admin)->delete("/memberships/{$membership->id}")->assertRedirect();
    expect($membership->fresh()->trashed())->toBeTrue('Delete proceeds once no paid invoice lives.');
});
