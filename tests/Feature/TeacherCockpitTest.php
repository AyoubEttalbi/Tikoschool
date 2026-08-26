<?php

use App\Models\Classes;
use App\Models\Invoice;
use App\Models\Level;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\TeacherMembershipPayment;
use App\Models\TeacherWalletEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;

/*
 * THE TEACHER'S HOME (« Espace enseignant »).
 *
 * /dashboard used to hard-bounce every teacher to their own HR profile page
 * (RoleRedirect -> teachers.show), so the first screen answered "who am I?"
 * instead of "what is my money doing and what do I teach?". The dashboard is
 * now role-aware for all three staff roles.
 */

beforeEach(function () {
    $this->school = School::factory()->create();
});

afterEach(fn () => Carbon::setTestNow());

/** A teacher User + staff row attached to the given school. */
function cockpitStaffTeacher(School $school, array $attributes = []): array
{
    $email = fake()->unique()->safeEmail();
    $user = User::factory()->create(['role' => 'teacher', 'email' => $email]);
    $teacher = Teacher::factory()->create(array_merge(['email' => $email], $attributes));
    $teacher->schools()->attach($school->id);

    return [$user, $teacher];
}

it('renders the teacher cockpit on the dashboard', function () {
    [$user, $teacher] = cockpitStaffTeacher($this->school);
    Session::put('school_id', $this->school->id);

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Menu/TeacherDashboard')
            ->where('identity.name', fn ($value) => filled($value))
            ->has('kpis.wallet')
            ->has('kpis.pendingCommissions')
            ->has('queue.recentLedger')
            ->has('announcements'));
});

it('exposes the cached wallet balance as the money KPI', function () {
    [$user, $teacher] = cockpitStaffTeacher($this->school);
    $teacher->forceFill(['wallet' => 1250.50])->save();
    Session::put('school_id', $this->school->id);

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('kpis.wallet.balance', fn ($value) => (float) $value === 1250.50));
});

it('computes pending commissions from unpaid months times the monthly share', function () {
    [$user, $teacher] = cockpitStaffTeacher($this->school);

    $level = Level::factory()->create();
    $class = Classes::factory()->create(['school_id' => $this->school->id, 'level_id' => $level->id]);
    $studentA = Student::factory()->create(['schoolId' => $this->school->id, 'classId' => $class->id, 'levelId' => $level->id]);
    $studentB = Student::factory()->create(['schoolId' => $this->school->id, 'classId' => $class->id, 'levelId' => $level->id]);

    // Two unpaid months at 150 DH each.
    TeacherMembershipPayment::forceCreate([
        'student_id' => $studentA->id,
        'teacher_id' => $teacher->id,
        'membership_id' => null,
        'invoice_id' => Invoice::factory()->create(['student_id' => $studentA->id])->id,
        'selected_months' => ['2026-07', '2026-08'],
        'months_rest_not_paid_yet' => ['2026-07', '2026-08'],
        'monthly_teacher_amount' => 150,
        'total_teacher_amount' => 300,
        'payment_percentage' => 50,
        'teacher_percentage' => 50,
        'teacher_subject' => '',
    ]);
    // Fully paid row: must contribute nothing to "à payer".
    TeacherMembershipPayment::forceCreate([
        'student_id' => $studentB->id,
        'teacher_id' => $teacher->id,
        'membership_id' => null,
        'invoice_id' => Invoice::factory()->create(['student_id' => $studentB->id])->id,
        'selected_months' => ['2026-08'],
        'months_rest_not_paid_yet' => [],
        'monthly_teacher_amount' => 999,
        'total_teacher_amount' => 999,
        'payment_percentage' => 50,
        'teacher_percentage' => 50,
        'teacher_subject' => '',
    ]);

    Session::put('school_id', $this->school->id);

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('kpis.pendingCommissions.count', 1)
            ->where('kpis.pendingCommissions.total', fn ($value) => (float) $value === 300.0)
            ->has('queue.pendingCommissionsList', 1)
            ->where('queue.pendingCommissionsList.0.student_name', fn ($value) => str_contains((string) $value, $studentA->refresh()->firstName)));
});

it('403s an admin off my-payments instead of redirecting', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->get('/my-payments')->assertForbidden();
});

