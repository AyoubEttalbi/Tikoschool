<?php

use App\Models\Assistant;
use App\Models\School;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Session;

/*
 * THE TASK BOARD.
 *
 * Route-level RequireRole gates admin+assistant; these tests pin the object-level
 * half: tasks belong to a school, and the session school is the boundary. A card
 * from another school must be invisible, unmovable, undeletable. Ownership is the
 * second axis: an assistant creates pre-assigned to themselves and sees only their
 * own cards; admins see everything and may assign anyone.
 */

beforeEach(function () {
    $this->mine = School::factory()->create();
    $this->theirs = School::factory()->create();

    $email = fake()->unique()->safeEmail();
    $this->assistant = User::factory()->create(['role' => 'assistant', 'email' => $email]);
    $row = Assistant::factory()->create(['email' => $email]);
    $row->schools()->attach([$this->mine->id, $this->theirs->id]);

    // A second assistant at the same school — ownership boundaries need a peer.
    $colleagueEmail = fake()->unique()->safeEmail();
    $this->colleague = User::factory()->create(['role' => 'assistant', 'email' => $colleagueEmail]);
    Assistant::factory()->create(['email' => $colleagueEmail])->schools()->attach([$this->mine->id]);

    Session::put('school_id', $this->mine->id);
});

function taskIn(School $school, array $overrides = []): Task
{
    return Task::factory()->create(array_merge([
        'school_id' => $school->id,
        'title' => 'Tâche de test',
    ], $overrides));
}

it('lists only the session school\'s tasks', function () {
    taskIn($this->mine, ['title' => 'Ma tâche', 'assigned_to' => $this->assistant->id]);
    taskIn($this->theirs, ['title' => 'Tâche interdite']);

    $json = $this->actingAs($this->assistant)
        ->get(route('tasks.index'))
        ->assertOk()
        ->inertiaPage();

    $titles = collect($json['props']['tasks'])->pluck('title')->all();

    expect($titles)->toContain('Ma tâche')
        ->not->toContain('Tâche interdite');
});

it('hides a same-school card assigned to another assistant', function () {
    taskIn($this->mine, ['title' => 'Carte du collègue', 'assigned_to' => $this->colleague->id]);

    $json = $this->actingAs($this->assistant)
        ->get(route('tasks.index'))
        ->assertOk()
        ->inertiaPage();

    expect(collect($json['props']['tasks'])->pluck('title')->all())
        ->not->toContain('Carte du collègue');
});

it('creates a task in the session school, pre-assigned to its creator', function () {
    $this->actingAs($this->assistant)
        ->post(route('tasks.store'), [
            'title' => 'Appeler les parents',
            'priority' => 'high',
            'due_date' => '2026-09-01',
        ])
        ->assertRedirect();

    $task = Task::where('title', 'Appeler les parents')->first();

    expect($task)->not->toBeNull()
        ->and((int) $task->school_id)->toBe($this->mine->id)
        ->and($task->status)->toBe('todo')
        ->and($task->created_by)->toBe($this->assistant->id)
        // Auto-assignment: an assistant's card is born theirs.
        ->and((int) $task->assigned_to)->toBe($this->assistant->id);
});

it('ignores an assistant trying to assign someone else at creation', function () {
    $this->actingAs($this->assistant)
        ->post(route('tasks.store'), [
            'title' => 'Tâche imposée ?',
            'assigned_to' => $this->colleague->id,
        ])
        ->assertRedirect();

    expect((int) Task::where('title', 'Tâche imposée ?')->first()->assigned_to)
        ->toBe($this->assistant->id);
});

it('lets an admin assign anyone at creation', function () {
    Session::put('school_id', $this->mine->id);

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->post(route('tasks.store'), [
            'title' => 'Relancer la famille Bennani',
            'assigned_to' => $this->colleague->id,
        ])
        ->assertRedirect();

    $task = Task::where('title', 'Relancer la famille Bennani')->first();

    expect((int) $task->assigned_to)->toBe($this->colleague->id)
        ->and((int) $task->school_id)->toBe($this->mine->id);
});

