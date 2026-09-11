<?php

use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Offer;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Models\User;
use App\Services\TeacherMembershipPaymentService;
use App\Support\LedgerShortfall;
use Carbon\Carbon;

/*
 * Phase-1 money-path hardening. Each test pins one door the Sept-2026
 * forensics found open:
 *
 * - reconcilePaidMonthsForInvoice() credited removed/inactive teachers
 *   (t6/7135, t7/7285, t23/7096 restores);
 * - GET /invoices/{id}/validate writes money behind a read-only name;
 * - destroy skipped inactive-with-money rows (t3/7040);
 * - wallet:adjust accepted teachers with no link to the invoice;
 * - POST /transactions admitted type=wallet to assistants (never used on
 *   either prod — kept that way by test);
 * - SettleStrandedPayouts + the monthly cron paid without roster checks;
 * - invoice membership_id could be re-pointed, minting a second pay chain.
 */

afterEach(fn () => Carbon::setTestNow());

function makeHardeningScene(): array
{
    \App\Models\Level::first() ?? \App\Models\Level::factory()->create();
    $teacher = Teacher::factory()->create(['wallet' => 0]);
    $student = Student::factory()->create();
    $offer = Offer::factory()->create([
        'price' => 300.0,
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

    return [$teacher, $student, $membership, $offer];
}

function billHardeningInvoice(Membership $membership, Student $student, float $paid = 300.0): Invoice
{
    $month = Carbon::now()->format('Y-m');

    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'offer_id' => $membership->offer_id,
        'billDate' => Carbon::now(),
        'creationDate' => Carbon::now(),
        'months' => 1,
        'selected_months' => [$month],
        'totalAmount' => 300,
        'amountPaid' => $paid,
        'rest' => round(300 - $paid, 2),
        'includePartialMonth' => false,
        'partialMonthAmount' => null,
        'last_payment_date' => Carbon::now(),
    ]);

    (new TeacherMembershipPaymentService)->processInvoicePayment($invoice, [
        'totalAmount' => 300,
        'amountPaid' => $paid,
        'rest' => round(300 - $paid, 2),
        'includePartialMonth' => false,
        'partialMonthAmount' => 0,
    ]);

    return $invoice;
}

test('reconcile ignores removed and inactive teachers', function () {
    [$teacher, $student, $membership] = makeHardeningScene();
    $invoice = billHardeningInvoice($membership, $student);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(150.0);

    // Teacher leaves with a gap on the record: desired 150, currently 100.
    $membership->update(['teachers' => []]);
    $record = TeacherMembershipPayment::where('invoice_id', $invoice->id)->first();
    $record->update(['is_active' => false, 'total_paid_to_teacher' => 100.0]);

    $result = (new TeacherMembershipPaymentService)->reconcilePaidMonthsForInvoice($invoice->fresh());

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(150.0, 'Removed teachers must never be re-credited.')
        ->and((int) ($result['adjusted_records'] ?? 0))->toBe(0);
});

test('the validate endpoint refuses teachers', function () {
    [$teacher, $student, $membership] = makeHardeningScene();
    $invoice = billHardeningInvoice($membership, $student);

    $teacherUser = User::factory()->create(['role' => 'teacher', 'email' => 'stranger-teacher@example.com']);

    $this->actingAs($teacherUser)->getJson("/invoices/{$invoice->id}/validate")->assertForbidden();

    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin)->getJson("/invoices/{$invoice->id}/validate")->assertSuccessful();
});

test('deleting an invoice reports inactive rows still holding money', function () {
    [$teacher, $student, $membership] = makeHardeningScene();
    $invoice = billHardeningInvoice($membership, $student);

    // Simulate a blocked-path leftover: dead record, totals intact, money held.
    TeacherMembershipPayment::where('invoice_id', $invoice->id)
        ->update(['is_active' => false]);

    $outcome = (new TeacherMembershipPaymentService)->reverseInvoicePayments($invoice->fresh());

    expect($outcome['blocked'] ?? [])->not->toBeEmpty('A dead row holding money must be reported, never silently skipped.')
        ->and(collect($outcome['blocked'])->pluck('reason')->all())->toContain('record_inactive');
});

test('wallet:adjust refuses a teacher with no link to the invoice', function () {
    [$teacher, $student, $membership] = makeHardeningScene();
    $invoice = billHardeningInvoice($membership, $student);

    $stranger = Teacher::factory()->create(['wallet' => 0]);

    $this->artisan('wallet:adjust', [
        '--teacher' => $stranger->id,
        '--amount' => 50,
        '--invoice' => $invoice->id,
        '--month' => '2026-09',
        '--subject' => 'Math',
        '--note' => 'stranger-probe',
        '--confirm' => true,
    ])->assertExitCode(1);

    expect(round((float) $stranger->fresh()->wallet, 2))->toBe(0.0);
});

