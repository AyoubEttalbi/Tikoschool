"use client";

import { useEffect, useRef, useState } from "react";
import {
    Trash2,
    Download,
    GraduationCap,
    School,
    BookOpen,
    Award,
    BookText,
} from "lucide-react";
import FormModal from "./FormModal";
import { role } from "@/lib/data";
import DeleteConfirmation from "./DeleteConfirmation";

// Helper function to get icon based on level name
const getLevelIcon = (levelName) => {
    const name = levelName.toLowerCase();
    if (name.includes("bac")) return GraduationCap;
    if (name.includes("2bac")) return Award;
    if (name.includes("college")) return School;
    if (name.includes("primary")) return BookOpen;
    return BookText;
};

// Helper function to get background color based on level name
const getIconBackground = (levelName) => {
    const name = levelName.toLowerCase();
    if (name.includes("bac")) return "bg-blue-100";
    if (name.includes("2bac")) return "bg-purple-100";
    if (name.includes("college")) return "bg-green-100";
    if (name.includes("primary")) return "bg-yellow-100";
    return "bg-gray-100";
};

// Helper function to get icon color based on level name
const getIconColor = (levelName) => {
    const name = levelName.toLowerCase();
    if (name.includes("bac")) return "text-blue-600";
    if (name.includes("2bac")) return "text-purple-600";
    if (name.includes("college")) return "text-green-600";
    if (name.includes("primary")) return "text-yellow-600";
    return "text-gray-600";
};

/*
 * "Print everyone in 2 BAC."
 *
 * A level exists across every branch, so the roster is only a meaningful document once
 * you know which branch it is for. With one school there is nothing to ask — the click
 * downloads. With several, the question has to be answered before the download starts,
 * which is why this opens a small picker instead of guessing a school.
 */
function RosterDownload({ level, schools }) {
    const [open, setOpen] = useState(false);
    const [schoolId, setSchoolId] = useState("");
    const boxRef = useRef(null);

    const base = route("othersettings.levels.students.download", level.id);
    const href = schoolId ? `${base}?school_id=${schoolId}` : base;

    // A picker that stays open after you have clicked away from it reads as stuck.
    useEffect(() => {
        if (!open) return;
        const onDocClick = (e) => {
            if (boxRef.current && !boxRef.current.contains(e.target)) setOpen(false);
        };
        const onKey = (e) => e.key === "Escape" && setOpen(false);
        document.addEventListener("mousedown", onDocClick);
        document.addEventListener("keydown", onKey);
        return () => {
            document.removeEventListener("mousedown", onDocClick);
            document.removeEventListener("keydown", onKey);
        };
    }, [open]);

    // One school (or none): nothing to choose, so do not make them choose it. The server
    // scopes the rows to what the caller may see either way.
    if (schools.length <= 1) {
        return (
            <a
                href={schools.length === 1 ? `${base}?school_id=${schools[0].id}` : base}
                target="_blank"
                rel="noopener noreferrer"
                title={`Télécharger la liste des élèves de ${level.name}`}
                className="w-7 h-7 flex items-center justify-center rounded-full bg-gray-100 text-black transition-all duration-200 hover:bg-lamaPurple hover:text-white"
            >
                <Download className="w-4 h-4" />
            </a>
        );
    }

    return (
        <div className="relative" ref={boxRef}>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-expanded={open}
                title={`Télécharger la liste des élèves de ${level.name}`}
                className="w-7 h-7 flex items-center justify-center rounded-full bg-gray-100 text-black transition-all duration-200 hover:bg-lamaPurple hover:text-white"
            >
                <Download className="w-4 h-4" />
            </button>

            {open && (
                <div className="absolute right-0 z-20 mt-2 w-60 rounded-lg border border-gray-200 bg-white p-3 shadow-lg">
                    <label className="block text-xs font-medium text-gray-600">
                        Établissement
                    </label>
                    <select
                        value={schoolId}
                        onChange={(e) => setSchoolId(e.target.value)}
                        className="mt-1 w-full rounded-md border-gray-300 text-sm focus:border-gray-900 focus:ring-gray-900"
                    >
                        <option value="">Tous les établissements</option>
                        {schools.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </select>

                    <a
                        href={href}
                        target="_blank"
                        rel="noopener noreferrer"
                        onClick={() => setOpen(false)}
                        className="mt-3 flex w-full items-center justify-center gap-2 rounded-md bg-lamaPurple px-3 py-2 text-sm font-medium text-white transition hover:opacity-90"
                    >
                        <Download className="w-4 h-4" />
                        Télécharger
                    </a>
                </div>
            )}
        </div>
    );
}

function LevelCard({ level, schools, onDelete }) {
    const Icon = getLevelIcon(level.name);
    const bgColor = getIconBackground(level.name);
    const iconColor = getIconColor(level.name);

    return (
        <div className="rounded-lg border border-gray-200 bg-white transition-all duration-200 hover:shadow-md hover:-translate-y-1 flex group">
            <div className="w-full text-left flex justify-between items-center p-4">
                <div className="flex items-center gap-3 min-w-0">
                    <div
                        className={`p-2 rounded-lg ${bgColor} transition-transform group-hover:scale-110`}
                    >
                        <Icon className={`w-4 h-4 ${iconColor}`} />
                    </div>
                    <span className="font-medium truncate">{level.name}</span>
                </div>
                <div className="flex items-center gap-2 shrink-0">
                    <RosterDownload level={level} schools={schools} />
                    <button
                        onClick={() => onDelete(level)}
                        title={`Supprimer le niveau ${level.name}`}
                        className="w-7 h-7 flex items-center hover:text-white text-black justify-center rounded-full bg-gray-100  transition-all duration-200 hover:bg-red-500"
                    >
                        <Trash2 className="w-4 h-4" />
                    </button>
                </div>
            </div>
        </div>
    );
}

function LevelsList({ levelsData = [], schools = [] }) {
    const [deleteLevel, setDeleteLevel] = useState(null);

    return (
        <div className="bg-white p-4 rounded-md flex-1 m-4 mt-0 md:mt-4">
            <div className="flex items-center justify-between mb-8">
                <div className="flex flex-row md:flex-row items-center gap-4 w-full md:w-auto">
                    <h1 className="font-semibold text-2xl">Ma liste de niveaux</h1>
                    {role === "admin" && (
                        <FormModal
                            table="level"
                            type="create"
                            buttonLabel="Ajouter un niveau"
                        />
                    )}
                </div>
            </div>
            <div className="container mx-auto">
                <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4">
                    {levelsData.map((level) => (
                        <LevelCard
                            key={level.id}
                            level={level}
                            schools={schools}
                            onDelete={setDeleteLevel}
                        />
                    ))}
                </div>
            </div>
            {deleteLevel && (
                <div className="fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center">
                    <DeleteConfirmation
                        id={deleteLevel.id}
                        route="othersettings/levels"
                        onDelete={() => setDeleteLevel(null)}
                        onClose={() => setDeleteLevel(null)}
                        confirmText="Supprimer le niveau ?"
                        cancelText="Annuler"
                        deleteButtonText="Supprimer"
                    />
                </div>
            )}
        </div>
    );
}

export default LevelsList;
