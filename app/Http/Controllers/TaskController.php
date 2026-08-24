<?php

namespace App\Http\Controllers;

use App\Exceptions\AccessDeniedException;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The assistant's task board.
 *
 * Route-level RequireRole gates admin+assistant; the object-level half lives here:
 * every task belongs to a school, and a non-admin may only see and touch tasks of
 * the school selected in their session. Denials throw AccessDeniedException (an
 * \Error), so no catch (\Exception) anywhere can turn them into redirects.
 */
class TaskController extends Controller
{
    private const STATUSES = ['todo', 'in_progress', 'done'];

    private const PRIORITIES = ['low', 'normal', 'high'];

    public function index(Request $request)
    {
        $isAdmin = Auth::user()?->role === 'admin';
        $schoolId = session('school_id');

        // An admin without a school selection sees every school's board — the same
        // "no selection = no filter" rule the students and classes lists follow.
        // Assistants always have one (RoleRedirect guarantees it) and stay pinned.
        $tasks = $isAdmin && ! $schoolId
            ? Task::query()->with('school:id,name')->orderByDesc('created_at')->get()
            : $this->scopedQuery()->get();

        return inertia('Menu/TasksPage', [
            'tasks' => $tasks->map(fn (Task $task) => $this->row($task, $isAdmin && ! $schoolId)),
            // The school picker in the header exists only for admins; assistants get
            // their school from RoleRedirect and have nothing to choose.
            'canSelectSchool' => $isAdmin,
            'schools' => $isAdmin ? \App\Models\School::orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    /**
     * Admin-only: pick which school's board to work on. Writes the same session keys
     * the school-selection flow uses, so every scoped page follows along.
     */
    public function selectSchool(Request $request)
    {
        if (Auth::user()?->role !== 'admin') {
            abort(403);
        }

        $validated = $request->validate([
            'school_id' => ['required', 'exists:schools,id'],
        ]);

        $school = \App\Models\School::find($validated['school_id']);
        session([
            'school_id' => $school->id,
            'school_name' => $school->name,
        ]);

        return back();
    }

    public function store(Request $request)
    {
        $schoolId = session('school_id');
        abort_if(! $schoolId, 403, 'Sélectionnez une école pour créer des tâches.');

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', Rule::in(self::PRIORITIES)],
            'due_date' => ['nullable', 'date'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
        ]);

        Task::create([
            'school_id' => $schoolId,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'priority' => $validated['priority'] ?? 'normal',
            'due_date' => $validated['due_date'] ?? null,
            'assigned_to' => $validated['assigned_to'] ?? null,
            // A kanban card may be born directly in a column ("done" after the fact).
            'status' => in_array($validated['status'] ?? null, self::STATUSES, true)
                ? $validated['status']
                : 'todo',
            'created_by' => Auth::id(),
        ]);

        return back();
    }

    public function update(Request $request, Task $task)
    {
        $this->authorizeTask($task);

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', Rule::in(self::PRIORITIES)],
            'due_date' => ['sometimes', 'nullable', 'date'],
            'assigned_to' => ['sometimes', 'nullable', 'exists:users,id'],
            'status' => ['sometimes', Rule::in(self::STATUSES)],
        ]);

        $task->fill($validated)->save();

        return back();
    }

    /** The drag-and-drop path: one column move per request. */
    public function updateStatus(Request $request, Task $task)
    {
        $this->authorizeTask($task);

        $validated = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        $task->update(['status' => $validated['status']]);

        return back();
    }

    public function destroy(Request $request, Task $task)
    {
        $this->authorizeTask($task);

        $task->delete();

        return back();
    }

    /** Every task of the caller's session school — the board is school-scoped for every role. */
    private function scopedQuery()
    {
        $schoolId = session('school_id');
        abort_if(! $schoolId, 403, 'Sélectionnez une école pour voir les tâches.');

        return Task::query()
            ->where('school_id', $schoolId)
            ->orderByRaw('CASE status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', ['in_progress', 'todo'])
            ->orderByDesc('priority')
            ->orderBy('due_date')
            ->with('assignee:id,name');
    }

    /**
     * Object-level guard. Admins pass; staff must match the session school —
     * guessing an id from another school must not move its cards.
     */
    private function authorizeTask(Task $task): void
    {
        $user = Auth::user();

        if ($user && $user->role === 'admin') {
            return;
        }

        if (! $user || (int) $task->school_id !== (int) session('school_id')) {
            // \Error-based: survives any catch (\Exception) upstream, renders 403.
            throw new AccessDeniedException("Vous n'avez pas accès à cette tâche.");
        }
    }

    private function row(Task $task, bool $withSchool = false): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'status' => $task->status,
            'priority' => $task->priority,
            'due_date' => $task->due_date?->format('Y-m-d'),
            'assigned_to_name' => $task->assignee?->name,
            // Shown on the all-schools admin view so cards stay attributable.
            'school_name' => $withSchool ? $task->school?->name : null,
        ];
    }
}
