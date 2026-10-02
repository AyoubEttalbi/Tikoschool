<?php

use App\Models\Teacher;
use App\Models\TeacherWalletEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TeacherWalletService;
use Illuminate\Support\Carbon;

/*
 * ARRONDI CAISSE — THE NO-CHANGE PAYOUT (Tiko customization, Oct 2026).
 *
 * The school pays cash and keeps no change: a teacher holding 1 990 DH gets
 * handed a 2 000 DH note, and the wallet goes to −10 DH — an advance the next
 * earnings absorb. Two things gate it, both server-enforced:
 *
 *   1. the wallet must be strictly positive (no stacking: one advance
 *      outstanding at most — a wallet already at or below zero refuses even
 *      with confirmation),
 *   2. the admin ticks the overdraft box (single confirmation by client
 *      decision, Oct 2026 — no retyped amount).
 *
 * Without the tick, the refusal is byte-for-byte the old behavior.
 */

afterEach(fn () => Carbon::setTestNow());

function overdraftTeacher(float $wallet = 1990.0): array
{
    $email = fake()->unique()->safeEmail();
    $teacher = Teacher::factory()->create(['email' => $email, 'wallet' => 0]);
    $teacherUser = User::factory()->create(['role' => 'teacher', 'email' => $email]);
    $admin = User::factory()->create(['role' => 'admin']);

    (new TeacherWalletService)->credit(
        $teacher, $wallet, TeacherWalletEntry::REASON_ADJUSTMENT, null, null, null, 'opening'
    );

    return [$teacher, $teacherUser, $admin];
}

function overdraftPost($t, User $admin, User $teacherUser, float $amount, array $extra = [])
{
    // type="salary" is what the form REALLY posts for a teacher payout (its
    // transform sends "salary" as a hint for every staff payment; the server
    // derives payment-vs-salary from the role). Posting "payment" directly
    // would bypass the exact quirk that hid the Oct-2026 gate bug from this
    // suite — every test here uses the real wire shape.
    return $t->actingAs($admin)->post('/transactions', array_merge([
        'user_id' => $teacherUser->id,
        'type' => 'salary',
        'amount' => $amount,
        'payment_date' => now()->toDateString(),
        'description' => 'Paie',
    ], $extra));
}

test('a confirmed no-change payout drives the wallet to exactly minus ten', function () {
    [$teacher, $teacherUser, $admin] = overdraftTeacher(1990.0);

    $response = overdraftPost($this, $admin, $teacherUser, 2000.0, [
        'overdraft_confirmed' => true,
    ]);

    $response->assertRedirect(route('transactions.index'));
    $teacher->refresh();

    expect((float) $teacher->wallet)->toBe(-10.0)
        ->and(TeacherWalletEntry::where('teacher_id', $teacher->id)->where('reason', TeacherWalletEntry::REASON_PAYOUT)->sum('amount'))
        ->toEqual(-2000.0)
        ->and((new TeacherWalletService)->drift())->toBeEmpty('The ledger must reconcile — including negatives.')
        ->and((float) Transaction::latest('id')->first()->rest)->toBe(-10.0);
});

test('the same payout without confirmations is refused and moves nothing', function () {
    [$teacher, $teacherUser, $admin] = overdraftTeacher(1990.0);

    $response = overdraftPost($this, $admin, $teacherUser, 2000.0);

    // The refusal points at the confirmation checkbox: that is how an intended
    // overdraft proceeds. An unintended one stops here.
    $response->assertSessionHasErrors('overdraft_confirmed');
    $teacher->refresh();

    expect((float) $teacher->wallet)->toBe(1990.0)
        ->and(Transaction::count())->toBe(0);
});

test('the posted type hint never decides anything', function () {
    // Regression for the Oct-2026 gate bug: the gate checked the posted type
    // ("payment") while the form always posts the "salary" hint, so the gate
    // never opened for real browser posts while the suite stayed green. Both
    // hints below must behave identically — the role decides, never the hint.
    foreach (['salary', 'payment'] as $hint) {
        [$teacher, $teacherUser, $admin] = overdraftTeacher(1990.0);

        $response = $this->actingAs($admin)->post('/transactions', [
            'user_id' => $teacherUser->id,
            'type' => $hint,
            'amount' => 2000.0,
            'payment_date' => now()->toDateString(),
            'description' => 'Paie',
            'overdraft_confirmed' => true,
        ]);

        $response->assertRedirect(route('transactions.index'));
        expect((float) $teacher->fresh()->wallet)->toBe(-10.0);
    }
});

test('a forged advance amount without the tick grants nothing', function () {
    // overdraft_amount is not even a validated field anymore: without the
    // tick it is stripped unknown input, and the payout is refused.
    [$teacher, $teacherUser, $admin] = overdraftTeacher(1990.0);

    $response = overdraftPost($this, $admin, $teacherUser, 2000.0, [
        'overdraft_amount' => 10.0,
    ]);

    $response->assertSessionHasErrors('overdraft_confirmed');
    $teacher->refresh();

    expect((float) $teacher->wallet)->toBe(1990.0)
        ->and(Transaction::count())->toBe(0);
});

