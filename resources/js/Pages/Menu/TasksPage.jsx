import { Head, router, useForm, usePage } from "@inertiajs/react";
import { useState } from "react";
import DashboardLayout from "@/Layouts/DashboardLayout";
import {
    CalendarDays,
    CheckCircle2,
    CircleDot,
    ListTodo,
    Plus,
    School,
    Trash2,
    X,
} from "lucide-react";

/*
 * Le tableau des tâches (kanban) — trois colonnes, glisser-déposer au bureau,
 * boutons de déplacement pour le tactile. Une carte appartient à l'école de la
 * session : le contrôleur refuse tout le reste.
 */

const columns = [
    {
        status: "todo",
        label: "À faire",
        icon: ListTodo,
        dot: "bg-gray-400",
        moves: { left: null, right: "in_progress" },
    },
    {
        status: "in_progress",
        label: "En cours",
        icon: CircleDot,
        dot: "bg-blue-500",
        moves: { left: "todo", right: "done" },
    },
    {
        status: "done",
        label: "Terminé",
        icon: CheckCircle2,
        dot: "bg-green-500",
        moves: { left: "in_progress", right: null },
    },
];

const priorityStyles = {
    high: "bg-red-100 text-red-700 border-red-200",
    normal: "bg-sky-100 text-sky-700 border-sky-200",
    low: "bg-gray-100 text-gray-600 border-gray-200",
};

const priorityLabels = { high: "Haute", normal: "Normale", low: "Basse" };

function TaskCard({ task, onDragStart }) {
    const overdue =
        task.due_date && task.status !== "done" &&
        new Date(task.due_date) < new Date(new Date().toDateString());

    return (
        <div
            draggable
            onDragStart={(e) => onDragStart(e, task.id)}
            className="bg-white rounded-lg border border-gray-150 shadow-sm p-3 cursor-grab active:cursor-grabbing hover:shadow-md transition-shadow focus-within:ring-2 focus-within:ring-lamaSky"
        >
            <div className="flex items-start justify-between gap-2">
                <p
                    className={`text-sm font-medium leading-snug ${
                        task.status === "done" ? "text-gray-400 line-through" : "text-gray-800"
                    }`}
                >
                    {task.title}
                </p>
                <button
                    type="button"
                    title="Supprimer la tâche"
                    aria-label={`Supprimer ${task.title}`}
                    onClick={() => router.delete(route("tasks.destroy", task.id), { preserveScroll: true })}
                    className="text-gray-300 hover:text-red-500 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-300 rounded shrink-0"
                >
                    <Trash2 className="w-4 h-4" aria-hidden="true" />
                </button>
            </div>

            {task.school_name && (
                <p className="mt-1 text-[10px] font-semibold uppercase tracking-wide text-gray-400">
                    {task.school_name}
                </p>
            )}

            {task.description && (
                <p className="mt-1 text-xs text-gray-500">{task.description}</p>
            )}

            <div className="mt-2 flex items-center flex-wrap gap-1.5">
                <span
                    className={`text-[10px] font-semibold px-2 py-0.5 rounded-full border ${
                        priorityStyles[task.priority] ?? priorityStyles.normal
                    }`}
                >
                    {priorityLabels[task.priority] ?? task.priority}
                </span>
                {task.due_date && (
                    <span
                        className={`inline-flex items-center gap-1 text-[10px] font-medium px-2 py-0.5 rounded-full border tabular-nums ${
                            overdue
                                ? "bg-red-50 text-red-600 border-red-200"
                                : "bg-gray-50 text-gray-500 border-gray-200"
                        }`}
                    >
                        <CalendarDays className="w-3 h-3" aria-hidden="true" />
                        {new Date(task.due_date).toLocaleDateString("fr-FR")}
                    </span>
                )}
                {task.assigned_to_name && (
                    <span
                        title={`Assignée à ${task.assigned_to_name}`}
                        className="ml-auto h-6 w-6 rounded-full bg-lamaPurpleLight text-purple-700 text-[10px] font-bold flex items-center justify-center"
                    >
                        {task.assigned_to_name.slice(0, 2).toUpperCase()}
                    </span>
                )}
            </div>

            {/* Déplacement tactile : même action que le drag, sans le drag. */}
            <div className="mt-2 flex gap-1.5">
                {columns.find((c) => c.status === task.status)?.moves.left && (
                    <button
                        type="button"
                        onClick={() =>
                            router.patch(
                                route("tasks.status", task.id),
                                { status: columns.find((c) => c.status === task.status).moves.left },
                                { preserveScroll: true },
                            )
                        }
                        className="flex-1 text-[11px] py-1 rounded border border-gray-200 text-gray-500 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky"
                    >
                        ← Reculer
                    </button>
                )}
                {columns.find((c) => c.status === task.status)?.moves.right && (
                    <button
                        type="button"
                        onClick={() =>
                            router.patch(
                                route("tasks.status", task.id),
                                { status: columns.find((c) => c.status === task.status).moves.right },
                                { preserveScroll: true },
                            )
                        }
                        className="flex-1 text-[11px] py-1 rounded border border-gray-200 text-gray-500 hover:bg-gray-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky"
                    >
                        Avancer →
                    </button>
                )}
            </div>
        </div>
    );
}

