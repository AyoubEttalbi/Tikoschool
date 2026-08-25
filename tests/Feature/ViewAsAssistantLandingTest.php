<?php

use App\Models\Assistant;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\Session;

/*
 * VIEW-AS LANDING FOR ASSISTANTS.
 *
 * When an admin inspects an assistant account, everything after school selection
 * must behave exactly like that assistant's own login: the /dashboard cockpit is
 * home. The old flow dropped them on assistants/{id} — their HR file — a leftover
 * from when RoleRedirect pinned assistants there.
 */

beforeEach(function () {
    $this->school = School::factory()->create();

    $email = fake()->unique()->safeEmail();
    $this->assistantUser = User::factory()->create(['role' => 'assistant', 'email' => $email]);
    $this->assistantRow = Assistant::factory()->create(['email' => $email]);
    $this->assistantRow->schools()->attach([$this->school->id]);

    $this->admin = User::factory()->create(['role' => 'admin']);
});

it('lands an inspecting admin on the dashboard after school selection', function () {
    // The inspection state: logged in AS the assistant, with the admin marker set.
    Session::put('admin_user_id', $this->admin->id);

    $this->actingAs($this->assistantUser)
        ->post(route('profiles.store'), ['school_id' => $this->school->id])
        ->assertRedirect(route('dashboard'));
});

it('sends an assistant back to the dashboard when they revisit school selection', function () {
    Session::put('admin_user_id', $this->admin->id);
    Session::put('school_id', $this->school->id);

    $this->actingAs($this->assistantUser)
        ->get(route('profiles.select'))
        ->assertRedirect(route('dashboard'));
});

it('keeps a plain assistant login landing on the dashboard too', function () {
    Session::put('school_id', $this->school->id);

    $this->actingAs($this->assistantUser)
        ->get(route('profiles.select'))
        ->assertRedirect(route('dashboard'));
});

it('still shows the picker when no school is chosen during inspection', function () {
    Session::put('admin_user_id', $this->admin->id);

    $this->actingAs($this->assistantUser)
        ->get(route('profiles.select'))
        ->assertOk()
        ->inertiaPage('Auth/SelectProfile');
});
