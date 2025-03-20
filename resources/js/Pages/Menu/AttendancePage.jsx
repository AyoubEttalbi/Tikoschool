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
  { header: "Student", accessor: "student" },
  { header: "Class", accessor: "class" },
  { header: "Date", accessor: "date", className: "hidden md:table-cell" },
  { header: "Status", accessor: "status" },
  { header: "Reason", accessor: "reason", className: "hidden lg:table-cell" },
  { header: "Recorded By", accessor: "teacher" },
  { header: "Actions", accessor: "action" },
];

const AttendancePage = ({ attendances,  assistants, schools, classes, students, teachers, filters, levels }) => {
  const { auth, errors } = usePage().props;
  const [showCreateModal, setShowCreateModal] = useState(false);
  const [showUpdateModal, setShowUpdateModal] = useState(false);
  const [selectedAttendance, setSelectedAttendance] = useState(null);
  const [attendanceData, setAttendanceData] = useState([]);
  const [date, setDate] = useState(new Date().toISOString().split('T')[0]);

  useEffect(() => {
    if (students?.length > 0) {
      setAttendanceData(students.map(student => ({
        student_id: student.id,
        status: 'present',
        reason: '',
        date: date,
        class_id: filters.class_id
      })));
    }
  }, [students, date]);

  const handleSubmit = (e) => {
    e.preventDefault();
    router.post(route('attendances.store'), {
      attendances: attendanceData,
      date: date
    }, {
      onSuccess: () => setShowCreateModal(false),
      preserveScroll: true
    });
  };

  const handleStatusChange = (index, value) => {
    const newData = [...attendanceData];
    newData[index].status = value;
    if (value !== 'absent') newData[index].reason = '';
    setAttendanceData(newData);
  };

  const handleReasonChange = (index, value) => {
    const newData = [...attendanceData];
    newData[index].reason = value;
    setAttendanceData(newData);
  };

  const handleEditClick = (attendance) => {
    setSelectedAttendance(attendance);
    setShowUpdateModal(true);
  };

  if (!filters.teacher_id || !filters.class_id) {
    return (
      <TeacherSelection
        assistants={assistants}
        teachers={teachers}
        schools={schools}
        levels={levels}
        currentSelection={{ teacher: filters.teacher_id, class: filters.class_id }}
      />
    );
  }

  const renderRow = (attendance) => (
    <tr
      key={attendance.id}
      className="border-b border-gray-200 even:bg-slate-50 text-sm hover:bg-purple-50 transition-colors"
    >
      <td className="p-4 font-medium">
        {attendance.student.firstName} {attendance.student.lastName}
      </td>
      <td>{attendance.classe.name}</td>
      <td className="hidden md:table-cell">
        {new Date(attendance.date).toLocaleDateString('en-US', {
          weekday: 'short',
          year: 'numeric',
          month: 'short',
          day: 'numeric'
        })}
      </td>
      <td>
        <span
          className={`px-2 py-1 rounded-full text-xs font-medium ${attendance.status === 'present'
              ? 'bg-green-100 text-green-800'
              : 'bg-rose-100 text-rose-800'
            }`}
        >
          {attendance.status}
        </span>
      </td>
      <td className="hidden lg:table-cell text-gray-600">
        {attendance.reason || '-'}
      </td>
      <td className="text-gray-600">
        {attendance.recorded_by ? `${attendance.recorded_by.name} as a ${attendance.recorded_by.role}` : '-'}
      </td>
      <td>
        <div className="flex items-center gap-2">
          <Link href={route('attendances.show', attendance.id)}>
            <button className="w-7 h-7 flex items-center justify-center rounded-full bg-blue-100 hover:bg-blue-200 transition-colors">
              <img src="/view.png" alt="View" className="w-4 h-4" />
            </button>
          </Link>
          {auth.user.role === "admin" && (
            <>
              <button
                onClick={() => handleEditClick(attendance)}
                className="w-7 h-7 flex items-center justify-center rounded-full bg-lamaYellow hover:bg-yellow-200 transition-colors"
              >
                <img src="/update.png" alt="update" className="w-4 h-4" />
              </button>
              <FormModal
                table="attendance"
                type="delete"
                id={attendance.id}
                route="attendances"
              />
            </>
          )}
        </div>
      </td>
    </tr>
  );

  return (
    <div className="bg-white p-6 rounded-lg  shadow-sm flex-1 m-4 mt-0">
      <div className="flex flex-col md:flex-row items-start md:items-center  justify-between mb-6 gap-4">
        <h1 className="text-xl font-semibold text-gray-800">Attendance Records</h1>
        <div className="flex items-center gap-3 w-full md:w-auto">
          <TableSearch routeName="attendances" filters={filters} />
          <button
            onClick={() => setShowCreateModal(true)}
            className="p-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 transition-colors flex items-center gap-2"
          >
            <Plus size={18} />
            <span className="hidden sm:inline">Add Records</span>
          </button>
        </div>
      </div>

      {/* Create Attendance Modal */}
      {showCreateModal && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
          <div className="bg-white rounded-lg p-6 w-full max-w-2xl">
            <h2 className="text-xl font-semibold mb-4">Create Attendance Records</h2>
            <form onSubmit={handleSubmit} className="space-y-4">
              <div>
                <label className="block text-sm font-medium mb-1">Date</label>
                <input
                  type="date"
                  value={date}
                  onChange={(e) => setDate(e.target.value)}
                  className="w-full p-2 border rounded-md"
                  required
                />
              </div>

              <div className="max-h-96 overflow-y-auto">
                {students?.map((student, index) => (
                  <div key={student.id} className="mb-4 p-2 border rounded-md">
                    <div className="flex items-center justify-between mb-2">
                      <span className="font-medium">
                        {student.firstName} {student.lastName}
                      </span>
                      <select
                        value={attendanceData[index]?.status || 'present'}
                        onChange={(e) => handleStatusChange(index, e.target.value)}
                        className="px-3  py-1 border rounded-md"
                      >
                        <option value="present">Present</option>
                        <option value="absent">Absent</option>
                        <option value="late">Late</option>
                      </select>
                    </div>
                    {attendanceData[index]?.status !== 'present' && (
                      <input
                        type="text"
                        placeholder="Reason for absence"
                        value={attendanceData[index]?.reason || ''}
                        onChange={(e) => handleReasonChange(index, e.target.value)}
                        className="w-full p-2 border rounded-md"
                      />
                    )}
                  </div>
                ))}
              </div>

              {errors.attendances && (
                <div className="text-red-500 text-sm">{errors.attendances}</div>
              )}

              <div className="flex justify-end gap-2">
                <button
                  type="button"
                  onClick={() => setShowCreateModal(false)}
                  className="px-4 py-2 text-gray-600 hover:bg-gray-100 rounded-md"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  className="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700"
                >
                  Save Attendance
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
        data={attendances.data}
        headerClassName="bg-gray-50 text-gray-600 text-sm font-medium"
      />

      <Pagination links={attendances.links} className="mt-6" />
    </div>
  );
};

AttendancePage.layout = (page) => <DashboardLayout>{page}</DashboardLayout>;
export default AttendancePage;