it('blocks an assistant reassigning a card on update', function () {
    $task = taskIn($this->mine, ['assigned_to' => $this->assistant->id]);

    $this->actingAs($this->assistant)
        ->put(route('tasks.update', $task), [
            'title' => 'Titre modifié',
            'assigned_to' => $this->colleague->id,
        ])
        ->assertRedirect();

    $fresh = $task->fresh();

    expect($fresh->title)->toBe('Titre modifié')
        ->and((int) $fresh->assigned_to)->toBe($this->assistant->id);
});

// Inertia submits ride in as application/json — a guard that only strips the
// form-data bag silently passes them through. This pins the JSON transport.
it('blocks an assistant reassigning via a JSON payload', function () {
    $task = taskIn($this->mine, ['assigned_to' => $this->assistant->id]);

    $this->actingAs($this->assistant)
        ->putJson(route('tasks.update', $task), [
            'title' => 'Titre JSON',
            'assigned_to' => $this->colleague->id,
        ])
        ->assertRedirect();

    $fresh = $task->fresh();

    // Title moves; the assignee must not — not even to a same-school peer.
    expect($fresh->title)->toBe('Titre JSON')
        ->and((int) $fresh->assigned_to)->toBe($this->assistant->id);
});

// Same transport blind spot at creation: the "exists" rule on assigned_to must
// never even run for assistants, or a crafted JSON payload turns validation
// errors into a user-id existence oracle ("422 ⇒ this id is not staff").
it('gives assistants no existence oracle for assigned_to at creation', function () {
    // NOT a teacher/assistant id: a surviving "exists" rule would reject this
    // payload with a validation error instead of silently ignoring the key.
    $admin = User::factory()->create(['role' => 'admin']);

    $this->actingAs($this->assistant)
        ->postJson(route('tasks.store'), [
            'title' => 'Sonde',
            'assigned_to' => $admin->id,
        ])
        ->assertRedirect();

    $task = Task::where('title', 'Sonde')->first();

    expect($task)->not->toBeNull()
        ->and((int) $task->assigned_to)->toBe($this->assistant->id);
});

it('lets an admin reassign a card on update', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    Session::put('school_id', $this->mine->id);
    $task = taskIn($this->mine, ['assigned_to' => $this->colleague->id]);

    $this->actingAs($admin)
        ->put(route('tasks.update', $task), ['assigned_to' => $this->assistant->id])
        ->assertRedirect();

    expect((int) $task->fresh()->assigned_to)->toBe($this->assistant->id);
});

it('refuses to touch another assistant\'s card in the same school', function () {
    $foreignCard = taskIn($this->mine, ['assigned_to' => $this->colleague->id, 'status' => 'todo']);

    foreach ([
        ['patch', route('tasks.status', $foreignCard), ['status' => 'done']],
        ['delete', route('tasks.destroy', $foreignCard), []],
    ] as [$method, $url, $data]) {
        $this->actingAs($this->assistant)->{$method}($url, $data)->assertForbidden();
    }

    expect($foreignCard->fresh()->status)->not->toBe('done')
        ->and(Task::find($foreignCard->id))->not->toBeNull();
});

it('moves a card between columns', function () {
    $task = taskIn($this->mine, ['assigned_to' => $this->assistant->id]);

    $this->actingAs($this->assistant)
        ->patch(route('tasks.status', $task), ['status' => 'done'])
        ->assertRedirect();

    expect($task->fresh()->status)->toBe('done');
});

it('refuses to touch a card from another school', function () {
    // The assistant works on their other school in this session.
    Session::put('school_id', $this->theirs->id);
    // Explicit todo: the factory randomises status, and the assertion below
    // needs a known starting point.
    $foreignCardStillVisible = taskIn($this->theirs, ['status' => 'todo']);

    // Switch back: the card now belongs to a school that is not the session's.
    Session::put('school_id', $this->mine->id);

    $this->actingAs($this->assistant)
        ->patch(route('tasks.status', $foreignCardStillVisible), ['status' => 'done'])
        ->assertForbidden();

    expect($foreignCardStillVisible->fresh()->status)->toBe('todo');
});