test('no advance stacks on an advance: a non-positive wallet refuses even confirmed', function () {
    [$teacher, $teacherUser, $admin] = overdraftTeacher(1990.0);

    overdraftPost($this, $admin, $teacherUser, 2000.0, [
        'overdraft_confirmed' => true,
        'overdraft_amount' => 10.0,
    ]);

    // Wallet is now −10. A second confirmed payout is refused outright.
    $response = overdraftPost($this, $admin, $teacherUser, 50.0, [
        'overdraft_confirmed' => true,
        'overdraft_amount' => 60.0,
    ]);

    $response->assertSessionHasErrors('amount');
    expect((float) $teacher->fresh()->wallet)->toBe(-10.0);
});

test('new earnings absorb the advance and payouts resume', function () {
    [$teacher, $teacherUser, $admin] = overdraftTeacher(1990.0);

    overdraftPost($this, $admin, $teacherUser, 2000.0, [
        'overdraft_confirmed' => true,
        'overdraft_amount' => 10.0,
    ]);

    (new TeacherWalletService)->credit(
        $teacher, 500.0, TeacherWalletEntry::REASON_MONTHLY, null, '2026-10', null, 'october'
    );

    // Wallet is 490: a plain payout inside the balance needs no confirmations.
    $response = overdraftPost($this, $admin, $teacherUser, 400.0);

    $response->assertRedirect(route('transactions.index'));
    expect((float) $teacher->fresh()->wallet)->toBe(90.0);
});

test('editing only the description of an overdraft payout keeps every dirham', function () {
    [$teacher, $teacherUser, $admin] = overdraftTeacher(1990.0);

    overdraftPost($this, $admin, $teacherUser, 2000.0, [
        'overdraft_confirmed' => true,
        'overdraft_amount' => 10.0,
    ]);

    $transaction = Transaction::latest('id')->first();

    // Same payee, same type, same amount: no money moves, so no funds check.
    // Posted as the form posts it (the "salary" hint) — this is the exact
    // shape that was broken before the no-move check derived the type.
    $response = $this->actingAs($admin)->put(route('transactions.update', $transaction->id), [
        'user_id' => $teacherUser->id,
        'type' => 'salary',
        'amount' => 2000.0,
        'payment_date' => now()->toDateString(),
        'description' => 'Paie — précision ajoutée',
    ]);

    $response->assertRedirect(route('transactions.index'));
    expect((float) $teacher->fresh()->wallet)->toBe(-10.0)
        ->and((float) $transaction->fresh()->rest)->toBe(-10.0);
});

test('an assistant salary cannot ride the overdraft flag', function () {
    $assistantUser = User::factory()->create(['role' => 'assistant']);
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->post('/transactions', [
        'user_id' => $assistantUser->id,
        'type' => 'salary',
        'amount' => 5000,
        'payment_date' => now()->toDateString(),
        'description' => 'Salaire',
        'overdraft_confirmed' => true,
        'overdraft_amount' => 5000.0,
    ]);

    // No assistant profile exists, so this fails on identity — the point is
    // the flag grants nothing: no transaction row is created either way.
    $response->assertSessionHasErrors();
    expect(Transaction::count())->toBe(0);
});

test('an assistant with a real profile is still capped at salary, flag or not', function () {
    $email = fake()->unique()->safeEmail();
    \App\Models\Assistant::factory()->create(['email' => $email, 'salary' => 3000.0]);
    $assistantUser = User::factory()->create(['role' => 'assistant', 'email' => $email]);
    $admin = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($admin)->post('/transactions', [
        'user_id' => $assistantUser->id,
        'type' => 'salary',
        'amount' => 4000,
        'payment_date' => now()->toDateString(),
        'description' => 'Salaire',
        'overdraft_confirmed' => true,
        'overdraft_amount' => 1000.0,
    ]);

    $response->assertSessionHasErrors('amount');
    expect(Transaction::count())->toBe(0);
});

test('raising an old payout past the balance is refused even with crafted confirmations', function () {
    [$teacher, $teacherUser, $admin] = overdraftTeacher(1990.0);

    overdraftPost($this, $admin, $teacherUser, 2000.0, [
        'overdraft_confirmed' => true,
        'overdraft_amount' => 10.0,
    ]);

    $transaction = Transaction::latest('id')->first();

    // Edits stay strict: confirmations are a create-path instrument.
    // Posted as the form posts it (the "salary" hint).
    $response = $this->actingAs($admin)->put(route('transactions.update', $transaction->id), [
        'user_id' => $teacherUser->id,
        'type' => 'salary',
        'amount' => 2100.0,
        'payment_date' => now()->toDateString(),
        'description' => 'Paie',
        'overdraft_confirmed' => true,
        'overdraft_amount' => 110.0,
    ]);

    $response->assertSessionHasErrors('amount');
    expect((float) $teacher->fresh()->wallet)->toBe(-10.0)
        ->and((float) $transaction->fresh()->amount)->toBe(2000.0);
});

