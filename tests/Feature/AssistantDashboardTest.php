<?php

use App\Models\Assistant;
use App\Models\Classes;
use App\Models\Invoice;
use App\Models\InvoicePaymentLog;
use App\Models\Level;
use App\Models\Membership;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
 * THE ASSISTANT'S HOME.
 *
 * /dashboard used to hard-bounce every assistant to their own HR profile page
 * (RoleRedirect -> assistants.show), so the first screen after login answered
 * "who am I?" instead of "what should I do today?", and two of its four action
 * links pointed at routes that did not exist. The dashboard is now role-aware:
 * assistants get an operations cockpit scoped to their schools, admins keep the
 * analytics page, teachers keep their own pinned profile.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-08-24 10:00:00');
});

afterEach(fn () => Carbon::setTestNow());

/** An assistant User + staff row attached to the given schools. */
function cockpitAssistant(array $schools): User
{
    $email = fake()->unique()->safeEmail();
    $user = User::factory()->create(['role' => 'assistant', 'email' => $email]);
    $assistant = Assistant::factory()->create(['email' => $email]);
    foreach ($schools as $school) {
        $assistant->schools()->attach($school->id);
    }

    return $user;
}

function cockpitTeacherUser(School $school): User
{
    $email = fake()->unique()->safeEmail();
    $user = User::factory()->create(['role' => 'teacher', 'email' => $email]);
    $teacher = Teacher::factory()->create(['email' => $email]);
    $teacher->schools()->attach($school->id);

    return $user;
}

/** A school with one class and one student in it. */
function cockpitSchoolWithStudent(): array
{
    $school = School::factory()->create();
    $level = Level::factory()->create();
    $class = Classes::factory()->create(['school_id' => $school->id, 'level_id' => $level->id]);
    $student = Student::factory()->create([
        'schoolId' => $school->id,
        'classId' => $class->id,
        'levelId' => $level->id,
        'status' => 'active',
    ]);

    return [$school, $student];
}

/** A partially paid invoice — the "unpaid" state every list filters on. */
function cockpitUnpaidInvoice(Student $student, float $paid, float $total): Invoice
{
    // rest is written explicitly rather than through the factory's partiallyPaid()
    // state: factory states resolve before create()-time attributes, so the state's
    // rest was computed from the random default totalAmount, not ours.
    return Invoice::factory()->create([
        'student_id' => $student->id,
        'totalAmount' => $total,
        'amountPaid' => $paid,
        'rest' => max(0, $total - $paid),
    ]);
}

/** A payment event through the same pipeline the invoice controller uses. */
function cockpitPay(Invoice $invoice, float $previous, float $newAmountPaid): void
{
    $invoice->update(['amountPaid' => $newAmountPaid]);
    InvoicePaymentLog::recordDelta($invoice, $previous, null);
}

