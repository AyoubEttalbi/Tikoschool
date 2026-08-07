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

    // Past the deadline the first request only asks; this is the confirmed one.
    $this->actingAs(admin())
        ->from('/students')
        ->delete("/invoices/{$s->invoice->id}", ['confirm_keep_wallet' => true])
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

/*
 * Deleting past the deadline asks first.
 *
 * Inside the window a delete is reversible in the sense that matters — the money comes back.
 * Past it, the invoice is gone and the teachers keep what they were paid whatever happens
 * next, so the first request must not act. It shows the consequence and offers the choice.
 */

/**
 * An assistant who is genuinely allowed to touch this student.
 *
 * SchoolScope authorizes by school membership, so an assistant with no school link is
 * refused before any of this is reached — and the test would pass for the wrong reason,
 * proving only that the request 403s rather than that the notice is redacted.
 */
function assistantAtStudentSchool(PaymentScenario $s): User
{
    $email = 'assistante@example.com';
    $user = User::factory()->create(['role' => 'assistant', 'email' => $email]);
    $assistant = App\Models\Assistant::factory()->create(['email' => $email]);
    $assistant->schools()->attach($s->student->schoolId);

    return $user;
}

function staleInvoiceScenario(): PaymentScenario
{
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-08'], total: 1000, paid: 1000, billDate: '2026-08-01');

    Carbon::setTestNow(Carbon::parse('2026-08-01')
        ->addDays(TeacherMembershipPaymentService::REVERSAL_DEADLINE_DAYS + 3)
        ->setTime(9, 0));

    return $s;
}

test('the first delete past the deadline does not delete anything', function () {
    $s = staleInvoiceScenario();

    $this->actingAs(admin())->from('/students')->delete("/invoices/{$s->invoice->id}");

    expect(App\Models\Invoice::find($s->invoice->id))
        ->not->toBeNull('The invoice must still be there until the warning is accepted.')
        ->and($s->wallet('Math'))->toBe(500.0)
        ->and(session('payment_notice')['title'])->toBe('Supprimer cette facture ?');
});

test('the dialog offers a second button that actually deletes', function () {
    $s = staleInvoiceScenario();

    $this->actingAs(admin())->from('/students')->delete("/invoices/{$s->invoice->id}");

    $action = session('payment_notice')['actions'][0];

    expect($action['method'])->toBe('delete')
        ->and($action['url'])->toBe("/invoices/{$s->invoice->id}")
        ->and($action['data'])->toBe(['confirm_keep_wallet' => true])
        ->and($action['label'])->toContain('Supprimer');
});

test('confirming deletes the invoice and still leaves the wallet alone', function () {
    $s = staleInvoiceScenario();

    $this->actingAs(admin())
        ->from('/students')
        ->delete("/invoices/{$s->invoice->id}", ['confirm_keep_wallet' => true]);

    expect(App\Models\Invoice::find($s->invoice->id))->toBeNull()
        ->and($s->wallet('Math'))->toBe(500.0, 'Confirming must never claw the money back either.');
});

test('closing the dialog leaves the invoice exactly as it was', function () {
    // "J'ai compris" sends nothing at all, so this is really a check that the first request
    // was side-effect free — including the payout record, which must stay active.
    $s = staleInvoiceScenario();

    $this->actingAs(admin())->from('/students')->delete("/invoices/{$s->invoice->id}");

    expect($s->record('Math')->is_active)->toBeTrue('The payout record must not be deactivated by a question.')
        ->and($s->record('Math')->months_rest_not_paid_yet)->toBe([]);
});

test('inside the deadline there is no question — it just deletes and claws back', function () {
    $s = PaymentScenario::make(['Math' => 50]);
    $s->bill(months: ['2026-08'], total: 1000, paid: 1000, billDate: '2026-08-10');

    $this->actingAs(admin())->from('/students')->delete("/invoices/{$s->invoice->id}");

    expect(App\Models\Invoice::find($s->invoice->id))->toBeNull()
        ->and($s->wallet('Math'))->toBe(0.0)
        ->and(session('payment_notice')['actions'])->toBe([], 'Nothing to confirm.');
});

/*
 * Per-teacher amounts are payroll.
 *
 * An assistant needs to know the claw-back failed — that changes what they do next — but not
 * who earns how much, which appears on no other screen they can reach.
 */

test('an assistant is told what happened but not who earns what', function () {
    $s = staleInvoiceScenario();
    $assistant = assistantAtStudentSchool($s);

    $this->actingAs($assistant)->from('/students')->delete("/invoices/{$s->invoice->id}");

    $notice = session('payment_notice');

    expect($notice['details'])->toBe([], 'The per-teacher table is admin-only.')
        ->and($notice['restricted_note'])->not->toBeNull()
        ->and(implode(' ', $notice['messages']))
        ->not->toContain($s->teachers['Math']->last_name)
        ->and(implode(' ', $notice['messages']))
        ->not->toContain('500', 'No amounts in the prose either.');
});

test('the assistant still learns the money could not be taken back', function () {
    $s = staleInvoiceScenario();
    $assistant = assistantAtStudentSchool($s);

    $this->actingAs($assistant)->from('/students')->delete("/invoices/{$s->invoice->id}");

    // Redaction must remove the payroll, not the warning.
    $text = implode(' ', session('payment_notice')['messages']);

    expect($text)->toContain('ne peut plus')
        ->and($text)->toContain('portefeuille')
        ->and($text)->toContain('7 jours');
});

test('an admin still sees the full breakdown', function () {
    $s = staleInvoiceScenario();

    $this->actingAs(admin())->from('/students')->delete("/invoices/{$s->invoice->id}");

    $notice = session('payment_notice');

    expect($notice['details'])->toHaveCount(1)
        ->and($notice['details'][0]['label'])->toContain($s->teachers['Math']->last_name)
        ->and($notice['details'][0]['value'])->toContain('500')
        ->and($notice['restricted_note'])->toBeNull();
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