test('wallet:adjust accepts a teacher with a record on the invoice', function () {
    [$teacher, $student, $membership] = makeHardeningScene();
    $invoice = billHardeningInvoice($membership, $student);

    $this->artisan('wallet:adjust', [
        '--teacher' => $teacher->id,
        '--amount' => 10,
        '--invoice' => $invoice->id,
        '--month' => '2026-09',
        '--subject' => 'Math',
        '--note' => 'linked-probe',
        '--confirm' => true,
    ])->assertExitCode(0);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(160.0);
});

test('assistant and admin cannot mint via type=wallet', function () {
    $school = App\Models\School::factory()->create();
    $admin = User::factory()->create(['role' => 'admin']);

    $assistantEmail = 'cashier-probe@example.com';
    $assistantUser = User::factory()->create(['role' => 'assistant', 'email' => $assistantEmail]);
    $assistant = App\Models\Assistant::factory()->create(['email' => $assistantEmail]);
    $assistant->schools()->attach($school->id);

    $teacherEmail = 'payee-probe@example.com';
    $teacherUser = User::factory()->create(['role' => 'teacher', 'email' => $teacherEmail]);
    $teacher = Teacher::factory()->create(['email' => $teacherEmail, 'wallet' => 0]);
    $teacher->schools()->attach($school->id);

    // Tikoschool keeps transactions admin-only (the assistant-CRUD
    // customization is centre-only): assistants bounce at the middleware.
    $this->actingAs($assistantUser)->post('/transactions', [
        'user_id' => $teacherUser->id,
        'type' => 'wallet',
        'amount' => 500,
        'payment_date' => now()->toDateString(),
        'description' => 'Mint probe',
    ])->assertRedirect('/dashboard');

    // Admins reach the form: the type itself must be refused.
    $this->actingAs($admin)->post('/transactions', [
        'user_id' => $teacherUser->id,
        'type' => 'wallet',
        'amount' => 500,
        'payment_date' => now()->toDateString(),
        'description' => 'Mint probe',
    ])->assertRedirect()->assertSessionHasErrors();

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(0.0, 'No wallet movement may back a rejected mint.')
        ->and(App\Models\Transaction::where('description', 'Mint probe')->count())->toBe(0);
});

test('gapped months are rejected on invoice create', function () {
    [$teacher, $student, $membership] = makeHardeningScene();
    $admin = User::factory()->create(['role' => 'admin']);

    // House behavior for validation failures is a redirect with errors
    // (the generic catch downgrades ValidationException into a flash).
    $this->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 2,
        'selected_months' => ['2026-09', '2026-11'],
        'billDate' => '2026-09-01',
        'creationDate' => '2026-09-01',
        'totalAmount' => 600,
        'amountPaid' => 600,
        'rest' => 0,
        'includePartialMonth' => false,
    ])->assertRedirect()->assertSessionHasErrors();

    expect(Invoice::where('membership_id', $membership->id)->count())->toBe(0, 'A gapped invoice must never be stored.');

    // Consecutive months are stored (proves the rule passes them through).
    $this->actingAs($admin)->post('/invoices', [
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'months' => 2,
        'selected_months' => ['2026-09', '2026-10'],
        'billDate' => '2026-09-01',
        'creationDate' => '2026-09-01',
        'totalAmount' => 600,
        'amountPaid' => 600,
        'rest' => 0,
        'includePartialMonth' => false,
    ])->assertRedirect();

    expect(Invoice::where('membership_id', $membership->id)->count())->toBe(1);
});

test('an invoice cannot be re-pointed at another membership once paid', function () {
    [$teacher, $student, $membership] = makeHardeningScene();
    $admin = User::factory()->create(['role' => 'admin']);

    $invoice = billHardeningInvoice($membership, $student);

    $otherMembership = Membership::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $membership->offer_id,
        'payment_status' => 'pending',
        'is_active' => true,
        'teachers' => [['teacherId' => $teacher->id, 'subject' => 'Math']],
    ]);

    $this->actingAs($admin)->put("/invoices/{$invoice->id}", [
        'membership_id' => $otherMembership->id,
        'student_id' => $student->id,
        'months' => 1,
        'selected_months' => [Carbon::now()->format('Y-m')],
        'billDate' => Carbon::now()->toDateString(),
        'totalAmount' => 300,
        'amountPaid' => 300,
        'rest' => 0,
        'includePartialMonth' => false,
    ])->assertRedirect()->assertSessionHasErrors();

    expect((int) $invoice->fresh()->membership_id)->toBe($membership->id);
});