test('a recurring template cannot carry an overdraft', function () {
    [$teacher, $teacherUser, $admin] = overdraftTeacher(1990.0);

    $response = overdraftPost($this, $admin, $teacherUser, 2000.0, [
        'overdraft_confirmed' => true,
        'overdraft_amount' => 10.0,
        'is_recurring' => true,
        'frequency' => 'monthly',
        'next_payment_date' => now()->addMonth()->toDateString(),
    ]);

    $response->assertSessionHasErrors('overdraft_confirmed');
    expect(Transaction::count())->toBe(0);
});

test('two rapid confirmed payouts advance only once', function () {
    [$teacher, $teacherUser, $admin] = overdraftTeacher(1990.0);

    // A double-click / replayed POST: both requests read 1 990 before either
    // commits. The first wins; the second meets a non-positive wallet.
    overdraftPost($this, $admin, $teacherUser, 2000.0, [
        'overdraft_confirmed' => true,
        'overdraft_amount' => 10.0,
    ]);
    $response = overdraftPost($this, $admin, $teacherUser, 2000.0, [
        'overdraft_confirmed' => true,
        'overdraft_amount' => 10.0,
    ]);

    $response->assertSessionHasErrors('amount');
    expect((float) $teacher->fresh()->wallet)->toBe(-10.0)
        ->and(Transaction::where('user_id', $teacherUser->id)->where('type', 'payment')->count())->toBe(1);
});

test('deleting an overdraft payout restores the wallet symmetrically', function () {
    [$teacher, $teacherUser, $admin] = overdraftTeacher(1990.0);

    overdraftPost($this, $admin, $teacherUser, 2000.0, [
        'overdraft_confirmed' => true,
        'overdraft_amount' => 10.0,
    ]);

    $transaction = Transaction::latest('id')->first();

    $this->actingAs($admin)->delete(route('transactions.destroy', $transaction->id));

    expect((float) $teacher->fresh()->wallet)->toBe(1990.0)
        ->and(TeacherWalletEntry::where('teacher_id', $teacher->id)->sum('amount'))->toEqual(1990.0)
        ->and((new TeacherWalletService)->drift())->toBeEmpty();
});

test('the service refuses an overdraft debit once the wallet is non-positive', function () {
    [$teacher] = overdraftTeacher(1990.0);
    $service = new TeacherWalletService;

    expect($service->debit($teacher, 2000.0, TeacherWalletEntry::REASON_PAYOUT, null, null, null, 'first', null, true))->toBeTrue();

    $teacher->refresh();

    expect((float) $teacher->wallet)->toBe(-10.0)
        ->and($service->debit($teacher, 50.0, TeacherWalletEntry::REASON_PAYOUT, null, null, null, 'second', null, true))->toBeFalse()
        ->and((float) $teacher->fresh()->wallet)->toBe(-10.0)
        ->and(TeacherWalletEntry::where('teacher_id', $teacher->id)->count())->toBe(2);
});

test('a non-payout debit on a negative wallet takes nothing instead of crediting', function () {
    // Regression pin for the clamp sign-flip: max(-50, -(-10)) = +10 would
    // have recorded a credit. On a negative wallet there is nothing to take.
    [$teacher] = overdraftTeacher(1990.0);
    $service = new TeacherWalletService;
    $service->debit($teacher, 2000.0, TeacherWalletEntry::REASON_PAYOUT, null, null, null, 'advance', null, true);

    expect($service->debit($teacher, 50.0, TeacherWalletEntry::REASON_ADJUSTMENT))->toBeFalse()
        ->and((float) $teacher->fresh()->wallet)->toBe(-10.0)
        ->and(TeacherWalletEntry::where('teacher_id', $teacher->id)->count())->toBe(2);
});

test('reversing an invoice against an advance is blocked as advance outstanding', function () {
    Carbon::setTestNow('2026-10-05 10:00:00');

    $scenario = \Tests\Support\PaymentScenario::make(['Math' => 50], ['Math'], 1000);
    $scenario->bill([Carbon::now()->format('Y-m')], 1000, 1000);

    $teacher = $scenario->teachers['Math'];
    $email = fake()->unique()->safeEmail();
    $teacher->forceFill(['email' => $email])->save();
    $teacherUser = User::factory()->create(['role' => 'teacher', 'email' => $email]);
    $admin = User::factory()->create(['role' => 'admin']);

    // Wallet holds the 500 commission; overdraft payout takes 510.
    // Posted as the form posts it (the "salary" hint).
    $this->actingAs($admin)->post('/transactions', [
        'user_id' => $teacherUser->id,
        'type' => 'salary',
        'amount' => 510,
        'payment_date' => now()->toDateString(),
        'description' => 'Paie',
        'overdraft_confirmed' => true,
        'overdraft_amount' => 10.0,
    ]);

    expect((float) $teacher->fresh()->wallet)->toBe(-10.0);

    $outcome = (new \App\Services\TeacherMembershipPaymentService)->reverseInvoicePayments($scenario->invoice->fresh());

    $reasons = collect($outcome['blocked'] ?? [])->pluck('reason')->all();

    expect($reasons)->toContain('advance_outstanding')
        ->and((float) $teacher->fresh()->wallet)->toBe(-10.0);
});