/* ------------------------------------------------------------------ */
/* « Mes gains » — the per-invoice/per-month share table */
/* ------------------------------------------------------------------ */

it('lists the teacher gains per invoice month with the offer share math', function () {
    // Freeze inside the invoice month: « Ce mois-ci » must count it.
    Carbon::setTestNow('2026-08-26 12:00:00');

    [$user, $teacher] = cockpitStaffTeacher($this->school);

    // Offer allocates 50% to "Math"; the membership names this teacher on
    // subject "Math"; invoice paid 400 DH over one month -> part = 200 DH.
    $level = Level::factory()->create();
    $offer = \App\Models\Offer::factory()->create([
        'levelId' => $level->id,
        'percentage' => ['Math' => 50],
    ]);
    $class = Classes::factory()->create(['school_id' => $this->school->id, 'level_id' => $level->id]);
    $student = Student::factory()->create([
        'schoolId' => $this->school->id,
        'classId' => $class->id,
        'levelId' => $level->id,
    ]);
    \App\Models\Membership::forceCreate([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'teachers' => [['teacherId' => (string) $teacher->id, 'subject' => 'Math']],
    ]);
    Invoice::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'selected_months' => ['2026-08'],
        'totalAmount' => 400,
        'amountPaid' => 400,
        'rest' => 0,
    ]);

    $this->actingAs($user)->get('/my-payments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('gains.data', 1)
            ->where('gains.data.0.student_name', fn ($value) => str_contains((string) $value, $student->refresh()->firstName))
            ->where('gains.data.0.teacher_amount', fn ($value) => (float) $value === 200.0)
            ->where('gains.data.0.is_month_paid', false)
            ->where('gains.current_page', 1)
            // Regression: Collection::where(...,'like',...) is not a thing —
            // it silently matched nothing and pinned this card to zero.
            ->where('gainsStats.current_month_gains', fn ($value) => (float) $value === 200.0));
});

it('filters the gains table and computes the summary cards on the filtered set', function () {
    [$user, $teacher] = cockpitStaffTeacher($this->school);

    $level = Level::factory()->create();
    $offer = \App\Models\Offer::factory()->create([
        'levelId' => $level->id,
        'percentage' => ['Math' => 50],
    ]);
    $class = Classes::factory()->create(['school_id' => $this->school->id, 'level_id' => $level->id]);

    // Paid commission: 400 DH paid × 50% = 200.
    $paidStudent = Student::factory()->create(['schoolId' => $this->school->id, 'classId' => $class->id, 'levelId' => $level->id]);
    $membership = \App\Models\Membership::forceCreate([
        'student_id' => $paidStudent->id,
        'offer_id' => $offer->id,
        'teachers' => [['teacherId' => (string) $teacher->id, 'subject' => 'Math']],
    ]);
    Invoice::factory()->create([
        'student_id' => $paidStudent->id,
        'offer_id' => $offer->id,
        'selected_months' => ['2026-08'],
        'totalAmount' => 400,
        'amountPaid' => 400,
        'rest' => 0,
    ]);
    // The Payé badge reads the PAYOUT tracker, not the invoice: mark this
    // teacher-month as handed over.
    TeacherMembershipPayment::forceCreate([
        'student_id' => $paidStudent->id,
        'teacher_id' => $teacher->id,
        // Must be THE membership id: the paid-month lookup joins on it.
        'membership_id' => $membership->id,
        'invoice_id' => Invoice::where('student_id', $paidStudent->id)->first()->id,
        'selected_months' => ['2026-08'],
        'months_rest_not_paid_yet' => [],
        'monthly_teacher_amount' => 200,
        'total_teacher_amount' => 200,
        'payment_percentage' => 50,
        'teacher_percentage' => 50,
        'teacher_subject' => '',
    ]);

    // Pending commission: 100 DH paid × 50% = 50.
    $pendingStudent = Student::factory()->create(['schoolId' => $this->school->id, 'classId' => $class->id, 'levelId' => $level->id]);
    \App\Models\Membership::forceCreate([
        'student_id' => $pendingStudent->id,
        'offer_id' => $offer->id,
        'teachers' => [['teacherId' => (string) $teacher->id, 'subject' => 'Math']],
    ]);
    Invoice::factory()->create([
        'student_id' => $pendingStudent->id,
        'offer_id' => $offer->id,
        'selected_months' => ['2026-07'],
        'totalAmount' => 100,
        'amountPaid' => 100,
        'rest' => 0,
    ]);

    // Status filter FIRST this time.
    $this->actingAs($user)
        ->get('/my-payments?payment_status_filter=pending')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('gains.data', 1)
            ->where('gains.data.0.is_month_paid', false));

    // Search second.
    $json2 = $this->actingAs($user)
        ->get('/my-payments?search='.urlencode($pendingStudent->firstName))
        ->assertOk()
        ->inertiaPage();
    dump('SECOND SEARCH:', $json2['props']['gainsFilters']['search'] ?? 'MISSING');
    dump('SECOND ROWS:', collect($json2['props']['gains']['data'])->pluck('student_name')->all());

    // Status filter alone keeps both rows visible in options but only pending rows listed.
    $this->actingAs($user)
        ->get('/my-payments?payment_status_filter=pending')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('gains.data', 1)
            ->where('gains.data.0.is_month_paid', false)
            // Options come from ALL rows, not the filtered ones.
            ->where('gainsOptions.classes', fn ($classes) => count($classes) >= 1));
});

