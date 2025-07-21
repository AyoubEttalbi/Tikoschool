import { FaCalendarAlt, FaExclamationTriangle } from "react-icons/fa";
import { format } from "date-fns";
import FormModal from "./FormModal";
import AttendanceModal from "@/Pages/Attendance/AttendanceModal";
import { useState } from "react";
import { Edit } from "lucide-react";
import { router } from '@inertiajs/react';
import Table from './Table';

const AbsenceLogTable = ({ absences, studentId, studentClassId }) => {
    const [showUpdateModal, setShowUpdateModal] = useState(false);
    const [selectedAbsence, setSelectedAbsence] = useState(null);
    // Defensive: ensure absences is always an array
    const safeAbsences = Array.isArray(absences) ? absences : [];
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
        student_id: studentId,
        class_id: studentClassId,
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
        setShowUpdateModal(true);
    };

    // Define columns for the Table component
    const columns = [
        { header: "Nom de l'élève", accessor: "student_name" },
        { header: "Date", accessor: "date" },
        { header: "Classe", accessor: "class" },
        { header: "Enregistré par", accessor: "recorded_by" },
        { header: "Statut", accessor: "status" },
        { header: "Motif", accessor: "reason" },
        { header: "Action", accessor: "action" },
    ];

    // Render a row for the Table component
    const renderRow = (absence) => (
        <tr key={absence.id} className="border-b border-gray-200 even:bg-slate-50 text-sm hover:bg-lamaPurpleLight">
            <td className="p-4">
                {(
                    (absence.first_name && absence.last_name && `${absence.first_name} ${absence.last_name}`) ||
                    (absence.student_first_name && absence.student_last_name && `${absence.student_first_name} ${absence.student_last_name}`) ||
                    absence.student_name || absence.studentName || '-'
                )}
            </td>
            <td className="p-4">{formatDate(absence.date)}</td>
            <td className="p-4">{absence.class_name || absence.class || "-"}</td>
            <td className="p-4">{absence.recorded_by_name || absence.recorded_by || absence.recordedBy || "-"}</td>
            <td className="p-4">{getStatusBadge(absence.status)}</td>
            <td className="p-4">{absence.reason || "---"}</td>
            <td className="p-4">
                <button
                    className="btn btn-green"
                    onClick={() => router.post(route('absence.notify', absence.student_id))}
                >
                    Envoyer WhatsApp
                </button>
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
        </div>
    );
};

export default AbsenceLogTable;
