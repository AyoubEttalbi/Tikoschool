<?php

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherWalletEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * SUITE — old-level membership update preload.
 *
 * A student who changed level keeps memberships on offers outside her current
 * level. The student page level-filters the offer list (correct for CREATE),
 * so the UPDATE form must preload from the membership's own offer data instead
 * of searching that filtered list — without ever widening what CREATE offers.
 */

function makeLevelChangedScenario(int $daysSincePayment = 0): array
{
    Carbon::setTestNow('2026-09-05 10:00:00');

    $school = App\Models\School::factory()->create();
    $admin = User::factory()->create(['role' => 'admin']);

    $oldLevel = App\Models\Level::factory()->create();
    $newLevel = App\Models\Level::factory()->create();

    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $teacher->schools()->attach($school->id);
    $replacement = Teacher::factory()->create(['wallet' => 0]);
    $replacement->schools()->attach($school->id);

    // The student moved on; her membership did not.
    $student = Student::factory()->create(['schoolId' => $school->id, 'levelId' => $newLevel->id]);
    $oldOffer = Offer::factory()->create([
        'levelId' => $oldLevel->id,
        'price' => 200.0,
        'subjects' => ['Math'],
        'percentage' => ['Math' => 50],
    ]);

    $membership = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $oldOffer->id,
        'payment_status' => 'paid',
        'is_active' => true,
        'start_date' => '2025-09-01',
        'end_date' => '2025-09-30',
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);

    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'offer_id' => $oldOffer->id,
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

    if ($daysSincePayment > 0) {
        Invoice::whereKey($invoice->id)->update(['last_payment_date' => now()->subDays($daysSincePayment)]);
    }

    return compact('school', 'admin', 'teacher', 'replacement', 'student', 'oldOffer', 'oldLevel', 'newLevel', 'membership', 'invoice');
}

afterEach(function () {
    Carbon::setTestNow();
});

test('level-changed membership payload carries its own offer data, create list stays level-scoped', function () {
    $f = makeLevelChangedScenario();
    extract($f);

    $this->actingAs($admin)->get("/students/{$student->id}")->assertOk()->assertInertia(
        fn (Assert $page) => $page
            ->component('Menu/SingleStudentPage')
            // The update form preloads from these — the whole point of the fix.
            ->where('student.memberships.0.offer_id', (int) $oldOffer->id)
            ->where('student.memberships.0.subjects', ['Math'])
            ->where('student.memberships.0.percentage', ['Math' => 50])
            // CREATE must never see the old-level offer.
            ->where('Alloffers', fn ($offers) => collect($offers)->every(fn ($o) => (int) $o['levelId'] === (int) $newLevel->id)
                && collect($offers)->pluck('id')->doesntContain((int) $oldOffer->id))
    );
});

test('saving an old-level membership unchanged moves no money', function () {
    $f = makeLevelChangedScenario();
    extract($f);

    $ledgerBefore = TeacherWalletEntry::count();

    $this->actingAs($admin)->put("/memberships/{$membership->id}", [
        'student_id' => $student->id,
        'offer_id' => $oldOffer->id,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math', 'amount' => 100]],
    ])->assertRedirect();

    expect((float) $teacher->fresh()->wallet)->toBe(100.0)
        ->and(TeacherWalletEntry::count())->toBe($ledgerBefore, 'Not even ledger noise.')
        ->and(session()->has('payment_notice'))->toBeFalse('No dialog for a moneyless save.');
});

test('fresh-paid old-level teacher swap previews then moves on confirm', function () {
    $f = makeLevelChangedScenario();
    extract($f);

    $payload = [
        'student_id' => $student->id,
        'offer_id' => $oldOffer->id,
        'teachers' => [['teacherId' => $replacement->id, 'subject' => 'Math', 'amount' => 100]],
    ];

    // Preview first: writes nothing.
    $this->actingAs($admin)->put("/memberships/{$membership->id}", $payload)->assertRedirect();
    expect(session('payment_notice'))->not->toBeNull('Swap on a paid membership previews.')
        ->and((float) $teacher->fresh()->wallet)->toBe(100.0, 'Preview moves nothing.');

    // Confirm: old teacher reversed, replacement credited at the OLD offer rate.
    $this->actingAs($admin)->put("/memberships/{$membership->id}", [
        'confirm_teachers_change' => true,
        'payload' => $payload,
    ])->assertRedirect();

    expect((float) $teacher->fresh()->wallet)->toBe(0.0, 'Old teacher reversed on confirm.')
        ->and((float) $replacement->fresh()->wallet)->toBe(100.0, 'Replacement credited at the old offer rate.');
});

test('settled old-level teacher swap re-points without moving wallets', function () {
    $f = makeLevelChangedScenario(30);
    extract($f);

    $payload = [
        'student_id' => $student->id,
        'offer_id' => $oldOffer->id,
        'teachers' => [['teacherId' => $replacement->id, 'subject' => 'Math', 'amount' => 100]],
    ];

    $this->actingAs($admin)->put("/memberships/{$membership->id}", [
        'confirm_teachers_change' => true,
        'payload' => $payload,
    ])->assertRedirect();

    expect($membership->fresh()->teachers[0]['teacherId'])->toBe((int) $replacement->id, 'Teachers re-pointed.')
        ->and((float) $teacher->fresh()->wallet)->toBe(100.0, 'Settled money stays with the teacher who earned it.')
        ->and((float) $replacement->fresh()->wallet)->toBe(0.0, 'No fresh credit for settled months.');
});

test('fresh old-level offer switch reprocesses at the new percentages', function () {
    $f = makeLevelChangedScenario();
    extract($f);

    // A current-level offer for the same subject at a different rate.
    $newOffer = Offer::factory()->create([
        'levelId' => $newLevel->id,
        'price' => 300.0,
        'subjects' => ['Math'],
        'percentage' => ['Math' => 100],
    ]);

    $payload = [
        'student_id' => $student->id,
        'offer_id' => $newOffer->id,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math', 'amount' => 300]],
    ];

    $this->actingAs($admin)->put("/memberships/{$membership->id}", [
        'confirm_teachers_change' => true,
        'payload' => $payload,
    ])->assertRedirect();

    // 100 taken back at the old rate, then the same 200 DH the student paid is
    // re-split at the new rate: 100% x 200 = 200. The payout follows money
    // actually received, never the new offer's sticker price (300).
    expect((int) $membership->fresh()->offer_id)->toBe((int) $newOffer->id)
        ->and((float) $teacher->fresh()->wallet)->toBe(200.0, 'Reprocessed at the new offer rate on money received.');
});

test('past-school-year memberships are flagged historical, current ones are not', function () {
    // Now is Sept 2026 → academic year 2026/2027. The fixture membership ended
    // Sept 2025 → 2025/2026 → historical.
    $f = makeLevelChangedScenario();
    extract($f);

    $current = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $oldOffer->id,
        'payment_status' => 'pending',
        'is_active' => true,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);

    $response = $this->actingAs($admin)->get("/students/{$student->id}")->assertOk();

    $response->assertInertia(
        fn (Assert $page) => $page
            ->where('student.memberships.0.is_historical', true)
            ->where('student.memberships.1.is_historical', false)
    );

    $current->delete();
});
