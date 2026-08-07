<?php

use App\Models\Assistant;
use App\Models\Teacher;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * THE /transactions PAYMENT PATH
 *
 * There were no tests for any of this. Every scenario below reproduces something the
 * screen actually did wrong, so a regression here is a regression somebody would have to
 * notice by reconciling wallets by hand.
 *
 * Everything goes through the HTTP layer rather than calling the controller directly:
 * several of the bugs were about what reached the SCREEN — a rejection flashed to a key
 * nobody rendered is indistinguishable from a form that silently did nothing.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-08-10 09:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function txAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

/** A teacher user whose staff record is joined by email, as the app does everywhere. */
function txTeacher(float $wallet): array
{
    $email = 'prof'.fake()->unique()->numberBetween(1, 99999).'@example.com';
    $user = User::factory()->create(['role' => 'teacher', 'email' => $email]);
    $teacher = Teacher::factory()->create(['email' => $email, 'wallet' => $wallet]);

    return [$user, $teacher];
}

function txAssistant(float $salary): array
{
    $email = 'assist'.fake()->unique()->numberBetween(1, 99999).'@example.com';
    $user = User::factory()->create(['role' => 'assistant', 'email' => $email]);
    $assistant = Assistant::factory()->create(['email' => $email, 'salary' => $salary]);

    return [$user, $assistant];
}

function payload(array $overrides = []): array
{
    return array_merge([
        'type' => 'salary',
        'amount' => 100,
        'payment_date' => '2026-08-10',
        'description' => 'Test',
        'is_recurring' => false,
    ], $overrides);
}

/*
 * ---------------------------------------------------------------- paying a teacher
 */

test('paying a teacher takes the money out of the wallet', function () {
    [$user, $teacher] = txTeacher(1000);

    $this->actingAs(txAdmin())
        ->post('/transactions', payload(['user_id' => $user->id, 'amount' => 400]))
        ->assertRedirect(route('transactions.index'))
        ->assertSessionHas('success');

    expect((float) $teacher->fresh()->wallet)->toBe(600.0);
});

test('the type follows the role, whatever the form posts', function () {
    // The old form decided the type in JavaScript and could post a stale one. A teacher
    // payout recorded as `salary` never touches the wallet at all.
    [$user, $teacher] = txTeacher(1000);

    $this->actingAs(txAdmin())
        ->post('/transactions', payload(['type' => 'salary', 'user_id' => $user->id, 'amount' => 300]));

    expect(Transaction::latest('id')->first()->type)->toBe('payment')
        ->and((float) $teacher->fresh()->wallet)->toBe(700.0);
});

test('a payment larger than the wallet is refused on the amount field', function () {
    [$user, $teacher] = txTeacher(500);

    $this->actingAs(txAdmin())
        ->from('/transactions/create')
        ->post('/transactions', payload(['user_id' => $user->id, 'amount' => 900]))
        ->assertSessionHasErrors('amount');

    expect((float) $teacher->fresh()->wallet)->toBe(500.0)
        ->and(Transaction::count())->toBe(0);
});

test('the refusal reaches the screen as a field error, not a silent redirect', function () {
    // The whole complaint in one test. This used to be ->with('error', ...), and the
    // payments page rendered only flash.success — so the form appeared to do nothing.
    [$user] = txTeacher(500);

    $response = $this->actingAs(txAdmin())
        ->from('/transactions/create')
        ->post('/transactions', payload(['user_id' => $user->id, 'amount' => 900]));

    $errors = session('errors')->get('amount');

    expect($errors[0])->toContain('portefeuille')
        ->and($errors[0])->toContain('500,00 DH', '900,00 DH');
});

test('a teacher with an empty wallet is refused', function () {
    [$user] = txTeacher(0);

    $this->actingAs(txAdmin())
        ->post('/transactions', payload(['user_id' => $user->id, 'amount' => 50]))
        ->assertSessionHasErrors('amount');
});

test('paying a teacher whose staff record is missing says so', function () {
    // teachers are joined to users BY EMAIL. Changing one address orphans the other, and
    // the old screen reported this as a 0 DH balance with no explanation.
    $user = User::factory()->create(['role' => 'teacher', 'email' => 'orphan@example.com']);

    $this->actingAs(txAdmin())
        ->post('/transactions', payload(['user_id' => $user->id, 'amount' => 50]))
        ->assertSessionHasErrors('user_id');

    expect(session('errors')->get('user_id')[0])->toContain('e-mail');
});