it('renders the operations cockpit on /dashboard for an assistant with a selected school', function () {
    [$school] = cockpitSchoolWithStudent();
    $user = cockpitAssistant([$school]);

    $page = $this->actingAs($user)
        ->withSession(['school_id' => $school->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->inertiaPage();

    expect($page['component'])->toBe('Menu/AssistantDashboard');
});

it('lists only the assistant\'s own open tasks on the cockpit', function () {
    [$school] = cockpitSchoolWithStudent();
    $user = cockpitAssistant([$school]);

    // Theirs.
    App\Models\Task::factory()->create([
        'school_id' => $school->id,
        'title' => 'Ma carte ouverte',
        'assigned_to' => $user->id,
        'status' => 'todo',
    ]);
    // A colleague's card in the same school — must not appear.
    $colleagueEmail = fake()->unique()->safeEmail();
    $colleague = User::factory()->create(['role' => 'assistant', 'email' => $colleagueEmail]);
    App\Models\Task::factory()->create([
        'school_id' => $school->id,
        'title' => 'Carte du collègue',
        'assigned_to' => $colleague->id,
        'status' => 'todo',
    ]);

    $page = $this->actingAs($user)
        ->withSession(['school_id' => $school->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->inertiaPage();

    $titles = collect($page['props']['queue']['openTasks'])->pluck('title')->all();

    expect($titles)->toContain('Ma carte ouverte')
        ->not->toContain('Carte du collègue');
});

it('still sends an assistant without a selected school to the school picker', function () {
    [$school] = cockpitSchoolWithStudent();
    $user = cockpitAssistant([$school]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('profiles.select'));
});

it('keeps the analytics dashboard for admins', function () {
    $admin = User::factory()->create(['role' => 'admin']);

    $page = $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk()
        ->inertiaPage();

    expect($page['component'])->toBe('Dashboard');
});

// Phase 2: teachers are no longer pinned to their HR profile — /dashboard is
// role-aware for all three staff roles. Their cockpit contract lives in
// TeacherCockpitTest; here we pin only that they do NOT get owner analytics.
it('never shows the teacher the owner analytics page', function () {
    [$school] = cockpitSchoolWithStudent();
    $user = cockpitTeacherUser($school);

    $this->actingAs($user)
        ->withSession(['school_id' => $school->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Menu/TeacherDashboard'));
});

it('scopes every cockpit number to the assistant\'s own schools', function () {
    [$mine, $myStudent] = cockpitSchoolWithStudent();
    [$theirs, $theirStudent] = cockpitSchoolWithStudent();

    $user = cockpitAssistant([$mine]);

    // One unpaid invoice on each side; only mine may count.
    $myInvoice = cockpitUnpaidInvoice($myStudent, 200, 500);     // rest 300
    $theirInvoice = cockpitUnpaidInvoice($theirStudent, 0, 900); // rest 900

    // One membership about to expire on each side; only mine may count.
    Membership::factory()->create([
        'student_id' => $myStudent->id,
        'start_date' => now()->subMonths(2),
        'end_date' => now()->addDays(3),
    ]);
    Membership::factory()->create([
        'student_id' => $theirStudent->id,
        'start_date' => now()->subMonths(2),
        'end_date' => now()->addDays(2),
    ]);

    // Cash moved today on both sides; only my school's money is mine to see.
    cockpitPay($myInvoice, 200, 300);
    cockpitPay($theirInvoice, 0, 400);

    $page = $this->actingAs($user)
        ->withSession(['school_id' => $mine->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->inertiaPage();

    expect($page['props']['kpis']['unpaidInvoices']['count'])->toBe(1)
        ->and($page['props']['kpis']['unpaidInvoices']['totalRest'])->toEqual(300.0)
        ->and($page['props']['kpis']['expiringMemberships']['count'])->toBe(1)
        ->and($page['props']['kpis']['todayCash']['total'])->toEqual(100.0)
        ->and($page['props']['kpis']['todayCash']['yesterdayTotal'])->toEqual(0.0)
        // The work-queue lists carry the same boundary.
        ->and(collect($page['props']['queue']['unpaidInvoices'])->pluck('student_id')->all())
        ->each->toBe($myStudent->id);
});

it('counts cash on the day it moved, not the invoice balance', function () {
    [$mine, $myStudent] = cockpitSchoolWithStudent();
    $user = cockpitAssistant([$mine]);

    $invoice = cockpitUnpaidInvoice($myStudent, 200, 500);

    // 200 arrived yesterday, the 100 top-up today.
    Carbon::setTestNow('2026-08-23 15:00:00');
    InvoicePaymentLog::recordDelta($invoice, 0, null);

    Carbon::setTestNow('2026-08-24 10:00:00');
    cockpitPay($invoice, 200, 300);

    $page = $this->actingAs($user)
        ->withSession(['school_id' => $mine->id])
        ->get(route('dashboard'))
        ->assertOk()
        ->inertiaPage();

    expect($page['props']['kpis']['todayCash']['total'])->toEqual(100.0)
        ->and($page['props']['kpis']['todayCash']['yesterdayTotal'])->toEqual(200.0);
});

it('lists unpaid invoices for the assistant\'s schools by default', function () {
    [$mine, $myStudent] = cockpitSchoolWithStudent();
    [$theirs, $theirStudent] = cockpitSchoolWithStudent();
    $user = cockpitAssistant([$mine]);

    $unpaid = cockpitUnpaidInvoice($myStudent, 100, 400);
    Invoice::factory()->create([ // fully paid, same school: filtered out by default
        'student_id' => $myStudent->id,
        'totalAmount' => 250,
        'amountPaid' => 250,
        'rest' => 0,
    ]);
    cockpitUnpaidInvoice($theirStudent, 50, 700); // other school: never visible

    $page = $this->actingAs($user)
        ->withSession(['school_id' => $mine->id])
        ->get(route('invoices.index'))
        ->assertOk()
        ->inertiaPage();

    expect($page['component'])->toBe('Menu/InvoicesIndexPage')
        ->and($page['props']['filters']['status'])->toBe('unpaid')
        ->and(count($page['props']['invoices']))->toBe(1)
        ->and($page['props']['invoices'][0]['id'])->toBe($unpaid->id)
        ->and($page['props']['invoices'][0]['rest'])->toEqual(300.0);
});

it('forbids teachers from the invoices index', function () {
    [$school] = cockpitSchoolWithStudent();
    $user = cockpitTeacherUser($school);

    $this->actingAs($user)
        ->withSession(['school_id' => $school->id])
        ->get(route('invoices.index'))
        ->assertForbidden();
});

it('lets admins read the invoices index across schools', function () {
    [$mine, $myStudent] = cockpitSchoolWithStudent();
    cockpitUnpaidInvoice($myStudent, 100, 400);
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($admin)
        ->get(route('invoices.index'))
        ->assertOk();
});

it('never serialises salary onto the assistant profile props', function () {
    [$school] = cockpitSchoolWithStudent();
    $email = fake()->unique()->safeEmail();
    $user = User::factory()->create(['role' => 'assistant', 'email' => $email]);
    $assistant = Assistant::factory()->create(['email' => $email, 'salary' => 7500]);
    $assistant->schools()->attach($school->id);

    $page = $this->actingAs($user)
        ->withSession(['school_id' => $school->id])
        ->get(route('assistants.show', $assistant))
        ->assertOk()
        ->inertiaPage();

    expect($page['props']['assistant'])->not->toHaveKey('salary');
});