it('forbids teachers at the route level', function () {
    $teacher = User::factory()->create(['role' => 'teacher']);

    $this->actingAs($teacher)
        ->get(route('tasks.index'))
        ->assertForbidden();
});

it('shows every school\'s board to an admin without a school selection', function () {
    taskIn($this->mine, ['title' => 'Tâche école A']);
    taskIn($this->theirs, ['title' => 'Tâche école B']);

    $admin = User::factory()->create(['role' => 'admin']);
    // beforeEach put a school in the session; the admin under test has none.
    Session::forget('school_id');

    $json = $this->actingAs($admin)
        ->get(route('tasks.index'))
        ->assertOk()
        ->inertiaPage();

    $titles = collect($json['props']['tasks'])->pluck('title')->all();

    expect($json['props']['canSelectSchool'])->toBeTrue()
        ->and($titles)->toContain('Tâche école A')
        ->and($titles)->toContain('Tâche école B')
        // The all-schools view labels each card so it stays attributable.
        ->and(collect($json['props']['tasks'])->filter(fn ($t) => $t['title'] === 'Tâche école A')->first()['school_name'])
        ->toBe($this->mine->name);
});

it('lets an admin pick a school and then sees only its board', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    taskIn($this->mine);
    taskIn($this->theirs);

    $this->actingAs($admin)
        ->post(route('tasks.select-school'), ['school_id' => $this->mine->id])
        ->assertRedirect();

    $json = $this->actingAs($admin)
        ->get(route('tasks.index'))
        ->assertOk()
        ->inertiaPage();

    expect(count($json['props']['tasks']))->toBe(1)
        ->and(Session::get('school_id'))->toBe($this->mine->id);
});

it('forbids assistants from switching the board\'s school', function () {
    $this->actingAs($this->assistant)
        ->post(route('tasks.select-school'), ['school_id' => $this->theirs->id])
        ->assertForbidden();
});

it('offers the selected school\'s staff to an assigning admin', function () {
    Session::put('school_id', $this->mine->id);

    $json = $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('tasks.index'))
        ->assertOk()
        ->inertiaPage();

    $ids = collect($json['props']['assignableUsers'])->pluck('id')->all();

    expect($ids)->toContain($this->assistant->id)
        ->and($ids)->toContain($this->colleague->id);
});

it('keeps other schools\' staff out of the assignee list', function () {
    // A teacher attached ONLY to the other school shares nobody's email with mine.
    $foreignEmail = fake()->unique()->safeEmail();
    User::factory()->create(['role' => 'teacher', 'email' => $foreignEmail]);
    \App\Models\Teacher::factory()->create(['email' => $foreignEmail])
        ->schools()->attach([$this->theirs->id]);

    Session::put('school_id', $this->mine->id);

    $json = $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->get(route('tasks.index'))
        ->assertOk()
        ->inertiaPage();

    expect(collect($json['props']['assignableUsers'])->pluck('id')->all())
        ->not->toContain(User::where('email', $foreignEmail)->value('id'));
});

it('refuses an admin assigning a card to someone outside the school', function () {
    $outsideEmail = fake()->unique()->safeEmail();
    $outsider = User::factory()->create(['role' => 'assistant', 'email' => $outsideEmail]);
    \App\Models\Assistant::factory()->create(['email' => $outsideEmail])
        ->schools()->attach([$this->theirs->id]);

    Session::put('school_id', $this->mine->id);

    $this->actingAs(User::factory()->create(['role' => 'admin']))
        ->post(route('tasks.store'), [
            'title' => 'Carte hors périmètre',
            'assigned_to' => $outsider->id,
        ])
        ->assertForbidden();

    expect(Task::where('title', 'Carte hors périmètre')->exists())->toBeFalse();
});

it('hands assistants no assignee list to choose from', function () {
    $json = $this->actingAs($this->assistant)
        ->get(route('tasks.index'))
        ->assertOk()
        ->inertiaPage();

    expect($json['props']['assignableUsers'])->toBe([]);
});
