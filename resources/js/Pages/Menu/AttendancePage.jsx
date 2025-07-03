import { Link, router, usePage } from "@inertiajs/react";
import { useState, useEffect } from "react";
import FormModal from "../../Components/FormModal";
import TableSearch from "../../Components/TableSearch";
import Table from "../../Components/Table";
import Pagination from "../../Components/Pagination";
import DashboardLayout from "@/Layouts/DashboardLayout";
import { Plus } from "lucide-react";
import TeacherSelection from "../Attendance/TeacherSelection";
import AttendanceModal from "../Attendance/AttendanceModal";

const columns = [
    { header: "Étudiant", accessor: "student" },
    { header: "Classe", accessor: "class" },
    { header: "Date", accessor: "date", className: "hidden md:table-cell" },
    { header: "Statut", accessor: "status" },
    { header: "Raison", accessor: "reason", className: "hidden lg:table-cell" },
    { header: "Enregistré par", accessor: "teacher" },
    { header: "Actions", accessor: "action" },
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
}) => {
    const { auth, errors } = usePage().props;
    const [showCreateModal, setShowCreateModal] = useState(false);
    const [showUpdateModal, setShowUpdateModal] = useState(false);
    const [selectedAttendance, setSelectedAttendance] = useState(null);
    const [attendanceData, setAttendanceData] = useState([]);
    const [formDate, setFormDate] = useState(
        filters.date || new Date().toISOString().split("T")[0],
    );

    useEffect(() => {
        if (students?.length > 0) {
            const newAttendanceData = students.map((student) => {
                return {
                    student_id: student.student_id || student.id,
                    status: student.status || "present",
                    reason: student.reason || "",
                    date:
                        filters.date || new Date().toISOString().split("T")[0],
                    class_id: filters.class_id,
                };
            });

            setAttendanceData(newAttendanceData);
        }
    }, [students, filters.date, filters.class_id]);

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

        const payload = {
            attendances: attendanceData,
            date: formDate,
            class_id: filters.class_id,
        };

        router.post(route("attendances.store"), payload, {
            onSuccess: () => {
                setShowCreateModal(false);

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

    const handleStatusChange = (index, value) => {
        const newData = [...attendanceData];

        if (!newData[index].student_id && students[index]) {
            newData[index].student_id =
                students[index].student_id || students[index].id;
        }

        newData[index].status = value;
        if (value !== "absent") newData[index].reason = "";
        setAttendanceData(newData);
    };

    const handleReasonChange = (index, value) => {
        const newData = [...attendanceData];

        if (!newData[index].student_id && students[index]) {
            newData[index].student_id =
                students[index].student_id || students[index].id;
        }

        newData[index].reason = value;
        setAttendanceData(newData);
    };

    const handleEditClick = (attendance) => {
        setSelectedAttendance(attendance);
        setShowUpdateModal(true);
    };

    const handleOpenCreateModal = () => {
        setFormDate(filters.date || new Date().toISOString().split("T")[0]);

        if (students?.length > 0) {
            const initialData = students.map((student) => ({
                student_id: student.student_id || student.id,
                status: student.status || "present",
                reason: student.reason || "",
                date: filters.date || new Date().toISOString().split("T")[0],
                class_id: filters.class_id,
            }));
            setAttendanceData(initialData);
        }

        setShowCreateModal(true);
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

    const renderRow = (student) => (
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
            <td>
                <span
                    className={`px-2 py-1 rounded-full text-xs font-medium ${
                        student.status === "present"
                            ? "bg-green-100 text-green-800"
                            : student.status === "late"
                              ? "bg-yellow-100 text-yellow-800"
                              : "bg-rose-100 text-rose-800"
                    }`}
                >
                    {student.status}
                </span>
            </td>
            <td className="hidden lg:table-cell text-gray-600">
                {student.reason || "-"}
            </td>
            <td className="text-gray-600">
                {auth.user.name} ({auth.user.role})
            </td>
            <td>
                <div className="flex items-center gap-2">
                    <button
                        onClick={() => handleEditClick(student)}
                        className="w-7 h-7 flex items-center justify-center rounded-full bg-lamaYellow hover:bg-yellow-200 transition-colors"
                    >
                        <img
                            src="/update.png"
                            alt="update"
                            className="w-4 h-4"
                        />
                    </button>
                </div>
            </td>
        </tr>
    );

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
                    <button
                        onClick={handleOpenCreateModal}
                        className="p-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 transition-colors flex items-center gap-2"
                    >
                        <Plus size={18} />
                        <span className="hidden sm:inline">Ajouter des enregistrements</span>
                    </button>
                </div>
            </div>

            {/* Create Attendance Modal */}
            {showCreateModal && (
                <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
                    <div className="bg-white rounded-lg p-6 w-full max-w-2xl">
                        <h2 className="text-xl font-semibold mb-4">
                            Créer des registres de présence
                        </h2>
                        <form onSubmit={handleSubmit} className="space-y-4">
                            <div>
                                <label className="block text-sm font-medium mb-1">
                                    Date
                                </label>
                                <input
                                    type="date"
                                    value={formDate}
                                    onChange={(e) =>
                                        setFormDate(e.target.value)
                                    }
                                    className="w-full p-2 border rounded-md"
                                    required
                                />
                            </div>
                            <div className="max-h-96 overflow-y-auto">
                                {students?.map((student, index) => (
                                    <div
                                        key={student.id}
                                        className="mb-4 p-2 border rounded-md"
                                    >
                                        <div className="flex items-center justify-between mb-2">
                                            <span className="font-medium">
                                                {student.firstName}{" "}
                                                {student.lastName}
                                            </span>
                                            <div className="flex items-center gap-2">
                                                {student.exists_in_db && (
                                                    <span className="text-xs text-indigo-600 italic">
                                                        Déjà enregistré
                                                    </span>
                                                )}
                                                <select
                                                    value={
                                                        attendanceData[index]
                                                            ?.status ||
                                                        "present"
                                                    }
                                                    onChange={(e) => {
                                                        handleStatusChange(
                                                            index,
                                                            e.target.value,
                                                        );
                                                    }}
                                                    className={`pl-3 pr-8 py-1 border rounded-md ${
                                                        student.exists_in_db
                                                            ? "border-indigo-300 bg-indigo-50"
                                                            : ""
                                                    }`}
                                                >
                                                    <option value="present">
                                                        Présent
                                                    </option>
                                                    <option value="absent">
                                                        Absent
                                                    </option>
                                                    <option value="late">
                                                        En retard
                                                    </option>
                                                </select>
                                            </div>
                                        </div>
                                        {(attendanceData[index]?.status ===
                                            "absent" ||
                                            attendanceData[index]?.status ===
                                                "late") && (
                                            <input
                                                type="text"
                                                placeholder="Raison de l'absence"
                                                value={
                                                    attendanceData[index]
                                                        ?.reason || ""
                                                }
                                                onChange={(e) =>
                                                    handleReasonChange(
                                                        index,
                                                        e.target.value,
                                                    )
                                                }
                                                className="w-full p-2 border rounded-md"
                                            />
                                        )}
                                    </div>
                                ))}
                            </div>

                            {errors.attendances && (
                                <div className="text-red-500 text-sm">
                                    {errors.attendances}
                                </div>
                            )}

                            <div className="flex justify-end gap-2">
                                <button
                                    type="button"
                                    onClick={() => setShowCreateModal(false)}
                                    className="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-md"
                                >
                                    Annuler
                                </button>
                                <button
                                    type="submit"
                                    className="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700"
                                >
                                    Enregistrer la présence
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}

            {showUpdateModal && selectedAttendance && (
                <AttendanceModal
                    table="attendances"
                    type="update"
                    id={selectedAttendance.id}
                    data={selectedAttendance}
                    students={students}
                    classes={classes}
                    onClose={() => setShowUpdateModal(false)}
                />
            )}

            <Table
                columns={columns}
                renderRow={renderRow}
                data={students || []}
                headerClassName="bg-gray-50 text-gray-600 text-sm font-medium"
            />

            {/* <Pagination links={attendances.links} className="mt-6" /> */}
        </div>
    );
};

AttendancePage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
export default AttendancePage;