test('settle skips teachers no longer on the roster', function () {
    [$teacher, $student, $membership] = makeHardeningScene();
    $invoice = billHardeningInvoice($membership, $student, paid: 100.0);

    // A gap the settle command would normally pay: totals 150, paid 50.
    $record = TeacherMembershipPayment::where('invoice_id', $invoice->id)->first();
    $record->update([
        'total_teacher_amount' => 150.0,
        'total_paid_to_teacher' => 50.0,
        'monthly_teacher_amount' => 0.0,
        'months_rest_not_paid_yet' => [],
    ]);

    // Teacher removed afterwards.
    $membership->update(['teachers' => []]);

    expect(LedgerShortfall::isAssigned(
        TeacherMembershipPayment::where('invoice_id', $invoice->id)->first()
    ))->toBeFalse('Fixture is wrong — teacher must read as unassigned.');

    $this->artisan('payouts:settle-stranded --apply')->assertExitCode(0);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(50.0, 'Stranded settle must not pay removed teachers.');
});

test('the monthly cron skips teachers no longer on the roster', function () {
    [$teacher, $student, $membership] = makeHardeningScene();

    $month = Carbon::now()->format('Y-m');

    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'offer_id' => $membership->offer_id,
        'billDate' => Carbon::now(),
        'creationDate' => Carbon::now(),
        'months' => 1,
        'selected_months' => [$month],
        'totalAmount' => 300,
        'amountPaid' => 300,
        'rest' => 0,
        'includePartialMonth' => false,
        'partialMonthAmount' => null,
        'last_payment_date' => Carbon::now(),
    ]);

    TeacherMembershipPayment::create([
        'teacher_id' => $teacher->id,
        'membership_id' => $membership->id,
        'invoice_id' => $invoice->id,
        'student_id' => $student->id,
        'selected_months' => [$month],
        'months_rest_not_paid_yet' => [$month],
        'total_teacher_amount' => 150.0,
        'monthly_teacher_amount' => 150.0,
        'payment_percentage' => 100.0,
        'teacher_subject' => 'Math',
        'teacher_percentage' => 50.0,
        'immediate_wallet_amount' => 0.0,
        'total_paid_to_teacher' => 0.0,
        'is_active' => true,
    ]);

    // Teacher leaves; the queued month must not follow them out.
    $membership->update(['teachers' => []]);

    (new TeacherMembershipPaymentService)->processMonthlyPayments($month);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(0.0)
        ->and(TeacherMembershipPayment::where('invoice_id', $invoice->id)->first()->months_rest_not_paid_yet)
        ->toBe([$month], 'The month stays queued for audit/repair instead of vanishing.');
});

test('a payment record always resolves its invoice, even trashed', function () {
    [$teacher, $student, $membership] = makeHardeningScene();
    $invoice = billHardeningInvoice($membership, $student);

    $record = TeacherMembershipPayment::where('invoice_id', $invoice->id)->first();

    $invoice->delete();

    expect($record->fresh()->invoice)->not->toBeNull('Trashed invoices must stay reachable from records.');
});

test('editing an offer price writes an audit row too', function () {
    \App\Models\Level::first() ?? \App\Models\Level::factory()->create();
    $offer = Offer::factory()->create([
        'price' => 300.0,
        'subjects' => ['Math'],
        'percentage' => ['Math' => 50],
    ]);

    $offer->update(['price' => 350.0]);

    expect(
        Spatie\Activitylog\Models\Activity::where('subject_type', Offer::class)
            ->where('subject_id', $offer->id)
            ->count()
    )->toBeGreaterThan(0, 'Price moves future commissions — it must leave a trace.');
});

/*
 * Reviewer HIGHs (Sept 2026): membership_id was only `exists:`-checked, so an
 * invoice could be billed against another student's membership — crediting the
 * wrong roster — and normaliseMonths() dropped garbage tokens silently while
 * storage kept the raw array (a stored 'garbage' token counts as a month and
 * dilutes every share). wallet:adjust uppercased subjects while the ledger
 * stores raw spelling, so the idempotency key and the ledger slice missed.
 */

