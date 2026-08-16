import { FaCalendarAlt, FaExclamationTriangle } from "react-icons/fa";
import { format } from "date-fns";
import { useState } from "react";
import { Edit } from "lucide-react";
import { router } from '@inertiajs/react';
import Table from './Table';
import { usePage } from "@inertiajs/react";
import WhatsAppButton from './WhatsAppButton';

const AbsenceLogTable = ({ absences, studentId, studentClassId, onNotified }) => {
    const [showUpdateModal, setShowUpdateModal] = useState(false);
    const [selectedAbsence, setSelectedAbsence] = useState(null);
    const [editForm, setEditForm] = useState({ status: "absent", reason: "", subject: "" });
    const [saving, setSaving] = useState(false);
    const [editError, setEditError] = useState(null);
    // Defensive: ensure absences is always an array
    const safeAbsences = Array.isArray(absences) ? absences : [];
    const role = usePage().props.auth.user.role;

    // Function to format the date
    const formatDate = (dateString) => {
        try {
            return format(new Date(dateString), "dd MMM yyyy");
        } catch (error) {
            return dateString;
        }
    };

    // Enrich absence data with student_id and class_id
    const enrichedAbsences = safeAbsences.map((absence) => ({
        ...absence,
        student_id: absence.student_id || studentId,
        class_id: absence.class_id || studentClassId,
    }));

    // Function to determine status badge style
    const getStatusBadge = (status) => {
        switch (status.toLowerCase()) {
            case "absent":
                return (
                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                        <FaExclamationTriangle className="mr-1" />
                        Absent(e)
                    </span>
                );
            case "late":
                return (
                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                        En retard
                    </span>
                );
            case "present":
                return (
                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                        Présent(e)
                    </span>
                );
            default:
                return (
                    <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                        {status}
                    </span>
                );
        }
    };

    const handleEditClick = (absence) => {
        setSelectedAbsence(absence);
        setEditForm({
            status: absence.status || "absent",
            reason: absence.reason || "",
            subject: absence.subject || "",
        });
        setEditError(null);
        setShowUpdateModal(true);
    };

    const handleEditSubmit = (e) => {
        e.preventDefault();
        if (!selectedAbsence || saving) return;
        setSaving(true);
        setEditError(null);

        router.put(
            route("attendances.update", selectedAbsence.id),
            {
                student_id: selectedAbsence.student_id,
                status: editForm.status,
                reason: editForm.reason,
                date: selectedAbsence.date,
                class_id: selectedAbsence.class_id,
                teacher_id: selectedAbsence.teacher_id || null,
                subject: editForm.subject,
            },
            {
                preserveState: true,
                preserveScroll: true,
                /*
                 * "present" deletes the row server-side (the update route's
                 * convention), so the local list must not keep it — ask for the day
                 * again and let the server say who is left.
                 */
                onSuccess: () => {
                    setShowUpdateModal(false);
                    onNotified();
                },
                onError: (errors) => {
                    const first = errors && Object.values(errors)[0];
                    setEditError(
                        first || "Impossible de modifier cet enregistrement.",
                    );
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    // Define columns for the Table component
    const columns = [
        { header: "Nom de l'élève", accessor: "student_name" },
        { header: "Date & Heure", accessor: "date" }, // updated header
        { header: "Classe", accessor: "class" },
        { header: "Enseignant", accessor: "teacher" }, // new
        { header: "Matière", accessor: "subject" },   // new
        { header: "Enregistré par", accessor: "recorded_by" },
        { header: "Statut", accessor: "status" },
        { header: "Motif", accessor: "reason" },
        { header: "Action", accessor: "action" },
    ];

    const formatDateTime = (dateString) => {
        try {
            return format(new Date(dateString), "dd MMM yyyy HH:mm", { locale: require('date-fns/locale/fr') });
        } catch (error) {
            return dateString;
        }
    };

    // Render a row for the Table component
    const renderRow = (absence) => (
        <tr key={absence.id} className="border-b border-gray-200 even:bg-slate-50 text-sm hover:bg-lamaPurpleLight">
            <td className={`p-4 ${role !== "teacher" && absence.student_id ? 'cursor-pointer hover:bg-gray-100' : ''}`} onClick={role !== "teacher" && absence.student_id ? () => router.visit(`/students/${absence.student_id}`) : undefined}>
                {(
                    (absence.first_name && absence.last_name && `${absence.first_name} ${absence.last_name}`) ||
                    (absence.student_first_name && absence.student_last_name && `${absence.student_first_name} ${absence.student_last_name}`) ||
                    absence.student_name || absence.studentName || '-'
                )}
                {!absence.student_id && role !== "teacher" && (
                    <span className="text-xs text-gray-500 ml-2">(ID manquant)</span>
                )}
            </td>
            <td className="p-4">{formatDateTime(absence.date)}</td>
            <td className="p-4">{absence.class_name || absence.class || "-"}</td>
            <td className="p-4">
                {absence.teacher_name || absence.teacher || (absence.teacher_id ? 'Teacher ID: ' + absence.teacher_id : '-')}
            </td>
            <td className="p-4">
                {absence.subject || absence.subject_name || '-'}
            </td>
            <td className="p-4">{absence.recorded_by_name || absence.recorded_by || absence.recordedBy || "-"}</td>
            <td className="p-4">{getStatusBadge(absence.status)}</td>
            <td className="p-4">{absence.reason || "---"}</td>
            <td className="p-4">
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={() => handleEditClick(absence)}
                        title="Modifier l'enregistrement"
                        className="p-2 rounded-md text-gray-500 hover:bg-indigo-50 hover:text-indigo-600 transition"
                    >
                        <Edit className="h-4 w-4" />
                    </button>
                    <WhatsAppButton
                        studentId={absence.student_id}
                        attendanceId={absence.id}
                        notification={absence.notification}
                        onSettled={onNotified}
                        studentName={
                            (absence.first_name && absence.last_name && `${absence.first_name} ${absence.last_name}`) ||
                            (absence.student_first_name && absence.student_last_name && `${absence.student_first_name} ${absence.student_last_name}`) ||
                            absence.student_name || absence.studentName || 'Élève'
                        }
                    />
                </div>
            </td>
        </tr>
    );

    return (
        <div className="mb-8 bg-white  overflow-hidden">
            <div className="overflow-x-auto">
                <Table columns={columns} data={enrichedAbsences} renderRow={renderRow} />
            </div>
            {/* Empty state */}
            {(safeAbsences.length === 0) && (
                <div className="p-8 text-center text-gray-500">
                    Aucun enregistrement d'absence trouvé.
                </div>
            )}

            {showUpdateModal && selectedAbsence && (
                <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
                    <div className="bg-white rounded-lg p-6 w-full max-w-md">
                        <h3 className="text-lg font-semibold mb-4">
                            Modifier l'enregistrement
                        </h3>
                        <form onSubmit={handleEditSubmit} className="space-y-4">
                            <div>
                                <label className="block text-sm font-medium mb-1">
                                    Statut
                                </label>
                                <select
                                    value={editForm.status}
                                    onChange={(e) =>
                                        setEditForm({
                                            ...editForm,
                                            status: e.target.value,
                                        })
                                    }
                                    className="w-full p-2 border border-gray-300 rounded-md"
                                    required
                                >
                                    <option value="absent">Absent(e)</option>
                                    <option value="late">En retard</option>
                                    <option value="present">Présent(e)</option>
                                </select>
                                {editForm.status === "present" && (
                                    <p className="mt-1 text-xs text-gray-500">
                                        Marquer présent supprime l'enregistrement —
                                        la notification en attente est annulée.
                                    </p>
                                )}
                            </div>

                            {editForm.status !== "present" && (
                                <>
                                    <div>
                                        <label className="block text-sm font-medium mb-1">
                                            Motif
                                        </label>
                                        <input
                                            type="text"
                                            value={editForm.reason}
                                            onChange={(e) =>
                                                setEditForm({
                                                    ...editForm,
                                                    reason: e.target.value,
                                                })
                                            }
                                            className="w-full p-2 border border-gray-300 rounded-md"
                                        />
                                    </div>
                                    <div>
                                        <label className="block text-sm font-medium mb-1">
                                            Matière
                                        </label>
                                        <input
                                            type="text"
                                            value={editForm.subject}
                                            onChange={(e) =>
                                                setEditForm({
                                                    ...editForm,
                                                    subject: e.target.value,
                                                })
                                            }
                                            className="w-full p-2 border border-gray-300 rounded-md"
                                        />
                                    </div>
                                </>
                            )}

                            {editError && (
                                <div className="text-red-500 text-sm">
                                    {editError}
                                </div>
                            )}

                            <div className="flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => setShowUpdateModal(false)}
                                    className="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-md"
                                >
                                    Annuler
                                </button>
                                <button
                                    type="submit"
                                    disabled={saving}
                                    className="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 disabled:opacity-50"
                                >
                                    {saving ? "Enregistrement…" : "Enregistrer"}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </div>
    );
};

export default AbsenceLogTable;