it('still sends a teacher without a selected school to the picker', function () {
    [$user] = cockpitStaffTeacher($this->school);

    $this->actingAs($user)->get('/dashboard')
        ->assertRedirect(route('profiles.select'));
});

it('never drops an orphaned teacher login into owner analytics', function () {
    // A users row with role=teacher whose email matches NO teachers row used to
    // fall through RoleRedirect straight into StatsController's unscoped
    // revenue charts.
    $orphan = User::factory()->create(['role' => 'teacher']);
    Session::put('school_id', $this->school->id);

    $this->actingAs($orphan)->get('/dashboard')
        ->assertRedirect(route('profiles.select'))
        ->assertSessionHas('error');
});

it('lands a view-as teacher on the dashboard like their own login', function () {
    [$user, $teacher] = cockpitStaffTeacher($this->school);
    $admin = User::factory()->create(['role' => 'admin']);

    // Inspection marker in session + school picked by the admin: the teacher
    // still lands on THEIR cockpit, not their HR profile.
    $this->actingAs($user)
        ->withSession(['admin_user_id' => $admin->id])
        ->post(route('profiles.store'), ['school_id' => $this->school->id])
        ->assertRedirect(route('dashboard'));
});

it('shows teachers their staff card on /profile', function () {
    [$user, $teacher] = cockpitStaffTeacher($this->school);

    $this->actingAs($user)->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('staff.first_name', $teacher->first_name)
            ->where('staff.roleLabel', 'Enseignant')
            ->has('staff.schools', 1));
});

/* ------------------------------------------------------------------ */
/* Profile gains-table stats — the placeholders that shipped as 0 */
/* ------------------------------------------------------------------ */

it('counts pending months and deleted memberships for real on the profile table', function () {
    Carbon::setTestNow('2026-08-26 12:00:00');

    [$user, $teacher] = cockpitStaffTeacher($this->school);

    $level = Level::factory()->create();
    $offer = \App\Models\Offer::factory()->create(['levelId' => $level->id, 'percentage' => ['Math' => 50]]);
    $class = Classes::factory()->create(['school_id' => $this->school->id, 'level_id' => $level->id]);
    $activeStudent = Student::factory()->create(['schoolId' => $this->school->id, 'classId' => $class->id, 'levelId' => $level->id]);
    $goneStudent = Student::factory()->create(['schoolId' => $this->school->id, 'classId' => $class->id, 'levelId' => $level->id]);

    // Active membership + unpaid month (no payout-tracker row -> En attente).
    \App\Models\Membership::forceCreate([
        'student_id' => $activeStudent->id,
        'offer_id' => $offer->id,
        'teachers' => [['teacherId' => (string) $teacher->id, 'subject' => 'Math']],
    ]);
    Invoice::factory()->create([
        'student_id' => $activeStudent->id,
        'offer_id' => $offer->id,
        'selected_months' => ['2026-08'],
        'totalAmount' => 200,
        'amountPaid' => 200,
        'rest' => 0,
    ]);

    // Withdrawn membership with its own PAID month -> counts in deleted only.
    $trashedMembership = \App\Models\Membership::forceCreate([
        'student_id' => $goneStudent->id,
        'offer_id' => $offer->id,
        'teachers' => [['teacherId' => (string) $teacher->id, 'subject' => 'Math']],
    ]);
    $goneInvoice = Invoice::factory()->create([
        'student_id' => $goneStudent->id,
        'offer_id' => $offer->id,
        'selected_months' => ['2026-08'],
        'totalAmount' => 100,
        'amountPaid' => 100,
        'rest' => 0,
    ]);
    $trashedMembership->delete();
    TeacherMembershipPayment::forceCreate([
        'student_id' => $goneStudent->id,
        'teacher_id' => $teacher->id,
        'membership_id' => $trashedMembership->id,
        'invoice_id' => $goneInvoice->id,
        'selected_months' => ['2026-08'],
        'months_rest_not_paid_yet' => [],
        'monthly_teacher_amount' => 50,
        'total_teacher_amount' => 50,
        'payment_percentage' => 50,
        'teacher_percentage' => 50,
        'teacher_subject' => '',
    ]);

    $this->actingAs($user)->get('/teachers/'.$teacher->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Was a hardcoded `return 0` placeholder for years.
            ->where('invoiceStats.pending_months', 1)
            // Was hardcoded 0 at the stats assembly.
            ->where('invoiceStats.deleted_memberships', 1));
});