export default function TasksPage({ tasks = [], canSelectSchool = false, schools = [] }) {
    const { activeSchool } = usePage().props;
    const [composerOpen, setComposerOpen] = useState(false);
    const [dragOver, setDragOver] = useState(null);

    // Admins pick a school before creating: without one the card has nowhere to live.
    const needsSchool = canSelectSchool && !activeSchool?.id;

    const { data, setData, post, processing, errors, reset } = useForm({
        title: "",
        description: "",
        priority: "normal",
        due_date: "",
    });

    const submit = (e) => {
        e.preventDefault();
        post(route("tasks.store"), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setComposerOpen(false);
            },
        });
    };

    const handleSchoolPick = (schoolId) => {
        if (!schoolId) return;
        router.post(route("tasks.select-school"), { school_id: schoolId }, { preserveScroll: true });
    };

    const handleDrop = (status) => (e) => {
        e.preventDefault();
        setDragOver(null);
        const id = e.dataTransfer.getData("text/task-id");
        if (!id) return;
        router.patch(route("tasks.status", id), { status }, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Tâches" />

            <div className="flex-1 p-4 lg:px-8 flex flex-col gap-5">
                <header className="flex flex-col md:flex-row md:items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold text-gray-900">Mes tâches</h1>
                        <p className="text-sm text-gray-500">
                            Glissez une carte d'une colonne à l'autre — ou utilisez les boutons sur la carte.
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        {canSelectSchool && (
                            <span className="inline-flex items-center gap-1.5 px-3 py-2 rounded-md bg-white border border-gray-200 text-xs font-medium text-gray-600">
                                <School className="w-4 h-4 text-lamaSky" aria-hidden="true" />
                                <select
                                    aria-label="École du tableau"
                                    value={activeSchool?.id ?? ""}
                                    onChange={(e) => handleSchoolPick(e.target.value)}
                                    className="bg-transparent outline-none focus-visible:ring-2 focus-visible:ring-lamaSky rounded"
                                >
                                    <option value="" disabled>Choisir une école…</option>
                                    {schools.map((school) => (
                                        <option key={school.id} value={school.id}>
                                            {school.name}
                                        </option>
                                    ))}
                                </select>
                            </span>
                        )}
                        <button
                            type="button"
                            onClick={() => setComposerOpen((open) => !open)}
                            aria-expanded={composerOpen}
                            disabled={needsSchool}
                            title={needsSchool ? "Choisissez d'abord une école" : undefined}
                            className="inline-flex items-center gap-2 text-sm font-medium px-4 py-2 rounded-md bg-lamaSky text-white hover:bg-lamaSky/90 transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-lamaSky disabled:bg-gray-300 disabled:cursor-not-allowed"
                        >
                            {composerOpen ? (
                                <X className="w-4 h-4" aria-hidden="true" />
                            ) : (
                                <Plus className="w-4 h-4" aria-hidden="true" />
                            )}
                            Nouvelle tâche
                        </button>
                    </div>
                </header>

                {needsSchool && (
                    <p className="rounded-md bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
                        Choisissez une école dans la liste ci-dessus pour voir et créer ses tâches.
                    </p>
                )}

                {composerOpen && (
                    <form
                        onSubmit={submit}
                        className="bg-white rounded-md border border-gray-100 shadow-sm p-4 grid grid-cols-1 md:grid-cols-12 gap-3 items-start"
                    >
                        <div className="md:col-span-5">
                            <label htmlFor="task-title" className="block text-xs font-medium text-gray-500 mb-1">
                                Tâche *
                            </label>
                            <input
                                id="task-title"
                                type="text"
                                required
                                maxLength={255}
                                value={data.title}
                                onChange={(e) => setData("title", e.target.value)}
                                placeholder="Ex. Appeler les parents de Youssef"
                                className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaSky focus:ring-lamaSky text-sm"
                            />
                            {errors.title && <p className="mt-1 text-xs text-red-500">{errors.title}</p>}
                        </div>
                        <div className="md:col-span-3">
                            <label htmlFor="task-desc" className="block text-xs font-medium text-gray-500 mb-1">
                                Détail
                            </label>
                            <input
                                id="task-desc"
                                type="text"
                                value={data.description}
                                onChange={(e) => setData("description", e.target.value)}
                                className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaSky focus:ring-lamaSky text-sm"
                            />
                        </div>
                        <div className="md:col-span-2">
                            <label htmlFor="task-priority" className="block text-xs font-medium text-gray-500 mb-1">
                                Priorité
                            </label>
                            <select
                                id="task-priority"
                                value={data.priority}
                                onChange={(e) => setData("priority", e.target.value)}
                                className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaSky focus:ring-lamaSky text-sm"
                            >
                                <option value="high">Haute</option>
                                <option value="normal">Normale</option>
                                <option value="low">Basse</option>
                            </select>
                        </div>
                        <div className="md:col-span-2">
                            <label htmlFor="task-due" className="block text-xs font-medium text-gray-500 mb-1">
                                Échéance
                            </label>
                            <input
                                id="task-due"
                                type="date"
                                value={data.due_date}
                                onChange={(e) => setData("due_date", e.target.value)}
                                className="w-full rounded-md border-gray-300 shadow-sm focus:border-lamaSky focus:ring-lamaSky text-sm"
                            />
                        </div>
                        <div className="md:col-span-12 flex justify-end">
                            <button
                                type="submit"
                                disabled={processing || !data.title.trim()}
                                className="inline-flex items-center gap-1.5 text-sm font-medium px-4 py-2 rounded-md bg-green-600 text-white hover:bg-green-700 disabled:bg-gray-300 disabled:cursor-not-allowed transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-green-400"
                            >
                                <Plus className="w-4 h-4" aria-hidden="true" />
                                Ajouter
                            </button>
                        </div>
                    </form>
                )}

                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    {columns.map((column) => {
                        const columnTasks = tasks.filter((task) => task.status === column.status);
                        return (
                            <section
                                key={column.status}
                                aria-label={column.label}
                                onDragOver={(e) => {
                                    e.preventDefault();
                                    setDragOver(column.status);
                                }}
                                onDragLeave={() => setDragOver((over) => (over === column.status ? null : over))}
                                onDrop={handleDrop(column.status)}
                                className={`rounded-xl p-3 min-h-[240px] flex flex-col gap-3 transition-colors ${
                                    dragOver === column.status
                                        ? "bg-lamaSkyLight ring-2 ring-lamaSky"
                                        : "bg-white/60"
                                }`}
                            >
                                <h2 className="flex items-center gap-2 text-sm font-semibold text-gray-700 px-1">
                                    <span className={`h-2.5 w-2.5 rounded-full ${column.dot}`} aria-hidden="true" />
                                    {column.label}
                                    <span className="text-xs font-normal text-gray-400 tabular-nums">
                                        {columnTasks.length}
                                    </span>
                                </h2>

                                {columnTasks.length === 0 ? (
                                    <p className="text-xs text-gray-400 text-center py-8 border-2 border-dashed border-gray-200 rounded-lg">
                                        Déposez une carte ici
                                    </p>
                                ) : (
                                    columnTasks.map((task) => (
                                        <TaskCard
                                            key={task.id}
                                            task={task}
                                            onDragStart={(e, id) =>
                                                e.dataTransfer.setData("text/task-id", String(id))
                                            }
                                        />
                                    ))
                                )}
                            </section>
                        );
                    })}
                </div>
            </div>
        </>
    );
}

TasksPage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