/*
 * ---------------------------------------------------------------- paying an assistant
 */

test('an assistant can be paid in instalments up to their salary', function () {
    [$user] = txAssistant(3000);
    $admin = txAdmin();

    $this->actingAs($admin)->post('/transactions', payload(['user_id' => $user->id, 'amount' => 1000]));
    $this->actingAs($admin)->post('/transactions', payload(['user_id' => $user->id, 'amount' => 2000]));

    expect(Transaction::where('user_id', $user->id)->sum('amount'))->toEqual(3000);
});

test('an instalment that would exceed the salary is refused', function () {
    [$user] = txAssistant(3000);
    $admin = txAdmin();

    $this->actingAs($admin)->post('/transactions', payload(['user_id' => $user->id, 'amount' => 2500]));

    $this->actingAs($admin)
        ->post('/transactions', payload(['user_id' => $user->id, 'amount' => 800]))
        ->assertSessionHasErrors('amount');

    expect(Transaction::where('user_id', $user->id)->count())->toBe(1);
});

test('the salary cap is measured per month, not overall', function () {
    // Paying August in full must not block September.
    [$user] = txAssistant(3000);
    $admin = txAdmin();

    $this->actingAs($admin)->post('/transactions', payload([
        'user_id' => $user->id, 'amount' => 3000, 'payment_date' => '2026-08-10',
    ]));

    $this->actingAs($admin)
        ->post('/transactions', payload([
            'user_id' => $user->id, 'amount' => 3000, 'payment_date' => '2026-09-10',
        ]))
        ->assertSessionHasNoErrors();

    expect(Transaction::where('user_id', $user->id)->count())->toBe(2);
});

test('rest records what is still owed, not what the client claimed', function () {
    [$user] = txAssistant(3000);

    $this->actingAs(txAdmin())->post('/transactions', payload([
        'user_id' => $user->id,
        'amount' => 1200,
        // The old form posted its own `rest` and update() saved it verbatim.
        'rest' => 999999,
    ]));

    expect((float) Transaction::latest('id')->first()->rest)->toBe(1800.0);
});

/*
 * ---------------------------------------------------------------- who can be paid
 */

test('an admin user cannot be paid a salary through this form', function () {
    // The picker used to offer admins, and the server rejected every attempt with a
    // message no screen rendered — a dead end you could only discover by giving up.
    $target = User::factory()->create(['role' => 'admin']);

    $this->actingAs(txAdmin())
        ->post('/transactions', payload(['user_id' => $target->id, 'amount' => 100]))
        ->assertSessionHasErrors('user_id');
});

test('a staff payment with nobody selected is refused', function () {
    $this->actingAs(txAdmin())
        ->post('/transactions', payload(['user_id' => null, 'amount' => 100]))
        ->assertSessionHasErrors('user_id');
});

test('a zero-dirham payment is refused', function () {
    // min:0 used to allow it. A 0 DH row moves nothing and then counts as "already paid",
    // which silently removes that person from the month's batch run.
    [$user] = txTeacher(1000);

    $this->actingAs(txAdmin())
        ->post('/transactions', payload(['user_id' => $user->id, 'amount' => 0]))
        ->assertSessionHasErrors('amount');
});

/*
 * ---------------------------------------------------------------- expenses
 */

test('an expense stores its category', function () {
    // There was no `category` column. The form showed a twelve-option picker and the value
    // was dropped by the validator, by $fillable, and by the schema.
    $this->actingAs(txAdmin())->post('/transactions', payload([
        'type' => 'expense',
        'category' => 'maintenance',
        'amount' => 750,
        'user_id' => null,
    ]));

    $expense = Transaction::latest('id')->first();

    expect($expense->type)->toBe('expense')
        ->and($expense->category)->toBe('maintenance')
        ->and($expense->rest)->toBeNull();
});

