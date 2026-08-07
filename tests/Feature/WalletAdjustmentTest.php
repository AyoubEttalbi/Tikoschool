<?php

use App\Models\Level;
use App\Models\Teacher;
use App\Models\TeacherWalletEntry;
use App\Models\User;
use App\Services\TeacherWalletService;
use Illuminate\Support\Carbon;

/*
 * Changing a teacher's balance by hand.
 *
 * The wallet field was removed from the teacher edit form because it re-submitted a stale
 * balance: saving a phone number reverted any earnings credited while the form was open.
 * Removing it left admins with no way to correct a balance at all — this endpoint is the
 * replacement, and these tests pin down what makes it safe:
 *
 *   - the delta is taken from the row under lock, not from the browser's stale number;
 *   - every movement lands in the ledger, so `wallet:check` still reconciles;
 *   - a reason is mandatory;
 *   - admin only.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-08-10 09:00:00');
    Level::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

function adminUser(): User
{
    return User::factory()->create(['role' => 'admin']);
}

test('an admin can raise a balance and the movement is recorded', function () {
    $teacher = Teacher::factory()->create(['wallet' => 300]);

    $this->actingAs(adminUser())
        ->from("/teachers/{$teacher->id}")
        ->post("/teachers/{$teacher->id}/wallet", [
            'new_balance' => 500,
            'note' => 'régularisation septembre',
        ])
        ->assertRedirect("/teachers/{$teacher->id}")
        ->assertSessionHas('payment_notice');

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(500.0);

    $entry = TeacherWalletEntry::where('teacher_id', $teacher->id)->latest('id')->first();

    expect((float) $entry->amount)->toBe(200.0, 'The DELTA is recorded, not the target.')
        ->and($entry->reason)->toBe(TeacherWalletEntry::REASON_ADJUSTMENT)
        ->and($entry->note)->toContain('régularisation septembre');
});

test('an admin can lower a balance', function () {
    $teacher = Teacher::factory()->create(['wallet' => 300]);

    $this->actingAs(adminUser())->from('/teachers')->post("/teachers/{$teacher->id}/wallet", [
        'new_balance' => 120,
        'note' => 'trop-perçu repris en espèces',
    ]);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(120.0)
        ->and((float) TeacherWalletEntry::where('teacher_id', $teacher->id)->latest('id')->value('amount'))
        ->toBe(-180.0);
});

test('the wallet column and the ledger still agree afterwards', function () {
    // The whole reason adjustments go through the service: wallet:check must keep passing.
    $teacher = Teacher::factory()->create(['wallet' => 0]);

    $this->actingAs(adminUser())->from('/teachers')->post("/teachers/{$teacher->id}/wallet", [
        'new_balance' => 750,
        'note' => 'solde d\'ouverture corrigé',
    ]);

    expect(round((float) $teacher->fresh()->wallet, 2))
        ->toBe((new TeacherWalletService)->ledgerBalance($teacher->fresh()));
});

test('the delta comes from the current balance, not the stale one on screen', function () {
    // The bug the old form field had. The admin opened the page at 300 and typed 500. In the
    // meantime a student paid, taking the balance to 400. The result must be 500 — one
    // recorded movement of +100 — and NOT a blind write that erases the 100 just earned.
    $teacher = Teacher::factory()->create(['wallet' => 300]);

    (new TeacherWalletService)->credit(
        $teacher, 100, TeacherWalletEntry::REASON_IMMEDIATE, null, null, null, 'paiement étudiant'
    );

    $this->actingAs(adminUser())->from('/teachers')->post("/teachers/{$teacher->id}/wallet", [
        'new_balance' => 500,
        'note' => 'ajustement',
    ]);

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(500.0)
        ->and((float) TeacherWalletEntry::where('teacher_id', $teacher->id)->latest('id')->value('amount'))
        ->toBe(100.0, 'Only the difference from the CURRENT balance is applied.');
});

test('a reason is required', function () {
    $teacher = Teacher::factory()->create(['wallet' => 300]);

    $this->actingAs(adminUser())
        ->from('/teachers')
        ->post("/teachers/{$teacher->id}/wallet", ['new_balance' => 500])
        ->assertSessionHasErrors('note');

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(300.0, 'Nothing moves without a reason.');
});

test('a negative balance is refused', function () {
    $teacher = Teacher::factory()->create(['wallet' => 300]);

    $this->actingAs(adminUser())
        ->from('/teachers')
        ->post("/teachers/{$teacher->id}/wallet", ['new_balance' => -50, 'note' => 'test'])
        ->assertSessionHasErrors('new_balance');

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(300.0);
});

test('setting the same balance writes nothing', function () {
    $teacher = Teacher::factory()->create(['wallet' => 300]);

    $this->actingAs(adminUser())->from('/teachers')->post("/teachers/{$teacher->id}/wallet", [
        'new_balance' => 300,
        'note' => 'aucun changement',
    ]);

    expect(TeacherWalletEntry::where('teacher_id', $teacher->id)->count())
        ->toBe(0, 'A no-op must not clutter the ledger.');
});

test('an assistant cannot adjust a wallet', function () {
    $teacher = Teacher::factory()->create(['wallet' => 300]);
    $assistant = User::factory()->create(['role' => 'assistant']);

    $this->actingAs($assistant)
        ->post("/teachers/{$teacher->id}/wallet", ['new_balance' => 5000, 'note' => 'nope'])
        ->assertForbidden();

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(300.0);
});

test('a teacher cannot adjust their own wallet', function () {
    $teacher = Teacher::factory()->create(['wallet' => 300, 'email' => 'prof@example.com']);
    $teacherUser = User::factory()->create(['role' => 'teacher', 'email' => 'prof@example.com']);

    $this->actingAs($teacherUser)
        ->post("/teachers/{$teacher->id}/wallet", ['new_balance' => 99999, 'note' => 'nope'])
        ->assertForbidden();

    expect(round((float) $teacher->fresh()->wallet, 2))->toBe(300.0);
});

test('saving the teacher edit form still cannot move the wallet', function () {
    // The original defect. Even with the field gone from the UI, a hand-crafted request
    // must not be able to set the balance through the ordinary update route.
    $teacher = Teacher::factory()->create(['wallet' => 300, 'status' => 'active']);

    $this->actingAs(adminUser())->from('/teachers')->put("/teachers/{$teacher->id}", [
        'first_name' => $teacher->first_name,
        'last_name' => $teacher->last_name,
        'email' => $teacher->email,
        'status' => 'active',
        'phone_number' => '0600000000',
        'wallet' => 999999,
    ]);

    expect(round((float) $teacher->fresh()->wallet, 2))
        ->toBe(300.0, 'A profile save must never be a payroll change.');
});
