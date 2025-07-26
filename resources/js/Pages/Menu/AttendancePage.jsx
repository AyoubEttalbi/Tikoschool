import { Link, router, usePage } from "@inertiajs/react";
import { useState, useEffect } from "react";
import FormModal from "../../Components/FormModal";
import TableSearch from "../../Components/TableSearch";
import Table from "../../Components/Table";
import Pagination from "../../Components/Pagination";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Plus, CheckCircle, XCircle, Clock } from "lucide-react";
import TeacherSelection from "../Attendance/TeacherSelection";
import AttendanceModal from "../Attendance/AttendanceModal";

const columns = [
    { header: "Étudiant", accessor: "student" },
    { header: "Classe", accessor: "class" },
    { header: "Date", accessor: "date", className: "hidden md:table-cell" },
    { header: "Matière", accessor: "subject" },
    { header: "Statut", accessor: "status" },
    { header: "Raison", accessor: "reason", className: "hidden lg:table-cell" },
    { header: "Enregistré par", accessor: "recorded_by_name" },
];

const AttendancePage = ({
    attendances,
    assistants,
    schools,
    classes,
    students,
    teachers,
    filters,
    levels,
    allSubjects: allSubjectsProp,
}) => {
    const { auth, errors } = usePage().props;
    const [attendanceData, setAttendanceData] = useState([]);
    const [selectedSubject, setSelectedSubject] = useState(filters.subject || "");
    const [subjectError, setSubjectError] = useState("");
    const [formDate, setFormDate] = useState(
        filters.date || new Date().toISOString().split("T")[0],
    );


    // Use allSubjects prop if provided, otherwise compute from students
    const allSubjects = allSubjectsProp && Array.isArray(allSubjectsProp)
        ? allSubjectsProp
        : Array.from(new Set(
            (students || []).flatMap(student => student.subjects || [])
        )).filter(Boolean);

    // Filtered students by selected subject
    const filteredStudents = selectedSubject
        ? (students || []).filter(student => (student.subjects || []).includes(selectedSubject))
        : students || [];

    useEffect(() => {
        if (students?.length > 0) {
            // Always use the status/reason from the backend if present, otherwise default to 'present'
            const newAttendanceData = students.map((student) => {
                return {
                    student_id: student.student_id || student.id,
                    status: typeof student.status !== 'undefined' ? student.status : "present",
                    reason: typeof student.reason !== 'undefined' ? student.reason : "",
                    date: student.date || filters.date || new Date().toISOString().split("T")[0],
                    class_id: student.classId || filters.class_id,
                    teacher_id: student.teacher_id || filters.teacher_id,
                };
            });

            setAttendanceData(newAttendanceData);

            // Pre-select subject if only one subject is available for the teacher in this class
            const allSubjects = Array.from(new Set(
                (students || []).flatMap(student => student.subjects || [])
            )).filter(Boolean);
            if (allSubjects.length === 1 && !filters.subject) {
                setSelectedSubject(allSubjects[0]);
            }
        }
    }, [students, filters.date, filters.class_id]);


    // Always update filters with selected subject when it changes
    useEffect(() => {
        // Only reload if the selected subject is different from the filter
        if (
            selectedSubject &&
            selectedSubject !== (filters.subject || "")
        ) {
            router.visit(route('attendances.index', {
                ...filters,
                subject: selectedSubject,
                date: formDate,
                _timestamp: new Date().getTime(),
            }), {
                preserveScroll: true,
                preserveState: true,
                only: ['students', 'filters'],
            });
        }
    }, [selectedSubject]);

    useEffect(() => {
        console.log("Students data changed:", {
            count: students?.length,
            filters,
            studentsData: students,
        });
    }, [students]);

    const handleSubmit = (e) => {
        e.preventDefault();
        if (
            !attendanceData ||
            attendanceData.length === 0 ||
            !attendanceData[0].student_id
        ) {
            console.error("Missing student_id in attendance data");
            return;
        }

        if (!selectedSubject) {
            setSubjectError("Veuillez sélectionner une matière.");
            document.getElementById('subject-dropdown')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        setSubjectError("");
        // Ensure subject is included for each attendance entry
        const payload = {
            attendances: attendanceData.map(entry => ({
                ...entry,
                subject: selectedSubject,
            })),
            date: formDate,
            class_id: filters.class_id,
            teacher_id: filters.teacher_id,
            subject: selectedSubject,
        };

        router.post(route("attendances.store"), payload, {
            onSuccess: () => {
                window.location.href = route("attendances.index", {
                    ...filters,
                    date: formDate,
                    _timestamp: new Date().getTime(),
                });
            },
            onError: (errors) => {
                console.log("errors", errors);
            },
            preserveScroll: false,
        });
    };

    // Helper to get attendance entry for a student
    const getAttendanceEntry = (studentId) => attendanceData.find((a) => a.student_id === studentId) || {};

    // Update status by student_id
    const handleStatusChange = (studentId, value) => {
        setAttendanceData((prev) => prev.map((entry) =>
            entry.student_id === studentId
                ? { ...entry, status: value, reason: (value === 'absent' || value === 'late') ? entry.reason : '' }
                : entry
        ));
    };

    // Update reason by student_id
    const handleReasonChange = (studentId, value) => {
        setAttendanceData((prev) => prev.map((entry) =>
            entry.student_id === studentId
                ? { ...entry, reason: value }
                : entry
        ));
    };

    // Render row using students for display, attendanceData for status/reason
    const renderRow = (student) => {
        const att = getAttendanceEntry(student.student_id || student.id);
        return (
            <tr
                key={student.id}
                className="border-b border-gray-200 even:bg-slate-50 text-sm hover:bg-purple-50 transition-colors"
            >
                <td className="p-4 font-medium">
                    {student.firstName} {student.lastName}
                </td>
                <td>{student.class?.name || "N/A"}</td>
                <td className="hidden md:table-cell">
                    {new Date(student.date).toLocaleDateString("en-US", {
                        weekday: "short",
                        year: "numeric",
                        month: "short",
                        day: "numeric",
                    })}
                </td>
                <td>{selectedSubject || '-'}</td>
                <td>
                    <div className="flex gap-2 items-center">
                        <button
                            type="button"
                            className={`flex items-center gap-1 px-2 py-1 rounded transition-colors duration-150 border focus:outline-none focus:ring-2 focus:ring-green-400 ${att.status === "present" ? "bg-green-500 text-white border-green-600" : "bg-gray-100 text-gray-700 border-gray-300 hover:bg-green-100"}`}
                            onClick={() => handleStatusChange(student.student_id || student.id, "present")}
                            title="Présent"
                        >
                            <CheckCircle className="w-4 h-4" /> Présent
                        </button>
                        <button
                            type="button"
                            className={`flex items-center gap-1 px-2 py-1 rounded transition-colors duration-150 border focus:outline-none focus:ring-2 focus:ring-rose-400 ${att.status === "absent" ? "bg-rose-500 text-white border-rose-600" : "bg-gray-100 text-gray-700 border-gray-300 hover:bg-rose-100"}`}
                            onClick={() => handleStatusChange(student.student_id || student.id, "absent")}
                            title="Absent"
                        >
                            <XCircle className="w-4 h-4" /> Absent
                        </button>
                        <button
                            type="button"
                            className={`flex items-center gap-1 px-2 py-1 rounded transition-colors duration-150 border focus:outline-none focus:ring-2 focus:ring-yellow-400 ${att.status === "late" ? "bg-yellow-400 text-white border-yellow-500" : "bg-gray-100 text-gray-700 border-gray-300 hover:bg-yellow-100"}`}
                            onClick={() => handleStatusChange(student.student_id || student.id, "late")}
                            title="En retard"
                        >
                            <Clock className="w-4 h-4" /> En retard
                        </button>
                    </div>
                </td>
                <td className="hidden lg:table-cell text-gray-600">
                    {(att.status === "absent" || att.status === "late") ? (
                        <input
                            type="text"
                            placeholder="Raison"
                            value={att.reason || ""}
                            onChange={(e) => handleReasonChange(student.student_id || student.id, e.target.value)}
                            className="w-full p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-400 transition"
                        />
                    ) : (
                        <span>-</span>
                    )}
                </td>
                <td className="text-gray-600">
                    {student.recorded_by_name || "-"}
                </td>
            </tr>
        );
    };

    if (!filters.teacher_id || !filters.class_id) {
        return (
            <TeacherSelection
                assistants={assistants}
                teachers={teachers}
                schools={schools}
                levels={levels}
                currentSelection={{
                    teacher: filters.teacher_id,
                    class: filters.class_id,
                }}
            />
        );
    }

    // allSubjects is now defined above, always from the full students list

    return (
        <div className="bg-white p-6 rounded-lg shadow-sm flex-1 m-4 mt-0">
            <div className="flex flex-col md:flex-row items-start md:items-center justify-between mb-6 gap-4">
                <h1 className="text-xl font-semibold text-gray-800">
                    Registres de présence
                </h1>
                <div className="flex items-center gap-3 w-full md:w-auto">
                    <TableSearch
                        routeName="attendances.index"
                        filters={filters}
                    />
                    <div>
                        <select
                            id="subject-dropdown"
                            value={selectedSubject}
                            onChange={e => {
                                setSelectedSubject(e.target.value);
                                setSubjectError("");
                            }}
                            required
                            className="p-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-400 transition"
                        >
                            <option value="" disabled>Choisir la matière</option>
                            {allSubjects.map(subject => (
                                <option key={subject} value={subject}>{subject}</option>
                            ))}
                        </select>
                        {subjectError && (
                            <div className="text-red-500 text-xs mt-1">{subjectError}</div>
                        )}
                    </div>
                </div>
            </div>
            <form onSubmit={handleSubmit}>
                <Table
                    columns={columns}
                    renderRow={renderRow}
                    data={filteredStudents}
                    headerClassName="bg-gray-50 text-gray-600 text-sm font-medium"
                />
                <div className="flex justify-end gap-2 mt-4">
                    <button
                        type="submit"
                        className="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700"
                    >
                        Enregistrer la présence
                    </button>
                </div>
            </form>
        </div>
    );
};

AttendancePage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
export default AttendancePage;