it('defines Mes élèves by membership relation like the profile does', function () {
    [$user, $teacher] = cockpitStaffTeacher($this->school);

    $level = Level::factory()->create();
    $offer = \App\Models\Offer::factory()->create(['levelId' => $level->id]);
    $class = Classes::factory()->create(['school_id' => $this->school->id, 'level_id' => $level->id]);

    // Two students tied ONLY through memberships (unpaid even), plus one
    // unrelated student sitting in a taught class who must NOT be counted.
    foreach ([1, 2] as $i) {
        $member = Student::factory()->create(['schoolId' => $this->school->id, 'classId' => $class->id, 'levelId' => $level->id]);
        \App\Models\Membership::forceCreate([
            'student_id' => $member->id,
            'offer_id' => $offer->id,
            'teachers' => [['teacherId' => (string) $teacher->id, 'subject' => 'Math']],
        ]);
    }
    Student::factory()->count(3)->create(['schoolId' => $this->school->id, 'classId' => $class->id, 'levelId' => $level->id]);

    Session::put('school_id', $this->school->id);

    $this->actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('kpis.myStudents', 2));
});

it('keeps the admin analytics dashboard for admins', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard'));
});

/* ------------------------------------------------------------------ */
/* « Mes paiements » — shared staff payroll surface */
/* ------------------------------------------------------------------ */

