<?php

use App\Models\Activity;
use App\Models\Assistant;
use App\Models\Invoice;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\Session;

/*
 * THE ASSISTANT PROFILE ACTIVITY JOURNAL.
 *
 * An admin inspecting an assistant sees what THAT person created — invoices,
 * students, absences recorded, memberships — read from the shared activity_log
 * table. The school-wide finance widgets are replaced on this view (and their
 * heavy queries skipped). An assistant viewing their own profile keeps the old
 * widgets and gets no journal.
 */

beforeEach(function () {
    $this->school = School::factory()->create();

    $email = fake()->unique()->safeEmail();
    $this->assistantUser = User::factory()->create(['role' => 'assistant', 'email' => $email]);
    Assistant::factory()->create(['email' => $email])->schools()->attach([$this->school->id]);

    $this->admin = User::factory()->create(['role' => 'admin']);
});

it('shows an admin the assistant\'s creations grouped per category', function () {
    $invoice = Invoice::factory()->create();
    $student = Student::factory()->create();

    activity()
        ->causedBy($this->assistantUser)
        ->performedOn($invoice)
        ->log('Created Invoice ('.$invoice->id.')');

    activity()
        ->causedBy($this->assistantUser)
        ->performedOn($student)
        ->log('Created Student ('.$student->id.')');

    // Somebody else's work must not land in this assistant's record.
    $otherInvoice = Invoice::factory()->create();
    activity()
        ->causedBy($this->admin)
        ->performedOn($otherInvoice)
        ->log('Created Invoice ('.$otherInvoice->id.')');

    Session::put('school_id', $this->school->id);

    $json = $this->actingAs($this->admin)
        ->get(route('assistants.show', Assistant::where('email', $this->assistantUser->email)->first()))
        ->assertOk()
        ->inertiaPage();

    $categories = $json['props']['journal']['categories'];

    expect($categories['invoices']['total'])->toBe(1)
        ->and($categories['students']['total'])->toBe(1)
        ->and($categories['absences']['total'])->toBe(0)
        ->and($categories['memberships']['total'])->toBe(0)
        ->and($categories['invoices']['entries'][0]['subject_id'])->toBe($invoice->id);
});

it('resolves business context into each journal entry', function () {
    $invoice = Invoice::factory()->create();

    activity()
        ->causedBy($this->assistantUser)
        ->performedOn($invoice)
        ->log('Created Invoice ('.$invoice->id.')');

    Session::put('school_id', $this->school->id);

    $json = $this->actingAs($this->admin)
        ->get(route('assistants.show', Assistant::where('email', $this->assistantUser->email)->first()))
        ->assertOk()
        ->inertiaPage();

    $entry = $json['props']['journal']['categories']['invoices']['entries'][0];

    expect($entry['detail'])->not->toBeNull()
        ->and($entry['detail'])->toHaveKeys(['student_name', 'offer_name', 'total', 'paid', 'rest'])
        ->and($entry['detail']['rest'])->toBe(0);
});

it('reports creation performance over time', function () {
    $invoice = Invoice::factory()->create();

    activity()
        ->causedBy($this->assistantUser)
        ->performedOn($invoice)
        ->log('Created Invoice ('.$invoice->id.')');
    activity()
        ->causedBy($this->assistantUser)
        ->performedOn($invoice)
        ->log('Updated Invoice ('.$invoice->id.')');

    Session::put('school_id', $this->school->id);

    $json = $this->actingAs($this->admin)
        ->get(route('assistants.show', Assistant::where('email', $this->assistantUser->email)->first()))
        ->assertOk()
        ->inertiaPage();

    $performance = $json['props']['journal']['performance'];

    // Creations only for the counters; the last action includes updates.
    expect($performance['last7'])->toBe(1)
        ->and($performance['last30'])->toBe(1)
        ->and($performance['total'])->toBe(1)
        ->and(count($performance['weekly']))->toBe(6)
        ->and($performance['last_activity_at'])->not->toBeNull();
});

it('still names a subject that was deleted after its creation', function () {
    $invoice = Invoice::factory()->create();
    $invoiceId = $invoice->id;

    activity()
        ->causedBy($this->assistantUser)
        ->performedOn($invoice)
        ->log('Created Invoice ('.$invoiceId.')');

    $invoice->delete();

    Session::put('school_id', $this->school->id);

    $json = $this->actingAs($this->admin)
        ->get(route('assistants.show', Assistant::where('email', $this->assistantUser->email)->first()))
        ->assertOk()
        ->inertiaPage();

    $entry = $json['props']['journal']['categories']['invoices']['entries'][0];

    // The creation still happened and still says who it was for: subject rows
    // are read withTrashed() on purpose.
    expect($json['props']['journal']['categories']['invoices']['total'])->toBe(1)
        ->and($entry['detail'])->not->toBeNull();
});

it('replaces the finance widgets with the journal for admins', function () {
    Session::put('school_id', $this->school->id);

    $json = $this->actingAs($this->admin)
        ->get(route('assistants.show', Assistant::where('email', $this->assistantUser->email)->first()))
        ->assertOk()
        ->inertiaPage();

    expect($json['props']['unpaidInvoices'])->toBe([])
        ->and($json['props']['recentAbsences'])->toBe([]);
});

it('keeps the finance widgets and sends no journal to the assistant themselves', function () {
    Session::put('school_id', $this->school->id);

    $json = $this->actingAs($this->assistantUser)
        ->get(route('assistants.show', Assistant::where('email', $this->assistantUser->email)->first()))
        ->assertOk()
        ->inertiaPage();

    expect($json['props']['journal'])->toBeNull();
});

it('degrades cleanly when the inspected assistant has no login account', function () {
    // A staff row whose email never got a users row: no causer to query, and
    // the page must still render with emptied widgets — not a 500.
    $orphanEmail = fake()->unique()->safeEmail();
    $orphan = Assistant::factory()->create(['email' => $orphanEmail]);
    $orphan->schools()->attach([$this->school->id]);

    Session::put('school_id', $this->school->id);

    $json = $this->actingAs($this->admin)
        ->get(route('assistants.show', $orphan))
        ->assertOk()
        ->inertiaPage();

    expect($json['props']['journal'])->toBeNull()
        ->and($json['props']['unpaidInvoices'])->toBe([]);
});

it('counts only creations, not edits or deletions', function () {
    $invoice = Invoice::factory()->create();

    activity()
        ->causedBy($this->assistantUser)
        ->performedOn($invoice)
        ->log('Created Invoice ('.$invoice->id.')');
    activity()
        ->causedBy($this->assistantUser)
        ->performedOn($invoice)
        ->log('Updated Invoice ('.$invoice->id.')');
    activity()
        ->causedBy($this->assistantUser)
        ->performedOn($invoice)
        ->log('Deleted Invoice ('.$invoice->id.')');

    Session::put('school_id', $this->school->id);

    $json = $this->actingAs($this->admin)
        ->get(route('assistants.show', Assistant::where('email', $this->assistantUser->email)->first()))
        ->assertOk()
        ->inertiaPage();

    expect($json['props']['journal']['categories']['invoices']['total'])->toBe(1);
});