function makeScopedBillingScene(): array
{
    \App\Models\Level::first() ?? \App\Models\Level::factory()->create();

    $schoolA = App\Models\School::factory()->create();
    $schoolB = App\Models\School::factory()->create();

    $assistantUser = User::factory()->create(['role' => 'assistant', 'email' => 'scope-cashier@example.com']);
    $assistant = App\Models\Assistant::factory()->create(['email' => 'scope-cashier@example.com']);
    $assistant->schools()->attach($schoolA->id);

    $build = function ($school): array {
        $teacher = Teacher::factory()->create(['wallet' => 0]);
        $student = Student::factory()->create(['schoolId' => $school->id]);
        $offer = Offer::factory()->create([
            'price' => 300.0,
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

        return [$teacher, $student, $membership];
    };

    return [$assistantUser, $build($schoolA), $build($schoolB)];
}

function billingPayload(int $membershipId, int $studentId, array $months): array
{
    return [
        'membership_id' => $membershipId,
        'student_id' => $studentId,
        'months' => count($months),
        'selected_months' => $months,
        'billDate' => Carbon::now()->toDateString(),
        'creationDate' => Carbon::now()->toDateString(),
        'totalAmount' => 300,
        'amountPaid' => 300,
        'rest' => 0,
        'includePartialMonth' => false,
    ];
}

test('assistant can still bill their own school (binding positive control)', function () {
    [$assistantUser, $sceneA] = makeScopedBillingScene();
    [$teacherA, $studentA, $membershipA] = $sceneA;
    $month = Carbon::now()->format('Y-m');

    $this->actingAs($assistantUser)->post('/invoices', billingPayload($membershipA->id, $studentA->id, [$month]))
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(Invoice::where('membership_id', $membershipA->id)->count())->toBe(1);
});

test('invoice store refuses a membership owned by another student', function () {
    [$teacher, $student, $membership] = makeHardeningScene();
    [$teacher2, $student2, $membership2] = makeHardeningScene();
    $admin = User::factory()->create(['role' => 'admin']);
    $month = Carbon::now()->format('Y-m');

    $this->actingAs($admin)->post('/invoices', billingPayload($membership2->id, $student->id, [$month]))
        ->assertRedirect()->assertSessionHasErrors();

    expect(Invoice::where('membership_id', $membership2->id)->count())
        ->toBe(0, 'Billing student A against student B’s membership must never store.');
});

test('assistant cannot bill across schools via a foreign membership', function () {
    [$assistantUser, $sceneA, $sceneB] = makeScopedBillingScene();
    [$teacherA, $studentA, $membershipA] = $sceneA;
    [$teacherB, $studentB, $membershipB] = $sceneB;
    $month = Carbon::now()->format('Y-m');

    // Own student (passes the student check) + foreign membership.
    $this->actingAs($assistantUser)->post('/invoices', billingPayload($membershipB->id, $studentA->id, [$month]))
        ->assertForbidden();

    expect(Invoice::where('membership_id', $membershipB->id)->count())->toBe(0);
});

test('invoice update refuses a re-point to another student’s membership', function () {
    [$teacher, $student, $membership] = makeHardeningScene();
    $month = Carbon::now()->format('Y-m');
    $invoice = Invoice::factory()->create([
        'membership_id' => $membership->id,
        'student_id' => $student->id,
        'offer_id' => $membership->offer_id,
        'billDate' => Carbon::now(),
        'creationDate' => Carbon::now(),
        'months' => 1,
        'selected_months' => [$month],
        'totalAmount' => 300,
        'amountPaid' => 0,
        'rest' => 300,
        'includePartialMonth' => false,
        'partialMonthAmount' => null,
        'last_payment_date' => Carbon::now(),
    ]);
    [$teacher2, $student2, $membership2] = makeHardeningScene();
    $admin = User::factory()->create(['role' => 'admin']);

    // No payment records exist, so the move-guard stays quiet: only the
    // membership/student binding check can refuse this.
    expect(TeacherMembershipPayment::where('invoice_id', $invoice->id)->count())->toBe(0);

    $this->actingAs($admin)->put("/invoices/{$invoice->id}", billingPayload($membership2->id, $student->id, [$month]))
        ->assertRedirect()->assertSessionHasErrors();

    expect((int) $invoice->fresh()->membership_id)->toBe((int) $membership->id);
});

test('price quote refuses a foreign membership', function () {
    [$assistantUser, $sceneA, $sceneB] = makeScopedBillingScene();
    [$teacherB, $studentB, $membershipB] = $sceneB;

    $this->actingAs($assistantUser)->postJson('/invoices/price', ['membership_id' => $membershipB->id])
        ->assertForbidden();

    $teacherUser = User::factory()->create(['role' => 'teacher', 'email' => 'quote-teacher@example.com']);
    $this->actingAs($teacherUser)->postJson('/invoices/price', ['membership_id' => $membershipB->id])
        ->assertForbidden();
});

test('garbage month tokens are rejected on store and update, never stored', function () {
    [$teacher, $student, $membership] = makeHardeningScene();
    $admin = User::factory()->create(['role' => 'admin']);
    $month = Carbon::now()->format('Y-m');

    $this->actingAs($admin)->post('/invoices', billingPayload($membership->id, $student->id, [$month, 'garbage']))
        ->assertRedirect()->assertSessionHasErrors();

    expect(Invoice::where('membership_id', $membership->id)->count())->toBe(0);

    $invoice = billHardeningInvoice($membership, $student);

    $this->actingAs($admin)->put("/invoices/{$invoice->id}", billingPayload($membership->id, $student->id, [$month, '2026-13']))
        ->assertRedirect()->assertSessionHasErrors();

    expect($invoice->fresh()->selected_months)->toBe([$month]);
});

test('wallet adjust treats subject casing as one key', function () {
    [$teacher, $student, $membership] = makeHardeningScene();
    $invoice = billHardeningInvoice($membership, $student);

    $this->artisan('wallet:adjust', [
        '--teacher' => $teacher->id,
        '--amount' => 10,
        '--invoice' => $invoice->id,
        '--month' => '2026-09',
        '--subject' => 'math',
        '--note' => 'case-probe',
        '--confirm' => true,
    ])->assertExitCode(0);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(160.0);

    // The ledger row stores the normalised spelling: the reversal-cap slice
    // and the idempotency key only work when every path writes one key.
    expect(App\Models\TeacherWalletEntry::where('invoice_id', $invoice->id)->where('note', 'case-probe')->value('teacher_subject'))
        ->toBe('math');

    // Same movement, different casing/spacing: already recorded, not a top-up.
    $this->artisan('wallet:adjust', [
        '--teacher' => $teacher->id,
        '--amount' => 10,
        '--invoice' => $invoice->id,
        '--month' => '2026-09',
        '--subject' => ' MATH ',
        '--note' => 'case-probe-retry',
        '--confirm' => true,
    ])->assertExitCode(1);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(160.0);
});

test('wallet adjust refuses a subject the teacher is not rostered for', function () {
    [$teacher, $student, $membership] = makeHardeningScene();
    $invoice = billHardeningInvoice($membership, $student);

    $this->artisan('wallet:adjust', [
        '--teacher' => $teacher->id,
        '--amount' => 10,
        '--invoice' => $invoice->id,
        '--month' => '2026-09',
        '--subject' => 'PC',
        '--note' => 'off-roster-probe',
        '--confirm' => true,
    ])->assertExitCode(1);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(150.0);
});

test('wallet adjust inherits the record subject when none is given', function () {
    [$teacher, $student, $membership] = makeHardeningScene();
    $invoice = billHardeningInvoice($membership, $student);
    $record = TeacherMembershipPayment::where('invoice_id', $invoice->id)->first();

    $this->artisan('wallet:adjust', [
        '--teacher' => $teacher->id,
        '--amount' => 5,
        '--invoice' => $invoice->id,
        '--record' => $record->id,
        '--month' => '2026-09',
        '--note' => 'inherit-probe',
        '--confirm' => true,
    ])->assertExitCode(0);

    // Lands in the record's own slice, not the '' slice no audit joins back.
    expect(App\Models\TeacherWalletEntry::where('invoice_id', $invoice->id)->where('note', 'inherit-probe')->value('teacher_subject'))
        ->toBe('math')
        ->and(round((float) $teacher->fresh()->wallet, 2))->toBe(155.0);
});

test('wallet adjust refuses a record owned by another invoice', function () {
    [$teacher, $student, $membership] = makeHardeningScene();
    $invoice = billHardeningInvoice($membership, $student);
    [$teacher2, $student2, $membership2] = makeHardeningScene();
    $invoice2 = billHardeningInvoice($membership2, $student2);
    $foreignRecord = TeacherMembershipPayment::where('invoice_id', $invoice2->id)->first();

    $this->artisan('wallet:adjust', [
        '--teacher' => $teacher->id,
        '--amount' => 10,
        '--invoice' => $invoice->id,
        '--record' => $foreignRecord->id,
        '--month' => '2026-09',
        '--subject' => 'Math',
        '--note' => 'foreign-record-probe',
        '--confirm' => true,
    ])->assertExitCode(1);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(150.0);
});