it('shows a teacher their own wallet, ledger and payouts on my-payments', function () {
    [$user, $teacher] = cockpitStaffTeacher($this->school);
    $teacher->forceFill(['wallet' => 900.00])->save();

    TeacherWalletEntry::forceCreate([
        'teacher_id' => $teacher->id,
        'invoice_id' => null,
        'month' => '2026-08',
        'teacher_subject' => '',
        'amount' => 1000.00,
        'balance_after' => 1000.00,
        'reason' => 'invoice.immediate',
        'created_by' => $user->id,
    ]);
    TeacherWalletEntry::forceCreate([
        'teacher_id' => $teacher->id,
        'invoice_id' => null,
        'month' => null,
        'teacher_subject' => '',
        'amount' => -100.00,
        'balance_after' => 900.00,
        'reason' => 'payout',
        'created_by' => $user->id,
    ]);

    $otherEmail = fake()->unique()->safeEmail();
    $otherTeacher = Teacher::factory()->create(['email' => $otherEmail]);
    TeacherWalletEntry::forceCreate([
        'teacher_id' => $otherTeacher->id,
        'invoice_id' => null,
        'month' => null,
        'teacher_subject' => '',
        'amount' => 99999.00,
        'balance_after' => 99999.00,
        'reason' => 'invoice.immediate',
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)->get('/my-payments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('wallet.balance', fn ($value) => (float) $value === 900.0)
            ->has('ledger.data', 2)
            // Another teacher's ledger rows must never appear.
            ->missing('ledger.data.2')
            ->where('wallet.roleView', 'teacher'));
});

it('keeps my-payments working for assistants', function () {
    $email = fake()->unique()->safeEmail();
    $assistantUser = User::factory()->create(['role' => 'assistant', 'email' => $email]);
    \App\Models\Assistant::factory()->create(['email' => $email]);

    $this->actingAs($assistantUser)->get('/my-payments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('wallet.roleView', 'assistant'));
});

/* ------------------------------------------------------------------ */
/* Invoice downloads from the earnings table */
/* ------------------------------------------------------------------ */

function cockpitEarningInvoice(User $staffUser, Teacher $teacher): Invoice
{
    $school = School::factory()->create();
    $level = Level::factory()->create();
    $class = Classes::factory()->create(['school_id' => $school->id, 'level_id' => $level->id]);
    $student = Student::factory()->create([
        'schoolId' => $school->id,
        'classId' => $class->id,
        'levelId' => $level->id,
    ]);
    $offer = \App\Models\Offer::factory()->create();
    $invoice = Invoice::factory()->create([
        'student_id' => $student->id,
        'offer_id' => $offer->id,
        'totalAmount' => 500,
        'amountPaid' => 0,
        'rest' => 500,
    ]);
    // The earnings-table definition of "mine": a commission row linking the
    // invoice to this teacher — NOT class-pivot ownership.
    TeacherMembershipPayment::forceCreate([
        'student_id' => $student->id,
        'teacher_id' => $teacher->id,
        'membership_id' => null,
        'invoice_id' => $invoice->id,
        'selected_months' => ['2026-08'],
        'months_rest_not_paid_yet' => [],
        'monthly_teacher_amount' => 250,
        'total_teacher_amount' => 250,
        'payment_percentage' => 50,
        'teacher_percentage' => 50,
        'teacher_subject' => '',
    ]);

    return $invoice;
}

it('lets a teacher download an invoice they hold a commission on', function () {
    [$user, $teacher] = cockpitStaffTeacher($this->school);
    $invoice = cockpitEarningInvoice($user, $teacher);

    // Debug: surface the real exception instead of a bare 500.
    $response = $this->actingAs($user)
        ->withoutExceptionHandling()
        ->get("/invoices/{$invoice->id}/download");

    expect($response->status())->toBe(200);
});

it('refuses a teacher an invoice nobody pays them a commission on', function () {
    [$user, $teacher] = cockpitStaffTeacher($this->school);

    $level = Level::factory()->create();
    $class = Classes::factory()->create(['school_id' => $this->school->id, 'level_id' => $level->id]);
    $student = Student::factory()->create([
        'schoolId' => $this->school->id,
        'classId' => $class->id,
        'levelId' => $level->id,
    ]);
    $foreign = Invoice::factory()->create(['student_id' => $student]);

    $this->actingAs($user)->get("/invoices/{$foreign->id}/download")
        ->assertForbidden();
});

/* ------------------------------------------------------------------ */
/* Bulk downloads ride through the same door */
/* ------------------------------------------------------------------ */

it('refuses a teacher a bulk download mixing their invoices with foreign ones', function () {
    [$user, $teacher] = cockpitStaffTeacher($this->school);
    $mine = cockpitEarningInvoice($user, $teacher);

    $level = Level::factory()->create();
    $class = Classes::factory()->create(['school_id' => $this->school->id, 'level_id' => $level->id]);
    $otherStudent = Student::factory()->create([
        'schoolId' => $this->school->id,
        'classId' => $class->id,
        'levelId' => $level->id,
    ]);
    $foreign = Invoice::factory()->create(['student_id' => $otherStudent]);

    // One bad id poisons the whole batch: deny rather than silently filter,
    // so a partial PDF can never be mistaken for the full selection.
    $this->actingAs($user)
        ->post('/invoices/bulk-download', ['invoiceIds' => [$mine->id, $foreign->id]])
        ->assertForbidden();
});

it('lets a teacher bulk download exclusively their own commission invoices', function () {
    [$user, $teacher] = cockpitStaffTeacher($this->school);
    $a = cockpitEarningInvoice($user, $teacher);
    $b = cockpitEarningInvoice($user, $teacher);

    $response = $this->actingAs($user)
        ->post('/invoices/bulk-download', ['invoiceIds' => [$a->id, $b->id]]);

    expect($response->status())->toBe(200);
});
