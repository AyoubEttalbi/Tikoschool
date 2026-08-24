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
 * from another school must be invisible, unmovable, undeletable.
 */

beforeEach(function () {
    $this->mine = School::factory()->create();
    $this->theirs = School::factory()->create();

    $email = fake()->unique()->safeEmail();
    $this->assistant = User::factory()->create(['role' => 'assistant', 'email' => $email]);
    $row = Assistant::factory()->create(['email' => $email]);
    $row->schools()->attach([$this->mine->id, $this->theirs->id]);

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
    taskIn($this->mine, ['title' => 'Ma tâche']);
    taskIn($this->theirs, ['title' => 'Tâche interdite']);

    $json = $this->actingAs($this->assistant)
        ->get(route('tasks.index'))
        ->assertOk()
        ->inertiaPage();

    $titles = collect($json['props']['tasks'])->pluck('title')->all();

    expect($titles)->toContain('Ma tâche')
        ->not->toContain('Tâche interdite');
});

it('creates a task in the session school', function () {
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
        ->and($task->created_by)->toBe($this->assistant->id);
});

it('moves a card between columns', function () {
    $task = taskIn($this->mine);

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
