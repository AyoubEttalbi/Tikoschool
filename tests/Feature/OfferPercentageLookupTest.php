<?php

use App\Models\Invoice;
use App\Models\Level;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Services\TeacherMembershipPaymentService;
use App\Support\OfferPercentages;

/*
 * `offers.percentage` is a JSON object keyed by subject name. PHP array keys are case
 * sensitive, so a membership storing "math" against an offer that records "Math" resolved
 * to 0% — the teacher earned nothing from that student while the offer plainly said 60%.
 *
 * Found in real production-shaped data: membership 222, offer "2 BAC MATH",
 * percentages {"Math": 60}, teacher assigned to subject "math".
 */

// OfferFactory resolves `Level::inRandomOrder()->first()->id`, so at least one level has
// to exist before any offer can be built.
beforeEach(fn () => Level::factory()->create());

test('an exact subject match is returned unchanged', function () {
    // The common case must behave identically — this fix must not move anyone's money.
    $offer = Offer::factory()->create(['percentage' => ['Math' => 60, 'PC' => 30]]);

    expect(OfferPercentages::forSubject($offer, 'Math'))->toBe(60.0)
        ->and(OfferPercentages::forSubject($offer, 'PC'))->toBe(30.0);
});

test('a subject that differs only by case still finds its percentage', function () {
    $offer = Offer::factory()->create(['percentage' => ['Math' => 60]]);

    expect(OfferPercentages::forSubject($offer, 'math'))->toBe(60.0)
        ->and(OfferPercentages::forSubject($offer, 'MATH'))->toBe(60.0)
        ->and(OfferPercentages::forSubject($offer, 'MaTh'))->toBe(60.0);
});

test('surrounding and repeated whitespace is ignored', function () {
    $offer = Offer::factory()->create(['percentage' => ['Sciences Physiques' => 45]]);

    expect(OfferPercentages::forSubject($offer, '  sciences physiques '))->toBe(45.0)
        ->and(OfferPercentages::forSubject($offer, 'Sciences  Physiques'))->toBe(45.0);
});

test('a subject the offer never mentions returns null, not zero', function () {
    // Null and 0.0 must stay distinguishable: "not mentioned" triggers the
    // equal-distribution fallback, an explicit 0 means the offer allocates nothing.
    $offer = Offer::factory()->create(['percentage' => ['Math' => 60]]);

    expect(OfferPercentages::forSubject($offer, 'Anglais'))->toBeNull()
        ->and(OfferPercentages::forSubject($offer, ''))->toBeNull()
        ->and(OfferPercentages::forSubject($offer, null))->toBeNull();
});

test('an explicit zero is returned as zero, not treated as absent', function () {
    $offer = Offer::factory()->create(['percentage' => ['Math' => 60, 'Sport' => 0]]);

    expect(OfferPercentages::forSubject($offer, 'Sport'))->toBe(0.0);
});

test('an offer listing one subject under two spellings resolves deterministically', function () {
    // {"Math": 60, "math": 40} has no correct answer. It must not depend on JSON key
    // order — the same input has to give the same output every time.
    $offer = Offer::factory()->create(['percentage' => ['Math' => 60, 'math' => 40]]);

    $first = OfferPercentages::forSubject($offer, 'MATH');
    $second = OfferPercentages::forSubject($offer, 'MATH');

    expect($first)->toBe($second)
        ->and($first)->toBeIn([60.0, 40.0]);

    // An exact match still wins over the ambiguity.
    expect(OfferPercentages::forSubject($offer, 'math'))->toBe(40.0)
        ->and(OfferPercentages::forSubject($offer, 'Math'))->toBe(60.0);
});

test('a teacher whose subject differs only by case is paid the offer percentage, not zero', function () {
    // The end-to-end version: this is membership 222 reproduced.
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $student = Student::factory()->create();

    $offer = Offer::factory()->create([
        'price' => 500.0,
        'subjects' => ['Math'],
        'percentage' => ['Math' => 60],   // capital M
    ]);

    $membership = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'payment_status' => 'paid',
        'is_active' => true,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'math']],  // lowercase
    ]);

    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'totalAmount' => 500.0,
        'amountPaid' => 500.0,
        'rest' => 0,
        'months' => 1,
        'selected_months' => [now()->format('Y-m')],
        'billDate' => now()->startOfMonth(),
        'endDate' => now()->addMonth(),
        'includePartialMonth' => false,
    ]);

    (new TeacherMembershipPaymentService)->processInvoicePayment($invoice, [
        'membership_id' => $invoice->membership_id,
        'student_id' => $invoice->student_id,
        'months' => 1,
        'selected_months' => $invoice->selected_months,
        'billDate' => $invoice->billDate,
        'totalAmount' => 500.0,
        'amountPaid' => 500.0,
        'rest' => 0.0,
        'includePartialMonth' => false,
        'partialMonthAmount' => 0.0,
    ]);

    $teacher->refresh();

    // 500 x 60% = 300, which is what the offer actually says.
    //
    // Before this fix the lookup for "math" missed, so the equal-distribution fallback
    // took over: 100 - 60 already allocated = 40% left, 1 teacher, so 40% -> 200. And on
    // the update path (before §6.7) it resolved to 0% and clawed that 200 back to nothing.
    // Three different answers for one teacher, none of them 60%.
    expect((float) $teacher->wallet)->toBe(300.0);
});