test('an expense in the "other" category stores what the admin typed', function () {
    $this->actingAs(txAdmin())->post('/transactions', payload([
        'type' => 'expense',
        'category' => 'other',
        'custom_category' => 'Assurance du bus',
        'amount' => 400,
    ]));

    expect(Transaction::latest('id')->first()->category)->toBe('Assurance du bus');
});

test('an unknown expense category is refused', function () {
    $this->actingAs(txAdmin())
        ->post('/transactions', payload(['type' => 'expense', 'category' => '../etc/passwd', 'amount' => 10]))
        ->assertSessionHasErrors('category');
});

test('an expense moves no wallet', function () {
    [, $teacher] = txTeacher(1000);

    $this->actingAs(txAdmin())->post('/transactions', payload([
        'type' => 'expense', 'category' => 'office', 'amount' => 500,
    ]));

    expect((float) $teacher->fresh()->wallet)->toBe(1000.0);
});

/*
 * ---------------------------------------------------------------- editing
 */

test('raising an edited payment takes only the difference', function () {
    [$user, $teacher] = txTeacher(1000);
    $admin = txAdmin();

    $this->actingAs($admin)->post('/transactions', payload(['user_id' => $user->id, 'amount' => 300]));
    $transaction = Transaction::latest('id')->first();

    expect((float) $teacher->fresh()->wallet)->toBe(700.0);

    $this->actingAs($admin)->put("/transactions/{$transaction->id}", payload([
        'user_id' => $user->id, 'amount' => 500,
    ]))->assertSessionHasNoErrors();

    expect((float) $teacher->fresh()->wallet)->toBe(500.0);
});

test('an edit cannot exceed the balance a create could not exceed', function () {
    // update() checked the wallet only when the amount went UP, and never checked the
    // assistant salary cap at all — so an edit could do what a create refused.
    [$user] = txAssistant(3000);
    $admin = txAdmin();

    $this->actingAs($admin)->post('/transactions', payload(['user_id' => $user->id, 'amount' => 1000]));
    $transaction = Transaction::latest('id')->first();

    $this->actingAs($admin)
        ->put("/transactions/{$transaction->id}", payload(['user_id' => $user->id, 'amount' => 5000]))
        ->assertSessionHasErrors('amount');

    expect((float) $transaction->fresh()->amount)->toBe(1000.0);
});

test('an edit measures the cap without counting itself', function () {
    // Without excluding the row being edited, changing 1000 to 1200 reads "already paid
    // 1000 of 3000" and refuses a change that is really only 200 DH more.
    [$user] = txAssistant(3000);
    $admin = txAdmin();

    $this->actingAs($admin)->post('/transactions', payload(['user_id' => $user->id, 'amount' => 2500]));
    $transaction = Transaction::latest('id')->first();

    $this->actingAs($admin)
        ->put("/transactions/{$transaction->id}", payload(['user_id' => $user->id, 'amount' => 2800]))
        ->assertSessionHasNoErrors();

    expect((float) $transaction->fresh()->amount)->toBe(2800.0);
});

test('an edit that overdraws the wallet reports it instead of returning a 500', function () {
    // update() had no try/catch. updateEmployeeBalance() throws on an insufficient wallet,
    // so this produced a raw error page.
    [$user] = txTeacher(1000);
    $admin = txAdmin();

    $this->actingAs($admin)->post('/transactions', payload(['user_id' => $user->id, 'amount' => 1000]));
    $transaction = Transaction::latest('id')->first();

    $response = $this->actingAs($admin)
        ->from('/transactions')
        ->put("/transactions/{$transaction->id}", payload(['user_id' => $user->id, 'amount' => 5000]));

    $response->assertRedirect('/transactions');

    expect($response->status())->not->toBe(500)
        ->and((float) $transaction->fresh()->amount)->toBe(1000.0, 'The row must be untouched too.');
});

test('retyping a teacher payout as an expense puts the money back', function () {
    // The old guard required the NEW type to move a balance, so this branch was skipped
    // entirely and the money stayed out of the wallet with nothing recording it.
    [$user, $teacher] = txTeacher(1000);
    $admin = txAdmin();

    $this->actingAs($admin)->post('/transactions', payload(['user_id' => $user->id, 'amount' => 400]));
    $transaction = Transaction::latest('id')->first();

    expect((float) $teacher->fresh()->wallet)->toBe(600.0);

    $this->actingAs($admin)->put("/transactions/{$transaction->id}", payload([
        'type' => 'expense', 'category' => 'office', 'user_id' => null, 'amount' => 400,
    ]))->assertSessionHasNoErrors();

    expect((float) $teacher->fresh()->wallet)->toBe(1000.0);
});

