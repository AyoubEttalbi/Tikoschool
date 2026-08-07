<?php

use App\Models\Level;
use App\Models\User;
use App\Services\TeacherMembershipPaymentService;
use App\Support\PaymentNotice;
use Illuminate\Support\Carbon;
use Tests\Support\PaymentScenario;

/*
 * SCENARIO SUITE 5 — does the warning actually reach the screen?
 *
 * Suites 1-4 prove the money is right and that the service explains itself. That is worth
 * nothing if the explanation stops at the controller. These tests follow one notice the
 * whole way:
 *
 *     service outcome -> PaymentNotice -> session('payment_notice') -> Inertia flash.payment
 *
 * The last hop is the one that silently broke before: several pages read `flash.success`
 * while the middleware only ever shared `flash.message`, so those banners never rendered
 * and nobody noticed, because a banner that never appears looks exactly like a banner with
 * nothing to say.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-08-10 09:00:00');
    Level::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

function admin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

test('deleting a stale invoice puts a warning in the session for the dialog', function () {
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-08'], total: 1000, paid: 1000, billDate: '2026-08-01');

    // Past the claw-back deadline: the teacher keeps 500 DH and somebody must be told.
    Carbon::setTestNow(Carbon::parse('2026-08-01')
        ->addDays(TeacherMembershipPaymentService::REVERSAL_DEADLINE_DAYS + 3)
        ->setTime(9, 0));

    $this->actingAs(admin())
        ->from('/students')
        ->delete("/invoices/{$s->invoice->id}")
        ->assertRedirect('/students')
        ->assertSessionHas('payment_notice', function ($notice) {
            return $notice['tone'] === 'warning'
                && str_contains($notice['title'], 'action requise')
                && $notice['details'] !== [];
        });

    expect($s->wallet('Math'))->toBe(500.0, 'The warning has to be true — the money really did stay.');
});

test('the notice names the teacher, the amount and why it was kept', function () {
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-08'], total: 1000, paid: 1000, billDate: '2026-08-01');

    Carbon::setTestNow(Carbon::parse('2026-08-01')
        ->addDays(TeacherMembershipPaymentService::REVERSAL_DEADLINE_DAYS + 3)
        ->setTime(9, 0));

    $this->actingAs(admin())->from('/students')->delete("/invoices/{$s->invoice->id}");

    $notice = session('payment_notice');
    $row = $notice['details'][0];

    expect($row['label'])->toContain($s->teachers['Math']->first_name)
        ->and($row['label'])->toContain('Math')
        ->and($row['value'])->toContain('500')
        ->and($row['note'])->toContain('délai dépassé');
});

test('a clean claw-back reports as a success, not a warning', function () {
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-08'], total: 1000, paid: 1000, billDate: '2026-08-10');

    $this->actingAs(admin())->from('/students')->delete("/invoices/{$s->invoice->id}");

    expect(session('payment_notice')['tone'])->toBe('success')
        ->and($s->wallet('Math'))->toBe(0.0);
});

test('deleting an invoice nobody was paid for opens no dialog at all', function () {
    // A dialog after every routine delete is a dialog people dismiss without reading.
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-08'], total: 1000, paid: 0, billDate: '2026-08-10');

    $this->actingAs(admin())
        ->from('/students')
        ->delete("/invoices/{$s->invoice->id}")
        ->assertSessionMissing('payment_notice');
});

test('the notice is shared to the front end as flash.payment', function () {
    // The hop that has broken before. Asserting the session alone would not catch it.
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-08'], total: 1000, paid: 1000, billDate: '2026-08-01');

    Carbon::setTestNow(Carbon::parse('2026-08-01')
        ->addDays(TeacherMembershipPaymentService::REVERSAL_DEADLINE_DAYS + 3)
        ->setTime(9, 0));

    $user = admin();

    $this->actingAs($user)->from('/students')->delete("/invoices/{$s->invoice->id}");

    $this->actingAs($user)
        ->get('/students')
        ->assertInertia(fn ($page) => $page
            ->where('flash.payment.tone', 'warning')
            ->has('flash.payment.messages')
            ->has('flash.payment.details', 1)
        );
});

test('every message the dialog will show is a complete French sentence', function () {
    // The dialog renders these verbatim. A raw array key or an English fragment leaking in
    // is not a cosmetic issue — it is the whole message the user gets.
    $s = PaymentScenario::make(['Math' => 40, 'Physique' => 30]);
    $s->bill(months: ['2026-08'], total: 1000, paid: 1000, billDate: '2026-08-01');

    Carbon::setTestNow(Carbon::parse('2026-08-01')
        ->addDays(TeacherMembershipPaymentService::REVERSAL_DEADLINE_DAYS + 1)
        ->setTime(9, 0));

    $this->actingAs(admin())->from('/students')->delete("/invoices/{$s->invoice->id}");

    $messages = session('payment_notice')['messages'];

    expect($messages)->not->toBeEmpty();

    foreach ($messages as $message) {
        expect($message)->toEndWith('.')
            ->and($message)->not->toMatch('/\b(deadline_passed|wallet_empty|teacher_id|Array)\b/');
    }
});

test('a rejected invoice explains every problem at once, not one per attempt', function () {
    // Two teachers both resolve to 0% — the clerk should see both, and stop discovering
    // them one form submission at a time.
    $s = PaymentScenario::make(
        percentages: ['Math' => 100],
        teacherSubjects: ['Math', 'Physique', 'SVT'],
    );

    $s->bill(months: ['2026-08'], total: 1000, paid: 1000);

    $notice = PaymentNotice::fromProcessingErrors($s->lastResult['errors']);

    expect($notice)->not->toBeNull()
        ->and($notice->toArray()['tone'])->toBe('error')
        ->and($notice->toArray()['messages'])->toHaveCount(2, 'Both underpaid teachers must be listed.');
});