/*
 * ---------------------------------------------------------------- deleting
 */

test('deleting a teacher payout returns the money to the wallet', function () {
    [$user, $teacher] = txTeacher(1000);
    $admin = txAdmin();

    $this->actingAs($admin)->post('/transactions', payload(['user_id' => $user->id, 'amount' => 400]));
    $transaction = Transaction::latest('id')->first();

    $this->actingAs($admin)->delete("/transactions/{$transaction->id}")
        ->assertSessionHas('success');

    expect((float) $teacher->fresh()->wallet)->toBe(1000.0)
        ->and(Transaction::find($transaction->id))->toBeNull();
});

test('the delete message says the wallet went back up', function () {
    // "Supprimer" sounds like nothing else changes, and then the balance moves.
    [$user] = txTeacher(1000);
    $admin = txAdmin();

    $this->actingAs($admin)->post('/transactions', payload(['user_id' => $user->id, 'amount' => 400]));
    $transaction = Transaction::latest('id')->first();

    $this->actingAs($admin)->delete("/transactions/{$transaction->id}");

    expect(session('success'))->toContain('portefeuille');
});

/*
 * ---------------------------------------------------------------- recurrence
 */

test('every frequency the form offers is accepted by the server', function () {
    // Three disagreeing vocabularies: the form offered values store() rejected, and stored
    // values that update() then rejected.
    [$user] = txAssistant(50000);
    $admin = txAdmin();

    foreach (Transaction::FREQUENCIES as $frequency) {
        $this->actingAs($admin)
            ->post('/transactions', payload([
                'user_id' => $user->id,
                'amount' => 100,
                'is_recurring' => true,
                'frequency' => $frequency,
                'next_payment_date' => '2026-09-10',
            ]))
            // No argument. assertSessionHasNoErrors($keys) treats a string as a KEY to
            // check, so passing a description here would assert that a field literally
            // named "frequency rejected: monthly" is clean — vacuously true, always.
            ->assertSessionHasNoErrors();

        expect(Transaction::latest('id')->first()->frequency)->toBe($frequency);
    }

    expect(Transaction::where('is_recurring', 1)->count())->toBe(count(Transaction::FREQUENCIES));
});

test('a recurring teacher payout actually debits the wallet when processed', function () {
    // THE BIG ONE. All four routed recurring processors created the payment row, wrote the
    // pivot entry, advanced the schedule and reported success — without ever touching the
    // wallet. The money stayed available and could be paid out again by hand.
    [$user, $teacher] = txTeacher(1000);
    $admin = txAdmin();

    $template = Transaction::create([
        'type' => 'payment',
        'user_id' => $user->id,
        'user_name' => $user->name,
        'amount' => 250,
        'description' => 'Avance mensuelle',
        'payment_date' => '2026-08-01',
        'is_recurring' => 1,
        'frequency' => 'monthly',
        'next_payment_date' => '2026-08-10',
    ]);

    $this->actingAs($admin)
        ->from('/recurring-transactions')
        ->post("/recurring-transactions/process/{$template->id}");

    expect((float) $teacher->fresh()->wallet)->toBe(750.0);
});

test('processing a recurrence advances it from the due date, not from today', function () {
    // Advancing from now() made a run that happened late push every future payment later,
    // month after month.
    [$user] = txTeacher(1000);

    $template = Transaction::create([
        'type' => 'payment',
        'user_id' => $user->id,
        'user_name' => $user->name,
        'amount' => 100,
        'payment_date' => '2026-08-01',
        'is_recurring' => 1,
        'frequency' => 'monthly',
        'next_payment_date' => '2026-08-01',   // due 9 days ago
    ]);

    $this->actingAs(txAdmin())
        ->from('/recurring-transactions')
        ->post("/recurring-transactions/process/{$template->id}");

    expect($template->fresh()->next_payment_date->format('Y-m-d'))->toBe('2026-09-01');
});

test('the transaction a recurrence creates is not itself recurring', function () {
    // replicate() carried is_recurring across, so the child became a template too and the
    // schedule multiplied on every run.
    [$user] = txTeacher(1000);

    $template = Transaction::create([
        'type' => 'payment',
        'user_id' => $user->id,
        'user_name' => $user->name,
        'amount' => 100,
        'payment_date' => '2026-08-01',
        'is_recurring' => 1,
        'frequency' => 'monthly',
        'next_payment_date' => '2026-08-10',
    ]);

    $this->actingAs(txAdmin())
        ->from('/recurring-transactions')
        ->post("/recurring-transactions/process/{$template->id}");

    expect(Transaction::where('is_recurring', 1)->count())->toBe(1);
});

test('an unpayable recurrence is skipped with a reason, not crashed over', function () {
    [$brokeUser] = txTeacher(0);
    [$okUser, $okTeacher] = txTeacher(1000);

    foreach ([$brokeUser, $okUser] as $user) {
        Transaction::create([
            'type' => 'payment',
            'user_id' => $user->id,
            'user_name' => $user->name,
            'amount' => 100,
            'payment_date' => '2026-08-01',
            'is_recurring' => 1,
            'frequency' => 'monthly',
            'next_payment_date' => '2026-08-10',
        ]);
    }

    $this->actingAs(txAdmin())
        ->from('/recurring-transactions')
        ->post('/recurring-transactions/process-all');

    // The payable one still went through, and the message names the one that did not.
    expect((float) $okTeacher->fresh()->wallet)->toBe(900.0)
        ->and(session('warning'))->toContain($brokeUser->name);
});

/*
 * ---------------------------------------------------------------- the form payload
 */

test('the create form ships the staff list with their available balances', function () {
    txTeacher(1500);
    txAssistant(3000);
    // An admin is NOT payable staff, so the picker must not offer them — it used to, and
    // every attempt to pay one was rejected by a message no screen rendered.
    User::factory()->create(['role' => 'admin']);

    $this->actingAs(txAdmin())
        ->get('/transactions/create')
        ->assertInertia(fn ($page) => $page
            ->component('Menu/PaymentsPage')
            ->where('formType', 'create')
            ->has('staff', 2)
            ->has('staff.0.available')
            ->has('staff.0.type')
            ->has('staff.0.has_profile')
            ->has('expenseCategories')
            ->has('frequencies')
        );
});

test('the available balance on the form nets off what was already paid', function () {
    [$user] = txAssistant(3000);
    $admin = txAdmin();

    $this->actingAs($admin)->post('/transactions', payload(['user_id' => $user->id, 'amount' => 1200]));

    $this->actingAs($admin)
        ->get('/transactions/create')
        ->assertInertia(fn ($page) => $page->where(
            'staff.0.available',
            fn ($available) => abs($available - 1800) < 0.01,
        ));
});

test('the create form does not ship the earnings dashboard', function () {
    // It used to call getCommonData(), which runs two aggregate queries per month since
    // the earliest invoice in the system — to render a panel the form view unmounts.
    $this->actingAs(txAdmin())
        ->get('/transactions/create')
        ->assertInertia(fn ($page) => $page
            ->where('adminEarnings', null)
            ->where('transactions.data', [])
        );
});

test('the pay button preselects the person it was pressed for', function () {
    [$user] = txTeacher(800);

    $this->actingAs(txAdmin())
        ->get('/transactions/create?user_id='.$user->id)
        ->assertInertia(fn ($page) => $page->where('preselectedUserId', $user->id));
});

/*
 * ---------------------------------------------------------------- authorization
 */

test('the teacher earnings report is closed to non-admins', function () {
    // It was `auth` only, took an OPTIONAL teacher_id, and returned every teacher's
    // earnings across every school when it was omitted.
    [$teacherUser] = txTeacher(100);

    $this->actingAs($teacherUser)->getJson('/teacher-earnings-report')->assertForbidden();
    $this->actingAs($teacherUser)->getJson('/teacher-invoice-breakdown')->assertForbidden();
});

test('an admin can still read the teacher earnings report', function () {
    $this->actingAs(txAdmin())->getJson('/teacher-earnings-report')->assertOk();
});
